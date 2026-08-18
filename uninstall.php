<?php
/**
 * Runs only on delete, never on deactivate. Data is kept unless the operator
 * explicitly opted in to a clean removal.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'vmsb_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

foreach ( array( 'memory', 'keywords', 'plan', 'issues', 'metrics', 'log', 'actions', 'vectors', 'outcomes', 'competitors', 'backlinks', 'experiments', 'rank_history', 'tasks', 'graph', 'links', 'competitor_velocity', 'usage' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vmsb_{$table}" );
}

foreach ( array( 'vmsb_settings', 'vmsb_license', 'vmsb_db_version', 'vmsb_installed_at', 'vmsb_last_scan', 'vmsb_author_id', 'vmsb_robots_sitemap', 'vmsb_delete_data_on_uninstall', 'vmsb_conversion_forecast', 'vmsb_traffic_forecast', 'vmsb_market_assessment', 'vmsb_health_last_check', 'vmsb_last_daily_run', 'vmsb_last_market_assessment', 'vmsb_link_index_built' ) as $option ) {
	delete_option( $option );
}

// Post meta this plugin writes. Left behind, these keep every post row
// carrying dead rows after the plugin itself is gone.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_vmsb\\_%'" );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vmsb_ai_calls_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vmsb_%' OR option_name LIKE '_transient_timeout_vmsb_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vmsb_pseo_generated_%'" );
