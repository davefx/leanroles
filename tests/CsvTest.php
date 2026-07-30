<?php
/**
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Catalogue;
use UserTags\Csv;
use UserTags\Store;
use UserTags\Tests\TestCase;

class CsvTest extends TestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->make_tag( 'gold', array( 'name' => 'Gold', 'description' => 'Big spenders' ) );
		$this->make_tag( 'wholesale' );

		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'tester',
				'user_email' => 'tester@example.org',
			)
		);
	}

	// --------------------------------------------------------------- export

	public function test_catalogue_export(): void {
		$rows = Csv::export_catalogue();

		$this->assertSame( array( 'slug', 'name', 'description', 'color', 'users' ), $rows[0] );

		$slugs = array_column( array_slice( $rows, 1 ), 0 );

		$this->assertContains( 'gold', $slugs );
	}

	public function test_assignment_export(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );

		$rows = Csv::export_assignments();

		$this->assertSame( array( 'user_id', 'user_login', 'user_email', 'tags' ), $rows[0] );
		$this->assertCount( 2, $rows );
		$this->assertSame( (string) $this->user_id, $rows[1][0] );
		$this->assertSame( 'tester', $rows[1][1] );
		$this->assertSame( 'gold;wholesale', $rows[1][3] );
	}

	public function test_untagged_users_are_not_exported(): void {
		self::factory()->user->create_many( 3 );

		$this->assertCount( 1, Csv::export_assignments() );
	}

	public function test_export_pages_through_large_sets(): void {
		$ids = self::factory()->user->create_many( 12 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		// A batch size smaller than the set exercises the paging loop.
		$rows = Csv::export_assignments( 5 );

		$this->assertCount( 13, $rows );
	}

	// --------------------------------------------------------------- import

	public function test_import_by_user_id(): void {
		$rows = array(
			array( 'user_id', 'tags' ),
			array( (string) $this->user_id, 'gold;wholesale' ),
		);

		$result = Csv::import_assignments( $rows );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( array( 'gold', 'wholesale' ), Store::get_tags( $this->user_id ) );
	}

	public function test_import_by_login(): void {
		$result = Csv::import_assignments(
			array(
				array( 'user_login', 'tags' ),
				array( 'tester', 'gold' ),
			)
		);

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_import_by_email(): void {
		$result = Csv::import_assignments(
			array(
				array( 'user_email', 'tags' ),
				array( 'tester@example.org', 'gold' ),
			)
		);

		$this->assertSame( 1, $result['imported'] );
	}

	public function test_import_accepts_commas_as_well_as_semicolons(): void {
		Csv::import_assignments(
			array(
				array( 'user_id', 'tags' ),
				array( (string) $this->user_id, 'gold,wholesale' ),
			)
		);

		$this->assertSame( array( 'gold', 'wholesale' ), Store::get_tags( $this->user_id ) );
	}

	public function test_import_adds_by_default(): void {
		Store::add( $this->user_id, 'wholesale' );

		Csv::import_assignments(
			array(
				array( 'user_id', 'tags' ),
				array( (string) $this->user_id, 'gold' ),
			)
		);

		$this->assertSame( array( 'gold', 'wholesale' ), Store::get_tags( $this->user_id ) );
	}

	public function test_import_can_replace(): void {
		Store::add( $this->user_id, 'wholesale' );

		Csv::import_assignments(
			array(
				array( 'user_id', 'tags' ),
				array( (string) $this->user_id, 'gold' ),
			),
			false,
			true
		);

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_import_reports_unknown_tags_without_creating_them(): void {
		$result = Csv::import_assignments(
			array(
				array( 'user_id', 'tags' ),
				array( (string) $this->user_id, 'brand_new' ),
			)
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'brand_new', $result['errors'][0] );
		$this->assertFalse( Catalogue::has( 'brand_new' ) );
	}

	public function test_import_can_create_tags(): void {
		$result = Csv::import_assignments(
			array(
				array( 'user_id', 'tags' ),
				array( (string) $this->user_id, 'brand_new' ),
			),
			true
		);

		$this->assertSame( array( 'brand_new' ), $result['created'] );
		$this->assertTrue( Catalogue::has( 'brand_new' ) );
		$this->assertSame( array( 'brand_new' ), Store::get_tags( $this->user_id ) );
	}

	public function test_import_skips_rows_with_no_matching_user(): void {
		$result = Csv::import_assignments(
			array(
				array( 'user_login', 'tags' ),
				array( 'nobody_here', 'gold' ),
			)
		);

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertStringContainsString( 'Line 2', $result['errors'][0] );
	}

	public function test_import_rejects_a_file_with_no_tags_column(): void {
		$result = Csv::import_assignments( array( array( 'user_id' ), array( '1' ) ) );

		$this->assertSame( 0, $result['imported'] );
		$this->assertStringContainsString( 'tags', $result['errors'][0] );
	}

	public function test_import_rejects_a_file_with_no_user_column(): void {
		$result = Csv::import_assignments( array( array( 'tags' ), array( 'gold' ) ) );

		$this->assertSame( 0, $result['imported'] );
		$this->assertStringContainsString( 'user_id', $result['errors'][0] );
	}

	public function test_headers_are_matched_case_insensitively(): void {
		$result = Csv::import_assignments(
			array(
				array( 'User_ID', ' Tags ' ),
				array( (string) $this->user_id, 'gold' ),
			)
		);

		$this->assertSame( 1, $result['imported'] );
	}

	// ----------------------------------------------------------- round trip

	public function test_a_full_round_trip(): void {
		$ids = self::factory()->user->create_many( 4 );

		foreach ( $ids as $i => $id ) {
			Store::add( $id, 0 === $i % 2 ? 'gold' : 'wholesale' );
		}

		$csv = Csv::to_string( Csv::export_assignments() );

		foreach ( $ids as $id ) {
			Store::set_tags( $id, array() );
		}

		$result = Csv::import_assignments( Csv::from_string( $csv ) );

		$this->assertSame( 4, $result['imported'] );

		foreach ( $ids as $i => $id ) {
			$this->assertSame(
				array( 0 === $i % 2 ? 'gold' : 'wholesale' ),
				Store::get_tags( $id )
			);
		}
	}

	public function test_serialization_handles_quotes_and_commas(): void {
		$csv  = Csv::to_string( array( array( 'a', 'b,c', 'says "hi"' ) ) );
		$rows = Csv::from_string( $csv );

		$this->assertSame( array( 'a', 'b,c', 'says "hi"' ), $rows[0] );
	}

	public function test_from_string_skips_blank_lines(): void {
		$rows = Csv::from_string( "a,b\n\nc,d\n" );

		$this->assertSame( array( array( 'a', 'b' ), array( 'c', 'd' ) ), $rows );
	}
}
