<?php
defined( 'ABSPATH' ) || exit;

/**
 * Service container + wiring. One place to see everything the plugin does.
 *
 * The map below used to be built with `new` on every entry, at plugins_loaded,
 * on every request. The constructors cascade - VMSB_Content builds six more
 * engines, VMSB_Brain and VMSB_Keywords each build a router and a logger, the
 * router builds another logger - so an anonymous visitor hitting a cached blog
 * post was autoloading roughly fifty class files and constructing well over a
 * hundred objects before WordPress had decided what page it was on. All of it
 * to support one wp_head callback that prints stored JSON-LD.
 *
 * Now the map holds class names and get() instantiates on first use, so a
 * front-end request pays for exactly what it touches: nothing.
 */
final class VMSB_Core {

	/**
	 * @var array<string,string> service key => class name
	 */
	private static $map = array(
		'settings'      => 'VMSB_Settings',
		'log'           => 'VMSB_Logger',
		'ai'            => 'VMSB_AI_Router',
		'images'        => 'VMSB_Image_Engine',
		'google'        => 'VMSB_Google',
		'rankmath'      => 'VMSB_RankMath',
		'brain'         => 'VMSB_Brain',
		'keywords'      => 'VMSB_Keywords',
		'silo'          => 'VMSB_Silo',
		'links'         => 'VMSB_Internal_Link_Autopilot',
		'taxonomy'      => 'VMSB_Taxonomy',
		'fixer'         => 'VMSB_Fixer',
		'content'       => 'VMSB_Content',
		'growth'        => 'VMSB_Growth',
		'competitor'    => 'VMSB_Competitor',
		'backlinks'     => 'VMSB_Backlinks',
		'aeo'           => 'VMSB_AEO',
		'entity'        => 'VMSB_Entity',
		'programmatic'  => 'VMSB_Programmatic',
		'roi'           => 'VMSB_ROI',
		'ctr'           => 'VMSB_CTR',
		'news'          => 'VMSB_News',
		'forecaster'    => 'VMSB_Forecaster',
		'schema'        => 'VMSB_Schema',
		'global'        => 'VMSB_Global_Expander',
		'external'      => 'VMSB_External_Data',
		'integrations'  => 'VMSB_Integrations',
		'health'        => 'VMSB_Health',
		'performance'   => 'VMSB_Performance',
		'market'        => 'VMSB_Market',
		'strategist'    => 'VMSB_Strategist',
		'niche'         => 'VMSB_Niche_Planner',
		'heatmap'       => 'VMSB_Heatmap',
		'reporting'     => 'VMSB_Reporting',
		'tasks'         => 'VMSB_Task_Runner',
		'indexing'      => 'VMSB_Indexing',
		'commander'     => 'VMSB_Commander',
		'thief'         => 'VMSB_Thief',
		'agents'        => 'VMSB_Agents',
		'graph'         => 'VMSB_Graph',
		'aipuffer'      => 'VMSB_AIPuffer',
		'webhooks'      => 'VMSB_Webhooks',
		'growth_engine' => 'VMSB_Growth_Engine',
		'citability'    => 'VMSB_Citability',
		'llms_txt'      => 'VMSB_LLMS_Txt',
	);

	/** @var array<string,object> Resolved instances. */
	private $services = array();

	public function __construct() {
		VMSB_Install::maybe_upgrade();

		// Hooks only. Anything that registers a WordPress hook in its
		// constructor has to be built eagerly - a listener that is not
		// attached before the action fires never runs - so these four stay,
		// and everything else in the map waits until something asks for it.
		new VMSB_Scheduler();
		new VMSB_REST();
		new VMSB_LLMS_Txt();
		VMSB_Link_Index::boot();

		foreach ( array( 'indexing', 'webhooks', 'backlinks' ) as $eager ) {
			$this->get( $eager );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			new VMSB_CLI();
		}

		if ( is_admin() ) {
			new VMSB_Admin();
			add_action( 'admin_init', array( $this, 'heartbeat' ) );
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_head', array( 'VMSB_Schema', 'print_schema' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'vm-seo-brain', false, dirname( plugin_basename( VMSB_FILE ) ) . '/languages' );
	}

	/**
	 * Heartbeat: Process a small batch of background tasks while the admin
	 * is active, reducing wait time for crons.
	 */
	public function heartbeat() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Only on our own pages to avoid slowing down the whole WP admin.
		$page = isset( $_GET['page'] ) ? $_GET['page'] : '';
		if ( 0 !== strpos( $page, 'vmsb' ) ) {
			return;
		}

		$last = (int) get_transient( 'vmsb_heartbeat_tick' );
		if ( $last && ( time() - $last ) < 300 ) {
			return; // Max once every 5 minutes
		}

		if ( class_exists( 'VMSB_Task_Runner' ) && VMSB_Task_Runner::pending_count() > 0 ) {
			set_transient( 'vmsb_heartbeat_tick', time(), 3600 );
			VMSB_Task_Runner::process( 1 ); // Just one task per heartbeat to keep it snappy
		}
	}

	/**
	 * @return object|null
	 */
	public function get( $key ) {
		if ( isset( $this->services[ $key ] ) ) {
			return $this->services[ $key ];
		}
		if ( ! isset( self::$map[ $key ] ) ) {
			return null;
		}

		$class = self::$map[ $key ];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$this->services[ $key ] = new $class();
		return $this->services[ $key ];
	}

	public function __get( $key ) {
		return $this->get( $key );
	}

	/**
	 * Views use isset( $core->silo ) in a few places; without this the magic
	 * getter is never consulted and the check is always false.
	 */
	public function __isset( $key ) {
		return isset( self::$map[ $key ] );
	}
}
