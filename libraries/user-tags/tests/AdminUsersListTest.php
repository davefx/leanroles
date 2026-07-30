<?php
/**
 * The users list: column, filter links, bulk assignment.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Admin\Badge;
use UserTags\Admin\UsersList;
use UserTags\Store;
use UserTags\Tests\TestCase;

class AdminUsersListTest extends TestCase {

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

	// --------------------------------------------------------------- column

	public function test_the_column_lands_after_role(): void {
		$columns = UsersList::add_column(
			array(
				'cb'    => '',
				'name'  => 'Name',
				'role'  => 'Role',
				'posts' => 'Posts',
			)
		);

		$this->assertSame(
			array( 'cb', 'name', 'role', 'user_tags', 'posts' ),
			array_keys( $columns )
		);
	}

	public function test_the_column_is_appended_when_there_is_no_role_column(): void {
		$columns = UsersList::add_column( array( 'cb' => '', 'name' => 'Name' ) );

		$this->assertSame( array( 'cb', 'name', 'user_tags' ), array_keys( $columns ) );
	}

	public function test_no_column_when_no_tags_exist(): void {
		\UserTags\Taxonomy::delete( 'gold' );
		\UserTags\Taxonomy::delete( 'wholesale' );
		$this->reset_plugin_state();

		$columns = UsersList::add_column( array( 'role' => 'Role' ) );

		$this->assertArrayNotHasKey( 'user_tags', $columns );
	}

	public function test_the_column_renders_a_badge_per_tag(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );

		$html = UsersList::render_column( '', 'user_tags', $this->user_id );

		$this->assertStringContainsString( 'Gold', $html );
		$this->assertStringContainsString( 'Wholesale', $html );
		$this->assertStringContainsString( 'user-tags-badge', $html );
	}

	public function test_the_badge_carries_the_tag_colour(): void {
		Store::add( $this->user_id, 'gold' );

		$html = UsersList::render_column( '', 'user_tags', $this->user_id );

		$this->assertStringContainsString( '#ffcc00', $html );
	}

	public function test_the_column_links_to_a_filtered_list(): void {
		Store::add( $this->user_id, 'gold' );

		$html = UsersList::render_column( '', 'user_tags', $this->user_id );

		$this->assertStringContainsString( 'role=gold', $html );
	}

	public function test_an_untagged_user_gets_a_dash(): void {
		$html = UsersList::render_column( '', 'user_tags', $this->user_id );

		$this->assertStringContainsString( '—', $html );
	}

	public function test_other_columns_are_passed_through_untouched(): void {
		$this->assertSame( 'original', UsersList::render_column( 'original', 'posts', $this->user_id ) );
	}

	// ---------------------------------------------------------------- views

	public function test_views_gain_a_link_per_populated_tag(): void {
		Store::add( $this->user_id, 'gold' );

		$views = UsersList::add_views( array( 'all' => '<a href="#">All</a>' ) );

		$this->assertArrayHasKey( 'user_tags_gold', $views );
		$this->assertStringContainsString( 'role=gold', $views['user_tags_gold'] );
		$this->assertStringContainsString( '(1)', $views['user_tags_gold'] );
	}

	public function test_empty_tags_get_no_link(): void {
		Store::add( $this->user_id, 'gold' );

		$views = UsersList::add_views( array() );

		$this->assertArrayHasKey( 'user_tags_gold', $views );
		$this->assertArrayNotHasKey( 'user_tags_wholesale', $views );
	}

	public function test_the_current_tag_is_marked(): void {
		Store::add( $this->user_id, 'gold' );

		$_REQUEST['role'] = 'gold';

		$views = UsersList::add_views( array() );

		$this->assertStringContainsString( 'aria-current="page"', $views['user_tags_gold'] );
	}

	public function test_views_are_untouched_when_no_tags_exist(): void {
		\UserTags\Taxonomy::delete( 'gold' );
		\UserTags\Taxonomy::delete( 'wholesale' );
		$this->reset_plugin_state();

		$this->assertSame( array( 'all' => 'x' ), UsersList::add_views( array( 'all' => 'x' ) ) );
	}

	// ----------------------------------------------------------------- bulk

	public function test_bulk_add(): void {
		$targets = self::factory()->user->create_many( 3 );

		$this->as_admin_request(
			array(
				'user_tags_bulk_add' => 'Add tag',
				'user_tags_bulk_tag' => 'gold',
				'users'              => $targets,
			),
			'user_tags_bulk',
			'user_tags_bulk_nonce'
		);

		$location = $this->capture_redirect( array( UsersList::class, 'handle_bulk' ) );

		$this->assertStringContainsString( 'user_tags_bulk=added', $location );
		$this->assertStringContainsString( 'user_tags_count=3', $location );

		foreach ( $targets as $id ) {
			$this->assertSame( array( 'gold' ), Store::get_tags( $id ) );
		}
	}

	public function test_bulk_remove(): void {
		$targets = self::factory()->user->create_many( 2 );

		foreach ( $targets as $id ) {
			Store::add( $id, array( 'gold', 'wholesale' ) );
		}

		$this->as_admin_request(
			array(
				'user_tags_bulk_remove' => 'Remove tag',
				'user_tags_bulk_tag'    => 'gold',
				'users'                 => $targets,
			),
			'user_tags_bulk',
			'user_tags_bulk_nonce'
		);

		$location = $this->capture_redirect( array( UsersList::class, 'handle_bulk' ) );

		$this->assertStringContainsString( 'user_tags_bulk=removed', $location );

		foreach ( $targets as $id ) {
			$this->assertSame( array( 'wholesale' ), Store::get_tags( $id ) );
		}
	}

	public function test_bulk_does_nothing_without_a_tag(): void {
		$this->as_admin_request(
			array(
				'user_tags_bulk_add' => 'Add tag',
				'user_tags_bulk_tag' => '',
				'users'              => array( $this->user_id ),
			),
			'user_tags_bulk',
			'user_tags_bulk_nonce'
		);

		$location = $this->capture_redirect( array( UsersList::class, 'handle_bulk' ) );

		$this->assertStringContainsString( 'user_tags_bulk=nothing', $location );
		$this->assertSame( array(), Store::get_tags( $this->user_id ) );
	}

	public function test_bulk_does_nothing_without_users(): void {
		$this->as_admin_request(
			array(
				'user_tags_bulk_add' => 'Add tag',
				'user_tags_bulk_tag' => 'gold',
			),
			'user_tags_bulk',
			'user_tags_bulk_nonce'
		);

		$location = $this->capture_redirect( array( UsersList::class, 'handle_bulk' ) );

		$this->assertStringContainsString( 'user_tags_bulk=nothing', $location );
	}

	public function test_bulk_ignores_an_unknown_tag(): void {
		$this->as_admin_request(
			array(
				'user_tags_bulk_add' => 'Add tag',
				'user_tags_bulk_tag' => 'not_a_tag',
				'users'              => array( $this->user_id ),
			),
			'user_tags_bulk',
			'user_tags_bulk_nonce'
		);

		$this->capture_redirect( array( UsersList::class, 'handle_bulk' ) );

		$this->assertSame( array(), Store::get_tags( $this->user_id ) );
	}

	public function test_bulk_is_a_no_op_when_no_button_was_pressed(): void {
		$this->as_admin_request( array( 'user_tags_bulk_tag' => 'gold' ) );

		$this->assertNull( $this->capture_redirect( array( UsersList::class, 'handle_bulk' ) ) );
	}

	public function test_bulk_refuses_a_user_without_promote_users(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_REQUEST = array(
			'user_tags_bulk_add' => 'Add tag',
			'user_tags_bulk_tag' => 'gold',
			'users'              => array( $this->user_id ),
		);

		$this->expectException( \WPDieException::class );

		UsersList::handle_bulk();
	}

	public function test_bulk_refuses_a_bad_nonce(): void {
		$this->as_admin_request(
			array(
				'user_tags_bulk_add'   => 'Add tag',
				'user_tags_bulk_tag'   => 'gold',
				'users'                => array( $this->user_id ),
				'user_tags_bulk_nonce' => 'obviously-wrong',
			)
		);

		$this->expectException( \WPDieException::class );

		UsersList::handle_bulk();
	}

	// --------------------------------------------------------------- notice

	public function test_the_success_notice(): void {
		$_GET = array(
			'user_tags_bulk'  => 'added',
			'user_tags_count' => '4',
			'user_tag'   => 'gold',
		);

		$html = $this->capture_output( array( UsersList::class, 'bulk_notice' ) );

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '4', $html );
		$this->assertStringContainsString( 'gold', $html );
	}

	public function test_the_removal_notice_reads_differently(): void {
		$_GET = array(
			'user_tags_bulk'  => 'removed',
			'user_tags_count' => '2',
			'user_tag'   => 'gold',
		);

		$html = $this->capture_output( array( UsersList::class, 'bulk_notice' ) );

		$this->assertStringContainsString( 'Removed', $html );
	}

	public function test_the_nothing_notice_is_a_warning(): void {
		$_GET = array( 'user_tags_bulk' => 'nothing' );

		$html = $this->capture_output( array( UsersList::class, 'bulk_notice' ) );

		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function test_no_notice_without_the_parameter(): void {
		$this->assertSame( '', $this->capture_output( array( UsersList::class, 'bulk_notice' ) ) );
	}
}
