<?php
/**
 * PHPUnit bootstrap for the library, run on its own.
 *
 * Loads WordPress and then the library exactly as a bundling plugin would —
 * one require of the entry file, nothing else. If that is not enough to make
 * the library work, the integration instructions are wrong.
 *
 * @package UserTags
 */

$user_tags_candidates = array_filter(
	array(
		getenv( 'WP_TESTS_DIR' ),
		__DIR__ . '/.wp/wordpress-tests-lib',
		rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib',
	)
);

$user_tags_tests_dir = '';

foreach ( $user_tags_candidates as $user_tags_candidate ) {
	$user_tags_candidate = rtrim( $user_tags_candidate, '/\\' );

	if ( file_exists( $user_tags_candidate . '/includes/functions.php' ) ) {
		$user_tags_tests_dir = $user_tags_candidate;
		break;
	}
}

if ( '' === $user_tags_tests_dir ) {
	fwrite( STDERR, "Could not find the WordPress test library.\n\nRun tests/bin/install.sh, or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $user_tags_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/user-tags.php';
	}
);

require $user_tags_tests_dir . '/includes/bootstrap.php';

if ( ! defined( 'USER_TAGS_TEST_PATH' ) ) {
	define( 'USER_TAGS_TEST_PATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/includes/TestCase.php';
