<?php
/**
 * The auditor end to end — including the promise everything else rests on:
 * that running it changes nothing.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Audit\Auditor;
use LeanRoles\Audit\StructureProbe;
use LeanRoles\Tests\TestCase;

class AuditorTest extends TestCase {

	private function report( array $args = array() ): array {
		return Auditor::run(
			wp_parse_args(
				$args,
				array(
					'benchmark'   => false,
					'user_counts' => false,
				)
			)
		);
	}

	private function finding( array $report, string $id ): ?array {
		foreach ( $report['findings'] as $finding ) {
			if ( $finding['id'] === $id ) {
				return $finding;
			}
		}

		return null;
	}

	// -------------------------------------------------------- read-only vow

	public function test_running_the_audit_changes_nothing(): void {
		self::factory()->user->create_many( 3, array( 'role' => 'author' ) );
		add_role( 'lr_temp', 'Temp', array( 'read' => true, 'edit_posts' => true ) );

		$before = $this->mutable_state_fingerprint();

		Auditor::run( array( 'benchmark' => true, 'user_counts' => true ) );

		$after = $this->mutable_state_fingerprint();

		$this->assertSame(
			$before,
			$after,
			'The auditor must be installable on someone else\'s production site without asking permission.'
		);

		remove_role( 'lr_temp' );
	}

	public function test_the_audit_issues_no_writes_to_the_role_option(): void {
		global $wpdb;

		$writes = 0;

		$filter = static function ( $value, $option ) use ( &$writes, $wpdb ) {
			if ( $option === $wpdb->get_blog_prefix() . 'user_roles' ) {
				++$writes;
			}

			return $value;
		};

		add_filter( 'pre_update_option', $filter, 10, 2 );

		Auditor::run( array( 'benchmark' => true, 'user_counts' => true ) );

		remove_filter( 'pre_update_option', $filter, 10 );

		$this->assertSame( 0, $writes );
	}

	// ------------------------------------------------------------ structure

	public function test_the_report_has_the_documented_shape(): void {
		$report = $this->report();

		foreach ( array( 'generated_at', 'site', 'size', 'structure', 'stack', 'bandwidth', 'findings' ) as $key ) {
			$this->assertArrayHasKey( $key, $report );
		}

		foreach ( array( 'role_count', 'assignments', 'distinct_caps', 'level_assignments', 'inert_roles', 'unrecognised' ) as $key ) {
			$this->assertArrayHasKey( $key, $report['structure'] );
		}
	}

	public function test_it_counts_the_default_roles(): void {
		$report = $this->report();

		// administrator, editor, author, contributor, subscriber.
		$this->assertSame( 5, $report['structure']['role_count'] );
	}

	public function test_it_counts_a_role_that_was_just_added(): void {
		add_role( 'lr_temp', 'Temp', array( 'read' => true ) );

		$this->assertSame( 6, $this->report()['structure']['role_count'] );

		remove_role( 'lr_temp' );
	}

	public function test_it_finds_the_deprecated_levels(): void {
		$report = $this->report();

		$this->assertGreaterThan( 0, $report['structure']['level_assignments'] );
		$this->assertNotNull( $this->finding( $report, 'levels' ) );
	}

	public function test_it_flags_a_role_that_grants_nothing(): void {
		add_role( 'lr_label', 'Label only', array( 'read' => true, 'level_0' => true ) );

		$report = $this->report();

		$this->assertContains( 'lr_label', $report['structure']['inert_roles'] );

		$finding = $this->finding( $report, 'inert_roles' );

		$this->assertNotNull( $finding );
		$this->assertSame( 'warning', $finding['severity'] );
		$this->assertContains( 'lr_label', $finding['items'] );

		remove_role( 'lr_label' );
	}

	public function test_it_does_not_flag_a_role_that_grants_something(): void {
		add_role( 'lr_real', 'Real', array( 'read' => true, 'upload_files' => true ) );

		$this->assertNotContains( 'lr_real', $this->report()['structure']['inert_roles'] );

		remove_role( 'lr_real' );
	}

	public function test_it_finds_clones(): void {
		$caps = get_role( 'editor' )->capabilities;

		add_role( 'lr_ed1', 'Editor clone 1', $caps );
		add_role( 'lr_ed2', 'Editor clone 2', $caps );

		$report = $this->report();
		$finding = $this->finding( $report, 'clones' );

		$this->assertNotNull( $finding );

		$grouped = array();

		foreach ( $report['structure']['clone_groups'] as $group ) {
			$grouped = array_merge( $grouped, $group['roles'] );
		}

		$this->assertContains( 'lr_ed1', $grouped );
		$this->assertContains( 'lr_ed2', $grouped );
		$this->assertContains( 'editor', $grouped );

		remove_role( 'lr_ed1' );
		remove_role( 'lr_ed2' );
	}

	public function test_it_finds_subset_relationships(): void {
		add_role( 'lr_small', 'Small', array( 'read' => true, 'upload_files' => true ) );

		$pairs = $this->report()['structure']['subset_pairs'];
		$flat  = array_map( static fn( $p ) => $p['parent'] . '>' . $p['child'], $pairs );

		$this->assertContains( 'editor>lr_small', $flat );

		remove_role( 'lr_small' );
	}

	public function test_it_reports_ghost_roles(): void {
		add_role( 'lr_ghost', 'Nobody has this', array( 'read' => true, 'upload_files' => true ) );

		delete_transient( StructureProbe::USER_COUNT_TRANSIENT );

		$report = $this->report( array( 'user_counts' => true ) );

		$this->assertContains( 'lr_ghost', $report['structure']['ghost_roles'] );

		remove_role( 'lr_ghost' );
	}

	public function test_a_role_with_users_is_not_a_ghost(): void {
		add_role( 'lr_busy', 'Busy', array( 'read' => true, 'upload_files' => true ) );
		self::factory()->user->create( array( 'role' => 'lr_busy' ) );

		delete_transient( StructureProbe::USER_COUNT_TRANSIENT );

		$this->assertNotContains( 'lr_busy', $this->report( array( 'user_counts' => true ) )['structure']['ghost_roles'] );

		remove_role( 'lr_busy' );
	}

	public function test_unrecognised_capabilities(): void {
		add_role( 'lr_odd', 'Odd', array( 'read' => true, 'acme_do_the_thing' => true ) );

		$report = $this->report();

		$this->assertContains( 'acme_do_the_thing', $report['structure']['unrecognised'] );

		$finding = $this->finding( $report, 'unrecognised' );

		$this->assertNotNull( $finding );
		$this->assertStringContainsString(
			'not mean orphaned',
			$finding['detail'],
			'The caveat is not optional; it is the reason anyone believes the rest of the report.'
		);

		remove_role( 'lr_odd' );
	}

	public function test_denied_capabilities_are_counted_separately(): void {
		add_role( 'lr_denied', 'Denied', array( 'read' => true, 'edit_posts' => false ) );

		$rows = $this->report()['structure']['roles'];

		$this->assertSame( 2, $rows['lr_denied']['declared'] );
		$this->assertSame( 1, $rows['lr_denied']['granted'] );
		$this->assertSame( 1, $rows['lr_denied']['denied'] );

		remove_role( 'lr_denied' );
	}

	// ------------------------------------------------------------ safeguards

	public function test_the_pairwise_analysis_is_skipped_above_the_threshold(): void {
		$filter = static fn() => 2;

		add_filter( 'leanroles_pairwise_limit', $filter );
		$report = $this->report();
		remove_filter( 'leanroles_pairwise_limit', $filter );

		$this->assertTrue( $report['structure']['pairwise_skipped'] );
		$this->assertSame( array(), $report['structure']['clone_groups'] );
		$this->assertNull( $report['structure']['inheritance_saving'] );
		$this->assertNotNull( $this->finding( $report, 'pairwise_skipped' ) );
	}

	public function test_user_counts_are_cached(): void {
		delete_transient( StructureProbe::USER_COUNT_TRANSIENT );

		$first = StructureProbe::user_counts();

		$this->assertIsArray( get_transient( StructureProbe::USER_COUNT_TRANSIENT ) );

		self::factory()->user->create_many( 2, array( 'role' => 'author' ) );

		$second = StructureProbe::user_counts();

		$this->assertSame( $first['total_users'], $second['total_users'], 'The cache should still be serving.' );

		$forced = StructureProbe::user_counts( true );

		$this->assertGreaterThan( $first['total_users'], $forced['total_users'] );
	}

	// --------------------------------------------------------------- filter

	public function test_the_report_is_filterable(): void {
		$filter = static function ( $report ) {
			$report['findings'] = array();

			return $report;
		};

		add_filter( 'leanroles_audit_report', $filter );
		$report = $this->report();
		remove_filter( 'leanroles_audit_report', $filter );

		$this->assertSame( array(), $report['findings'] );
	}

	// ------------------------------------------------------------ benchmark

	public function test_the_benchmark_produces_real_numbers(): void {
		$report = Auditor::run( array( 'benchmark' => true, 'user_counts' => false ) );

		$this->assertTrue( $report['benchmark']['available'] );
		$this->assertGreaterThan( 0, $report['benchmark']['unserialize']['per_call'] );
		$this->assertGreaterThan( 0, $report['benchmark']['memory']['elements'] );
		$this->assertNotNull( $report['capacity'] );
	}

	public function test_the_pairwise_guard_keeps_a_large_role_set_quick(): void {
		// Well past the default threshold, so the quadratic pass is skipped.
		for ( $i = 0; $i < 250; $i++ ) {
			add_role( 'lr_bulk_' . $i, 'Bulk ' . $i, array( 'read' => true, 'cap_' . $i => true ) );
		}

		$started = microtime( true );
		$report  = Auditor::run( array( 'benchmark' => false, 'user_counts' => false ) );
		$elapsed = microtime( true ) - $started;

		$this->assertTrue( $report['structure']['pairwise_skipped'] );
		$this->assertSame( 255, $report['structure']['role_count'] );
		$this->assertLessThan( 5, $elapsed, 'The guard exists so this never runs long inside a page load.' );

		for ( $i = 0; $i < 250; $i++ ) {
			remove_role( 'lr_bulk_' . $i );
		}
	}

	public function test_the_benchmark_can_be_skipped(): void {
		$this->assertNull( $this->report()['benchmark'] );
		$this->assertNull( $this->report()['capacity'] );
	}
}
