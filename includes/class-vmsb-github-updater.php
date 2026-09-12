<?php
defined( 'ABSPATH' ) || exit;

/**
 * VMSB_GitHub_Updater - Enterprise GitHub Update Engine for VM SEO Brain.
 *
 * Provides:
 * 1. WordPress Core Update System integration (pre_set_site_transient_update_plugins, plugins_api).
 * 2. Directory normalization filter (upgrader_source_selection) for GitHub zip archives.
 * 3. In-place 1-click direct updater (perform_direct_update) via WP_Filesystem.
 * 4. Multi-tier update discovery: GitHub Releases -> GitHub Tags -> Raw repository master/main header.
 * 5. Secure GitHub Personal Access Token (PAT) support for private repos and rate limit immunity.
 * 6. Protection for local files (.git, .claude, .idea, .vscode, translation files, custom backups).
 */
class VMSB_GitHub_Updater {

	const DEFAULT_REPO   = 'vmai-plugins/vm-seo-brain';
	const DEFAULT_BRANCH = 'master';
	const CHECK_TRANSIENT = 'vmsb_update_check';
	const APPLIED_TRANSIENT = 'vmsb_update_applied';
	const CHECK_TTL       = 21600; // 6 hours

	/**
	 * Boot the updater hooks.
	 */
	public static function init() {
		// WordPress update hooks
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_plugins_transient' ) );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'filter_update_plugins_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'filter_upgrader_source_selection' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_process_complete' ), 10, 2 );

		// Plugin screen links
		if ( is_admin() ) {
			add_filter( 'plugin_action_links_' . plugin_basename( VMSB_FILE ), array( __CLASS__, 'filter_plugin_action_links' ) );
			add_filter( 'plugin_row_meta', array( __CLASS__, 'filter_plugin_row_meta' ), 10, 2 );
		}
	}

	/**
	 * Repository slug (e.g. "vmai-plugins/vm-seo-brain").
	 */
	public static function repo() {
		$repo = self::DEFAULT_REPO;
		return apply_filters( 'vmsb_github_updater_repo', $repo );
	}

	/**
	 * Branch name (e.g. "master" or "main").
	 */
	public static function branch() {
		$branch = self::DEFAULT_BRANCH;
		return apply_filters( 'vmsb_github_updater_branch', $branch );
	}

	/**
	 * Get GitHub Personal Access Token if configured.
	 */
	public static function token() {
		if ( defined( 'VMSB_GITHUB_TOKEN' ) && VMSB_GITHUB_TOKEN ) {
			return VMSB_GITHUB_TOKEN;
		}
		if ( class_exists( 'VMSB_Settings' ) ) {
			$token = VMSB_Settings::get( 'github_token', '' );
			if ( ! empty( $token ) ) {
				return $token;
			}
		}
		return apply_filters( 'vmsb_github_updater_token', '' );
	}

