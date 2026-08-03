<?php
defined( 'ABSPATH' ) || exit;

/**
 * Service container + wiring. One place to see everything the plugin does.
 */
final class VMSB_Core {

	/** @var array<string,object> */
	private $services = array();

	public function __construct() {
		VMSB_Install::maybe_upgrade();

		$this->services = array(
			'settings'    => new VMSB_Settings(),
			'log'         => new VMSB_Logger(),
			'ai'          => new VMSB_AI_Router(),
			'images'      => new VMSB_Image_Engine(),
			'google'      => new VMSB_Google(),
			'rankmath'    => new VMSB_RankMath(),
			'brain'       => new VMSB_Brain(),
			'keywords'    => new VMSB_Keywords(),
			'silo'        => new VMSB_Silo(),
			'taxonomy'    => new VMSB_Taxonomy(),
			'fixer'       => new VMSB_Fixer(),
			'content'     => new VMSB_Content(),
			'growth'      => new VMSB_Growth(),
			'competitor'  => new VMSB_Competitor(),
			'backlinks'   => new VMSB_Backlinks(),
			'aeo'         => new VMSB_AEO(),
			'entity'      => new VMSB_Entity(),
			'programmatic'=> new VMSB_Programmatic(),
			'roi'         => new VMSB_ROI(),
			'ctr'         => new VMSB_CTR(),
			'news'        => new VMSB_News(),
			'forecaster'  => new VMSB_Forecaster(),
			'schema'      => new VMSB_Schema(),
			'global'      => new VMSB_Global_Expander(),
			'external'    => new VMSB_External_Data(),
			'integrations'=> new VMSB_Integrations(),
			'health'      => new VMSB_Health(),
			'performance' => new VMSB_Performance(),
			'market'      => new VMSB_Market(),
			'strategist'  => new VMSB_Strategist(),
			'niche'       => new VMSB_Niche_Planner(),
			'heatmap'     => new VMSB_Heatmap(),
			'reporting'   => new VMSB_Reporting(),
			'tasks'       => new VMSB_Task_Runner(),
			'indexing'    => new VMSB_Indexing(),
			'commander'   => new VMSB_Commander(),
			'thief'       => new VMSB_Thief(),
			'agents'      => new VMSB_Agents(),
			'graph'       => new VMSB_Graph(),
			'aipuffer'    => new VMSB_AIPuffer(),
		);

		new VMSB_Scheduler();
		new VMSB_REST();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			new VMSB_CLI();
		}

		if ( is_admin() ) {
			new VMSB_Admin();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_head', array( 'VMSB_Schema', 'print_schema' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'vm-seo-brain', false, dirname( plugin_basename( VMSB_FILE ) ) . '/languages' );
	}

	/**
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->services[ $key ] ) ? $this->services[ $key ] : null;
	}

	public function __get( $key ) {
		return $this->get( $key );
	}
}
