<?php
/**
 * Live WordPress integration test. Runs real REST requests against this install.
 * CLI only: php tests/test-integration.php
 *
 * Creates temporary posts (title prefix "[Smart Schedule Test]"), asserts the
 * REST conversion + UTC-intent handoff + cron scheduling, then deletes them.
 * It does NOT wait for or trigger actual publication.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 'CLI only.' );
}
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "SKIP: wp-load.php not found at $wp_load\n" );
	exit( 0 );
}
// Provide the server variables wp-config.php reads for its URL defines.
// (Do not define WP_CLI: some bundled plugins then try to load WP-CLI classes.)
$_SERVER['HTTP_HOST']      = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_SCHEME'] = isset( $_SERVER['REQUEST_SCHEME'] ) ? $_SERVER['REQUEST_SCHEME'] : 'http';
$_SERVER['REQUEST_URI']    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
require $wp_load;

if ( ! defined( 'SMART_SCHEDULE_VERSION' ) ) {
	fwrite( STDERR, "SKIP: Smart Schedule plugin is not active.\n" );
	exit( 0 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) {
	fwrite( STDERR, "SKIP: no administrator user found.\n" );
	exit( 0 );
}
wp_set_current_user( (int) $admins[0] );

$passed = 0;
$created = array();
function check( $label, $truth ) {
	global $passed;
	if ( ! $truth ) {
		fwrite( STDERR, "FAIL: $label\n" );
		exit( 1 );
	}
	$passed++;
	echo "PASS: $label\n";
}
function rest( $method, $route, $params = array() ) {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_do_request( $req );
}
function make_post( $status ) {
	global $created;
	$res = rest( 'POST', '/wp/v2/posts', array( 'title' => '[Smart Schedule Test] ' . $status . ' ' . wp_rand(), 'status' => $status, 'content' => 'Temporary integration-test post.' ) );
	if ( $res->get_status() !== 201 ) {
		fwrite( STDERR, "FAIL: could not create test post (HTTP " . $res->get_status() . ")\n" );
		exit( 1 );
	}
	$id = $res->get_data()['id'];
	$created[] = $id;
	return $id;
}

try {
	// 1. Conversion endpoint agrees with the engine for a cross-zone time.
	$preview = Smart_Schedule_Time::preview( '2027-06-15', '09:30', 'America/Los_Angeles' );
	$res = rest( 'POST', '/smart-schedule/v1/convert', array( 'date' => '2027-06-15', 'time' => '09:30', 'timezone' => 'America/Los_Angeles' ) );
	check( 'REST /convert returns 200', $res->get_status() === 200 );
	check( 'REST /convert matches engine UTC', $res->get_data()['utc'] === $preview['utc'] );

	// 2. Unauthenticated callers cannot convert.
	wp_set_current_user( 0 );
	$res = rest( 'POST', '/smart-schedule/v1/convert', array( 'date' => '2027-06-15', 'time' => '09:30', 'timezone' => 'UTC' ) );
	check( 'REST /convert rejects logged-out callers', in_array( $res->get_status(), array( 401, 403 ), true ) );
	wp_set_current_user( (int) $GLOBALS['admins'][0] );

	// 3. Schedule a draft through the real REST intent handoff.
	$draft = make_post( 'draft' );
	$future_utc = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 );
	$site_local = ( new DateTimeImmutable( $future_utc ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:s' );
	$res = rest( 'POST', '/wp/v2/posts/' . $draft, array( 'status' => 'future', 'date' => $site_local, 'smart_schedule_utc_intent' => $future_utc ) );
	check( 'Intent save returns 200', $res->get_status() === 200 );
	$post = get_post( $draft );
	check( 'post_status is future', $post->post_status === 'future' );
	check( 'post_date_gmt preserves exact UTC', $post->post_date_gmt === str_replace( array( 'T', 'Z' ), array( ' ', '' ), $future_utc ) );
	check( 'publish_future_post cron event scheduled', (bool) wp_next_scheduled( 'publish_future_post', array( $draft ) ) );

	// 4. Mismatched site-local date is rejected with 409.
	$res = rest( 'POST', '/wp/v2/posts/' . $draft, array( 'date' => '2027-01-01T00:00:00', 'smart_schedule_utc_intent' => $future_utc ) );
	check( 'Date mismatch rejected with 409', $res->get_status() === 409 );

	// 5. Published post cannot be moved to a future date (server-side guard).
	$published = make_post( 'publish' );
	$res = rest( 'POST', '/wp/v2/posts/' . $published, array( 'date' => $site_local, 'smart_schedule_utc_intent' => $future_utc ) );
	check( 'Published + future intent rejected', $res->get_status() === 400 && $res->get_data()['code'] === 'smart_schedule_published_future' );
	$res = rest( 'POST', '/wp/v2/posts/' . $published, array( 'status' => 'future', 'date' => $site_local, 'smart_schedule_utc_intent' => $future_utc ) );
	check( 'Published + future intent + status=future rejected', $res->get_status() === 400 && $res->get_data()['code'] === 'smart_schedule_published_future' );
	check( 'Published post stayed published', get_post( $published )->post_status === 'publish' );

	// 6. Published post CAN be backdated to another past date.
	$past_utc = gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 );
	$past_local = ( new DateTimeImmutable( $past_utc ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:s' );
	$res = rest( 'POST', '/wp/v2/posts/' . $published, array( 'date' => $past_local, 'smart_schedule_utc_intent' => $past_utc ) );
	check( 'Published backdating accepted', $res->get_status() === 200 );
	check( 'Backdated post_date_gmt exact', get_post( $published )->post_date_gmt === str_replace( array( 'T', 'Z' ), array( ' ', '' ), $past_utc ) );

	// 7. Out-of-range years are rejected through the intent path.
	$res = rest( 'POST', '/wp/v2/posts/' . $draft, array( 'date' => '9999-12-31T23:59:59', 'smart_schedule_utc_intent' => '9999-12-31T23:59:59Z' ) );
	check( 'Year 9999 intent rejected', $res->get_status() === 400 );

	// 8. DST fold: both occurrences of a repeated site-local hour persist exactly.
	$zone = wp_timezone();
	$transitions = $zone->getTransitions( time(), time() + 400 * DAY_IN_SECONDS );
	$fold_ts = null;
	if ( is_array( $transitions ) ) {
		foreach ( $transitions as $i => $tr ) {
			if ( $i > 0 && $tr['offset'] < $transitions[ $i - 1 ]['offset'] && ! $tr['isdst'] ) {
				$fold_ts = $tr['ts'];
				break;
			}
		}
	}
	if ( $fold_ts ) {
		$wall = ( new DateTimeImmutable( '@' . ( $fold_ts + 1800 ) ) )->setTimezone( $zone );
		$first = Smart_Schedule_Time::preview( $wall->format( 'Y-m-d' ), $wall->format( 'H:i' ), $zone->getName(), 'first' );
		$second = Smart_Schedule_Time::preview( $wall->format( 'Y-m-d' ), $wall->format( 'H:i' ), $zone->getName(), 'second' );
		if ( isset( $first['utc'], $second['utc'] ) && $first['utc'] !== $second['utc'] ) {
			$fold_post = make_post( 'draft' );
			foreach ( array( $first, $second ) as $occurrence ) {
				$local = ( new DateTimeImmutable( $occurrence['utc'] ) )->setTimezone( $zone )->format( 'Y-m-d\TH:i:s' );
				$res = rest( 'POST', '/wp/v2/posts/' . $fold_post, array( 'status' => 'future', 'date' => $local, 'smart_schedule_utc_intent' => $occurrence['utc'] ) );
				check( 'DST occurrence saves (' . $occurrence['choice'] . ')', $res->get_status() === 200 && get_post( $fold_post )->post_date_gmt === str_replace( array( 'T', 'Z' ), array( ' ', '' ), $occurrence['utc'] ) );
			}
		} else {
			echo "SKIP: site timezone has no upcoming DST fold to test.\n";
		}
	} else {
		echo "SKIP: site timezone has no upcoming DST fold to test.\n";
	}

	echo "All $passed live WordPress integration assertions passed.\n";
} finally {
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
	echo 'Cleaned up ' . count( $created ) . " test post(s).\n";
}