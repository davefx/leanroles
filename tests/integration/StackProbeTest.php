<?php
/**
 * The stack probe decides the tone of the whole report, so what it claims and
 * — more importantly — what it refuses to claim is worth pinning down.
 *
 * The drop-in is never written into the live wp-content: a malformed
 * object-cache.php would be loaded on the next bootstrap and take the install
 * with it. The path is injected instead.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Audit\StackProbe;
use LeanRoles\Tests\TestCase;

class StackProbeTest extends TestCase {

	/** @var string[] */
	private $temp_files = array();

	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		$this->temp_files = array();

		parent::tear_down();
	}

	/**
	 * Write a throwaway file that looks like a drop-in.
	 */
	private function fake_dropin( string $source ): string {
		$path = tempnam( sys_get_temp_dir(), 'lr-dropin-' );

		file_put_contents( $path, $source );

		$this->temp_files[] = $path;

		return $path;
	}

	public function test_no_dropin(): void {
		$report = StackProbe::run( sys_get_temp_dir() . '/definitely-not-here-' . uniqid() . '.php' );

		$this->assertFalse( $report['dropin_present'] );
		$this->assertSame( array(), $report['backends'] );
		$this->assertNotEmpty( $report['notes'] );
		$this->assertStringContainsString( 'No object-cache.php', $report['notes'][0] );
	}

	public function test_it_identifies_redis(): void {
		$path = $this->fake_dropin( '<?php class WP_Object_Cache { public function __construct() { $this->redis = new Redis(); } }' );

		$report = StackProbe::run( $path );

		$this->assertTrue( $report['dropin_present'] );
		$this->assertContains( 'Redis', $report['backends'] );
	}

	public function test_it_identifies_memcached(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php $mc = new Memcached();' ) );

		$this->assertContains( 'Memcached', $report['backends'] );
	}

	public function test_it_identifies_apcu(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php apcu_fetch( "key" ); apcu_store( "k", 1 );' ) );

		$this->assertContains( 'APCu', $report['backends'] );
	}

	public function test_it_notices_igbinary(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php $v = igbinary_serialize( $data );' ) );

		$this->assertContains( 'igbinary serializer', $report['mitigations'] );
	}

	public function test_it_notices_compression(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php $v = gzcompress( $data );' ) );

		$this->assertContains( 'value compression', $report['mitigations'] );
	}

	public function test_it_tones_down_the_alarm_for_a_drop_in_that_already_helps(): void {
		$report = StackProbe::run(
			$this->fake_dropin( '<?php class C { function set($k,$v){ return $this->redis->set($k, gzcompress($v)); } }' )
		);

		$this->assertContains( 'Redis', $report['backends'] );
		$this->assertContains( 'value compression', $report['mitigations'] );

		$notes = implode( ' ', $report['notes'] );

		$this->assertStringContainsString(
			'already do something',
			$notes,
			'Preaching catastrophe at a stack that has solved it is the fastest way to lose an expert reader.'
		);
	}

	public function test_plain_redis_gets_the_single_threaded_warning(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php $r = new Redis(); $r->get( "alloptions" );' ) );

		$notes = implode( ' ', $report['notes'] );

		$this->assertStringContainsString( 'one command at a time', $notes );
	}

	public function test_a_mitigated_redis_does_not_get_the_warning(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php $r = new Redis(); $v = zstd_compress( $x );' ) );

		$notes = implode( ' ', $report['notes'] );

		$this->assertStringNotContainsString( 'one command at a time', $notes );
	}

	public function test_it_always_says_the_detection_may_be_wrong(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php $r = new Redis();' ) );

		$notes = implode( ' ', $report['notes'] );

		$this->assertStringContainsString(
			'may be wrong',
			$notes,
			'Commercial drop-ins change between releases; the report must not pretend otherwise.'
		);
	}

	public function test_an_unidentifiable_dropin_claims_nothing(): void {
		$report = StackProbe::run( $this->fake_dropin( '<?php // a bespoke cache nobody has heard of' ) );

		$this->assertTrue( $report['dropin_present'] );
		$this->assertSame( array(), $report['backends'] );
		$this->assertSame( array(), $report['mitigations'] );
	}

	public function test_backends_are_not_reported_twice(): void {
		$report = StackProbe::run(
			$this->fake_dropin( '<?php $a = new Redis(); $b = new Redis(); $c = new Predis\Client();' )
		);

		$this->assertSame( array( 'Redis' ), $report['backends'] );
	}

	public function test_only_the_head_of_a_huge_dropin_is_read(): void {
		// A marker past the read window is not found — which is a deliberate
		// trade, and is why detection is reported as a marker rather than a fact.
		$source = '<?php ' . str_repeat( '// padding padding padding' . PHP_EOL, 20000 ) . 'new Redis();';

		$report = StackProbe::run( $this->fake_dropin( $source ) );

		$this->assertTrue( $report['dropin_present'] );
		$this->assertGreaterThan( 262144, $report['dropin_bytes'] );
	}

	public function test_it_reports_the_drop_in_size(): void {
		$path = $this->fake_dropin( '<?php // small' );

		$this->assertSame( filesize( $path ), StackProbe::run( $path )['dropin_bytes'] );
	}
}
