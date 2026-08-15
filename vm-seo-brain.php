<?php
/**
 * Plugin Name:       VM SEO Brain
 * Plugin URI:        https://vmstudio.digital/vm-seo-brain
 * Description:       Autonomous SEO brain for WordPress. Understands the business, researches keywords, plans topics, fixes technical + on-page errors, rebuilds silo structure, optimises taxonomies, generates images, and ships published posts through AI Puffer.
 * Version:           1.5.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            VM Studio Creatives
 * Author URI:        https://vmstudio.digital
 * License:           GPL-2.0-or-later
 * Text Domain:       vm-seo-brain
 */

defined( 'ABSPATH' ) || exit;

define( 'VMSB_VERSION', '1.5.0' );
define( 'VMSB_FILE', __FILE__ );
define( 'VMSB_DIR', plugin_dir_path( __FILE__ ) );
define( 'VMSB_URL', plugin_dir_url( __FILE__ ) );
define( 'VMSB_CAP', 'manage_options' );

/**
 * Autoloader for VMSB_* classes.
 * Optimized with runtime path caching for world-class VPS performance.
 */
spl_autoload_register(
	static function ( $class ) {
		static $path_cache = array();
		if ( 0 !== strpos( $class, 'VMSB_' ) ) {
			return;
		}

		if ( isset( $path_cache[ $class ] ) ) {
			require_once $path_cache[ $class ];
			return;
		}

		$slug = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		foreach ( array( 'includes/', 'admin/' ) as $dir ) {
			$path = VMSB_DIR . $dir . $slug;
			if ( file_exists( $path ) ) {
				$path_cache[ $class ] = $path;
				require_once $path;
				return;
			}
		}
	}
);

/**
 * Schema. Everything the brain remembers lives here, not in options bloat.
 */
final class VMSB_Install {

	const DB_VERSION = '1.11.1'; // 1.11.1: Fixed dbDelta syntax for tasks table

