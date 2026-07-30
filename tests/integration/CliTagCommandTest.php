<?php
/**
 * `wp leanroles tag`
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Cli\TagCommand;
use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;
use LeanRoles\Tests\CliTestCase;

class CliTagCommandTest extends CliTestCase {

	/** @var TagCommand */
	private $command;

	public function set_up(): void {
		parent::set_up();

		$this->command = new TagCommand();
	}

	// --------------------------------------------------------------- create

	public function test_create(): void {
		$result = $this->run_command( $this->command, 'create', array( 'gold' ), array( 'name' => 'Gold tier' ) );

		$this->assertCommandSucceeded( $result, 'Created tag "gold"' );

		$this->reset_plugin_state();

		$this->assertTrue( Catalogue::has( 'gold' ) );
		$this->assertSame( 'Gold tier', Taxonomy::get_by_slug( 'gold' )->name );
	}

	public function test_create_carries_every_option(): void {
		$this->run_command(
			$this->command,
			'create',
			array( 'gold' ),
			array(
				'name'        => 'Gold',
				'description' => 'Spends a lot',
				'color'       => '#ffcc00',
				'legacy-role' => 'old_gold',
			)
		);

		$this->reset_plugin_state();
		$term = Taxonomy::get_by_slug( 'gold' );

		$this->assertSame( 'Spends a lot', $term->description );
		$this->assertSame( '#ffcc00', get_term_meta( $term->term_id, Taxonomy::META_COLOR, true ) );
		$this->assertSame( 'old_gold', get_term_meta( $term->term_id, Taxonomy::META_LEGACY, true ) );
	}

	public function test_create_defaults_the_name_to_the_slug(): void {
		$this->run_command( $this->command, 'create', array( 'gold' ) );

		$this->reset_plugin_state();

		$this->assertSame( 'gold', Taxonomy::get_by_slug( 'gold' )->name );
	}

	public function test_create_fails_on_a_duplicate(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->command, 'create', array( 'gold' ) );

		$this->assertCommandFailed( $result, 'already exists' );
	}

	public function test_create_fails_on_a_role_slug(): void {
		$result = $this->run_command( $this->command, 'create', array( 'editor' ) );

		$this->assertCommandFailed( $result, 'already a real role' );
	}

	// --------------------------------------------------------------- delete

	public function test_delete(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->command, 'delete', array( 'gold' ), array( 'yes' => true ) );

		$this->assertCommandSucceeded( $result, 'Deleted tag "gold"' );

		$this->reset_plugin_state();

		$this->assertNull( Taxonomy::get_by_slug( 'gold' ) );
	}

	public function test_delete_fails_on_an_unknown_tag(): void {
		$result = $this->run_command( $this->command, 'delete', array( 'nope' ), array( 'yes' => true ) );

		$this->assertCommandFailed( $result, 'No tag with the slug' );
	}

	public function test_delete_states_the_blast_radius_before_asking(): void {
		$this->make_tag( 'gold' );

		foreach ( self::factory()->user->create_many( 3 ) as $id ) {
			Store::add( $id, 'gold' );
		}

		$result = $this->run_command( $this->command, 'delete', array( 'gold' ), array( 'yes' => true ) );

		$this->assertStringContainsString( '3 user(s)', $this->all_output( $result ) );
	}

	// ----------------------------------------------------------------- list

	public function test_list(): void {
		$this->make_tag( 'gold', array( 'name' => 'Gold' ) );
		$this->make_tag( 'wholesale', array( 'name' => 'Wholesale' ) );

		$result = $this->run_command( $this->command, 'list_', array(), array( 'format' => 'json' ) );

		$this->assertCommandSucceeded( $result );

		$rows = $this->decode_rows( $result );

		$this->assertSame( array( 'gold', 'wholesale' ), array_column( $rows, 'slug' ) );
		$this->assertSame( array( 'Gold', 'Wholesale' ), array_column( $rows, 'name' ) );
	}

	public function test_list_reports_user_counts(): void {
		$this->make_tag( 'gold' );
		Store::add( self::factory()->user->create(), 'gold' );

		$result = $this->run_command( $this->command, 'list_', array(), array( 'format' => 'json' ) );

		$rows = $this->decode_rows( $result );

		$this->assertSame( 1, $rows[0]['users'] );
	}

	public function test_list_as_ids(): void {
		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		$result = $this->run_command( $this->command, 'list_', array(), array( 'format' => 'ids' ) );

		$this->assertStringContainsString( 'gold wholesale', $this->all_output( $result ) );
	}

	public function test_list_can_rebuild_the_catalogue_first(): void {
		$this->make_tag( 'gold' );
		delete_option( Catalogue::OPTION );
		$this->reset_plugin_state();

		$result = $this->run_command( $this->command, 'list_', array(), array( 'format' => 'json', 'rebuild' => true ) );

		$this->assertStringContainsString( 'rebuilt', $this->all_output( $result ) );
		$this->assertNotFalse( get_option( Catalogue::OPTION, false ) );
	}

	public function test_list_when_there_are_no_tags(): void {
		$result = $this->run_command( $this->command, 'list_', array(), array( 'format' => 'json' ) );

		$this->assertCommandSucceeded( $result );
		$this->assertSame( array(), $this->decode_rows( $result ) );
	}

	// --------------------------------------------------------------- assign

	public function test_assign_by_user_ids(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 3 );

		$result = $this->run_command(
			$this->command,
			'assign',
			array( 'gold' ),
			array( 'users' => implode( ',', $ids ) )
		);

		$this->assertCommandSucceeded( $result, 'Tagged 3 user(s)' );

		foreach ( $ids as $id ) {
			$this->assertSame( array( 'gold' ), Store::get_tags( $id ) );
		}
	}

	public function test_assign_by_role(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 5, array( 'role' => 'author' ) );

		$result = $this->run_command(
			$this->command,
			'assign',
			array( 'gold' ),
			array( 'role' => 'author', 'batch-size' => 2 )
		);

		$this->assertCommandSucceeded( $result, 'Tagged 5 user(s)' );

		foreach ( $ids as $id ) {
			$this->assertSame( array( 'gold' ), Store::get_tags( $id ) );
		}
	}

	public function test_assign_by_role_reports_the_last_id_for_resuming(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 3, array( 'role' => 'author' ) );

		$result = $this->run_command(
			$this->command,
			'assign',
			array( 'gold' ),
			array( 'role' => 'author', 'batch-size' => 1 )
		);

		$this->assertStringContainsString(
			'Last user id ' . max( $ids ),
			$this->all_output( $result ),
			'A run that dies half way has to be resumable, so it has to say where it got to.'
		);
	}

	public function test_assign_can_resume(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 4, array( 'role' => 'author' ) );
		sort( $ids );

		$this->run_command(
			$this->command,
			'assign',
			array( 'gold' ),
			array( 'role' => 'author', 'resume-after' => $ids[1] )
		);

		$this->assertSame( array(), Store::get_tags( $ids[0] ) );
		$this->assertSame( array(), Store::get_tags( $ids[1] ) );
		$this->assertSame( array( 'gold' ), Store::get_tags( $ids[2] ) );
		$this->assertSame( array( 'gold' ), Store::get_tags( $ids[3] ) );
	}

	public function test_assign_fails_on_an_unknown_tag(): void {
		$result = $this->run_command( $this->command, 'assign', array( 'nope' ), array( 'role' => 'author' ) );

		$this->assertCommandFailed( $result, 'Create it first' );
	}

	public function test_assign_fails_on_an_unknown_role(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->command, 'assign', array( 'gold' ), array( 'role' => 'no_such_role' ) );

		$this->assertCommandFailed( $result, 'No role with the slug' );
	}

	public function test_assign_fails_with_neither_selector(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->command, 'assign', array( 'gold' ) );

		$this->assertCommandFailed( $result, 'Give either --role or --users' );
	}

	public function test_assign_warns_about_a_user_that_does_not_exist(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->command, 'assign', array( 'gold' ), array( 'users' => '99999999' ) );

		$this->assertCommandSucceeded( $result );
		$this->assertStringContainsString( 'does not exist', $result['stderr'] );
		$this->assertStringContainsString( 'Tagged 0 user(s)', $this->all_output( $result ) );
	}

	public function test_assign_by_role_on_an_empty_role(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->command, 'assign', array( 'gold' ), array( 'role' => 'contributor' ) );

		$this->assertCommandSucceeded( $result, 'Tagged 0 user(s)' );
	}

	// --------------------------------------------------------------- remove

	public function test_remove_by_ids(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 2 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		$result = $this->run_command(
			$this->command,
			'remove',
			array( 'gold' ),
			array( 'users' => implode( ',', $ids ) )
		);

		$this->assertCommandSucceeded( $result, 'Removed "gold" from 2 user(s)' );

		foreach ( $ids as $id ) {
			$this->assertSame( array(), Store::get_tags( $id ) );
		}
	}

	public function test_remove_all(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 3 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		$result = $this->run_command( $this->command, 'remove', array( 'gold' ), array( 'all' => true, 'yes' => true ) );

		$this->assertCommandSucceeded( $result, 'from 3 user(s)' );

		foreach ( $ids as $id ) {
			$this->assertSame( array(), Store::get_tags( $id ) );
		}
	}

	public function test_remove_fails_without_a_selector(): void {
		$this->make_tag( 'gold' );

		$result = $this->run_command( $this->command, 'remove', array( 'gold' ) );

		$this->assertCommandFailed( $result, 'Give either --users or --all' );
	}

	public function test_remove_fails_on_an_unknown_tag(): void {
		$result = $this->run_command( $this->command, 'remove', array( 'nope' ), array( 'all' => true, 'yes' => true ) );

		$this->assertCommandFailed( $result, 'No tag with the slug' );
	}

	// ---------------------------------------------------------------- users

	public function test_users(): void {
		$this->make_tag( 'gold' );

		$id = self::factory()->user->create( array( 'user_login' => 'goldie', 'role' => 'author' ) );
		Store::add( $id, 'gold' );

		$result = $this->run_command( $this->command, 'users', array( 'gold' ), array( 'format' => 'json' ) );

		$this->assertCommandSucceeded( $result );

		$rows = $this->decode_rows( $result );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'goldie', $rows[0]['user_login'] );
	}

	public function test_users_excludes_the_tag_from_the_roles_column(): void {
		$this->make_tag( 'gold' );

		$id = self::factory()->user->create( array( 'role' => 'author' ) );
		Store::add( $id, 'gold' );

		$result = $this->run_command( $this->command, 'users', array( 'gold' ), array( 'format' => 'json' ) );

		$this->assertSame(
			'author',
			$this->decode_rows( $result )[0]['roles'],
			'The tag is the thing being listed; repeating it in the roles column is noise.'
		);
	}

	public function test_users_as_ids_and_count(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 2 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		$as_ids = $this->run_command( $this->command, 'users', array( 'gold' ), array( 'format' => 'ids' ) );
		$count  = $this->run_command( $this->command, 'users', array( 'gold' ), array( 'format' => 'count' ) );

		foreach ( $ids as $id ) {
			$this->assertStringContainsString( (string) $id, $this->all_output( $as_ids ) );
		}

		$this->assertStringContainsString( '2', $this->all_output( $count ) );
	}

	public function test_users_fails_on_an_unknown_tag(): void {
		$result = $this->run_command( $this->command, 'users', array( 'nope' ) );

		$this->assertCommandFailed( $result, 'No tag with the slug' );
	}

	// --------------------------------------------------------- export/import

	public function test_export_to_stdout(): void {
		$this->make_tag( 'gold' );
		Store::add( self::factory()->user->create( array( 'user_login' => 'goldie' ) ), 'gold' );

		$result = $this->run_command( $this->command, 'export', array(), array() );

		$this->assertCommandSucceeded( $result );
		$this->assertStringContainsString( 'goldie', $this->all_output( $result ) );
		$this->assertStringContainsString( 'user_id,user_login', $this->all_output( $result ) );
	}

	public function test_export_the_catalogue(): void {
		$this->make_tag( 'gold', array( 'name' => 'Gold' ) );

		$result = $this->run_command( $this->command, 'export', array(), array( 'what' => 'catalogue' ) );

		$this->assertStringContainsString( 'slug,name,description', $this->all_output( $result ) );
	}

	public function test_export_to_a_file(): void {
		$this->make_tag( 'gold' );
		Store::add( self::factory()->user->create(), 'gold' );

		$file = tempnam( sys_get_temp_dir(), 'lr-export-' );

		$result = $this->run_command( $this->command, 'export', array(), array( 'file' => $file ) );

		$this->assertCommandSucceeded( $result, 'Wrote 1 row(s)' );
		$this->assertStringContainsString( 'gold', (string) file_get_contents( $file ) );

		unlink( $file );
	}

	public function test_export_fails_on_an_unwritable_path(): void {
		$result = $this->run_command( $this->command, 'export', array(), array( 'file' => '/proc/nope/leanroles.csv' ) );

		$this->assertCommandFailed( $result, 'Could not write' );
	}

	public function test_import(): void {
		$this->make_tag( 'gold' );

		$id   = self::factory()->user->create();
		$file = tempnam( sys_get_temp_dir(), 'lr-import-' );

		file_put_contents( $file, "user_id,tags\n{$id},gold\n" );

		$result = $this->run_command( $this->command, 'import', array( $file ), array() );

		$this->assertCommandSucceeded( $result, '1 user(s) updated' );
		$this->assertSame( array( 'gold' ), Store::get_tags( $id ) );

		unlink( $file );
	}

	public function test_import_can_create_tags(): void {
		$id   = self::factory()->user->create();
		$file = tempnam( sys_get_temp_dir(), 'lr-import-' );

		file_put_contents( $file, "user_id,tags\n{$id},brand_new\n" );

		$result = $this->run_command( $this->command, 'import', array( $file ), array( 'create-tags' => true ) );

		$this->assertCommandSucceeded( $result, '1 tag(s) created' );

		$this->reset_plugin_state();

		$this->assertTrue( Catalogue::has( 'brand_new' ) );

		unlink( $file );
	}

	public function test_import_surfaces_problems_as_warnings(): void {
		$file = tempnam( sys_get_temp_dir(), 'lr-import-' );

		file_put_contents( $file, "user_login,tags\nnobody_at_all,gold\n" );

		$result = $this->run_command( $this->command, 'import', array( $file ), array() );

		$this->assertCommandSucceeded( $result );
		$this->assertStringContainsString( 'no matching user', $result['stderr'] );

		unlink( $file );
	}

	public function test_import_fails_on_an_unreadable_file(): void {
		$result = $this->run_command( $this->command, 'import', array( '/no/such/file.csv' ), array() );

		$this->assertCommandFailed( $result, 'Cannot read' );
	}

	// -------------------------------------------------------- rebuild-mirror

	public function test_rebuild_mirror(): void {
		$this->make_tag( 'gold' );

		$id = self::factory()->user->create();
		Store::add( $id, 'gold' );

		// Corrupt the mirror the way a bad import would.
		update_user_meta( $id, Store::mirror_key(), array( 'wholesale' ) );
		Store::flush_memo();

		$result = $this->run_command( $this->command, 'rebuild_mirror', array(), array() );

		$this->assertCommandSucceeded( $result, 'Rebuilt the mirror' );
		$this->assertSame( array( 'gold' ), Store::get_tags( $id ) );
	}

	public function test_rebuild_mirror_clears_a_stale_row(): void {
		$this->make_tag( 'gold' );

		$id = self::factory()->user->create();

		// A mirror row for a user who holds nothing in the taxonomy.
		update_user_meta( $id, Store::mirror_key(), array( 'gold' ) );
		Store::flush_memo();

		$this->run_command( $this->command, 'rebuild_mirror', array(), array() );

		$this->assertSame( array(), Store::get_tags( $id ) );
	}

	public function test_rebuild_mirror_with_no_tags(): void {
		$result = $this->run_command( $this->command, 'rebuild_mirror', array(), array() );

		$this->assertCommandSucceeded( $result, 'No tags exist' );
	}
}
