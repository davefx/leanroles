<?php
/**
 * The public API is the part other plugins are meant to adopt, so its contract
 * matters more than anything else here.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Tests\TestCase;

class ApiTest extends TestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		$this->user_id = self::factory()->user->create();
	}

	public function test_every_documented_function_exists(): void {
		$documented = array(
			'leanroles_user_has_tag',
			'leanroles_get_user_tags',
			'leanroles_get_users_by_tag',
			'user_tags_exists',
			'leanroles_add_tag',
			'leanroles_remove_tag',
			'leanroles_register_tag',
			'leanroles_set_user_tags',
			'leanroles_get_tags',
			'leanroles_audit',
		);

		foreach ( $documented as $function ) {
			$this->assertTrue( function_exists( $function ), "{$function}() is part of the published API." );
		}
	}

	public function test_add_and_has(): void {
		$this->assertTrue( leanroles_add_tag( $this->user_id, 'gold' ) );
		$this->assertTrue( leanroles_user_has_tag( $this->user_id, 'gold' ) );
		$this->assertFalse( leanroles_user_has_tag( $this->user_id, 'wholesale' ) );
	}

	public function test_add_accepts_an_array(): void {
		leanroles_add_tag( $this->user_id, array( 'gold', 'wholesale' ) );

		$this->assertSame( array( 'gold', 'wholesale' ), leanroles_get_user_tags( $this->user_id ) );
	}

	public function test_remove(): void {
		leanroles_add_tag( $this->user_id, array( 'gold', 'wholesale' ) );

		$this->assertTrue( leanroles_remove_tag( $this->user_id, 'gold' ) );
		$this->assertSame( array( 'wholesale' ), leanroles_get_user_tags( $this->user_id ) );
	}

	public function test_set_replaces(): void {
		leanroles_add_tag( $this->user_id, 'gold' );

		$this->assertSame( array( 'wholesale' ), leanroles_set_user_tags( $this->user_id, array( 'wholesale' ) ) );
	}

	public function test_tag_exists(): void {
		$this->assertTrue( leanroles_tag_exists( 'gold' ) );
		$this->assertFalse( leanroles_tag_exists( 'no_such_tag' ) );
		$this->assertFalse( leanroles_tag_exists( 'editor' ), 'A role is not a tag.' );
	}

	public function test_get_users_by_tag(): void {
		$ids = self::factory()->user->create_many( 3 );

		foreach ( $ids as $id ) {
			leanroles_add_tag( $id, 'gold' );
		}

		$found = leanroles_get_users_by_tag( 'gold' );

		sort( $ids );
		sort( $found );

		$this->assertSame( $ids, $found );
	}

	public function test_register_tag(): void {
		$result = leanroles_register_tag( 'silver', array( 'name' => 'Silver' ) );

		$this->assertIsInt( $result );
		$this->assertTrue( leanroles_tag_exists( 'silver' ) );
	}

	public function test_register_tag_is_safe_to_call_repeatedly(): void {
		leanroles_register_tag( 'silver' );

		$second = leanroles_register_tag( 'silver' );

		$this->assertWPError( $second );
		$this->assertSame( 'user_tags_exists', $second->get_error_code() );
	}

	public function test_get_tags_returns_the_catalogue(): void {
		$catalogue = leanroles_get_tags();

		$this->assertArrayHasKey( 'gold', $catalogue );
		$this->assertArrayHasKey( 'term_id', $catalogue['gold'] );
	}

	public function test_writing_an_unknown_tag_reports_success_but_stores_nothing(): void {
		// set_tags() drops unknown slugs rather than erroring, so the boolean
		// wrapper is true while the effect is nil. Pinned so the behaviour is
		// deliberate rather than accidental.
		$this->assertTrue( leanroles_add_tag( $this->user_id, 'no_such_tag' ) );
		$this->assertSame( array(), leanroles_get_user_tags( $this->user_id ) );
	}

	public function test_writing_to_an_unknown_user_returns_false(): void {
		$this->assertFalse( leanroles_add_tag( 99999999, 'gold' ) );
	}

	public function test_audit_returns_a_report(): void {
		$report = leanroles_audit( array( 'benchmark' => false, 'user_counts' => false ) );

		$this->assertArrayHasKey( 'size', $report );
		$this->assertArrayHasKey( 'structure', $report );
		$this->assertArrayHasKey( 'findings', $report );
	}

	public function test_the_tag_actions_fire_for_api_callers(): void {
		$added = 0;

		add_action( 'user_tags_added', static function () use ( &$added ) {
			++$added;
		} );

		leanroles_add_tag( $this->user_id, 'gold' );

		$this->assertSame( 1, $added );
	}
}
