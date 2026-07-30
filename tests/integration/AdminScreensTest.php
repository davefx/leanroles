<?php
/**
 * The profile screen, the tag screen and the audit screen.
 *
 * Rendering is checked lightly — enough to know the screens are wired up and
 * escape what they print. The capability and nonce checks are checked properly,
 * because those are the parts that matter if they are wrong.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Admin\AuditPage;
use LeanRoles\Admin\Menu;
use LeanRoles\Admin\TagsPage;
use LeanRoles\Admin\UserProfile;
use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;
use LeanRoles\Tests\TestCase;

class AdminScreensTest extends TestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->make_tag( 'gold', array( 'name' => 'Gold', 'color' => '#ffcc00' ) );
		$this->make_tag( 'wholesale', array( 'name' => 'Wholesale' ) );

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		$this->clear_request();

		parent::tear_down();
	}

	// ----------------------------------------------------------------- menu

	public function test_menu_urls(): void {
		$url = Menu::url( Menu::TAGS_SLUG, array( 'edit' => 'gold' ) );

		$this->assertStringContainsString( 'page=' . Menu::TAGS_SLUG, $url );
		$this->assertStringContainsString( 'edit=gold', $url );
		$this->assertStringContainsString( 'admin.php', $url );
	}

	// -------------------------------------------------------------- badges

	public function test_a_badge_escapes_its_label(): void {
		$html = TagsPage::badge( '<script>alert(1)</script>', '#ffffff' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_a_badge_rejects_a_malformed_colour(): void {
		$html = TagsPage::badge( 'Gold', 'javascript:alert(1)' );

		$this->assertStringNotContainsString( 'javascript', $html );
	}

	public function test_badge_text_contrasts_with_its_background(): void {
		$this->assertStringContainsString( '#1d2327', TagsPage::badge( 'Gold', '#ffffff' ), 'Dark text on light.' );
		$this->assertStringContainsString( '#fff', TagsPage::badge( 'Gold', '#000000' ), 'Light text on dark.' );
	}

	public function test_a_badge_with_a_three_digit_colour(): void {
		$this->assertStringContainsString( 'leanroles-badge', TagsPage::badge( 'Gold', '#fff' ) );
	}

	// ------------------------------------------------------- profile screen

	public function test_the_profile_screen_lists_every_tag(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->capture_output(
			fn() => UserProfile::render( get_userdata( $this->user_id ) )
		);

		$this->assertStringContainsString( 'value="gold"', $html );
		$this->assertStringContainsString( 'value="wholesale"', $html );
		$this->assertStringContainsString( 'leanroles_tags[]', $html );
	}

	public function test_the_profile_screen_checks_the_tags_a_user_holds(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Store::add( $this->user_id, 'gold' );

		$html = $this->capture_output(
			fn() => UserProfile::render( get_userdata( $this->user_id ) )
		);

		$this->assertMatchesRegularExpression( '/value="gold"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="wholesale"[^>]*checked/', $html );
	}

	public function test_the_profile_screen_is_read_only_without_promote_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$html = $this->capture_output(
			fn() => UserProfile::render( get_userdata( $this->user_id ) )
		);

		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'leanroles_tags_submitted', $html );
	}

	public function test_the_profile_screen_renders_nothing_when_no_tags_exist(): void {
		Taxonomy::delete( 'gold' );
		Taxonomy::delete( 'wholesale' );
		$this->reset_plugin_state();

		$html = $this->capture_output(
			fn() => UserProfile::render( get_userdata( $this->user_id ) )
		);

		$this->assertSame( '', $html );
	}

	public function test_saving_the_profile_sets_the_tags(): void {
		$this->as_admin_request(
			array(
				'leanroles_tags_submitted' => '1',
				'leanroles_tags'           => array( 'gold' ),
			),
			'leanroles_profile_tags',
			'leanroles_profile_nonce'
		);

		UserProfile::save( $this->user_id );

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_saving_the_profile_with_nothing_checked_clears_the_tags(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );

		$this->as_admin_request(
			array( 'leanroles_tags_submitted' => '1' ),
			'leanroles_profile_tags',
			'leanroles_profile_nonce'
		);

		UserProfile::save( $this->user_id );

		$this->assertSame( array(), Store::get_tags( $this->user_id ) );
	}

	public function test_saving_ignores_unknown_slugs(): void {
		$this->as_admin_request(
			array(
				'leanroles_tags_submitted' => '1',
				'leanroles_tags'           => array( 'gold', 'administrator', 'made_up' ),
			),
			'leanroles_profile_tags',
			'leanroles_profile_nonce'
		);

		UserProfile::save( $this->user_id );

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_a_profile_form_without_the_marker_is_left_alone(): void {
		Store::add( $this->user_id, 'gold' );

		$this->as_admin_request( array(), 'leanroles_profile_tags', 'leanroles_profile_nonce' );

		UserProfile::save( $this->user_id );

		$this->assertSame(
			array( 'gold' ),
			Store::get_tags( $this->user_id ),
			'A form that never rendered the tag field must not clear the tags.'
		);
	}

	public function test_a_site_admin_cannot_tag_users_on_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Single site: administrators may edit users.' );
		}

		Store::add( $this->user_id, 'gold' );

		// WordPress withholds edit_users from site administrators on a network.
		// The plugin defers to that rather than inventing its own rule.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST = array(
			'leanroles_tags_submitted' => '1',
			'leanroles_tags'           => array( 'wholesale' ),
		);

		UserProfile::save( $this->user_id );

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_saving_without_permission_changes_nothing(): void {
		Store::add( $this->user_id, 'gold' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST = array(
			'leanroles_tags_submitted' => '1',
			'leanroles_tags'           => array( 'wholesale' ),
		);

		UserProfile::save( $this->user_id );

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_saving_with_a_bad_nonce_dies(): void {
		$this->as_admin_request(
			array(
				'leanroles_tags_submitted' => '1',
				'leanroles_tags'           => array( 'gold' ),
				'leanroles_profile_nonce'  => 'wrong',
			)
		);

		$this->expectException( \WPDieException::class );

		UserProfile::save( $this->user_id );
	}

	// ---------------------------------------------------------- tags screen

	public function test_creating_a_tag_from_the_screen(): void {
		$this->as_admin_request(
			array(
				'leanroles_action' => 'create',
				'slug'             => 'platinum',
				'name'             => 'Platinum',
				'description'      => 'The very best',
				'color'            => '#cccccc',
			),
			'leanroles_tags'
		);

		$location = $this->capture_redirect( array( TagsPage::class, 'handle_actions' ) );

		$this->assertStringContainsString( 'message=created', $location );
		$this->assertTrue( Catalogue::has( 'platinum' ) );
	}

	public function test_creating_a_duplicate_reports_the_error(): void {
		$this->as_admin_request(
			array(
				'leanroles_action' => 'create',
				'slug'             => 'gold',
				'name'             => 'Gold again',
			),
			'leanroles_tags'
		);

		$location = $this->capture_redirect( array( TagsPage::class, 'handle_actions' ) );

		$this->assertStringContainsString( 'error=', $location );
	}

	public function test_updating_a_tag_from_the_screen(): void {
		$this->as_admin_request(
			array(
				'leanroles_action' => 'update',
				'slug'             => 'gold',
				'name'             => 'Gold renamed',
			),
			'leanroles_tags'
		);

		$location = $this->capture_redirect( array( TagsPage::class, 'handle_actions' ) );

		$this->assertStringContainsString( 'message=updated', $location );
		$this->assertSame( 'Gold renamed', Taxonomy::get_by_slug( 'gold' )->name );
	}

	public function test_deleting_a_tag_from_the_screen(): void {
		$this->as_admin_request(
			array(
				'leanroles_action' => 'delete',
				'slug'             => 'gold',
			),
			'leanroles_tags'
		);

		$location = $this->capture_redirect( array( TagsPage::class, 'handle_actions' ) );

		$this->assertStringContainsString( 'message=deleted', $location );
		$this->assertNull( Taxonomy::get_by_slug( 'gold' ) );
	}

	public function test_the_tags_screen_refuses_a_user_without_promote_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST    = array( 'leanroles_action' => 'delete', 'slug' => 'gold' );
		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );

		TagsPage::handle_actions();
	}

	public function test_the_tags_screen_refuses_a_bad_nonce(): void {
		$this->as_admin_request(
			array(
				'leanroles_action' => 'delete',
				'slug'             => 'gold',
				'_wpnonce'         => 'wrong',
			)
		);

		$this->expectException( \WPDieException::class );

		TagsPage::handle_actions();
	}

	public function test_no_action_means_no_work(): void {
		$this->as_admin_request( array() );

		$this->assertNull( $this->capture_redirect( array( TagsPage::class, 'handle_actions' ) ) );
	}

	public function test_the_tags_screen_renders(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Store::add( $this->user_id, 'gold' );

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'User tags', $html );
		$this->assertStringContainsString( 'Gold', $html );
		$this->assertStringContainsString( 'leanroles_action', $html );
		$this->assertStringContainsString( 'Import and export', $html );
	}

	public function test_the_tags_screen_shows_an_error_notice(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['error'] = 'Something went wrong';

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Something went wrong', $html );
	}

	public function test_the_tags_screen_is_denied_without_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );

		TagsPage::render();
	}

	// --------------------------------------------------------- audit screen

	public function test_the_audit_screen_renders(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['benchmark'] = '0';

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'Role audit', $html );
		$this->assertStringContainsString( 'Findings', $html );
		$this->assertStringContainsString( 'Roles', $html );
		$this->assertStringContainsString( 'administrator', $html );
	}

	public function test_the_audit_screen_states_that_it_writes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['benchmark'] = '0';

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'Nothing here writes', $html );
	}

	public function test_the_audit_screen_honours_its_inputs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET = array(
			'benchmark' => '0',
			'rps'       => '100',
			'ram'       => '8192',
			'worker'    => '128',
		);

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'value="100"', $html );
		$this->assertStringContainsString( 'value="8192"', $html );
		$this->assertStringContainsString( 'value="128"', $html );
	}

	public function test_the_audit_screen_runs_the_benchmark_by_default(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'Measured cost', $html );
		$this->assertStringContainsString( 'Unserialization, per request', $html );
	}

	public function test_the_audit_screen_is_denied_without_list_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );

		AuditPage::render();
	}

	public function test_the_audit_screen_carries_the_orphan_caveat(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_role( 'lr_odd', 'Odd', array( 'read' => true, 'acme_unknown_cap' => true ) );

		$_GET['benchmark'] = '0';

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'not mean orphaned', $html );

		remove_role( 'lr_odd' );
	}
}
