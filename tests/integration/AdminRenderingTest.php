<?php
/**
 * The branches of the audit screen that only render when the site is in an
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
use LeanRoles\Audit\StructureProbe;
use LeanRoles\Support\Roles;
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

	public function test_boot_attaches_the_menu_hooks(): void {
		Menu::boot();

		$this->assertNotFalse( has_action( 'admin_menu', array( Menu::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( Menu::class, 'enqueue' ) ) );
	}
}
