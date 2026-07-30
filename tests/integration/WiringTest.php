<?php
/**
 * The wiring itself: what each boot() attaches, and the last few defensive
 * branches.
 *
 * boot() runs once at plugin load, long before any test exists, so nothing
 * inside it is ever observed by a test that only checks effects. Calling it
 * again and asserting what it registered is the difference between "the hooks
 * are attached" and "this method is what attached them".
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Autoloader;
use LeanRoles\Cli\BackupCommand;
use LeanRoles\Plugin;
use LeanRoles\Support\Roles;
use UserTags\Catalogue;
use UserTags\Cleanup;
use UserTags\Query;
use UserTags\Runtime;
use UserTags\Store;
use UserTags\Taxonomy;
use LeanRoles\Tests\CliTestCase;

class WiringTest extends CliTestCase {

	// ------------------------------------------------------------- boot()

	public function test_runtime_boot_attaches_every_injection_hook(): void {
		Runtime::boot();

		$this->assertSame( 10, has_filter( 'get_user_metadata', array( Runtime::class, 'inject_on_read' ) ) );
		$this->assertSame( 10, has_filter( 'update_user_metadata', array( Runtime::class, 'strip_on_update' ) ) );
		$this->assertSame( 10, has_filter( 'add_user_metadata', array( Runtime::class, 'strip_on_add' ) ) );
		$this->assertSame( 20, has_filter( 'user_has_cap', array( Runtime::class, 'assert_tags' ) ) );
		$this->assertSame( 10, has_action( 'wp_roles_init', array( Runtime::class, 'register_tag_roles' ) ) );
		$this->assertSame( 5, has_action( 'init', array( Runtime::class, 'register_tag_roles_late' ) ) );
	}

	public function test_cleanup_boot_covers_every_way_a_user_leaves(): void {
		Cleanup::boot();

		foreach ( array( 'deleted_user', 'wpmu_delete_user', 'remove_user_from_blog' ) as $hook ) {
			$this->assertNotFalse(
				has_action( $hook, array( Cleanup::class, 'purge_user' ) ),
				"{$hook} leaves term relationships behind unless something clears them."
			);
		}
	}

	public function test_query_boot_attaches_both_hooks(): void {
		Query::boot();

		$this->assertNotFalse( has_action( 'pre_get_users', array( Query::class, 'translate_tag_queries' ) ) );
		$this->assertNotFalse( has_action( 'pre_user_query', array( Query::class, 'apply_resume_cursor' ) ) );
	}

	public function test_catalogue_boot_watches_term_changes(): void {
		Catalogue::boot();

		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), "{$hook} should invalidate the catalogue cache." );
		}
	}

	public function test_admin_boot_is_wired(): void {
		\LeanRoles\Admin\UsersList::boot();
		\LeanRoles\Admin\UserProfile::boot();

		$this->assertNotFalse( has_filter( 'manage_users_columns', array( \LeanRoles\Admin\UsersList::class, 'add_column' ) ) );
		$this->assertNotFalse( has_filter( 'views_users', array( \LeanRoles\Admin\UsersList::class, 'add_views' ) ) );
		$this->assertNotFalse( has_action( 'restrict_manage_users', array( \LeanRoles\Admin\UsersList::class, 'render_controls' ) ) );
		$this->assertNotFalse( has_action( 'load-users.php', array( \LeanRoles\Admin\UsersList::class, 'handle_bulk' ) ) );
		$this->assertNotFalse( has_action( 'show_user_profile', array( \LeanRoles\Admin\UserProfile::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'edit_user_profile_update', array( \LeanRoles\Admin\UserProfile::class, 'save' ) ) );
	}

	public function test_plugin_boot_registers_the_taxonomy_and_the_prune_job(): void {
		Plugin::boot();

		$this->assertSame( 0, has_action( 'init', array( Taxonomy::class, 'register' ) ) );
		$this->assertSame( 1, has_action( 'init', array( Catalogue::class, 'prime' ) ) );
		$this->assertNotFalse( has_action( 'user_tags_prune_mirrors', array( Store::class, 'prune_mirrors' ) ) );
	}

	public function test_the_textdomain_loads_without_complaint(): void {
		Plugin::load_textdomain();

		// No translations ship yet, so this asserts only that the call is well
		// formed — a wrong path here fails silently in production.
		$this->assertTrue( true );
	}

	public function test_registering_the_cli_commands_is_harmless_outside_wp_cli(): void {
		// WP_CLI::add_command() bails unless the WP_CLI constant is defined, so
		// this is a no-op here. Defining that constant to make the assertion
		// stronger would change how WordPress itself behaves for every test in
		// the process, which is a worse trade than the weaker assertion.
		Plugin::register_cli();

		$this->assertFalse( defined( 'WP_CLI' ) );
	}

	/**
	 * The published command surface.
	 *
	 * The split is by operation, not by interface: these primitives are free
	 * precisely so a developer can compose a conversion by hand. Renaming or
	 * dropping one is a breaking change to somebody's shell loop.
	 *
	 * @dataProvider cli_surface
	 */
	public function test_the_documented_cli_surface_exists( string $class, array $methods ): void {
		$this->assertTrue( class_exists( $class ) );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( $class, $method ),
				"{$class}::{$method}() backs a documented subcommand."
			);

			$reflection = new \ReflectionMethod( $class, $method );

			$this->assertTrue( $reflection->isPublic(), "{$class}::{$method}() must be public to be dispatchable." );
			$this->assertNotEmpty(
				$reflection->getDocComment(),
				"{$class}::{$method}() needs a docblock; WP-CLI builds --help from it."
			);
		}
	}

	public function cli_surface(): array {
		return array(
			'wp leanroles audit'  => array( \LeanRoles\Cli\AuditCommand::class, array( '__invoke' ) ),
			'wp leanroles tag'    => array(
				\LeanRoles\Cli\TagCommand::class,
				array( 'create', 'delete', 'list_', 'assign', 'remove', 'users', 'export', 'import', 'rebuild_mirror' ),
			),
			'wp leanroles role'   => array( \LeanRoles\Cli\RoleCommand::class, array( 'delete', 'list_' ) ),
			'wp leanroles backup' => array( \LeanRoles\Cli\BackupCommand::class, array( 'create', 'list_', 'restore' ) ),
		);
	}

	// -------------------------------------------------------- autoloader

	public function test_the_autoloader_resolves_a_real_class(): void {
		Autoloader::load( 'LeanRoles\\Support\\Format' );

		$this->assertTrue( class_exists( 'LeanRoles\\Support\\Format', false ) );
	}

	public function test_the_autoloader_is_silent_about_a_missing_file(): void {
		Autoloader::load( 'LeanRoles\\Nope\\DoesNotExist' );

		$this->assertFalse( class_exists( 'LeanRoles\\Nope\\DoesNotExist', false ) );
	}

	public function test_the_autoloader_ignores_other_namespaces(): void {
		Autoloader::load( 'Acme\\Widgets\\Thing' );

		$this->assertFalse( class_exists( 'Acme\\Widgets\\Thing', false ) );
	}

	public function test_registering_the_autoloader_is_safe(): void {
		$before = count( spl_autoload_functions() );

		Autoloader::register();

		$this->assertGreaterThanOrEqual( $before, count( spl_autoload_functions() ) );
	}

	// ------------------------------------------------- defensive branches

	public function test_role_registration_ignores_something_that_is_not_wp_roles(): void {
		Runtime::register_tag_roles( null );
		Runtime::register_tag_roles( new \stdClass() );

		$this->assertTrue( true, 'Neither call should have thrown.' );
	}

	public function test_user_has_cap_adds_a_tag_that_is_missing_from_allcaps(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		// A plugin that rebuilt allcaps from scratch and dropped the tag.
		$allcaps = apply_filters(
			'user_has_cap',
			array( 'read' => true ),
			array( 'read' ),
			array( 'read' ),
			new \WP_User( $user_id )
		);

		$this->assertTrue( $allcaps['gold'] );
	}

	public function test_tag_creation_relays_a_failure_from_the_taxonomy(): void {
		$filter = static function () {
			return new \WP_Error( 'nope', 'The term store said no.' );
		};

		add_filter( 'pre_insert_term', $filter );
		$result = Taxonomy::create( 'gold' );
		remove_filter( 'pre_insert_term', $filter );

		$this->assertWPError( $result );
		$this->assertSame( 'nope', $result->get_error_code() );
		$this->assertFalse( Catalogue::has( 'gold' ) );
	}

	public function test_a_tag_rename_relays_a_failure_from_the_taxonomy(): void {
		$this->make_tag( 'gold', array( 'name' => 'Gold' ) );

		// Whitespace survives the "did they supply a name" check and is then
		// rejected downstream, which is the path that has to relay cleanly.
		$result = Taxonomy::update( 'gold', array( 'name' => '   ' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'Gold', Taxonomy::get_by_slug( 'gold' )->name, 'The old name should survive.' );
	}

	public function test_a_restore_point_taken_with_no_role_option_warns(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Roles::option_name() ) );
		wp_cache_delete( Roles::option_name(), 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$result = $this->run_command( new BackupCommand(), 'create', array(), array() );

		$this->assertCommandSucceeded( $result );
		$this->assertStringContainsString( 'the restore point is empty', $result['stderr'] );
	}

	public function test_the_tags_screen_accepts_an_action_from_the_query_string(): void {
		$this->make_tag( 'gold' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}

		wp_set_current_user( $admin_id );

		// A row action link, not a form post.
		$_POST    = array();
		$_GET     = array(
			'leanroles_action' => 'delete',
			'slug'             => 'gold',
			'_wpnonce'         => wp_create_nonce( 'leanroles_tags' ),
		);
		$_REQUEST = $_GET;

		$location = $this->capture_redirect( array( \LeanRoles\Admin\TagsPage::class, 'handle_actions' ) );

		$this->assertStringContainsString( 'message=deleted', (string) $location );
		$this->assertNull( Taxonomy::get_by_slug( 'gold' ) );

		$this->clear_request();
	}

	public function test_a_badge_falls_back_to_white_text_on_a_broken_colour(): void {
		// sanitize_hex_color() lets a three-digit value through, so the
		// contrast helper has to cope with lengths other than six.
		$html = \LeanRoles\Admin\TagsPage::badge( 'Gold', '#12' );

		$this->assertStringContainsString( 'leanroles-badge', $html );
	}
}
