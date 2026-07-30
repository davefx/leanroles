<?php
/**
 * PHPUnit bootstrap.
 *
 * Every test in this suite runs against a real WordPress install and a real
 * database. That is deliberate: almost everything worth getting wrong in this
 * plugin lives in the seams — the short-circuit contract of the metadata
 * filters, what WP_User does with a capabilities array, how WP_User_Query
 * assembles its role clauses. A stub of those seams would only ever test my
 * reading of them.
 *
 * @package LeanRoles
 */

/*
 * Where the test library lives, in order of preference: an explicit
 * WP_TESTS_DIR, the copy tests/bin/install.sh puts inside the project, and
 * finally the conventional /tmp location the upstream installer defaults to.
 */
$leanroles_candidates = array_filter(
	array(
		getenv( 'WP_TESTS_DIR' ),
		__DIR__ . '/.wp/wordpress-tests-lib',
		rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib',
	)
);

$leanroles_tests_dir = '';

foreach ( $leanroles_candidates as $leanroles_candidate ) {
	$leanroles_candidate = rtrim( $leanroles_candidate, '/\\' );

	if ( file_exists( $leanroles_candidate . '/includes/functions.php' ) ) {
		$leanroles_tests_dir = $leanroles_candidate;
		break;
	}
}

if ( '' === $leanroles_tests_dir ) {
	$leanroles_tests_dir = rtrim( (string) reset( $leanroles_candidates ), '/\\' );
}

if ( ! file_exists( $leanroles_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$leanroles_tests_dir}.\n\n" .
		"Run tests/bin/install.sh, or set WP_TESTS_DIR to an existing installation.\n"
	);
	exit( 1 );
}

require_once $leanroles_tests_dir . '/includes/functions.php';

/**
 * Load the plugin into the test install.
 *
 * `muplugins_loaded` is the earliest hook the test bootstrap offers, and it
 * matters here: the plugin attaches its metadata filters at file load precisely
 * because WP_Roles is built before `init`. Loading it any later would test a
 * configuration that never happens in production.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/leanroles.php';
	}
);

require $leanroles_tests_dir . '/includes/bootstrap.php';

/*
 * WP-CLI's function files are loaded by its own launcher, not by composer's
 * autoloader, so the Utils\* helpers the commands call have to be pulled in by
 * hand. WP_CLI_ROOT is what those files use to locate their templates.
 *
 * The WP_CLI *constant* is deliberately left undefined: defining it would
 * change how WordPress and the plugin behave, and the commands are called
 * directly rather than dispatched.
 */
$leanroles_wp_cli = dirname( __DIR__ ) . '/vendor/wp-cli/wp-cli';

if ( is_dir( $leanroles_wp_cli ) ) {
	if ( ! defined( 'WP_CLI_ROOT' ) ) {
		define( 'WP_CLI_ROOT', $leanroles_wp_cli . '/php' );
	}

	require_once WP_CLI_ROOT . '/utils.php';
	require_once WP_CLI_ROOT . '/dispatcher.php';
}

if ( ! defined( 'USER_TAGS_TEST_PATH' ) ) {
	define( 'USER_TAGS_TEST_PATH', LEANROLES_PATH . 'libraries/user-tags/' );
}

require_once __DIR__ . '/includes/TestCase.php';
require_once __DIR__ . '/includes/CliTestCase.php';

// The bundled library's own suite runs alongside the plugin's, so its base
// class has to be available here too.
require_once LEANROLES_PATH . 'libraries/user-tags/tests/includes/TestCase.php';