	/**
	 * Request headers including User-Agent and optional Authorization Bearer.
	 */
	private static function request_headers() {
		$headers = array(
			'User-Agent' => 'vm-seo-brain/' . ( defined( 'VMSB_VERSION' ) ? VMSB_VERSION : '1.6.0' ),
			'Accept'     => 'application/vnd.github.v3+json',
		);
		$token = self::token();
		if ( ! empty( $token ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/* ------------------------------------------------------------ update check */

	/**
	 * Compare installed version against GitHub.
	 *
	 * @param bool $force Force refresh without using transient cache.
	 * @return array
	 */
	public static function check( $force = false ) {
		$cached = get_transient( self::CHECK_TRANSIENT );
		if ( ! $force && is_array( $cached ) && ! empty( $cached['checked_at'] ) ) {
			return $cached;
		}

		$remote = self::fetch_remote_data();
		$installed = defined( 'VMSB_VERSION' ) ? VMSB_VERSION : '1.0.0';
		$remote_ver = isset( $remote['version'] ) ? $remote['version'] : '';

		$is_update_available = false;
		if ( $remote_ver && version_compare( $remote_ver, $installed, '>' ) ) {
			$is_update_available = true;
		}

		$result = array(
			'ok'                => ! empty( $remote_ver ),
			'checked_at'        => time(),
			'installed_version' => $installed,
			'remote_version'    => $remote_ver,
			'update_available'  => $is_update_available,
			'package_url'       => isset( $remote['package_url'] ) ? $remote['package_url'] : '',
			'release_name'      => isset( $remote['release_name'] ) ? $remote['release_name'] : '',
			'release_notes'     => isset( $remote['release_notes'] ) ? $remote['release_notes'] : '',
			'published_at'      => isset( $remote['published_at'] ) ? $remote['published_at'] : '',
			'source_type'       => isset( $remote['source_type'] ) ? $remote['source_type'] : 'raw_branch',
			'repo'              => self::repo(),
			'branch'            => self::branch(),
			'repo_url'          => 'https://github.com/' . self::repo(),
			'message'           => isset( $remote['error'] ) ? $remote['error'] : '',
		);

		$ttl = $result['ok'] ? self::CHECK_TTL : HOUR_IN_SECONDS;
		set_transient( self::CHECK_TRANSIENT, $result, $ttl );

		return $result;
	}

	/**
	 * Quick check if an update is available.
	 */
	public static function update_available() {
		$data = self::check( false );
		return ! empty( $data['update_available'] );
	}

	/**
	 * Multi-tier remote data fetcher:
	 * Tier 1: GitHub API Releases (/releases/latest)
	 * Tier 2: GitHub API Tags (/tags)
	 * Tier 3: GitHub Raw File Header (raw.githubusercontent.com/.../master/vm-seo-brain.php)
	 */
	private static function fetch_remote_data() {
		$repo    = self::repo();
		$branch  = self::branch();
		$headers = self::request_headers();

		// Tier 1: Try GitHub Releases API
		$releases_url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$res = wp_remote_get(
			$releases_url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$version = ltrim( trim( $body['tag_name'] ), 'vV' );
				$package_url = ! empty( $body['zipball_url'] ) ? $body['zipball_url'] : '';

				// If there's an attached asset named .zip, prefer that
				if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
					foreach ( $body['assets'] as $asset ) {
						if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['browser_download_url'] ) ) {
							$package_url = $asset['browser_download_url'];
							break;
						}
					}
				}

				if ( empty( $package_url ) ) {
					$package_url = 'https://github.com/' . $repo . '/archive/refs/tags/' . $body['tag_name'] . '.zip';
				}

				return array(
					'version'       => $version,
					'package_url'   => $package_url,
					'release_name'  => ! empty( $body['name'] ) ? $body['name'] : $body['tag_name'],
					'release_notes' => ! empty( $body['body'] ) ? $body['body'] : '',
					'published_at'  => ! empty( $body['published_at'] ) ? $body['published_at'] : '',
					'source_type'   => 'release',
				);
			}
		}

		// Tier 2: Try GitHub Tags API
		$tags_url = 'https://api.github.com/repos/' . $repo . '/tags';
		$tags_res = wp_remote_get(
			$tags_url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( ! is_wp_error( $tags_res ) && 200 === wp_remote_retrieve_response_code( $tags_res ) ) {
			$tags = json_decode( wp_remote_retrieve_body( $tags_res ), true );
			if ( is_array( $tags ) && ! empty( $tags[0]['name'] ) ) {
				$tag_name = $tags[0]['name'];
				$version  = ltrim( trim( $tag_name ), 'vV' );
				$package_url = ! empty( $tags[0]['zipball_url'] )
					? $tags[0]['zipball_url']
					: 'https://github.com/' . $repo . '/archive/refs/tags/' . $tag_name . '.zip';

				return array(
					'version'       => $version,
					'package_url'   => $package_url,
					'release_name'  => 'Tag ' . $tag_name,
					'release_notes' => 'Release tag ' . $tag_name . ' from GitHub repository.',
					'published_at'  => '',
					'source_type'   => 'tag',
				);
			}
		}

		// Tier 3: Fallback to Raw Repository Header on master or main
		$branches_to_try = array( $branch, 'master', 'main' );
		$branches_to_try = array_unique( array_filter( $branches_to_try ) );

		$last_error = '';
		foreach ( $branches_to_try as $b ) {
			$raw_url = 'https://raw.githubusercontent.com/' . $repo . '/' . $b . '/vm-seo-brain.php';
			$raw_res = wp_remote_get(
				$raw_url,
				array(
					'timeout' => 15,
					'headers' => $headers,
				)
			);

			if ( is_wp_error( $raw_res ) ) {
				$last_error = $raw_res->get_error_message();
				continue;
			}

			$code = wp_remote_retrieve_response_code( $raw_res );
			if ( 200 === $code ) {
				$raw_body = wp_remote_retrieve_body( $raw_res );
				if ( preg_match( '/\*\s*Version:\s*([0-9][0-9a-zA-Z.\-]*)/i', $raw_body, $m ) ) {
					$version = $m[1];
					$package_url = 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . $b;
					return array(
						'version'       => $version,
						'package_url'   => $package_url,
						'release_name'  => 'Branch ' . $b . ' (v' . $version . ')',
						'release_notes' => 'Latest tracking build from ' . $repo . ' branch ' . $b . '.',
						'published_at'  => '',
						'source_type'   => 'raw_branch',
					);
				}
			} else {
				$last_error = 'GitHub raw returned HTTP ' . $code . '.';
			}
		}

		return array(
			'error' => ! empty( $last_error ) ? $last_error : 'Could not retrieve remote version from GitHub.',
		);
	}

