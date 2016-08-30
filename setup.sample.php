<?php
require_once(__DIR__ . '/json-feed.php');

new JSONFeed(array(
	'url'      => '^api/deals-feed\.json', // regex -- fed directly into add_rewrite_rule()
	'get_data' => 'get_the_feed_data',     // a callabale which returns an array (or an object) -- will be json_encoded

	# OPTIONAL:

	// 'key'           => 'json-feed-' . ($UID)++, // must not be empty and contain only alphanumeric, _ or - chars
	// 'post_type'     => 'post',                  // the post type to attach the last_modified handler -- set to false to not attach actions
	// 'edit_posts'    => 'edit_posts',            // publish capability
	// 'delete_posts'  => 'delete_posts',          // trash capability
	// 'cache_timeout' => 3600,                    // cache timeout in seconds -- set to false to disable it
));

function get_the_feed_data($url, $key, $last_modified) {
	# $url and $key  are useful if you've got multiple feeds
	# $last_modified will be the last modified date (timestamp) or null, if there is no cached value

	# Sample code for fetching and returning a list of posts with custom fields
	$query_args = array(
		'post_type'      => 'post',
		'posts_per_page' => -1,
		'meta_query'     => array(
			// Only load offers
			array(
				'key'     => 'offer',
				'compare' => '==',
				'value'   => 1
			),
			// Make sure posts aren't expired
			array(
				'key'     => 'offer_expiry_date',
				'compare' => '>=',
				'value'   => intval(date('Ymd'))
			)
		)
	);

	# Query the DB
	$q = new WP_query( $query_args );

	# Populate the entries array
	$entries = array();

	if ( $q->have_posts() ) {

		$current_time = current_time( 'timestamp' );

		while ( $q->have_posts() ) {

			$q->the_post();

			$post_id = get_the_ID();

			$fields = get_fields( $post_id );

			# Make sure the expiry date ends at the end of the day
			$expiry_timestamp = strtotime( date_i18n( 'Y-m-d', strtotime( $fields['offer_expiry_date'] ) ) . ' 23:59:59' );

			# Skip all expired offers
			if ( $expiry_timestamp < $current_time ) {
				continue;
			}

			array_push( $entries, array(
				'id'                => get_the_ID(),
				'date'              => get_the_modified_date( 'c' ),
				'created'           => get_the_time( 'c' ),
				'title'             => wp_kses_decode_entities( html_entity_decode( get_the_title() ), ENT_QUOTES ),
				'permalink'         => get_permalink(),
				'the_offer'         => wp_kses_decode_entities( html_entity_decode( $fields['the_offer'] ), ENT_QUOTES ),
				'offer_expiry_date' => date_i18n( 'c', $expiry_timestamp ),
				'feature_image'     => $fields['feature_image'],
				// 'content'           => str_replace( ']]>', ']]&gt;', apply_filters( 'the_content', get_the_content() ) ),
			));

		}

		wp_reset_postdata();

	}

	return $entries;

}
