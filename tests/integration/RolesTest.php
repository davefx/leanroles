<?php
/**
 * Restore points and the one destructive primitive the plugin ships.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Support\Roles;
use LeanRoles\Tests\TestCase;

class RolesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( Roles::BACKUP_OPTION );
	}

	// --------------------------------------------------------------- reading

	public function test_stored_roles_reads_the_option_not_the_object(): void {
		$stored = Roles::stored_roles();

		$this->assertArrayHasKey( 'administrator', $stored );
		$this->assertArrayHasKey( 'subscriber', $stored );
	}

	public function test_stored_roles_does_not_include_tag_shims(): void {
		$this->make_tag( 'gold' );

		$this->assertArrayNotHasKey(
			'gold',
			Roles::stored_roles(),
			'A tag is registered in memory only; it must never appear in the stored option.'
		);
	}

	public function test_granted_caps_ignores_denied_ones(): void {
		$granted = Roles::granted_caps(
			array(
				'capabilities' => array(
					'read'         => true,
					'edit_posts'   => false,
					'upload_files' => true,
				),
			)
		);

		$this->assertSame( array( 'read', 'upload_files' ), $granted );
	}

	public function test_granted_caps_on_a_malformed_role(): void {
		$this->assertSame( array(), Roles::granted_caps( array() ) );
		$this->assertSame( array(), Roles::granted_caps( array( 'capabilities' => 'nonsense' ) ) );
	}

	// --------------------------------------------------------------- backups

	public function test_creating_a_backup(): void {
		$entry = Roles::create_backup( 'test-run' );

		$this->assertNotEmpty( $entry['id'] );
		$this->assertSame( 'test-run', $entry['reason'] );
		$this->assertGreaterThan( 0, $entry['bytes'] );
		$this->assertSame( hash( 'sha256', $entry['value'] ), $entry['sha256'] );
	}

	public function test_backups_accumulate_oldest_first(): void {
		Roles::create_backup( 'first' );
		Roles::create_backup( 'second' );

		$backups = Roles::backups();

		$this->assertCount( 2, $backups );
		$this->assertSame( 'first', $backups[0]['reason'] );
		$this->assertSame( 'second', $backups[1]['reason'] );
	}

	public function test_backups_are_capped(): void {
		for ( $i = 0; $i < Roles::BACKUP_LIMIT + 5; $i++ ) {
			Roles::create_backup( 'run-' . $i );
		}

		$backups = Roles::backups();

		$this->assertCount( Roles::BACKUP_LIMIT, $backups );
		$this->assertSame( 'run-' . ( Roles::BACKUP_LIMIT + 4 ), end( $backups )['reason'] );
	}

	public function test_restore_puts_the_option_back(): void {
		Roles::create_backup( 'before' );

		add_role( 'lr_gone_soon', 'Gone soon', array( 'read' => true ) );
		$this->assertArrayHasKey( 'lr_gone_soon', Roles::stored_roles() );

		$this->assertTrue( Roles::restore_backup() );

		$this->assertArrayNotHasKey( 'lr_gone_soon', Roles::stored_roles() );
	}

	public function test_restore_targets_a_specific_id(): void {
		$first = Roles::create_backup( 'first' );

		add_role( 'lr_added', 'Added', array( 'read' => true ) );
		Roles::create_backup( 'second' );

		$this->assertTrue( Roles::restore_backup( $first['id'] ) );
		$this->assertArrayNotHasKey( 'lr_added', Roles::stored_roles() );
	}

	public function test_restore_refuses_a_tampered_backup(): void {
		Roles::create_backup( 'tampered' );

		$backups = Roles::backups();
		$backups[0]['value'] = serialize( array( 'attacker' => array( 'name' => 'x', 'capabilities' => array( 'manage_options' => true ) ) ) );
		update_option( Roles::BACKUP_OPTION, $backups, false );

		$error = Roles::restore_backup();

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_corrupt_backup', $error->get_error_code() );
		$this->assertArrayNotHasKey( 'attacker', Roles::stored_roles() );
	}

	public function test_restore_with_no_backups(): void {
		$error = Roles::restore_backup();

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_no_backup', $error->get_error_code() );
	}

	public function test_restore_with_an_unknown_id(): void {
		Roles::create_backup( 'x' );

		$error = Roles::restore_backup( 'not-a-real-id' );

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_unknown_backup', $error->get_error_code() );
	}

	public function test_restore_refreshes_the_in_memory_roles(): void {
		Roles::create_backup( 'before' );

		add_role( 'lr_temp', 'Temp', array( 'read' => true ) );
		$this->assertTrue( wp_roles()->is_role( 'lr_temp' ) );

		Roles::restore_backup();

		$this->assertFalse(
			wp_roles()->is_role( 'lr_temp' ),
			'Restoring the option while WP_Roles still holds the old one would be a trap.'
		);
	}

	// ----------------------------------------------------------- delete_role

	public function test_delete_role_moves_its_users(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true, 'upload_files' => true ) );

		$ids = self::factory()->user->create_many( 3, array( 'role' => 'lr_old' ) );

		$moved = Roles::delete_role( 'lr_old', 'subscriber' );

		$this->assertSame( 3, $moved );
		$this->assertArrayNotHasKey( 'lr_old', Roles::stored_roles() );

		foreach ( $ids as $id ) {
			$user = $this->fresh_user( $id );

			$this->assertContains( 'subscriber', (array) $user->roles );
			$this->assertNotContains( 'lr_old', (array) $user->roles );
		}
	}

	public function test_delete_role_without_reassignment_leaves_users_roleless(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true ) );

		$id = self::factory()->user->create( array( 'role' => 'lr_old' ) );

		Roles::delete_role( 'lr_old' );

		$this->assertSame( array(), (array) $this->fresh_user( $id )->roles );
	}

	public function test_delete_role_takes_a_restore_point_first(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true ) );

		Roles::delete_role( 'lr_old', 'subscriber' );

		$backups = Roles::backups();

		$this->assertNotEmpty( $backups );
		$this->assertSame( 'delete_role:lr_old', end( $backups )['reason'] );

		// And the restore point genuinely contains the deleted role.
		$restored = maybe_unserialize( end( $backups )['value'] );

		$this->assertArrayHasKey( 'lr_old', $restored );
	}

	public function test_delete_role_refuses_an_unknown_role(): void {
		$error = Roles::delete_role( 'no_such_role' );

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_unknown_role', $error->get_error_code() );
	}

	public function test_delete_role_refuses_an_unknown_reassignment_target(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true ) );

		$error = Roles::delete_role( 'lr_old', 'no_such_role' );

		$this->assertWPError( $error );
		$this->assertArrayHasKey( 'lr_old', Roles::stored_roles(), 'Nothing should have been deleted.' );

		remove_role( 'lr_old' );
	}

	public function test_administrator_is_protected_by_default(): void {
		$error = Roles::delete_role( 'administrator', 'editor' );

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_protected_role', $error->get_error_code() );
		$this->assertArrayHasKey( 'administrator', Roles::stored_roles() );
	}

	public function test_the_protected_list_is_filterable(): void {
		add_role( 'lr_precious', 'Precious', array( 'read' => true ) );

		$filter = static fn( $slugs ) => array_merge( $slugs, array( 'lr_precious' ) );

		add_filter( 'leanroles_protected_roles', $filter );
		$error = Roles::delete_role( 'lr_precious' );
		remove_filter( 'leanroles_protected_roles', $filter );

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_protected_role', $error->get_error_code() );

		remove_role( 'lr_precious' );
	}

	public function test_delete_role_keeps_a_users_other_roles(): void {
		add_role( 'lr_old', 'Old', array( 'read' => true ) );

		$id   = self::factory()->user->create( array( 'role' => 'author' ) );
		$user = $this->fresh_user( $id );
		$user->add_role( 'lr_old' );

		Roles::delete_role( 'lr_old', 'subscriber' );

		$roles = (array) $this->fresh_user( $id )->roles;

		$this->assertContains( 'author', $roles );
		$this->assertContains( 'subscriber', $roles );
		$this->assertNotContains( 'lr_old', $roles );
	}

	public function test_delete_role_does_not_disturb_tags(): void {
		$this->make_tag( 'gold' );

		add_role( 'lr_old', 'Old', array( 'read' => true ) );

		$id = self::factory()->user->create( array( 'role' => 'lr_old' ) );
		\UserTags\Store::add( $id, 'gold' );

		Roles::delete_role( 'lr_old', 'subscriber' );

		$this->assertSame( array( 'gold' ), \UserTags\Store::get_tags( $id ) );
	}
}