	/* -------------------------------------------------- WordPress Core Hooks */

	/**
	 * Hook into pre_set_site_transient_update_plugins.
	 */
	public static function filter_update_plugins_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$data = self::check( false );
		$file = plugin_basename( VMSB_FILE );
		$slug = 'vm-seo-brain';

		if ( ! empty( $data['update_available'] ) && ! empty( $data['remote_version'] ) ) {
			$update_item = (object) array(
				'id'            => 'vmsb-github-updater',
				'slug'          => $slug,
				'plugin'        => $file,
				'new_version'   => $data['remote_version'],
				'url'           => 'https://github.com/' . self::repo(),
				'package'       => ! empty( $data['package_url'] ) ? $data['package_url'] : '',
				'requires'      => '6.2',
				'requires_php'  => '8.0',
				'icons'         => array(),
				'banners'       => array(),
			);
			$transient->response[ $file ] = $update_item;
			unset( $transient->no_update[ $file ] );
		} else {
			$no_update_item = (object) array(
				'id'            => 'vmsb-github-updater',
				'slug'          => $slug,
				'plugin'        => $file,
				'new_version'   => defined( 'VMSB_VERSION' ) ? VMSB_VERSION : '1.6.0',
				'url'           => 'https://github.com/' . self::repo(),
				'package'       => '',
				'requires'      => '6.2',
				'requires_php'  => '8.0',
			);
			$transient->no_update[ $file ] = $no_update_item;
			unset( $transient->response[ $file ] );
		}

