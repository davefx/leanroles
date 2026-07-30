<?php
/**
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Unit;

use LeanRoles\Support\Format;
use LeanRoles\Tests\TestCase;

class FormatTest extends TestCase {

	public function test_null_bytes_render_as_a_dash(): void {
		$this->assertSame( '—', Format::bytes( null ) );
	}

	public function test_bytes_switch_units_at_the_right_thresholds(): void {
		$this->assertStringContainsString( 'byte', Format::bytes( 512 ) );
		$this->assertStringContainsString( 'KB', Format::bytes( 1024 ) );
		$this->assertStringContainsString( 'KB', Format::bytes( 1024 * 1024 - 1 ) );
		$this->assertStringContainsString( 'MB', Format::bytes( 1024 * 1024 ) );
	}

	public function test_singular_and_plural_bytes(): void {
		$this->assertSame( '1 byte', Format::bytes( 1 ) );
		$this->assertSame( '2 bytes', Format::bytes( 2 ) );
		$this->assertSame( '0 bytes', Format::bytes( 0 ) );
	}

	public function test_duration_switches_units(): void {
		$this->assertSame( '—', Format::duration( null ) );
		$this->assertStringContainsString( 'µs', Format::duration( 0.0000005 ) );
		$this->assertStringContainsString( 'ms', Format::duration( 0.05 ) );
		$this->assertStringContainsString( 's', Format::duration( 2.5 ) );
	}

	public function test_microsecond_boundary(): void {
		// Exactly 1 ms is not sub-millisecond.
		$this->assertStringContainsString( 'ms', Format::duration( 0.001 ) );
		$this->assertStringContainsString( 'µs', Format::duration( 0.0009 ) );
	}

	public function test_percent(): void {
		$this->assertSame( '25.0%', Format::percent( 25, 100 ) );
		$this->assertSame( '33.3%', Format::percent( 1, 3 ) );
		$this->assertSame( '0.0%', Format::percent( 0, 100 ) );
	}

	public function test_percent_refuses_to_divide_by_zero(): void {
		$this->assertSame( '—', Format::percent( 5, 0 ) );
		$this->assertSame( '—', Format::percent( 5, -1 ) );
	}
}
