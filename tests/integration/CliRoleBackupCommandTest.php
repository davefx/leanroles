<?php
/**
 * `wp leanroles role` and `wp leanroles backup`
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Cli\BackupCommand;
use LeanRoles\Cli\RoleCommand;
use LeanRoles\Support\Roles;
use LeanRoles\Tests\CliTestCase;

class CliRoleBackupCommandTest extends CliTestCase {

	/** @var RoleCommand */
	private $role;

	/** @var BackupCommand */
	private $backup;

	public function set_up(): void {
		parent::set_up();

		$this->role   = new RoleCommand();
		$this->backup = new BackupCommand();

		delete_option( Roles::BACKUP_OPTION );
	}

	// ------------------------------------------------------------ role list

	public function test_role_list(): void {
		$rows = $this->decode_rows(
			$this->run_command( $this->role, 'list_', array(), array( 'format' => 'json' ) )
		);

		$slugs = array_column( $rows, 'slug' );

		$this->assertContains( 'administrator', $slugs );
		$this->assertContains( 'subscriber', $slugs );
	}

	public function test_role_list_separates_granted_from_effective(): void {
		add_role( 'lr_label', 'Label only', array( 'read' => true, 'level_0' => true ) );

		$rows = $this->decode_rows(
			$this->run_command( $this->role, 'list_', array(), array( 'format' => 'json' ) )
		);

		foreach ( $rows as $row ) {
			if ( 'lr_label' === $row['slug'] ) {
				$this->assertSame( 2, $row['granted'] );
				$this->assertSame( 0, $row['effective'] );

				remove_role( 'lr_label' );

				return;
			}
		}

		$this->fail( 'lr_label was not listed.' );
	}

	public function test_role_list_counts_users(): void {
		add_role( 'lr_busy', 'Busy', array( 'read' => true ) );
		self::factory()->user->create_many( 2, array( 'role' => 'lr_busy' ) );

		$rows = $this->decode_rows(
			$this->run_command( $this->role, 'list_', array(), array( 'format' => 'json' ) )
		);

		$busy = array_values( array_filter( $rows, static fn( $r ) => 'lr_busy' === $r['slug'] ) );

		$this->assertSame( 2, $busy[0]['users'] );

		remove_role( 'lr_busy' );
	}

	// ---------------------------------------------------------- role delete

	public function test_role_delete_dry_run_changes_nothing(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true, 'upload_files' => true ) );
		$id = self::factory()->user->create( array( 'role' => 'lr_old' ) );

		$result = $this->run_command(
			$this->role,
			'delete',
			array( 'lr_old' ),
			array( 'reassign' => 'subscriber', 'dry-run' => true )
		);

		$this->assertCommandSucceeded( $result, 'nothing was changed' );
		$this->assertArrayHasKey( 'lr_old', Roles::stored_roles() );
		$this->assertContains( 'lr_old', (array) $this->fresh_user( $id )->roles );

		remove_role( 'lr_old' );
	}

	public function test_role_delete_reports_its_informational_safeguard(): void {
		// "This role grants N capabilities and M users carry it" — courtesy,
		// not analysis, and the report must say so rather than implying more.
		add_role( 'lr_old', 'Old', array( 'read' => true, 'upload_files' => true, 'edit_posts' => true ) );
		self::factory()->user->create_many( 3, array( 'role' => 'lr_old' ) );

		$output = $this->all_output(
			$this->run_command( $this->role, 'delete', array( 'lr_old' ), array( 'dry-run' => true ) )
		);

		$this->assertStringContainsString( '3 granted', $output );
		$this->assertStringContainsString( 'Users:           3', $output );
		$this->assertStringContainsString( 'is not analysed here', $output );

		remove_role( 'lr_old' );
	}

	public function test_role_delete_warns_when_nothing_is_reassigned(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true ) );

		$output = $this->all_output(
			$this->run_command( $this->role, 'delete', array( 'lr_old' ), array( 'dry-run' => true ) )
		);

		$this->assertStringContainsString( 'left with no role', $output );

		remove_role( 'lr_old' );
	}

	public function test_role_delete(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true ) );
		$id = self::factory()->user->create( array( 'role' => 'lr_old' ) );

		$result = $this->run_command(
			$this->role,
			'delete',
			array( 'lr_old' ),
			array( 'reassign' => 'subscriber', 'yes' => true )
		);

		$this->assertCommandSucceeded( $result, 'moved 1 user(s)' );
		$this->assertArrayNotHasKey( 'lr_old', Roles::stored_roles() );
		$this->assertContains( 'subscriber', (array) $this->fresh_user( $id )->roles );
	}

	public function test_role_delete_takes_a_restore_point(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true ) );

		$this->run_command( $this->role, 'delete', array( 'lr_old' ), array( 'yes' => true ) );

		$backups = Roles::backups();

		$this->assertNotEmpty( $backups );
		$this->assertSame( 'delete_role:lr_old', end( $backups )['reason'] );
	}

	public function test_role_delete_fails_on_an_unknown_role(): void {
		$result = $this->run_command( $this->role, 'delete', array( 'nope' ), array( 'yes' => true ) );

		$this->assertCommandFailed( $result, 'No role with the slug' );
	}

	public function test_role_delete_refuses_the_administrator(): void {
		$result = $this->run_command(
			$this->role,
			'delete',
			array( 'administrator' ),
			array( 'reassign' => 'editor', 'yes' => true )
		);

		$this->assertCommandFailed( $result, 'protected' );
		$this->assertArrayHasKey( 'administrator', Roles::stored_roles() );
	}

	// --------------------------------------------------------------- backup

	public function test_backup_create(): void {
		$result = $this->run_command( $this->backup, 'create', array(), array( 'reason' => 'before-surgery' ) );

		$this->assertCommandSucceeded( $result, 'Restore point' );

		$backups = Roles::backups();

		$this->assertCount( 1, $backups );
		$this->assertSame( 'before-surgery', $backups[0]['reason'] );
	}

	public function test_backup_list_is_newest_first(): void {
		$this->run_command( $this->backup, 'create', array(), array( 'reason' => 'first' ) );
		$this->run_command( $this->backup, 'create', array(), array( 'reason' => 'second' ) );

		$rows = $this->decode_rows(
			$this->run_command( $this->backup, 'list_', array(), array( 'format' => 'json' ) )
		);

		$this->assertSame( array( 'second', 'first' ), array_column( $rows, 'reason' ) );
	}

	public function test_backup_list_when_there_are_none(): void {
		$result = $this->run_command( $this->backup, 'list_', array(), array( 'format' => 'json' ) );

		$this->assertCommandSucceeded( $result, 'No restore points' );
	}

	public function test_backup_list_shows_a_truncated_hash(): void {
		$this->run_command( $this->backup, 'create', array(), array() );

		$rows = $this->decode_rows(
			$this->run_command( $this->backup, 'list_', array(), array( 'format' => 'json' ) )
		);

		$this->assertSame( 12, strlen( $rows[0]['sha256'] ) );
	}

	public function test_backup_restore(): void {
		$this->run_command( $this->backup, 'create', array(), array( 'reason' => 'before' ) );

		add_role( 'lr_temp', 'Temp', array( 'read' => true ) );
		$this->assertArrayHasKey( 'lr_temp', Roles::stored_roles() );

		$result = $this->run_command( $this->backup, 'restore', array(), array( 'yes' => true ) );

		$this->assertCommandSucceeded( $result, 'Role option restored' );
		$this->assertArrayNotHasKey( 'lr_temp', Roles::stored_roles() );
	}

	public function test_backup_restore_targets_an_id(): void {
		$this->run_command( $this->backup, 'create', array(), array( 'reason' => 'first' ) );
		$first = Roles::backups()[0]['id'];

		add_role( 'lr_temp', 'Temp', array( 'read' => true ) );
		$this->run_command( $this->backup, 'create', array(), array( 'reason' => 'second' ) );

		$this->run_command( $this->backup, 'restore', array(), array( 'to' => $first, 'yes' => true ) );

		$this->assertArrayNotHasKey( 'lr_temp', Roles::stored_roles() );
	}

	public function test_backup_restore_fails_with_no_restore_points(): void {
		$result = $this->run_command( $this->backup, 'restore', array(), array( 'yes' => true ) );

		$this->assertCommandFailed( $result, 'no restore points' );
	}

	public function test_backup_restore_fails_on_an_unknown_id(): void {
		$this->run_command( $this->backup, 'create', array(), array() );

		$result = $this->run_command( $this->backup, 'restore', array(), array( 'to' => 'nope', 'yes' => true ) );

		$this->assertCommandFailed( $result, 'does not exist' );
	}

	public function test_backup_restore_refuses_a_tampered_restore_point(): void {
		$this->run_command( $this->backup, 'create', array(), array() );

		$backups             = Roles::backups();
		$backups[0]['value'] = serialize( array( 'attacker' => array( 'name' => 'x', 'capabilities' => array( 'manage_options' => true ) ) ) );
		update_option( Roles::BACKUP_OPTION, $backups, false );

		$result = $this->run_command( $this->backup, 'restore', array(), array( 'yes' => true ) );

		$this->assertCommandFailed( $result, 'integrity check' );
		$this->assertArrayNotHasKey( 'attacker', Roles::stored_roles() );
	}
}
