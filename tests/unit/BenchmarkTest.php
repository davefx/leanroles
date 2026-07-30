<?php
/**
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Unit;

use LeanRoles\Audit\Benchmark;
use LeanRoles\Tests\TestCase;

class BenchmarkTest extends TestCase {

	public function test_it_reports_unavailable_for_an_empty_option(): void {
		$result = Benchmark::run( '' );

		$this->assertFalse( $result['available'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	public function test_it_measures_a_real_structure(): void {
		$roles = array();

		for ( $i = 0; $i < 40; $i++ ) {
			$caps = array();

			for ( $c = 0; $c < 35; $c++ ) {
				$caps[ 'cap_' . $c ] = true;
			}

			$roles[ 'role_' . $i ] = array(
				'name'         => 'Role ' . $i,
				'capabilities' => $caps,
			);
		}

		$serialized = serialize( $roles );
		$result     = Benchmark::run( $serialized );

		$this->assertTrue( $result['available'] );
		$this->assertSame( strlen( $serialized ), $result['bytes'] );

		$this->assertGreaterThan( 0, $result['unserialize']['iterations'] );
		$this->assertGreaterThan( 0, $result['unserialize']['per_call'] );
		$this->assertLessThan( 1, $result['unserialize']['per_call'], 'One unserialize should not take a second.' );

		// 40 roles x (2 keys + 35 caps) + 40 top-level entries.
		$this->assertSame( 40 + ( 40 * ( 2 + 35 ) ), $result['memory']['elements'] );

		$this->assertGreaterThan( 0, $result['memory']['bytes'] );
		$this->assertGreaterThan(
			1,
			$result['memory']['ratio'],
			'A PHP hashtable always costs more resident than its serialized form; if this ever fails, the measurement is broken.'
		);
	}

	public function test_the_iteration_count_is_filterable(): void {
		$filter = static fn() => 7;

		add_filter( 'leanroles_benchmark_iterations', $filter );
		$result = Benchmark::run( serialize( array( 'a' => 1 ) ) );
		remove_filter( 'leanroles_benchmark_iterations', $filter );

		$this->assertSame( 7, $result['unserialize']['iterations'] );
	}

	public function test_the_measurement_does_not_leak_its_anchor(): void {
		$before = memory_get_usage();

		for ( $i = 0; $i < 5; $i++ ) {
			Benchmark::run( serialize( array_fill( 0, 2000, 'x' ) ) );
		}

		$growth = memory_get_usage() - $before;

		$this->assertLessThan(
			512 * 1024,
			$growth,
			'Benchmark::run() must release the structure it measured; the anchor exists only to survive the reading.'
		);
	}

	public function test_capacity_arithmetic(): void {
		// 4 GB pool, 64 MB workers, and the option costs 16 MB of each.
		$capacity = Benchmark::capacity( 16 * 1024 * 1024, 4096, 64 );

		$this->assertSame( 64, $capacity['workers_now'] );      // 4096 / 64
		$this->assertSame( 85, $capacity['workers_leaner'] );   // 4096 / 48
		$this->assertSame( 21, $capacity['extra_workers'] );
	}

	public function test_capacity_never_reports_a_loss(): void {
		// A footprint larger than the worker itself is nonsense; it must not
		// produce a negative headroom figure.
		$capacity = Benchmark::capacity( 999 * 1024 * 1024, 1024, 32 );

		$this->assertGreaterThanOrEqual( 0, $capacity['extra_workers'] );
	}

	public function test_bandwidth_arithmetic(): void {
		$bandwidth = Benchmark::bandwidth( 1024 * 1024, 20 );

		$this->assertSame( 20 * 1024 * 1024, $bandwidth['bytes_per_sec'] );
		$this->assertEqualsWithDelta( 1687.5, $bandwidth['gb_per_day'], 0.1 );
	}

	public function test_bandwidth_at_zero_traffic(): void {
		$bandwidth = Benchmark::bandwidth( 5000, 0 );

		$this->assertSame( 0, $bandwidth['bytes_per_sec'] );
		$this->assertSame( 0.0, $bandwidth['gb_per_day'] );
	}

	public function test_negative_request_rates_are_clamped(): void {
		$this->assertSame( 0, Benchmark::bandwidth( 5000, -10 )['bytes_per_sec'] );
	}
}
