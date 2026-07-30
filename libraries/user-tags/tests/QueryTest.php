<?php
/**
 * WP_User_Query has to find tagged users through the role argument, or every
 * plugin that filters users by role slug breaks the moment a role becomes a tag.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Store;
use UserTags\Tests\TestCase;

class QueryTest extends TestCase {

	private $gold = array();
	private $wholesale = array();
	private $both = array();
	private $plain = array();

	public function set_up(): void {
		parent::set_up();

		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );
		$this->make_tag( 'golden' );

		$this->gold      = self::factory()->user->create_many( 3, array( 'role' => 'subscriber' ) );
		$this->wholesale = self::factory()->user->create_many( 2, array( 'role' => 'subscriber' ) );
		$this->both      = self::factory()->user->create_many( 2, array( 'role' => 'author' ) );
		$this->plain     = self::factory()->user->create_many( 2, array( 'role' => 'subscriber' ) );

		foreach ( $this->gold as $id ) {
			Store::add( $id, 'gold' );
		}

		foreach ( $this->wholesale as $id ) {
			Store::add( $id, 'wholesale' );
		}

		foreach ( $this->both as $id ) {
			Store::add( $id, array( 'gold', 'wholesale' ) );
		}
	}

	private function ids( array $args ): array {
		$args['fields'] = 'ID';

		$ids = array_map( 'intval', get_users( $args ) );
		sort( $ids );

		return $ids;
	}

	private function sorted( array $ids ): array {
		$ids = array_map( 'intval', $ids );
		sort( $ids );

		return $ids;
	}

	// ------------------------------------------------------------------ role

	public function test_role_finds_tagged_users(): void {
		$this->assertSame(
			$this->sorted( array_merge( $this->gold, $this->both ) ),
			$this->ids( array( 'role' => 'gold' ) )
		);
	}

	public function test_role_as_an_array_is_an_and(): void {
		$this->assertSame(
			$this->sorted( $this->both ),
			$this->ids( array( 'role' => array( 'gold', 'wholesale' ) ) )
		);
	}

	public function test_a_real_role_still_works(): void {
		$this->assertSame(
			$this->sorted( $this->both ),
			$this->ids( array( 'role' => 'author' ) )
		);
	}

	public function test_a_real_role_and_a_tag_together(): void {
		$this->assertSame(
			$this->sorted( $this->both ),
			$this->ids( array( 'role' => array( 'author', 'gold' ) ) )
		);
	}

	public function test_a_real_role_that_excludes_the_tag_holders(): void {
		$this->assertSame(
			array(),
			$this->ids( array( 'role' => array( 'subscriber', 'author' ) ) )
		);
	}

	public function test_a_comma_separated_string_of_roles(): void {
		$this->assertSame(
			$this->sorted( $this->both ),
			$this->ids( array( 'role' => 'gold,wholesale' ) )
		);
	}

	// --------------------------------------------------------------- role__in

	public function test_role_in_is_an_or(): void {
		$this->assertSame(
			$this->sorted( array_merge( $this->gold, $this->wholesale, $this->both ) ),
			$this->ids( array( 'role__in' => array( 'gold', 'wholesale' ) ) )
		);
	}

	public function test_role_in_mixing_a_real_role_and_a_tag(): void {
		// Everyone with the author role, plus everyone tagged gold.
		$this->assertSame(
			$this->sorted( array_merge( $this->gold, $this->both ) ),
			$this->ids( array( 'role__in' => array( 'author', 'gold' ) ) )
		);
	}

	public function test_role_in_with_only_real_roles_is_untouched(): void {
		$this->assertSame(
			$this->sorted( $this->both ),
			$this->ids( array( 'role__in' => array( 'author' ) ) )
		);
	}

	// ----------------------------------------------------------- role__not_in

	public function test_role_not_in_excludes_tag_holders(): void {
		$found = $this->ids( array( 'role__not_in' => array( 'gold' ) ) );

		foreach ( array_merge( $this->gold, $this->both ) as $tagged ) {
			$this->assertNotContains( $tagged, $found );
		}

		foreach ( $this->wholesale as $untagged ) {
			$this->assertContains( $untagged, $found );
		}
	}

	public function test_role_not_in_mixing_a_role_and_a_tag(): void {
		$found = $this->ids( array( 'role__not_in' => array( 'gold', 'author' ) ) );

		foreach ( array_merge( $this->gold, $this->both ) as $excluded ) {
			$this->assertNotContains( $excluded, $found );
		}
	}

	// ----------------------------------------------------------- exact match

	public function test_a_slug_that_is_a_prefix_of_another_does_not_bleed(): void {
		$golden = self::factory()->user->create();
		Store::add( $golden, 'golden' );

		$found = $this->ids( array( 'role' => 'gold' ) );

		$this->assertNotContains(
			$golden,
			$found,
			'Searching for "gold" must not match the serialized slug "golden".'
		);

		$this->assertSame( array( $golden ), $this->ids( array( 'role' => 'golden' ) ) );
	}

	// ----------------------------------------------------------- composition

	public function test_an_existing_meta_query_is_preserved(): void {
		update_user_meta( $this->gold[0], 'lr_flag', 'yes' );

		$found = $this->ids(
			array(
				'role'       => 'gold',
				'meta_query' => array(
					array(
						'key'   => 'lr_flag',
						'value' => 'yes',
					),
				),
			)
		);

		$this->assertSame( array( (int) $this->gold[0] ), $found );
	}

	public function test_the_explicit_tag_argument(): void {
		$this->assertSame(
			$this->sorted( array_merge( $this->gold, $this->both ) ),
			$this->ids( array( 'user_tags_tag' => 'gold' ) )
		);
	}

	public function test_counting_works(): void {
		$query = new \WP_User_Query(
			array(
				'role'        => 'gold',
				'fields'      => 'ID',
				'number'      => 2,
				'count_total' => true,
			)
		);

		$this->assertCount( 2, $query->get_results() );
		$this->assertSame( 5, (int) $query->get_total() );
	}

	public function test_ordering_and_pagination_still_apply(): void {
		$page_one = $this->ids( array( 'role' => 'gold', 'number' => 2, 'orderby' => 'ID', 'order' => 'ASC' ) );

		$this->assertCount( 2, $page_one );
	}

	public function test_an_untagged_query_is_left_alone(): void {
		$this->assertSame(
			$this->sorted( array_merge( $this->gold, $this->wholesale, $this->plain ) ),
			$this->ids( array( 'role' => 'subscriber' ) )
		);
	}

	// -------------------------------------------------------- resume cursor

	public function test_the_resume_cursor_restricts_by_id(): void {
		$all = $this->ids( array( 'role' => 'gold' ) );
		sort( $all );

		$cutoff = $all[1];

		$after = $this->ids( array( 'role' => 'gold', 'user_tags_id_after' => $cutoff ) );

		$this->assertSame( array_slice( $all, 2 ), $after );
	}

	public function test_no_cursor_means_no_restriction(): void {
		$this->assertSame(
			$this->ids( array( 'role' => 'gold' ) ),
			$this->ids( array( 'role' => 'gold', 'user_tags_id_after' => 0 ) )
		);
	}
}
