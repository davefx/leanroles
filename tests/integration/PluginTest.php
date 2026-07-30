<?php
/**
 * Bootstrap, activation, deactivation, and the admin menu.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Admin\Menu;
use LeanRoles\Audit\StructureProbe;
use LeanRoles\Plugin;
use LeanRoles\Support\Roles;
use UserTags\Catalogue;
use UserTags\Taxonomy;
use LeanRoles\Tests\TestCase;

class PluginTest extends TestCase {

	// ------------------------------------------------------------ bootstrap

	public function test_the_constants_are_defined(): void {
		$this->assertTrue( defined( 'LEANROLES_VERSION' ) );
		$this->assertTrue( defined( 'LEANROLES_FILE' ) );
		$this->assertTrue( defined( 'LEANROLES_PATH' ) );
		$this->assertTrue( defined( 'LEANROLES_URL' ) );
	}

	public function test_the_version_matches_the_plugin_header(): void {
		$data = get_file_data( LEANROLES_FILE, array( 'Version' => 'Version' ) );

		$this->assertSame( $data['Version'], LEANROLES_VERSION );
	}

	public function test_the_headers_declare_the_supported_floor(): void {
		$data = get_file_data(
			LEANROLES_FILE,
			array(
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
				'TextDomain'  => 'Text Domain',
			)
		);

		$this->assertSame( '5.9', $data['RequiresWP'] );
		$this->assertSame( '7.4', $data['RequiresPHP'] );
		$this->assertSame( 'leanroles', $data['TextDomain'] );
	}

	public function test_the_autoloader_resolves_plugin_classes(): void {
		$this->assertTrue( class_exists( 'LeanRoles\\Audit\\Auditor' ) );
		$this->assertTrue( class_exists( 'LeanRoles\\Audit\\Auditor' ) );
	}

	public function test_the_autoloader_ignores_foreign_namespaces(): void {
		$this->assertFalse( class_exists( 'SomeoneElse\\Whatever\\Thing' ) );
	}

	public function test_the_runtime_hooks_are_attached(): void {
		// Attached by the bundled library rather than by the plugin, but they
		// still have to be in place before the first WP_User of the request.
		$this->assertNotFalse( has_filter( 'get_user_metadata', array( 'UserTags\\Runtime', 'inject_on_read' ) ) );
		$this->assertNotFalse( has_filter( 'update_user_metadata', array( 'UserTags\\Runtime', 'strip_on_update' ) ) );
		$this->assertNotFalse( has_filter( 'add_user_metadata', array( 'UserTags\\Runtime', 'strip_on_add' ) ) );
		$this->assertNotFalse( has_filter( 'user_has_cap', array( 'UserTags\\Runtime', 'assert_tags' ) ) );
		$this->assertNotFalse( has_action( 'wp_roles_init', array( 'UserTags\\Runtime', 'register_tag_roles' ) ) );
	}

	public function test_the_cleanup_hooks_are_attached(): void {
		$this->assertNotFalse( has_action( 'deleted_user', array( 'UserTags\\Cleanup', 'purge_user' ) ) );
		$this->assertNotFalse( has_action( 'wpmu_delete_user', array( 'UserTags\\Cleanup', 'purge_user' ) ) );
		$this->assertNotFalse( has_action( 'remove_user_from_blog', array( 'UserTags\\Cleanup', 'purge_user' ) ) );
	}

	public function test_the_query_hooks_are_attached(): void {
		$this->assertNotFalse( has_action( 'pre_get_users', array( 'UserTags\\Query', 'translate_tag_queries' ) ) );
		$this->assertNotFalse( has_action( 'pre_user_query', array( 'UserTags\\Query', 'apply_resume_cursor' ) ) );
	}

	// ----------------------------------------------------------- activation

	public function test_activation_registers_the_taxonomy_and_builds_the_catalogue(): void {
		delete_option( Catalogue::OPTION );
		$this->reset_plugin_state();

		Plugin::activate();

		$this->assertTrue( taxonomy_exists( Taxonomy::NAME ) );
		$this->assertNotFalse( get_option( Catalogue::OPTION, false ) );
	}

	public function test_activation_takes_a_restore_point(): void {
		delete_option( Roles::BACKUP_OPTION );

		Plugin::activate();

		$backups = Roles::backups();

		$this->assertNotEmpty( $backups );
		$this->assertSame( 'activation', end( $backups )['reason'] );
	}

	public function test_activation_does_not_touch_the_role_option(): void {
		$before = Roles::raw_option_value();

		Plugin::activate();

		$this->assertSame(
			$before,
			Roles::raw_option_value(),
			'The plugin never rewrites {prefix}user_roles, not even on activation.'
		);
	}

	public function test_activation_schedules_the_mirror_prune(): void {
		wp_clear_scheduled_hook( 'user_tags_prune_mirrors' );

		Plugin::activate();

		$this->assertNotFalse( wp_next_scheduled( 'user_tags_prune_mirrors' ) );
	}

	public function test_activation_is_idempotent(): void {
		Plugin::activate();
		$first = wp_next_scheduled( 'user_tags_prune_mirrors' );

		Plugin::activate();

		$this->assertSame( $first, wp_next_scheduled( 'user_tags_prune_mirrors' ) );
	}

	// --------------------------------------------------------- deactivation

	public function test_deactivation_clears_only_what_the_plugin_owns(): void {
		Plugin::activate();
		set_transient( StructureProbe::USER_COUNT_TRANSIENT, array( 'total_users' => 1 ), HOUR_IN_SECONDS );

		Plugin::deactivate();

		$this->assertFalse( get_transient( StructureProbe::USER_COUNT_TRANSIENT ) );
		$this->assertNotFalse(
			wp_next_scheduled( 'user_tags_prune_mirrors' ),
			'The tag housekeeping job belongs to the library; another plugin may still need it.'
		);
	}

	public function test_deactivation_leaves_the_site_functionally_identical(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\UserTags\Store::add( $user_id, 'gold' );

		$roles_before = Roles::raw_option_value();

		Plugin::deactivate();

		// Tags grant nothing, so nothing a user could do changes; and the
		// assignments survive for when the plugin comes back.
		$this->assertSame( $roles_before, Roles::raw_option_value() );
		$this->assertSame( array( 'gold' ), \UserTags\Store::get_tags( $user_id ) );
	}

	public function test_deactivation_does_not_delete_tags(): void {
		$this->make_tag( 'gold' );

		Plugin::deactivate();

		$this->assertNotNull( Taxonomy::get_by_slug( 'gold' ) );
	}

	// ----------------------------------------------------------------- menu

	public function test_the_menu_is_registered(): void {
		global $menu, $submenu;

		$menu    = array();
		$submenu = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		Menu::register();

		$slugs = wp_list_pluck( $menu, 2 );

		$this->assertContains( Menu::AUDIT_SLUG, $slugs );
		$this->assertArrayHasKey( Menu::AUDIT_SLUG, $submenu );

		$sub_slugs = wp_list_pluck( $submenu[ Menu::AUDIT_SLUG ], 2 );

		$this->assertContains( Menu::AUDIT_SLUG, $sub_slugs );
	}

	public function test_the_audit_screen_needs_only_list_users(): void {
		global $menu, $submenu;

		$menu    = array();
		$submenu = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		Menu::register();

		$caps = array();

		foreach ( $submenu[ Menu::AUDIT_SLUG ] as $entry ) {
			$caps[ $entry[2] ] = $entry[1];
		}

		$this->assertSame( 'list_users', $caps[ Menu::AUDIT_SLUG ] );
	}

	public function test_the_stylesheet_loads_on_the_audit_screen(): void {
		// The users list and the profile are the library's screens now, and they
		// bring their own stylesheet.
		$this->reset_static( Menu::class, 'screens', array( 'toplevel_page_leanroles' ) );

		Menu::enqueue( 'toplevel_page_leanroles' );

		$this->assertTrue( wp_style_is( 'leanroles-admin', 'enqueued' ) );

		wp_dequeue_style( 'leanroles-admin' );
	}

	public function test_the_stylesheet_stays_off_unrelated_screens(): void {
		Menu::enqueue( 'edit.php' );

		$this->assertFalse( wp_style_is( 'leanroles-admin', 'enqueued' ) );
	}

	public function test_the_stylesheet_file_exists(): void {
		$this->assertFileExists( LEANROLES_PATH . 'assets/css/admin.css' );
	}

	// ------------------------------------------------------------ uninstall

	public function test_the_uninstall_script_is_shipped_and_parses(): void {
		$path = LEANROLES_PATH . 'uninstall.php';

		$this->assertFileExists( $path );

		$output = array();
		$status = 0;

		exec( sprintf( 'php -l %s 2>&1', escapeshellarg( $path ) ), $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}

	public function test_the_uninstall_script_refuses_to_run_directly(): void {
		$source = file_get_contents( LEANROLES_PATH . 'uninstall.php' );

		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $source );
	}
}
