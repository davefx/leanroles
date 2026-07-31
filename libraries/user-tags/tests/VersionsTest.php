<?php
/**
 * The bundled-library version registry.
 *
 * This is the only genuinely new code the extraction introduced, and it is the
 * part with no second chance: get the arbitration wrong and a site with three
 * plugins bundling User Tags runs the wrong copy, silently, for years.
 *
 * The tests build throwaway copies on disk rather than mocking, because what is
 * under test is exactly the file loading.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Tests\TestCase;
use UserTags\Library;

class VersionsTest extends TestCase {

	/** @var string[] */
	private $temp_dirs = array();

	/** @var array The real registry state, put back afterwards. */
	private $saved_state;

	public function set_up(): void {
		parent::set_up();

		$this->saved_state = isset( $GLOBALS['user_tags_registry'] ) ? $GLOBALS['user_tags_registry'] : null;
	}

	public function tear_down(): void {
		if ( null === $this->saved_state ) {
			unset( $GLOBALS['user_tags_registry'] );
		} else {
			$GLOBALS['user_tags_registry'] = $this->saved_state;
		}

		foreach ( $this->temp_dirs as $dir ) {
			array_map( 'unlink', glob( $dir . '/*' ) );
			rmdir( $dir );
		}

		$this->temp_dirs = array();

		parent::tear_down();
	}

	/**
	 * Build a throwaway copy whose bootstrap records that it ran.
	 *
	 * @param string $version Version to register as.
	 * @return string Path to the bootstrap file.
	 */
	private function fake_copy( string $version ): string {
		$dir = sys_get_temp_dir() . '/ut-copy-' . str_replace( '.', '_', $version ) . '-' . uniqid();
		mkdir( $dir );
		$this->temp_dirs[] = $dir;

		$bootstrap = $dir . '/bootstrap.php';

		file_put_contents(
			$bootstrap,
			'<?php $GLOBALS["ut_test_booted"][] = ' . var_export( $version, true ) . ';'
		);

		return $bootstrap;
	}

	/**
	 * Start from an empty registry.
	 */
	private function reset_registry(): void {
		unset( $GLOBALS['user_tags_registry'], $GLOBALS['ut_test_booted'] );
		$GLOBALS['ut_test_booted'] = array();
	}

	/**
	 * Seed copies without going through register().
	 *
	 * Inside the test suite every early hook has already fired, so register()
	 * would boot on the first call and the arbitration would never be exercised.
	 * Writing the state directly is possible precisely because the registry
	 * keeps it in a global rather than locked inside its own class — the point
	 * of that design, demonstrated.
	 *
	 * @param array<string,string> $versions Version => bootstrap path.
	 */
	private function seed( array $versions ): void {
		$this->reset_registry();

		$GLOBALS['user_tags_registry'] = array(
			'registry_version' => \UserTags_Versions::REGISTRY_VERSION,
			'copies'           => array(),
			'duplicates'       => array(),
			'booted'           => null,
			'booted_early'     => false,
		);

		foreach ( $versions as $version => $source ) {
			$GLOBALS['user_tags_registry']['copies'][ $version ] = array(
				'bootstrap' => $this->fake_copy( $version ),
				'source'    => $source,
			);
		}
	}

	// ------------------------------------------------------------ arbitration

	public function test_the_highest_version_wins(): void {
		$this->seed( array( '1.0.0' => 'a.php', '1.4.2' => 'b.php', '1.2.0' => 'c.php' ) );

		$this->assertSame( '1.4.2', \UserTags_Versions::boot_latest() );
		$this->assertSame( array( '1.4.2' ), $GLOBALS['ut_test_booted'] );
	}

	public function test_registration_order_does_not_matter(): void {
		$this->seed( array( '2.0.0' => 'first.php', '1.0.0' => 'second.php' ) );

		$this->assertSame( '2.0.0', \UserTags_Versions::boot_latest() );
	}

	public function test_versions_compare_as_versions_not_as_strings(): void {
		// String comparison would put 1.9.0 above 1.10.0.
		$this->seed( array( '1.9.0' => 'a.php', '1.10.0' => 'b.php' ) );

		$this->assertSame( '1.10.0', \UserTags_Versions::boot_latest() );
	}

	public function test_a_prerelease_loses_to_the_release(): void {
		$this->seed( array( '1.1.0-beta1' => 'a.php', '1.1.0' => 'b.php' ) );

		$this->assertSame( '1.1.0', \UserTags_Versions::boot_latest() );
	}

	public function test_only_one_copy_is_ever_loaded(): void {
		$this->seed( array( '1.0.0' => 'a.php', '1.1.0' => 'b.php', '1.2.0' => 'c.php' ) );

		\UserTags_Versions::boot_latest();
		\UserTags_Versions::boot_latest();
		\UserTags_Versions::boot_latest();

		$this->assertSame(
			array( '1.2.0' ),
			$GLOBALS['ut_test_booted'],
			'Booting twice would redeclare every class in the library.'
		);
	}

	// -------------------------------------------------------------- collisions

	public function test_the_same_version_bundled_twice_keeps_the_first_seat(): void {
		$this->reset_registry();

		\UserTags_Versions::register( '1.0.0', $this->fake_copy( '1.0.0' ), 'plugin-a.php' );
		\UserTags_Versions::register( '1.0.0', $this->fake_copy( '1.0.0' ), 'plugin-b.php' );

		$copies = \UserTags_Versions::copies();

		$this->assertCount( 1, $copies );
		$this->assertSame( 'plugin-a.php', $copies['1.0.0']['source'] );
	}

	public function test_a_collision_is_recorded_rather_than_swallowed(): void {
		$this->reset_registry();

		\UserTags_Versions::register( '1.0.0', $this->fake_copy( '1.0.0' ), 'plugin-a.php' );
		\UserTags_Versions::register( '1.0.0', $this->fake_copy( '1.0.0' ), 'plugin-b.php' );

		$duplicates = \UserTags_Versions::duplicates();

		$this->assertCount( 1, $duplicates );
		$this->assertSame( 'plugin-b.php', $duplicates[0]['source'] );
	}

	// -------------------------------------------------------------- reporting

	public function test_the_registry_names_the_active_copy(): void {
		$this->seed( array( '1.0.0' => '/plugins/old/user-tags.php', '1.5.0' => '/plugins/new/user-tags.php' ) );

		\UserTags_Versions::boot_latest();

		$copies = \UserTags_Versions::copies();

		$this->assertTrue( $copies['1.5.0']['active'] );
		$this->assertFalse( $copies['1.0.0']['active'] );
		$this->assertSame( '/plugins/new/user-tags.php', $copies['1.5.0']['source'] );
	}

	public function test_copies_are_reported_newest_first(): void {
		$this->seed( array( '1.0.0' => 'a.php', '2.0.0' => 'b.php', '1.5.0' => 'c.php' ) );

		$this->assertSame( array( '2.0.0', '1.5.0', '1.0.0' ), array_keys( \UserTags_Versions::copies() ) );
	}

	// ------------------------------------------------------------- robustness

	public function test_an_empty_registry_boots_nothing(): void {
		$this->reset_registry();

		$this->assertNull( \UserTags_Versions::boot_latest() );
	}

	public function test_a_missing_bootstrap_does_not_fatal(): void {
		$this->reset_registry();

		\UserTags_Versions::register( '9.9.9', '/no/such/path/bootstrap.php', 'broken.php' );

		$this->assertNull( \UserTags_Versions::boot_latest() );
		$this->assertSame( array(), $GLOBALS['ut_test_booted'] );
	}

	public function test_registering_after_plugins_loaded_boots_immediately(): void {
		// `plugins_loaded` has long since fired inside the test suite, which is
		// the same position a plugin activated mid-request finds itself in.
		$this->reset_registry();

		$this->assertGreaterThan( 0, did_action( 'plugins_loaded' ) );

		\UserTags_Versions::register( '3.0.0', $this->fake_copy( '3.0.0' ), 'late.php' );

		$this->assertSame(
			array( '3.0.0' ),
			$GLOBALS['ut_test_booted'],
			'Staying dormant until the next request is what makes "it works after a refresh" bug reports.'
		);
		$this->assertTrue(
			\UserTags_Versions::booted_early(),
			'The flag records that the winner was chosen without a full view of the site.'
		);
	}

	public function test_a_late_registration_does_not_lock_out_a_newer_sibling(): void {
		// Two copies arriving in the same late burst. Booting on the first
		// would hand the request to whichever was included first, which is not
		// the same thing as the newest.
		$this->reset_registry();

		\UserTags_Versions::register( '1.0.0', $this->fake_copy( '1.0.0' ), 'older.php' );

		$this->assertSame( array( '1.0.0' ), $GLOBALS['ut_test_booted'] );

		\UserTags_Versions::register( '2.0.0', $this->fake_copy( '2.0.0' ), 'newer.php' );

		$copies = \UserTags_Versions::copies();

		$this->assertArrayHasKey(
			'2.0.0',
			$copies,
			'The newer copy is still recorded, so the next request arbitrates correctly.'
		);
		$this->assertFalse( $copies['2.0.0']['active'] );
	}

	public function test_the_state_lives_in_a_global_not_a_class_static(): void {
		// A future registry redesign has to be able to read what an older one
		// collected. Action Scheduler locks its registry inside its class.
		$this->reset_registry();

		\UserTags_Versions::register( '1.0.0', $this->fake_copy( '1.0.0' ), 'a.php' );

		$this->assertArrayHasKey( 'user_tags_registry', $GLOBALS );
		$this->assertArrayHasKey( 'copies', $GLOBALS['user_tags_registry'] );
		$this->assertArrayHasKey( '1.0.0', $GLOBALS['user_tags_registry']['copies'] );
		$this->assertSame( 1, $GLOBALS['user_tags_registry']['registry_version'] );
	}

	public function test_the_boot_hook_runs_before_wp_roles_is_built(): void {
		$this->assertLessThan(
			0,
			\UserTags_Versions::BOOT_PRIORITY,
			'WP_Roles is constructed as soon as plugins_loaded finishes; the shim has to be in place first.'
		);
	}

	// ------------------------------------------------------- the real library

	public function test_the_real_library_booted(): void {
		$this->assertTrue( Library::is_ready() );
		$this->assertNotSame( '', Library::version() );
		$this->assertSame( \UserTags_Versions::booted(), Library::version() );
	}

	public function test_the_library_reports_where_it_was_loaded_from(): void {
		$path = Library::path();

		// Not a hard-coded layout: the same code runs from its own repository
		// and from inside whichever directory a plugin bundles it into.
		$this->assertDirectoryExists( $path );
		$this->assertFileExists( $path . '/Library.php' );
		$this->assertFileExists( $path . '/Versions.php' );
		$this->assertSame( realpath( USER_TAGS_TEST_PATH . 'src' ), realpath( $path ) );
	}

	public function test_features_are_named_not_inferred_from_a_version(): void {
		// A consumer bundling a newer copy can end up running against an older
		// one. Comparing version strings is a guess about what a version held;
		// asking for the capability is not.
		$this->assertTrue( Library::supports( 'role-shim' ) );
		$this->assertTrue( Library::supports( 'user-query' ) );
		$this->assertFalse( Library::supports( 'something-from-2030' ) );
	}

	public function test_diagnostics_answer_the_first_support_question(): void {
		$diagnostics = user_tags_diagnostics();

		$this->assertTrue( $diagnostics['ready'] );
		$this->assertNotEmpty( $diagnostics['version'] );
		$this->assertNotEmpty( $diagnostics['path'] );
		$this->assertIsArray( $diagnostics['copies'] );
		$this->assertIsArray( $diagnostics['features'] );
	}

	// ------------------------------------------------- standalone vs bundled

	public function test_a_bundled_copy_is_not_standalone(): void {
		// The suite runs the library from its own checkout, not from inside a
		// plugin directory, so nothing here looks like an activated plugin.
		$this->assertFalse( Library::is_standalone() );
	}

	public function test_a_copy_living_directly_in_the_plugins_directory_is_standalone(): void {
		$plugins = realpath( WP_PLUGIN_DIR );

		$this->assertNotFalse( $plugins, 'WP_PLUGIN_DIR should exist in the test install.' );

		$dir = $plugins . '/ut-standalone-' . uniqid();
		mkdir( $dir );
		$entry = $dir . '/user-tags.php';
		file_put_contents( $entry, '<?php // pretend plugin' );

		$saved = $GLOBALS['user_tags_registry'];

		$GLOBALS['user_tags_registry']['copies']['9.9.9'] = array(
			'bootstrap' => $entry,
			'source'    => $entry,
		);

		$standalone = Library::is_standalone();

		$GLOBALS['user_tags_registry'] = $saved;
		unlink( $entry );
		rmdir( $dir );

		$this->assertTrue(
			$standalone,
			'A directory sitting directly inside wp-content/plugins is a plugin in its own right.'
		);
	}

	public function test_the_screens_default_to_standalone(): void {
		// The filter result cannot be asserted here: this suite forces it on so
		// it can exercise the screens. What matters is what the default is
		// derived from — activate the plugin, get the screens, without having to
		// know a filter exists.
		$source = file_get_contents( dirname( __DIR__ ) . '/src/Admin/Admin.php' );

		$this->assertStringContainsString(
			'$default = Library::is_standalone();',
			$source
		);
		$this->assertStringContainsString(
			'apply_filters( \'user_tags_enable_admin\', $default )',
			$source
		);
	}

	public function test_diagnostics_report_whether_it_is_standalone(): void {
		$this->assertArrayHasKey( 'standalone', user_tags_diagnostics() );
	}

	public function test_the_ready_hook_fires(): void {
		$this->assertGreaterThan(
			0,
			did_action( 'user_tags_ready' ),
			'Consumers need a documented moment to register tags from code.'
		);
	}
}
