<?php
defined( 'ABSPATH' ) || exit;

/**
 * Entity schema beyond Article and FAQ.
 *
 * VMSB only ever emitted FAQPage and Article. For a travel site the valuable
 * types are the entity ones - a destination is a place, an event has a date and
 * a venue - and those are what produce visible rich results.
 *
 * How this reaches Google: Rank Math reads any postmeta key beginning
 * rank_math_schema_ and merges the stored array into its @graph. Verified on
 * this install - a FAQPage written by VMSB renders in the frontend graph as
 * ["WebPage","FAQPage"]. Two rules from its validate_schema() shape everything
 * below: arbitrary @type values pass through untouched, but a node holding
 * nothing except @type is silently dropped, as is any empty-string property.
 *
 * The hard rule here is that nothing is invented. Structured data that
 * contradicts the page is worse than none: it is the one category of SEO error
 * Google issues manual actions for. So every field is either read from the post
 * or omitted, and a type whose required field cannot be sourced is not emitted
 * at all - the caller is told why instead.
 */
class VMSB_Schema_Entity {

	private $log;

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	/**
	 * Decide which entity type a post should carry.
	 *
	 * Driven by post type first, because that is a fact, and only then by the
	 * title as a hint. Returns '' when nothing better than Article applies.
	 */
	public function detect_type( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}

		$map = array(
			'destinations' => 'TouristDestination',
			'destination'  => 'TouristDestination',
			'attractions'  => 'TouristAttraction',
			'events'       => 'Event',
			'event'        => 'Event',
			'tours'        => 'TouristTrip',
			'trips'        => 'TouristTrip',
		);
		if ( isset( $map[ $post->post_type ] ) ) {
			return $map[ $post->post_type ];
		}

		// A plain post can still be a how-to; that is a safe, content-derived
		// call because it depends on the post genuinely having ordered steps.
		if ( preg_match( '/\b(how to|step[- ]by[- ]step)\b/i', $post->post_title )
			&& preg_match_all( '/<li[^>]*>/i', $post->post_content ) >= 3 ) {
			return 'HowTo';
		}