	public static function activate() {
		self::tables();
		update_option( 'vmsb_db_version', self::DB_VERSION );
		if ( ! get_option( 'vmsb_installed_at' ) ) {
			update_option( 'vmsb_installed_at', time() );
		}
		VMSB_Scheduler::schedule();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		VMSB_Scheduler::unschedule();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'vmsb_db_version' ) !== self::DB_VERSION ) {
			self::tables();
			update_option( 'vmsb_db_version', self::DB_VERSION );
		}
	}

	private static function tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'vmsb_';

		$sql = array();

		// Long-term memory for the brain: facts, embeddings-lite, decisions.
		$sql[] = "CREATE TABLE {$p}memory (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket VARCHAR(64) NOT NULL,
			mkey VARCHAR(191) NOT NULL,
			mvalue LONGTEXT NULL,
			confidence FLOAT NOT NULL DEFAULT 0.5,
			source VARCHAR(64) NOT NULL DEFAULT 'brain',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY bucket_key (bucket, mkey),
			KEY bucket_confidence (bucket, confidence)
		) {$charset};";

		// Keyword universe.
		$sql[] = "CREATE TABLE {$p}keywords (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(191) NOT NULL,
			cluster VARCHAR(191) NULL,
			intent VARCHAR(32) NULL,
			funnel VARCHAR(32) NULL,
			volume INT NULL,
			difficulty INT NULL,
			position FLOAT NULL,
			impressions INT NOT NULL DEFAULT 0,
			clicks INT NOT NULL DEFAULT 0,
			ctr FLOAT NOT NULL DEFAULT 0,
			opportunity FLOAT NOT NULL DEFAULT 0,
			serp_features TEXT NULL,
			post_id BIGINT UNSIGNED NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'new',
			source VARCHAR(32) NOT NULL DEFAULT 'gsc',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY keyword (keyword),
			KEY cluster (cluster),
			KEY opportunity (opportunity),
		KEY status (status),
		KEY impressions (impressions),
		KEY position (position),
		KEY source (source),
		KEY post_status (post_id, status)
		) {$charset};";

		// Content plan / editorial queue mirrored to Google Sheets.
		$sql[] = "CREATE TABLE {$p}plan (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			row_uid VARCHAR(64) NOT NULL,
			title TEXT NOT NULL,
			primary_keyword VARCHAR(191) NOT NULL,
			secondary_keywords LONGTEXT NULL,
			cluster VARCHAR(191) NULL,
			intent VARCHAR(32) NULL,
			content_language VARCHAR(10) NULL,
			agent_task VARCHAR(64) NULL,
			editor_note TEXT NULL,
			is_pillar TINYINT(1) NOT NULL DEFAULT 0,
			content_type VARCHAR(32) NOT NULL DEFAULT 'blog',
			brief LONGTEXT NULL,
			internal_links LONGTEXT NULL,
			target_words INT NOT NULL DEFAULT 1600,
			priority FLOAT NOT NULL DEFAULT 0,
			scheduled_for DATETIME NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'planned',
			post_id BIGINT UNSIGNED NULL,
			sheet_row INT NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY row_uid (row_uid),
			KEY status (status),
			KEY priority (priority)
		) {$charset};";

		// Audit issues found + fix ledger (every autonomous change is reversible).
		$sql[] = "CREATE TABLE {$p}issues (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(32) NOT NULL DEFAULT 'post',
			object_id BIGINT UNSIGNED NULL,
			rule VARCHAR(64) NOT NULL,
			severity VARCHAR(16) NOT NULL DEFAULT 'medium',
			impact FLOAT NOT NULL DEFAULT 0,
			confidence FLOAT NOT NULL DEFAULT 0,
			detail LONGTEXT NULL,
			suggested LONGTEXT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'open',
			fixed_by VARCHAR(32) NULL,
			revert_payload LONGTEXT NULL,
			detected_at DATETIME NOT NULL,
			fixed_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY object (object_type, object_id),
			KEY rule (rule),
			KEY status (status),
			KEY rule_status (rule, status)
		) {$charset};";

		// Daily metric snapshots powering the growth model.
		$sql[] = "CREATE TABLE {$p}metrics (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_date DATE NOT NULL,
			source VARCHAR(24) NOT NULL,
			sessions INT NOT NULL DEFAULT 0,
			users INT NOT NULL DEFAULT 0,
			clicks INT NOT NULL DEFAULT 0,
			impressions INT NOT NULL DEFAULT 0,
			indexed_pages INT NOT NULL DEFAULT 0,
			avg_position FLOAT NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY snap (snapshot_date, source)
		) {$charset};";

		// Operational log.
		$sql[] = "CREATE TABLE {$p}log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(16) NOT NULL DEFAULT 'info',
			channel VARCHAR(48) NOT NULL DEFAULT 'core',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY channel (channel),
			KEY created_at (created_at)
		) {$charset};";

		// Centralized Action Log (X-Standard: Rollback & Explainability).
		$sql[] = "CREATE TABLE {$p}actions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id BIGINT UNSIGNED NULL,
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			action_type VARCHAR(64) NOT NULL,
			before_value LONGTEXT NULL,
			after_value LONGTEXT NULL,
			reason TEXT NULL,
			model VARCHAR(64) NULL,
			tokens_in INT DEFAULT 0,
			tokens_out INT DEFAULT 0,
			cost FLOAT DEFAULT 0,
			rollback_status VARCHAR(32) DEFAULT 'none',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY task_id (task_id),
			KEY object (object_type, object_id),
			KEY action_type (action_type)
		) {$charset};";

		// Semantic vectors: every post/cluster embedded for meaning-based search.
		$sql[] = "CREATE TABLE {$p}vectors (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(30) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(255) NULL,
			content_hash CHAR(32) NOT NULL,
			model VARCHAR(80) NOT NULL,
			dims SMALLINT UNSIGNED NOT NULL,
			vector LONGBLOB NOT NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY object (object_type, object_id),
			KEY dims (dims),
			KEY updated_at (updated_at)
		) {$charset};";

		// Outcome ledger: every autonomous action's hypothesis + measured result.
		$sql[] = "CREATE TABLE {$p}outcomes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			module VARCHAR(60) NOT NULL,
			action VARCHAR(60) NOT NULL,
			object_id BIGINT UNSIGNED NULL,
			object_label VARCHAR(255) NULL,
			hypothesis TEXT NULL,
			baseline LONGTEXT NULL,
			result_short LONGTEXT NULL,
			result_long LONGTEXT NULL,
			delta_short FLOAT NULL,
			delta_long FLOAT NULL,
			verdict VARCHAR(20) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			measure_short_at DATETIME NULL,
			measure_long_at DATETIME NULL,
			measured_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY module (module),
			KEY status (status),
			KEY due_short (status, measure_short_at),
			KEY due_long (status, measure_long_at)
		) {$charset};";

		// Competitor intelligence: tracked domains + point-in-time snapshots.
		$sql[] = "CREATE TABLE {$p}competitors (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			domain VARCHAR(191) NOT NULL,
			label VARCHAR(191) NULL,
			shared_keywords INT NOT NULL DEFAULT 0,
			overlap_score FLOAT NOT NULL DEFAULT 0,
			their_gain INT NOT NULL DEFAULT 0,
			our_gain INT NOT NULL DEFAULT 0,
			last_snapshot LONGTEXT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'active',
			checked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY domain (domain)
		) {$charset};";

		// Backlink pipeline: prospects found, pitched, and negotiated.
		$sql[] = "CREATE TABLE {$p}backlinks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			domain VARCHAR(191) NOT NULL,
			source_url TEXT NULL,
			contact_email VARCHAR(191) NULL,
			relevance FLOAT NOT NULL DEFAULT 0,
			authority_hint VARCHAR(24) NULL,
			target_post_id BIGINT UNSIGNED NULL,
			pitch LONGTEXT NULL,
			thread LONGTEXT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'prospect',
			last_contact_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY domain (domain)
		) {$charset};";

		// CTR experiments: title/meta variants tested against real GSC clicks.
		$sql[] = "CREATE TABLE {$p}experiments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			field VARCHAR(24) NOT NULL DEFAULT 'seo_title',
			original_value TEXT NULL,
			variant_value TEXT NULL,
			baseline_ctr FLOAT NOT NULL DEFAULT 0,
			result_ctr FLOAT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			started_at DATETIME NOT NULL,
			concludes_at DATETIME NULL,
			concluded_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY status (status)
		) {$charset};";

		// Rank history: point-in-time position snapshots per tracked keyword, so
		// "did this fix work" can be answered from our own data, not just GSC's
		// rolling window.
		$sql[] = "CREATE TABLE {$p}rank_history (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(191) NOT NULL,
			url TEXT NULL,
			position FLOAT NOT NULL,
			impressions INT NOT NULL DEFAULT 0,
			clicks INT NOT NULL DEFAULT 0,
			snapshot_date DATE NOT NULL,
			PRIMARY KEY (id),
			KEY keyword_date (keyword(100), snapshot_date)
		) {$charset};";

		// Task queue: heavier daily intelligence work is queued here and drained
		// a few at a time on the hourly cron, rather than run synchronously inside
		// one daily request where it risks a PHP timeout on shared hosting.
		$sql[] = "CREATE TABLE {$p}tasks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			task_type VARCHAR(60) NOT NULL,
			payload LONGTEXT NULL,
			score FLOAT NOT NULL DEFAULT 0,
			reason VARCHAR(255) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			attempts TINYINT UNSIGNED DEFAULT 0,
			max_attempts TINYINT UNSIGNED DEFAULT 3,
			retry_after DATETIME NULL,
			last_error TEXT NULL,
			timeline LONGTEXT NULL,
			result LONGTEXT NULL,
			queued_at DATETIME NOT NULL,
			ran_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status_score (status, score),
			KEY retry (status, retry_after)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}graph (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_type VARCHAR(50) NOT NULL,
			subject_id BIGINT UNSIGNED NOT NULL,
			predicate VARCHAR(100) NOT NULL,
			object_type VARCHAR(50) NOT NULL,
			object_id VARCHAR(250) NOT NULL,
			weight FLOAT DEFAULT 1.0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY triple (subject_type, subject_id, predicate, object_type, object_id)
		) {$charset};";

		// Competitor velocity: tracking how fast they publish vs us.
		$sql[] = "CREATE TABLE {$p}competitor_velocity (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			domain VARCHAR(191) NOT NULL,
			post_count INT NOT NULL DEFAULT 0,
			snapshot_date DATE NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY domain_date (domain, snapshot_date)
		) {$charset};";

		// AI Usage: token consumption and cost tracking.
		$sql[] = "CREATE TABLE {$p}usage (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider VARCHAR(32) NOT NULL,
			model VARCHAR(64) NOT NULL,
			persona VARCHAR(32) NULL,
			tokens_in INT UNSIGNED DEFAULT 0,
			tokens_out INT UNSIGNED DEFAULT 0,
			cost FLOAT DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY provider (provider),
			KEY created_at (created_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}

register_activation_hook( __FILE__, array( 'VMSB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VMSB_Install', 'deactivate' ) );

/**
 * Boot.
 */
function vmsb() {
	static $core = null;
	if ( null === $core ) {
		$core = new VMSB_Core();
	}
	return $core;
}

add_action( 'plugins_loaded', 'vmsb', 5 );
