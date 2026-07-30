<?php
/**
 * The branches of the admin screens that only render when the site is in an
 * unusual state — no role option, a mitigating drop-in, a truncated list.
 *
 * These matter more than ordinary display code: several of them exist to keep
 * the report honest, and a refactor that dropped one would be invisible.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Admin\AuditPage;
use LeanRoles\Admin\Menu;
use LeanRoles\Admin\TagsPage;
use LeanRoles\Admin\UsersList;
use LeanRoles\Audit\StructureProbe;
use LeanRoles\Support\Roles;
use UserTags\Store;
use LeanRoles\Tests\TestCase;

class AdminRenderingTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['benchmark'] = '0';
	}

	public function tear_down(): void {
		$this->clear_request();

		parent::tear_down();
	}

	private function audit_html(): string {
		return $this->capture_output( array( AuditPage::class, 'render' ) );
	}

	// ------------------------------------------------------- audit branches

	public function test_the_audit_screen_with_no_role_option(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Roles::option_name() ) );
		wp_cache_delete( Roles::option_name(), 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$html = $this->audit_html();

		$this->assertStringContainsString( 'measured with LENGTH()', $html );
		$this->assertStringContainsString( 'No role option was found', $html );
	}

	public function test_the_audit_screen_when_there_is_nothing_to_report(): void {
		$filter = static function ( $report ) {
			$report['findings'] = array();

			return $report;
		};

		add_filter( 'leanroles_audit_report', $filter );
		$html = $this->audit_html();
		remove_filter( 'leanroles_audit_report', $filter );

		$this->assertStringContainsString( 'genuine result, not a placeholder', $html );
	}

	public function test_a_long_finding_list_says_how_much_it_cut(): void {
		$caps = array( 'read' => true );

		for ( $i = 0; $i < 60; $i++ ) {
			$caps[ 'acme_cap_' . $i ] = true;
		}

		add_role( 'lr_many', 'Many', $caps );

		$html = $this->audit_html();

		$this->assertStringContainsString(
			'and 20 more',
			$html,
			'A silent truncation reads as "that was all of them".'
		);

		remove_role( 'lr_many' );
	}

	public function test_the_audit_screen_reports_a_benchmark_that_could_not_run(): void {
		$filter = static function ( $report ) {
			$report['benchmark'] = array(
				'available' => false,
				'reason'    => 'A very specific reason.',
			);
			$report['capacity']  = null;

			return $report;
		};

		unset( $_GET['benchmark'] );

		add_filter( 'leanroles_audit_report', $filter );
		$html = $this->audit_html();
		remove_filter( 'leanroles_audit_report', $filter );

		$this->assertStringContainsString( 'A very specific reason.', $html );
	}

	public function test_the_audit_screen_reports_an_unidentified_dropin(): void {
		$filter = static function ( $report ) {
			$report['stack']['dropin_present'] = true;
			$report['stack']['backends']       = array();

			return $report;
		};

		add_filter( 'leanroles_audit_report', $filter );
		$html = $this->audit_html();
		remove_filter( 'leanroles_audit_report', $filter );

		$this->assertStringContainsString( 'backend not identified', $html );
	}

	public function test_the_audit_screen_acknowledges_a_mitigating_dropin(): void {
		$filter = static function ( $report ) {
			$report['stack']['dropin_present'] = true;
			$report['stack']['backends']       = array( 'Redis' );
			$report['stack']['mitigations']    = array( 'value compression' );
			$report['stack']['notes']          = array( 'This drop-in appears to already do something about it.' );

			return $report;
		};

		add_filter( 'leanroles_audit_report', $filter );
		$html = $this->audit_html();
		remove_filter( 'leanroles_audit_report', $filter );

		$this->assertStringContainsString( 'Drop-in appears to use', $html );
		$this->assertStringContainsString( 'value compression', $html );
		$this->assertStringContainsString( 'already do something', $html );
	}

	public function test_the_audit_screen_mentions_subset_candidates(): void {
		add_role( 'lr_small', 'Small', array( 'read' => true, 'upload_files' => true ) );

		$html = $this->audit_html();

		$this->assertStringContainsString( 'subset relationship', $html );

		remove_role( 'lr_small' );
	}

	public function test_the_audit_screen_explains_a_skipped_pairwise_pass(): void {
		$filter = static fn() => 2;

		add_filter( 'leanroles_pairwise_limit', $filter );
		$html = $this->audit_html();
		remove_filter( 'leanroles_pairwise_limit', $filter );

		$this->assertStringContainsString( 'quadratic', $html );
	}

	public function test_the_audit_screen_dates_the_user_counts(): void {
		unset( $_GET['benchmark'] );
		delete_transient( StructureProbe::USER_COUNT_TRANSIENT );

		$html = $this->audit_html();

		$this->assertStringContainsString( 'cached for twelve hours', $html );
	}

	// -------------------------------------------------------- users list UI

	public function test_the_bulk_controls_render(): void {
		$this->make_tag( 'gold', array( 'name' => 'Gold' ) );
		$this->make_tag( 'wholesale', array( 'name' => 'Wholesale' ) );

		$html = $this->capture_output( static fn() => UsersList::render_controls( 'top' ) );

		$this->assertStringContainsString( 'leanroles_bulk_tag', $html );
		$this->assertStringContainsString( 'value="gold"', $html );
		$this->assertStringContainsString( 'leanroles_bulk_add', $html );
		$this->assertStringContainsString( 'leanroles_bulk_remove', $html );
		$this->assertStringContainsString( 'leanroles_bulk_nonce', $html );
	}

	public function test_the_bulk_controls_render_once_not_twice(): void {
		$this->make_tag( 'gold' );

		$this->assertSame( '', $this->capture_output( static fn() => UsersList::render_controls( 'bottom' ) ) );
	}

	public function test_the_bulk_controls_stay_hidden_without_permission(): void {
		$this->make_tag( 'gold' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->capture_output( static fn() => UsersList::render_controls( 'top' ) ) );
	}

	public function test_the_bulk_controls_stay_hidden_with_no_tags(): void {
		$this->assertSame( '', $this->capture_output( static fn() => UsersList::render_controls( 'top' ) ) );
	}

	// ------------------------------------------------------- tags screen UI

	public function test_the_edit_form_is_prefilled_and_locks_the_slug(): void {
		$this->make_tag( 'gold', array( 'name' => 'Gold', 'color' => '#ffcc00' ) );

		$_GET['edit'] = 'gold';

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'Edit tag', $html );
		$this->assertStringContainsString( 'value="gold" readonly', $html );
		$this->assertStringContainsString( 'value="#ffcc00"', $html );
		$this->assertStringContainsString( 'Cancel', $html );
	}

	public function test_the_table_notes_a_legacy_role(): void {
		$this->make_tag( 'gold', array( 'legacy_role' => 'wholesale_customer' ) );

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'stands in for the role', $html );
		$this->assertStringContainsString( 'wholesale_customer', $html );
	}

	public function test_the_screen_says_when_there_are_no_tags(): void {
		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'No tags yet', $html );
	}

	public function test_the_import_summary_notice(): void {
		set_transient(
			'leanroles_import_result_' . get_current_user_id(),
			array(
				'imported' => 12,
				'skipped'  => 3,
				'created'  => array( 'gold' ),
				'errors'   => array( 'Line 4: no matching user.' ),
			),
			MINUTE_IN_SECONDS
		);

		$_GET['message'] = 'imported';

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( '12 user(s) updated', $html );
		$this->assertStringContainsString( 'Line 4: no matching user.', $html );
		$this->assertFalse(
			get_transient( 'leanroles_import_result_' . get_current_user_id() ),
			'The summary is shown once, then cleared.'
		);
	}

	public function test_a_non_import_notice_does_not_look_for_an_import_summary(): void {
		set_transient(
			'leanroles_import_result_' . get_current_user_id(),
			array( 'imported' => 99, 'skipped' => 0, 'created' => array(), 'errors' => array() ),
			MINUTE_IN_SECONDS
		);

		$_GET['message'] = 'created';

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'Tag created.', $html );
		$this->assertStringNotContainsString( '99 user(s) updated', $html );
	}

	public function test_an_import_notice_with_no_summary_left(): void {
		delete_transient( 'leanroles_import_result_' . get_current_user_id() );

		$_GET['message'] = 'imported';

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'Import finished.', $html );
		$this->assertStringNotContainsString( 'user(s) updated', $html );
	}

	public function test_an_import_with_no_file_reports_it(): void {
		$_FILES = array();

		$location = $this->capture_redirect(
			function () {
				$this->as_admin_request( array( 'leanroles_action' => 'import' ), 'leanroles_tags' );
				TagsPage::handle_actions();
			}
		);

		$this->assertStringContainsString( 'error=', $location );
		$this->assertStringContainsString( 'No+file', str_replace( '%20', '+', (string) $location ) );
	}

	public function test_the_users_column_links_and_counts(): void {
		$this->make_tag( 'gold' );

		foreach ( self::factory()->user->create_many( 2 ) as $id ) {
			Store::add( $id, 'gold' );
		}

		$html = $this->capture_output( array( TagsPage::class, 'render' ) );

		$this->assertStringContainsString( 'users.php?role=gold', str_replace( '&amp;', '&', $html ) );
		$this->assertMatchesRegularExpression( '/>2<\/a>/', $html );
	}

	// ------------------------------------------------------------ menu boot

	public function test_boot_attaches_the_menu_hooks(): void {
		Menu::boot();

		$this->assertNotFalse( has_action( 'admin_menu', array( Menu::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( Menu::class, 'enqueue' ) ) );
	}
}