		return '';
	}

	/**
	 * Build and store the entity schema for a post.
	 *
	 * @return array|WP_Error Report of what was written, or why it was not.
	 */
	public function generate( $post_id, $type = '' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_schema', 'Post not found.', array( 'status' => 404 ) );
		}

		$type = $type ? $type : $this->detect_type( $post );
		if ( ! $type ) {
			return new WP_Error(
				'vmsb_schema',
				'No entity type applies to this post; Article/FAQ handling already covers it.',
				array( 'status' => 409 )
			);
		}

		switch ( $type ) {
			case 'TouristDestination':
			case 'TouristAttraction':
			case 'TouristTrip':
				$schema = $this->build_place( $post, $type );
				break;
			case 'Event':
				$schema = $this->build_event( $post );
				break;
			case 'HowTo':
				$schema = $this->build_howto( $post );
				break;
			default:
				return new WP_Error( 'vmsb_schema', "Unsupported entity type '{$type}'.", array( 'status' => 400 ) );
		}

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		// Rank Math drops any node that carries only an @type, so a schema
		// that gained no real properties must not be written at all.
		if ( count( $schema ) < 2 ) {
			return new WP_Error( 'vmsb_schema', 'Nothing verifiable to describe; refusing to write an empty node.', array( 'status' => 409 ) );
		}

		$meta_key = 'rank_math_schema_' . $type;
		update_post_meta( $post_id, $meta_key, $schema );

		$this->log->info( 'schema', sprintf( 'Wrote %s schema for post #%d (%d properties).', $type, $post_id, count( $schema ) - 1 ) );

		return array(
			'post_id'    => (int) $post_id,
			'type'       => $type,
			'meta_key'   => $meta_key,
			'properties' => array_keys( $schema ),
			'schema'     => $schema,
		);
	}

	/**
	 * Place-like entities. Everything here comes from the post itself.
	 */
	private function build_place( $post, $type ) {
		$schema = array(
			'@type'       => $type,
			'name'        => get_the_title( $post ),
			'description' => $this->description( $post ),
			'url'         => get_permalink( $post ),
		);

		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( ! $image ) {
			$image = $this->first_image( $post->post_content );
		}
		if ( $image ) {
			$schema['image'] = $image;
		}

		// Geo and address are only emitted when the site has actually recorded
		// them. Coordinates guessed from a place name are exactly the kind of
		// confident-but-wrong structured data that earns a manual action.
		$lat = get_post_meta( $post->ID, '_vmsb_latitude', true );
		$lng = get_post_meta( $post->ID, '_vmsb_longitude', true );
		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}

		$region   = get_post_meta( $post->ID, '_vmsb_region', true );
		$country  = get_post_meta( $post->ID, '_vmsb_country', true );
		$locality = get_post_meta( $post->ID, '_vmsb_locality', true );
		$address  = array_filter( array(
			'addressLocality' => $locality ? (string) $locality : '',
			'addressRegion'   => $region ? (string) $region : '',
			'addressCountry'  => $country ? (string) $country : (string) VMSB_Settings::get( 'country', '' ),
		) );
		if ( $address ) {
			$schema['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
		}

		return array_filter( $schema, static function ( $v ) {
			return '' !== $v && null !== $v && array() !== $v;
		} );
	}

	/**
	 * Events. startDate is required by Google for an Event rich result, and it
	 * is not something that can be reasonably guessed - a wrong date sends
	 * people to a venue on the wrong day. If the post does not carry one, no
	 * Event schema is written.
	 */
	private function build_event( $post ) {
		$start = $this->event_date( $post, '_vmsb_event_start' );
		if ( ! $start ) {
			return new WP_Error(
				'vmsb_schema',
				'Event schema needs a real start date. None found in post meta (_vmsb_event_start) or the content, and inventing one would misinform readers.',
				array( 'status' => 409 )
			);
		}

		$schema = array(
			'@type'               => 'Event',
			'name'                => get_the_title( $post ),
			'description'         => $this->description( $post ),
			'url'                 => get_permalink( $post ),
			'startDate'           => $start,
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'eventStatus'         => 'https://schema.org/EventScheduled',
		);

		$end = $this->event_date( $post, '_vmsb_event_end' );
		if ( $end ) {
			$schema['endDate'] = $end;
		}

		$venue = (string) get_post_meta( $post->ID, '_vmsb_venue', true );
		if ( $venue ) {
			$place = array( '@type' => 'Place', 'name' => $venue );
			$locality = (string) get_post_meta( $post->ID, '_vmsb_locality', true );
			$region   = (string) get_post_meta( $post->ID, '_vmsb_region', true );
			$addr     = array_filter( array(
				'addressLocality' => $locality,
				'addressRegion'   => $region,
				'addressCountry'  => (string) VMSB_Settings::get( 'country', '' ),
			) );
			if ( $addr ) {
				$place['address'] = array_merge( array( '@type' => 'PostalAddress' ), $addr );
			}
			$schema['location'] = $place;
		}

		$image = get_the_post_thumbnail_url( $post, 'full' ) ?: $this->first_image( $post->post_content );
		if ( $image ) {
			$schema['image'] = $image;
		}

		return $schema;
	}

	/**
	 * HowTo, built from the list items the post already has - never from
	 * steps imagined for it.
	 */
	private function build_howto( $post ) {
		preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $post->post_content, $m );
		$steps = array();
		foreach ( (array) $m[1] as $i => $li ) {
			$text = trim( wp_strip_all_tags( $li ) );
			if ( mb_strlen( $text ) < 10 ) {
				continue;
			}
			$steps[] = array(
				'@type'    => 'HowToStep',
				'position' => count( $steps ) + 1,
				'name'     => mb_substr( $text, 0, 80 ),
				'text'     => $text,
			);
			if ( count( $steps ) >= 12 ) {
				break;
			}
		}

		if ( count( $steps ) < 3 ) {
			return new WP_Error( 'vmsb_schema', 'Fewer than three usable steps found; not a HowTo.', array( 'status' => 409 ) );
		}

		return array(
			'@type'       => 'HowTo',
			'name'        => get_the_title( $post ),
			'description' => $this->description( $post ),
			'step'        => $steps,
		);
	}

	/**
	 * A start date from post meta, or an unambiguous one from the content.
	 * Ambiguity is treated as absence.
	 */
	private function event_date( $post, $meta_key ) {
		$raw = (string) get_post_meta( $post->ID, $meta_key, true );
		if ( $raw ) {
			$ts = strtotime( $raw );
			return $ts ? gmdate( 'c', $ts ) : '';
		}

		if ( '_vmsb_event_start' !== $meta_key ) {
			return '';
		}

		// Only a fully-qualified date is accepted: "14 March 2026" or
		// "2026-03-14". A bare "March" or "next spring" is not a date.
		$text = wp_strip_all_tags( $post->post_content );
		if ( preg_match( '/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m ) ) {
			$ts = strtotime( $m[1] );
			return $ts ? gmdate( 'c', $ts ) : '';
		}
		if ( preg_match( '/\b(\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4})\b/i', $text, $m ) ) {
			$ts = strtotime( $m[1] );
			return $ts ? gmdate( 'c', $ts ) : '';
		}
		return '';
	}

	private function description( $post ) {
		$desc = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		if ( '' === $desc ) {
			$desc = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		}
		if ( '' === $desc ) {
			$desc = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40, '' );
		}
		return trim( $desc );
	}

	private function first_image( $content ) {
		if ( preg_match( '/<img[^>]+src\s*=\s*("|\')(.*?)\1/i', $content, $m ) ) {
			return esc_url_raw( $m[2] );
		}
		return '';
	}

	/**
	 * Batch pass for the scheduler: write entity schema where it is missing.
	 */
	public function sweep( $limit = 10 ) {
		$types = array_values( array_filter(
			array( 'destinations', 'destination', 'attractions', 'events', 'event', 'tours', 'trips' ),
			'post_type_exists'
		) );
		// Plain posts are included so HowTo detection still has something to
		// work on when no travel CPTs are registered.
		$types[] = 'post';

		$posts = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'fields'         => 'ids',
		) );

		$done = 0;
		foreach ( $posts as $pid ) {
			$type = $this->detect_type( $pid );
			if ( ! $type || get_post_meta( $pid, 'rank_math_schema_' . $type, true ) ) {
				continue;
			}
			if ( ! is_wp_error( $this->generate( $pid, $type ) ) ) {
				$done++;
			}
		}
		return array( 'written' => $done, 'considered' => count( $posts ) );
	}
}
