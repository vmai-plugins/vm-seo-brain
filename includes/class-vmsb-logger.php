<?php
defined( 'ABSPATH' ) || exit;

class VMSB_Logger {

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_log';
	}

	public function write( $level, $channel, $message, $context = array() ) {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'level'      => substr( (string) $level, 0, 16 ),
				'channel'    => substr( (string) $channel, 0, 48 ),
				'message'    => (string) $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function info( $channel, $message, $context = array() )  { $this->write( 'info', $channel, $message, $context ); }
	public function warn( $channel, $message, $context = array() )  { $this->write( 'warning', $channel, $message, $context ); }
	public function error( $channel, $message, $context = array() ) { $this->write( 'error', $channel, $message, $context ); }

	public function recent( $limit = 100, $channel = '' ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		if ( $channel ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE channel = %s ORDER BY id DESC LIMIT %d", $channel, $limit ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	public function prune( $days = 30 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", (int) $days ) );
	}
}
