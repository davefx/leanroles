<?php
/**
 * The command paths that only run when something fails.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Cli\AuditCommand;
use LeanRoles\Cli\TagCommand;
use UserTags\Store;
use LeanRoles\Tests\CliTestCase;

class CliEdgeCasesTest extends CliTestCase {

	/** @var TagCommand */
	private $tag;

	/** @var AuditCommand */
	private $audit;

	public function set_up(): void {
		parent::set_up();

		$this->tag   = new TagCommand();
		$this->audit = new AuditCommand();
	}

	// ---------------------------------------------------------------- assign

	public function test_an_interrupted_assign_says_how_to_resume(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 4, array( 'role' => 'author' ) );
		sort( $ids );

		// Blow up part way through, the way a timeout or a fatal in someone
		// else's hook would.
		$explode_after = $ids[1];

		$saboteur = static function ( $user_id ) use ( $explode_after ) {
			if ( $user_id > $explode_after ) {
				throw new \RuntimeException( 'the database went away' );
			}
		};

		add_action( 'user_tags_added', $saboteur );

		$result = $this->run_command(
			$this->tag,
			'assign',
			array( 'gold' ),
			array( 'role' => 'author', 'batch-size' => 10 )
		);

		remove_action( 'user_tags_added', $saboteur );

		$this->assertCommandFailed( $result, 'the database went away' );
		$this->assertStringContainsString(
			'--resume-after=',
			$result['stderr'],
			'A half-finished conversion that cannot be resumed is worse than no command at all.'
		);
	}

	// ---------------------------------------------------------------- remove

	public function test_remove_warns_about_a_user_that_does_not_exist(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->tag, 'remove', array( 'gold' ), array( 'users' => '99999999' ) );

		$this->assertCommandSucceeded( $result );
		$this->assertStringContainsString( 'does not exist', $result['stderr'] );
	}

	// ---------------------------------------------------------------- export

	public function test_export_reports_a_write_that_fails(): void {
		$this->make_tag( 'gold' );
		Store::add( self::factory()->user->create(), 'gold' );

		// The parent directory is writable, but the target is a directory.
		$directory = sys_get_temp_dir() . '/lr-export-dir-' . uniqid();
		mkdir( $directory );

		$result = $this->run_command( $this->tag, 'export', array(), array( 'file' => $directory ) );

		rmdir( $directory );

		$this->assertCommandFailed( $result, 'Could not write' );
	}

	// ---------------------------------------------------------------- import

	public function test_import_caps_how_many_problems_it_prints(): void {
		$rows = array( 'user_login,tags' );

		for ( $i = 0; $i < 30; $i++ ) {
			$rows[] = "nobody_{$i},gold";
		}

		$file = tempnam( sys_get_temp_dir(), 'lr-import-' );
		file_put_contents( $file, implode( "\n", $rows ) . "\n" );

		$result = $this->run_command( $this->tag, 'import', array( $file ), array() );

		unlink( $file );

		$this->assertCommandSucceeded( $result );
		$this->assertStringContainsString(
			'more problems',
			$result['stderr'],
			'Truncating the list silently would read as "those were all of them".'
		);
	}

	// ----------------------------------------------------------------- audit

	public function test_findings_as_json(): void {
		add_role( 'lr_label', 'Label only', array( 'read' => true ) );

		$result = $this->run_command(
			$this->audit,
			'__invoke',
			array(),
			array( 'no-benchmark' => true, 'no-user-counts' => true, 'findings' => true, 'format' => 'json' )
		);

		$findings = json_decode( trim( $result['printed'] ), true );

		$this->assertIsArray( $findings );
		$this->assertContains( 'inert_roles', array_column( $findings, 'id' ) );

		remove_role( 'lr_label' );
	}

	public function test_the_summary_names_the_cache_backend(): void {
		$filter = static function ( $report ) {
			$report['stack']['dropin_present'] = true;
			$report['stack']['backends']       = array( 'Redis' );
			$report['stack']['mitigations']    = array( 'igbinary serializer' );

			return $report;
		};

		add_filter( 'leanroles_audit_report', $filter );

		$result = $this->run_command(
			$this->audit,
			'__invoke',
			array(),
			array( 'no-benchmark' => true, 'no-user-counts' => true )
		);

		remove_filter( 'leanroles_audit_report', $filter );

		$output = $this->all_output( $result );

		$this->assertStringContainsString( 'Redis', $output );
		$this->assertStringContainsString( 'igbinary serializer', $output );
	}

	public function test_the_summary_reports_an_unidentified_dropin(): void {
		$filter = static function ( $report ) {
			$report['stack']['dropin_present'] = true;
			$report['stack']['backends']       = array();

			return $report;
		};

		add_filter( 'leanroles_audit_report', $filter );

		$result = $this->run_command(
			$this->audit,
			'__invoke',
			array(),
			array( 'no-benchmark' => true, 'no-user-counts' => true )
		);

		remove_filter( 'leanroles_audit_report', $filter );

		$this->assertStringContainsString( 'backend not identified', $this->all_output( $result ) );
	}

	public function test_the_summary_reports_a_skipped_pairwise_pass(): void {
		$filter = static fn() => 2;

		add_filter( 'leanroles_pairwise_limit', $filter );

		$result = $this->run_command(
			$this->audit,
			'__invoke',
			array(),
			array( 'no-benchmark' => true, 'no-user-counts' => true )
		);

		remove_filter( 'leanroles_pairwise_limit', $filter );

		$this->assertStringContainsString( 'skipped', $this->all_output( $result ) );
	}
}
