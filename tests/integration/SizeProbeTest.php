<?php
/**
 * The size numbers have to come from the database, not from re-serializing in
 * PHP. Re-serializing produces a different string from the one MySQL stores and
 * PHP unserializes, and the gap is exactly the sort of small dishonesty that
 * gets a performance plugin disbelieved.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Audit\SizeProbe;
use LeanRoles\Support\Roles;
use LeanRoles\Tests\TestCase;

class SizeProbeTest extends TestCase {

	public function test_it_reports_the_role_option_for_this_site(): void {
		global $wpdb;

		$this->assertSame( $wpdb->get_blog_prefix() . 'user_roles', SizeProbe::run()['option_name'] );
	}

	public function test_the_size_matches_what_mysql_stores(): void {
		global $wpdb;

		$expected = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s",
				Roles::option_name()
			)
		);

		$this->assertSame( $expected, SizeProbe::run()['role_bytes'] );
	}

	public function test_the_size_is_measured_not_recomputed(): void {
		// serialize(unserialize($x)) is not always $x — float precision, key
		// ordering, object markers. The probe must never take that shortcut.
		$raw = Roles::raw_option_value();

		$this->assertSame( strlen( $raw ), SizeProbe::run()['role_bytes'] );
	}

	public function test_it_grows_when_a_role_is_added(): void {
		$before = SizeProbe::run()['role_bytes'];

		$caps = array();

		for ( $i = 0; $i < 60; $i++ ) {
			$caps[ 'lr_cap_' . $i ] = true;
		}

		add_role( 'lr_fat', 'A fat role', $caps );

		$this->assertGreaterThan( $before, SizeProbe::run()['role_bytes'] );

		remove_role( 'lr_fat' );
	}

	public function test_the_autoload_total_covers_the_modern_vocabulary(): void {
		global $wpdb;

		// WordPress 6.6 replaced yes/no with a small vocabulary. An install
		// carrying a mix must be summed across all of them.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				'lr_probe_auto_on',
				str_repeat( 'x', 500 ),
				'auto-on'
			)
		);

		$report = SizeProbe::run();

		$wpdb->delete( $wpdb->options, array( 'option_name' => 'lr_probe_auto_on' ) );

		$this->assertGreaterThanOrEqual( 500, $report['autoload_bytes'] );
		$this->assertGreaterThan( 0, $report['autoload_count'] );
	}

	public function test_options_that_are_not_autoloaded_are_excluded(): void {
		$before = SizeProbe::run();

		update_option( 'lr_probe_off', str_repeat( 'y', 100000 ), false );

		$after = SizeProbe::run();

		$this->assertSame(
			$before['autoload_bytes'],
			$after['autoload_bytes'],
			'A non-autoloaded option costs nothing per request and must not be counted.'
		);

		delete_option( 'lr_probe_off' );
	}

	public function test_the_share_is_a_fraction_between_zero_and_one(): void {
		$report = SizeProbe::run();

		$this->assertGreaterThan( 0, $report['role_share'] );
		$this->assertLessThanOrEqual( 1, $report['role_share'] );
	}

	public function test_the_share_is_consistent_with_the_two_totals(): void {
		$report = SizeProbe::run();

		$this->assertEqualsWithDelta(
			$report['role_bytes'] / $report['autoload_bytes'],
			$report['role_share'],
			0.0000001
		);
	}

	public function test_the_heaviest_options_are_listed_largest_first(): void {
		$heaviest = SizeProbe::run()['heaviest'];

		$this->assertNotEmpty( $heaviest );
		$this->assertLessThanOrEqual( 10, count( $heaviest ) );

		$sizes  = array_column( $heaviest, 'bytes' );
		$sorted = $sizes;
		rsort( $sorted );

		$this->assertSame( $sorted, $sizes );
	}

	public function test_a_very_large_role_option_reaches_the_top_ten(): void {
		$caps = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$caps[ 'lr_bulk_cap_' . $i ] = true;
		}

		for ( $r = 0; $r < 10; $r++ ) {
			add_role( 'lr_bulk_' . $r, 'Bulk ' . $r, $caps );
		}

		$report = SizeProbe::run();

		$this->assertNotNull( $report['role_option_rank'] );
		$this->assertGreaterThanOrEqual( 1, $report['role_option_rank'] );

		for ( $r = 0; $r < 10; $r++ ) {
			remove_role( 'lr_bulk_' . $r );
		}
	}

	public function test_a_missing_role_option_reads_as_null_not_zero(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Roles::option_name() ) );
		wp_cache_delete( Roles::option_name(), 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$report = SizeProbe::run();

		$this->assertNull(
			$report['role_bytes'],
			'Absent and empty are different states, and the report says which one it found.'
		);
		$this->assertNull( $report['role_share'] );
	}
}