		return $transient;
	}

	/**
	 * Hook into plugins_api to provide plugin details modal.
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$file = plugin_basename( VMSB_FILE );
		$slug = 'vm-seo-brain';

		if ( ( isset( $args->slug ) && ( $args->slug === $slug || $args->slug === $file ) ) ||
		     ( isset( $args->plugin ) && $args->plugin === $file ) ) {

			$data = self::check( false );
			$installed = defined( 'VMSB_VERSION' ) ? VMSB_VERSION : '1.6.0';
			$version = ! empty( $data['remote_version'] ) ? $data['remote_version'] : $installed;

			$changelog = ! empty( $data['release_notes'] )
				? nl2br( esc_html( $data['release_notes'] ) )
				: '<p>Direct tracking updates from GitHub repository <code>' . esc_html( self::repo() ) . '</code>.</p>';

			$res = (object) array(
				'name'          => 'VM SEO Brain',
				'slug'          => $slug,
				'plugin_name'   => 'VM SEO Brain',
				'version'       => $version,
				'author'        => '<a href="https://vmstudio.digital">VM Studio Creatives</a>',
				'author_profile'=> 'https://vmstudio.digital',
				'homepage'      => 'https://github.com/' . self::repo(),
				'download_link' => ! empty( $data['package_url'] ) ? $data['package_url'] : '',
				'requires'      => '6.2',
				'tested'        => get_bloginfo( 'version' ),
				'requires_php'  => '8.0',
				'last_updated'  => ! empty( $data['published_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data['published_at'] ) ) : gmdate( 'Y-m-d H:i:s' ),
				'sections'      => array(
					'description' => 'Autonomous SEO brain for WordPress. Understands the business, researches keywords, plans topics, fixes technical + on-page errors, rebuilds silo structure, optimises taxonomies, generates images, and ships published posts through AI Puffer.',
					'changelog'   => $changelog,
					'installation'=> '<p>Updates are synchronized directly from GitHub repository <code>' . esc_html( self::repo() ) . '</code>.</p>',
				),
				'banners'       => array(),
			);

			return $res;
		}

		return $result;
	}

	/**
	 * Hook into upgrader_source_selection.
	 *
	 * When WordPress unpacks a GitHub zip archive, GitHub gives the folder a name
	 * like "vmai-plugins-vm-seo-brain-a1b2c3d" or "vm-seo-brain-master".
	 * This filter renames the extracted directory to the exact plugin folder slug "vm-seo-brain".
	 */
	public static function filter_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $source ) || ! is_string( $source ) ) {
			return $source;
		}

		$source_dir = trailingslashit( $source );

		// Check if this package is VM SEO Brain
		if ( ! file_exists( $source_dir . 'vm-seo-brain.php' ) ) {
			return $source;
		}

		$expected_slug = dirname( plugin_basename( VMSB_FILE ) );
		if ( empty( $expected_slug ) || '.' === $expected_slug ) {
			$expected_slug = 'vm-seo-brain';
		}

		$current_folder_name = basename( untrailingslashit( $source ) );
		if ( $current_folder_name === $expected_slug ) {
			return $source;
		}

		$new_source = trailingslashit( $remote_source ) . $expected_slug;

		if ( $wp_filesystem ) {
			if ( $wp_filesystem->exists( $new_source ) ) {
				$wp_filesystem->delete( $new_source, true );
			}
			$renamed = $wp_filesystem->move( $source, $new_source );
		} else {
			if ( file_exists( $new_source ) ) {
				self::rm_rf( $new_source );
			}
			$renamed = @rename( $source, $new_source );
		}

		if ( $renamed ) {
			return trailingslashit( $new_source );
		}

		return $source;
	}

	/**
	 * Clean up transients on upgrader completion.
	 */
	public static function on_upgrader_process_complete( $upgrader, $options ) {
		if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( self::CHECK_TRANSIENT );
			if ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset();
			}
		}
	}

	/**
	 * Action links in plugins.php.
	 */
	public static function filter_plugin_action_links( $links ) {
		$update_link = sprintf(
			'<a href="%s" style="font-weight:600; color:#d4af37;">%s</a>',
			esc_url( admin_url( 'admin.php?page=vmsb-settings&tab=updates' ) ),
			__( 'GitHub Updates', 'vm-seo-brain' )
		);
		array_unshift( $links, $update_link );
		return $links;
	}

	/**
	 * Row meta links in plugins.php.
	 */
	public static function filter_plugin_row_meta( $links, $file ) {
		if ( $file === plugin_basename( VMSB_FILE ) ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s ↗</a>',
				esc_url( 'https://github.com/' . self::repo() ),
				__( 'GitHub Repo', 'vm-seo-brain' )
			);
		}
		return $links;
	}

	/* ------------------------------------------------- In-Place Direct Updater */

	/**
	 * Direct 1-click in-place updater.
	 *
	 * Downloads the latest archive from GitHub, extracts it to temporary storage,
	 * verifies integrity and version, and safely copies files over VMSB_DIR
	 * without touching protected files or DB tables.
	 *
	 * @return array|WP_Error
	 */
	public static function perform_direct_update() {
		if ( ! current_user_can( VMSB_CAP ) ) {
			return new WP_Error( 'vmsb_forbidden', 'Insufficient permissions to perform plugin updates.' );
		}

		// Ensure fresh data
		$check = self::check( true );
		if ( empty( $check['ok'] ) || empty( $check['package_url'] ) ) {
			return new WP_Error( 'vmsb_check_failed', ! empty( $check['message'] ) ? $check['message'] : 'Could not locate a valid release or branch on GitHub.' );
		}

		$old_version = defined( 'VMSB_VERSION' ) ? VMSB_VERSION : 'unknown';
		$new_version = $check['remote_version'];
		$download_url = $check['package_url'];

		// Step 1: Download zip archive
		$zip_file = self::download_archive( $download_url );
		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		// Step 2: Prepare temp working directory
		$temp_dir = trailingslashit( get_temp_dir() ) . 'vmsb-direct-update-' . time() . '-' . wp_generate_password( 6, false );
		self::rm_rf( $temp_dir );
		if ( ! self::mkdirp( $temp_dir ) ) {
			@unlink( $zip_file );
			return new WP_Error( 'vmsb_dir_create_failed', 'Could not create temporary working directory for update.' );
		}

		// Step 3: Extract archive
		$extracted = self::extract_archive( $zip_file, $temp_dir );
		@unlink( $zip_file );

		if ( ! $extracted ) {
			self::rm_rf( $temp_dir );
			return new WP_Error( 'vmsb_extract_failed', 'Could not extract the downloaded GitHub archive.' );
		}

		// Step 4: Locate plugin root folder
		$plugin_root = self::find_plugin_root( $temp_dir );
		if ( ! $plugin_root || ! is_file( $plugin_root . '/vm-seo-brain.php' ) ) {
			self::rm_rf( $temp_dir );
			return new WP_Error( 'vmsb_invalid_package', 'The downloaded archive did not contain a valid vm-seo-brain.php plugin file.' );
		}

		// Step 5: Verify version header in new file
		$extracted_version = self::version_from_file( $plugin_root . '/vm-seo-brain.php' );
		if ( $extracted_version && $old_version && version_compare( $extracted_version, $old_version, '<' ) ) {
			self::rm_rf( $temp_dir );
			return new WP_Error( 'vmsb_downgrade_refused', sprintf( 'Refusing to downgrade: remote is %s, installed is %s.', $extracted_version, $old_version ) );
		}

		// Step 6: Copy files into VMSB_DIR using safe recursive copy
		$copied = self::copy_tree( $plugin_root, VMSB_DIR );
		self::rm_rf( $temp_dir );

		if ( ! $copied ) {
			if ( class_exists( 'VMSB_Logger' ) ) {
				( new VMSB_Logger() )->warn( 'updater', 'Direct update finished with file copy warnings for ' . VMSB_DIR );
			}
			return new WP_Error( 'vmsb_copy_error', 'Some files could not be copied. Please check directory write permissions.' );
		}

		// Step 7: Clear transients and OPcache
		delete_transient( self::CHECK_TRANSIENT );
		delete_site_transient( 'update_plugins' );
		set_transient( self::APPLIED_TRANSIENT, array(
			'at'          => time(),
			'old_version' => $old_version,
			'version'     => $extracted_version ? $extracted_version : $new_version,
		), 7 * DAY_IN_SECONDS );

		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			@wp_cache_flush();
		}

		// Step 8: Log outcome
		if ( class_exists( 'VMSB_Logger' ) ) {
			( new VMSB_Logger() )->info( 'updater', sprintf( 'Successfully updated from GitHub (%s -> %s).', $old_version, $extracted_version ?: $new_version ) );
		}

		return array(
			'success'     => true,
			'ok'          => true,
			'old_version' => $old_version,
			'new_version' => $extracted_version ? $extracted_version : $new_version,
			'message'     => sprintf( 'Successfully updated VM SEO Brain to version %s.', $extracted_version ? $extracted_version : $new_version ),
		);
	}

	/* ------------------------------------------------------ Filesystem Helpers */

	/**
	 * Download archive with authentication headers.
	 */
	private static function download_archive( $url ) {
		$tmp_file = trailingslashit( get_temp_dir() ) . 'vmsb-pkg-' . time() . '-' . wp_generate_password( 6, false ) . '.zip';

		$headers = self::request_headers();
		$headers['Accept'] = 'application/octet-stream, application/zip, */*';

		$res = wp_remote_get(
			$url,
			array(
				'timeout'  => 180,
				'headers'  => $headers,
				'stream'   => true,
				'filename' => $tmp_file,
			)
		);

		if ( is_wp_error( $res ) ) {
			@unlink( $tmp_file );
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code && 302 !== $code ) {
			@unlink( $tmp_file );
			return new WP_Error( 'vmsb_download_error', 'GitHub download failed with HTTP ' . $code . '.' );
		}

		if ( ! file_exists( $tmp_file ) || filesize( $tmp_file ) < 100 ) {
			@unlink( $tmp_file );
			return new WP_Error( 'vmsb_download_empty', 'Downloaded archive was empty or corrupt.' );
		}

		return $tmp_file;
	}

	/**
	 * Extract zip archive using WP_Filesystem, ZipArchive, or system extractors.
	 */
	private static function extract_archive( $zip_file, $dest_dir ) {
		// Method 1: WordPress unzip_file()
		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$unzipped = unzip_file( $zip_file, $dest_dir );
		if ( ! is_wp_error( $unzipped ) && self::has_contents( $dest_dir ) ) {
			return true;
		}

		// Method 2: ZipArchive class
		if ( class_exists( 'ZipArchive' ) ) {
			try {
				$za = new \ZipArchive();
				if ( true === $za->open( $zip_file ) ) {
					$za->extractTo( $dest_dir );
					$za->close();
					if ( self::has_contents( $dest_dir ) ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				// Continue to fallback
			}
		}

		// Method 3: PowerShell on Windows
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$ps = "Expand-Archive -LiteralPath '" . str_replace( "'", "''", $zip_file ) . "' -DestinationPath '" . str_replace( "'", "''", $dest_dir ) . "' -Force";
			@shell_exec( 'powershell -NoProfile -Command ' . escapeshellarg( $ps ) );
			if ( self::has_contents( $dest_dir ) ) {
				return true;
			}
		}

		// Method 4: tar or python
		foreach ( array(
			array( 'tar', array( '-xf', $zip_file, '-C', $dest_dir ) ),
			array( 'python3', array( '-m', 'zipfile', '-e', $zip_file, $dest_dir ) ),
			array( 'python', array( '-m', 'zipfile', '-e', $zip_file, $dest_dir ) ),
		) as $cli ) {
			@shell_exec( $cli[0] . ' ' . implode( ' ', array_map( 'escapeshellarg', $cli[1] ) ) );
			if ( self::has_contents( $dest_dir ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_contents( $dir ) {
		$items = self::list_dir( $dir );
		return ! empty( $items );
	}

	private static function find_plugin_root( $temp_dir ) {
		if ( is_file( $temp_dir . '/vm-seo-brain.php' ) ) {
			return $temp_dir;
		}
		foreach ( self::list_dir( $temp_dir ) as $name ) {
			$subdir = $temp_dir . '/' . $name;
			if ( is_dir( $subdir ) && is_file( $subdir . '/vm-seo-brain.php' ) ) {
				return $subdir;
			}
		}
		return null;
	}

	/**
	 * Copy tree recursively while preserving local/protected assets.
	 */
	private static function copy_tree( $src, $dst ) {
		$ok = true;
		self::mkdirp( $dst );
		foreach ( self::list_dir( $src ) as $name ) {
			$from = $src . '/' . $name;
			$to   = $dst . '/' . $name;
			if ( is_dir( $from ) ) {
				if ( self::is_protected( $name ) ) {
					continue;
				}
				$ok = self::copy_tree( $from, $to ) && $ok;
			} elseif ( is_file( $from ) ) {
				if ( self::is_protected( $name ) ) {
					continue;
				}
				$ok = self::copy_file( $from, $to ) && $ok;
			}
		}
		return $ok;
	}

	private static function copy_file( $from, $to ) {
		self::mkdirp( dirname( $to ) );
		$bytes = @file_get_contents( $from );
		if ( false === $bytes ) {
			return false;
		}
		return false !== @file_put_contents( $to, $bytes );
	}

	/**
	 * Files/folders that must NEVER be overwritten during an update.
	 */
	private static function is_protected( $rel ) {
		$rel = strtolower( ltrim( $rel, '/' ) );
		if ( '' === $rel || '.' === $rel || '..' === $rel ) {
			return true;
		}
		foreach ( array( '.claude', '.idea', '.git', '.vscode' ) as $prefix ) {
			if ( $rel === $prefix || 0 === strpos( $rel, $prefix . '/' ) ) {
				return true;
			}
		}
		if ( self::ends_with( $rel, '.zip' ) || self::ends_with( $rel, '.tar.gz' ) ) {
			return true;
		}
		if ( 0 === strpos( $rel, 'languages/' ) && ( self::ends_with( $rel, '.mo' ) || self::ends_with( $rel, '.po' ) ) ) {
			return true;
		}
		return false;
	}

	private static function ends_with( $s, $suffix ) {
		$ls = strlen( $s );
		$ll = strlen( $suffix );
		return $ll <= $ls && substr( $s, $ls - $ll ) === $suffix;
	}

	private static function list_dir( $dir ) {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$out[] = $name;
		}
		ksort( $out );
		return $out;
	}

	private static function mkdirp( $dir ) {
		if ( is_dir( $dir ) ) {
			return true;
		}
		$parent = dirname( $dir );
		if ( $parent !== $dir && ! self::mkdirp( $parent ) ) {
			return false;
		}
		return @mkdir( $dir, 0755, true );
	}

	private static function rm_rf( $path ) {
		if ( is_dir( $path ) ) {
			foreach ( self::list_dir( $path ) as $name ) {
				self::rm_rf( $path . '/' . $name );
			}
			@rmdir( $path );
			return true;
		}
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
		return true;
	}

	public static function version_from_file( $path ) {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$head = @file_get_contents( $path, false, null, 0, 8192 );
		if ( ! $head ) {
			return null;
		}
		if ( preg_match( '/\*\s*Version:\s*([0-9][0-9a-zA-Z.\-]*)/i', (string) $head, $m ) ) {
			return $m[1];
		}
		return null;
	}
}
