<?php
/**
 * `wp leanroles audit`
 *
 * The JSON output matters more than it looks: it is what lets somebody walk a
 * portfolio of sites with a shell loop and aggregate the results, so its shape
 * is part of the contract.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Audit\StructureProbe;
use LeanRoles\Cli\AuditCommand;
use LeanRoles\Tests\CliTestCase;

class CliAuditCommandTest extends CliTestCase {

	/** @var AuditCommand */
	private $command;

	public function set_up(): void {
		parent::set_up();

		$this->command = new AuditCommand();
	}

	private function run_audit( array $assoc_args = array() ): array {
		return $this->run_command(
			$this->command,
			'__invoke',
			array(),
			wp_parse_args( $assoc_args, array( 'no-benchmark' => true, 'no-user-counts' => true ) )
		);
	}

	// ----------------------------------------------------------------- json

	public function test_json_output_is_the_whole_report(): void {
		$result = $this->run_audit( array( 'format' => 'json' ) );

		$this->assertCommandSucceeded( $result );

		$report = json_decode( trim( $result['printed'] ), true );

		$this->assertIsArray( $report );

		foreach ( array( 'generated_at', 'site', 'size', 'structure', 'stack', 'bandwidth', 'findings' ) as $key ) {
			$this->assertArrayHasKey( $key, $report, "The aggregation contract includes {$key}." );
		}
	}

	public function test_json_carries_the_numbers_worth_aggregating(): void {
		$report = json_decode( trim( $this->run_audit( array( 'format' => 'json' ) )['printed'] ), true );

		$this->assertSame( 5, $report['structure']['role_count'] );
		$this->assertGreaterThan( 0, $report['size']['role_bytes'] );
		$this->assertGreaterThan( 0, $report['size']['autoload_bytes'] );
		$this->assertIsArray( $report['structure']['inert_roles'] );
	}

	public function test_json_identifies_the_site(): void {
		$report = json_decode( trim( $this->run_audit( array( 'format' => 'json' ) )['printed'] ), true );

		$this->assertSame( get_current_blog_id(), $report['site']['blog_id'] );
		$this->assertSame( is_multisite(), $report['site']['multisite'] );
	}

	public function test_yaml_output(): void {
		$result = $this->run_audit( array( 'format' => 'yaml' ) );

		$this->assertCommandSucceeded( $result );
		$this->assertStringContainsString( 'structure', $this->all_output( $result ) );
	}

	// -------------------------------------------------------------- summary

	public function test_the_default_summary(): void {
		$result = $this->run_audit();

		$this->assertCommandSucceeded( $result );

		$output = $this->all_output( $result );

		$this->assertStringContainsString( 'Role option', $output );
		$this->assertStringContainsString( 'Roles', $output );
		$this->assertStringContainsString( 'finding(s)', $output );
	}

	public function test_the_summary_reports_the_measurements_when_asked(): void {
		$result = $this->run_command(
			$this->command,
			'__invoke',
			array(),
			array( 'no-user-counts' => true )
		);

		$output = $this->all_output( $result );

		$this->assertStringContainsString( 'Unserialize, per request', $output );
		$this->assertStringContainsString( 'Resident memory', $output );
		$this->assertStringContainsString( 'Extra workers if removed', $output );
	}

	public function test_the_summary_says_when_user_counts_were_skipped(): void {
		$this->assertStringContainsString( 'not measured', $this->all_output( $this->run_audit() ) );
	}

	public function test_the_capacity_inputs_are_honoured(): void {
		$result = $this->run_command(
			$this->command,
			'__invoke',
			array(),
			array(
				'no-user-counts' => true,
				'server-ram-mb'  => 8192,
				'worker-rss-mb'  => 128,
			)
		);

		$output = $this->all_output( $result );

		$this->assertStringContainsString( '8,192', $output );
		$this->assertStringContainsString( '128', $output );
	}

	public function test_the_request_rate_reaches_the_bandwidth_projection(): void {
		$report = json_decode(
			trim( $this->run_audit( array( 'format' => 'json', 'requests-per-sec' => 250 ) )['printed'] ),
			true
		);

		$this->assertSame( 250, $report['bandwidth']['requests_per_sec'] );
	}

	// ---------------------------------------------------------------- roles

	public function test_the_roles_listing(): void {
		$result = $this->run_audit( array( 'roles' => true, 'format' => 'json' ) );

		$rows  = $this->decode_rows( $result );
		$slugs = array_column( $rows, 'slug' );

		$this->assertContains( 'administrator', $slugs );
		$this->assertContains( 'subscriber', $slugs );
	}

	public function test_the_roles_listing_is_heaviest_first(): void {
		$rows    = $this->decode_rows( $this->run_audit( array( 'roles' => true, 'format' => 'json' ) ) );
		$granted = array_column( $rows, 'granted' );
		$sorted  = $granted;

		rsort( $sorted );

		$this->assertSame( $sorted, $granted );
		$this->assertSame( 'administrator', $rows[0]['slug'] );
	}

	public function test_the_roles_listing_marks_the_inert_ones(): void {
		add_role( 'lr_label', 'Label only', array( 'read' => true, 'level_0' => true ) );

		$rows = $this->decode_rows( $this->run_audit( array( 'roles' => true, 'format' => 'json' ) ) );

		foreach ( $rows as $row ) {
			if ( 'lr_label' === $row['slug'] ) {
				$this->assertSame( 'yes', $row['inert'] );
				$this->assertSame( 0, $row['effective'] );

				remove_role( 'lr_label' );

				return;
			}
		}

		$this->fail( 'lr_label was not listed.' );
	}

	// ------------------------------------------------------------- findings

	public function test_the_findings_view(): void {
		add_role( 'lr_label', 'Label only', array( 'read' => true ) );

		$result = $this->run_audit( array( 'findings' => true ) );
		$output = $this->all_output( $result );

		$this->assertStringContainsString( 'grant no effective permission', $output );

		remove_role( 'lr_label' );
	}

	public function test_the_findings_view_keeps_the_orphan_caveat(): void {
		add_role( 'lr_odd', 'Odd', array( 'read' => true, 'acme_unknown_cap' => true ) );

		$output = $this->all_output( $this->run_audit( array( 'findings' => true ) ) );

		$this->assertStringContainsString( 'not mean orphaned', $output );

		remove_role( 'lr_odd' );
	}

	public function test_the_findings_view_truncates_long_item_lists(): void {
		$caps = array( 'read' => true );

		for ( $i = 0; $i < 40; $i++ ) {
			$caps[ 'acme_cap_' . $i ] = true;
		}

		add_role( 'lr_many', 'Many', $caps );

		$output = $this->all_output( $this->run_audit( array( 'findings' => true ) ) );

		$this->assertStringContainsString(
			'more.',
			$output,
			'A silent cut would read as "that is all of them".'
		);

		remove_role( 'lr_many' );
	}

	public function test_the_findings_view_when_there_is_nothing_to_say(): void {
		$filter = static function ( $report ) {
			$report['findings'] = array();

			return $report;
		};

		add_filter( 'leanroles_audit_report', $filter );
		$result = $this->run_audit( array( 'findings' => true ) );
		remove_filter( 'leanroles_audit_report', $filter );

		$this->assertStringContainsString( 'Nothing worth flagging', $this->all_output( $result ) );
	}

	// -------------------------------------------------------------- recount

	public function test_recount_bypasses_the_cache(): void {
		set_transient( StructureProbe::USER_COUNT_TRANSIENT, array( 'total_users' => 999999 ), HOUR_IN_SECONDS );

		$result = $this->run_audit( array( 'recount' => true ) );

		$this->assertStringContainsString( 'Recounted', $this->all_output( $result ) );
		$this->assertNotSame( 999999, get_transient( StructureProbe::USER_COUNT_TRANSIENT )['total_users'] );
	}

	// ------------------------------------------------------------ read-only

	public function test_the_command_changes_nothing(): void {
		$before = $this->mutable_state_fingerprint();

		$this->run_command( $this->command, '__invoke', array(), array( 'format' => 'json' ) );

		$this->assertSame( $before, $this->mutable_state_fingerprint() );
	}
}
