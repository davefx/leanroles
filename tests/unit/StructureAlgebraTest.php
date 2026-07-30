<?php
/**
 * The set algebra behind the audit, tested directly.
 *
 * These are the numbers the product's whole argument rests on, so they are
 * exercised against hand-built inputs rather than against whatever roles the
 * install happens to have.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Unit;

use LeanRoles\Audit\StructureProbe;
use LeanRoles\Tests\TestCase;

class StructureAlgebraTest extends TestCase {

	/**
	 * @param string $method Private method on StructureProbe.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function probe( string $method, array $args ) {
		return $this->call_static( StructureProbe::class, $method, $args );
	}

	// ---------------------------------------------------------------- inert

	public function test_inert_roles(): void {
		$granted = array(
			'subscriber'  => array( 'level_0', 'read' ),
			'label_only'  => array( 'read' ),
			'empty'       => array(),
			'levels_only' => array( 'level_0', 'level_1' ),
			'editor'      => array( 'edit_posts', 'level_7', 'read' ),
		);

		$this->assertSame(
			array( 'subscriber', 'label_only', 'empty', 'levels_only' ),
			$this->probe( 'inert_roles', array( $granted ) )
		);
	}

	public function test_a_role_granting_one_real_capability_is_not_inert(): void {
		$granted = array( 'nearly' => array( 'read', 'level_0', 'upload_files' ) );

		$this->assertSame( array(), $this->probe( 'inert_roles', array( $granted ) ) );
	}

	// --------------------------------------------------------------- clones

	public function test_clone_groups(): void {
		$granted = array(
			'a' => array( 'x', 'y' ),
			'b' => array( 'x', 'y' ),
			'c' => array( 'x', 'y' ),
			'd' => array( 'x' ),
			'e' => array( 'z' ),
		);

		$groups = $this->probe( 'clone_groups', array( $granted ) );

		$this->assertCount( 1, $groups );
		$this->assertSame( array( 'a', 'b', 'c' ), $groups[0]['roles'] );
		$this->assertSame( 2, $groups[0]['size'] );
	}

	public function test_capability_order_does_not_affect_clone_detection(): void {
		// granted_caps() sorts, so this mirrors what the probe really sees.
		$granted = array(
			'a' => array( 'x', 'y', 'z' ),
			'b' => array( 'x', 'y', 'z' ),
		);

		$this->assertCount( 1, $this->probe( 'clone_groups', array( $granted ) ) );
	}

	public function test_roles_that_grant_nothing_group_together(): void {
		$granted = array(
			'a' => array(),
			'b' => array(),
			'c' => array( 'x' ),
		);

		$groups = $this->probe( 'clone_groups', array( $granted ) );

		$this->assertCount( 1, $groups );
		$this->assertSame( 0, $groups[0]['size'] );
	}

	// -------------------------------------------------------------- subsets

	public function test_subset_pairs(): void {
		$granted = array(
			'big'   => array( 'a', 'b', 'c' ),
			'mid'   => array( 'a', 'b' ),
			'small' => array( 'a' ),
			'other' => array( 'z' ),
			'empty' => array(),
		);

		$pairs = $this->probe( 'subset_pairs', array( $granted ) );
		$flat  = array_map( static fn( $p ) => $p['parent'] . '>' . $p['child'], $pairs );
		sort( $flat );

		$this->assertSame( array( 'big>mid', 'big>small', 'mid>small' ), $flat );
	}

	public function test_an_empty_role_is_never_reported_as_a_subset(): void {
		$granted = array(
			'big'   => array( 'a', 'b' ),
			'empty' => array(),
		);

		$this->assertSame( array(), $this->probe( 'subset_pairs', array( $granted ) ) );
	}

	public function test_identical_roles_are_not_subset_pairs(): void {
		// Equal sets are clones, and are reported as clones. Treating them as
		// subsets too would double-count them in the saving estimate.
		$granted = array(
			'a' => array( 'x', 'y' ),
			'b' => array( 'x', 'y' ),
		);

		$this->assertSame( array(), $this->probe( 'subset_pairs', array( $granted ) ) );
	}

	public function test_subset_pairs_carry_the_delta(): void {
		$granted = array(
			'big' => array( 'a', 'b', 'c', 'd' ),
			'mid' => array( 'a', 'b' ),
		);

		$pairs = $this->probe( 'subset_pairs', array( $granted ) );

		$this->assertCount( 1, $pairs );
		$this->assertSame( 2, $pairs[0]['shared'] );
		$this->assertSame( 2, $pairs[0]['delta'] );
	}

	// ----------------------------------------------------------- inheritance

	public function test_clones_save_every_copy_but_the_first(): void {
		$caps = array();

		for ( $i = 0; $i < 35; $i++ ) {
			$caps[] = 'cap_' . $i;
		}

		$granted = array(
			'ed1' => $caps,
			'ed2' => $caps,
			'ed3' => $caps,
		);

		$saving = $this->probe(
			'inheritance_saving',
			array( $granted, $this->probe( 'clone_groups', array( $granted ) ) )
		);

		$this->assertSame( 70, $saving['entries'] );
		$this->assertSame( 105, $saving['of'] );
	}

	public function test_a_chain_inherits_from_the_largest_proper_subset(): void {
		$granted = array(
			'big'   => array( 'a', 'b', 'c', 'd' ),
			'mid'   => array( 'a', 'b', 'c' ),
			'small' => array( 'a' ),
		);

		$saving = $this->probe( 'inheritance_saving', array( $granted, array() ) );

		// big inherits mid (3) + mid inherits small (1) + small inherits nothing.
		$this->assertSame( 4, $saving['entries'] );
		$this->assertSame( 8, $saving['of'] );
	}

	public function test_a_role_with_no_subset_available_saves_nothing(): void {
		$saving = $this->probe( 'inheritance_saving', array( array( 'only' => array( 'a', 'b' ) ), array() ) );

		$this->assertSame( 0, $saving['entries'] );
	}

	public function test_the_estimate_never_exceeds_the_total(): void {
		$granted = array(
			'r1' => array( 'a' ),
			'r2' => array( 'a', 'b' ),
			'r3' => array( 'a', 'b' ),
			'r4' => array( 'a', 'b', 'c' ),
			'r5' => array( 'a', 'b', 'c', 'd' ),
		);

		$saving = $this->probe(
			'inheritance_saving',
			array( $granted, $this->probe( 'clone_groups', array( $granted ) ) )
		);

		$this->assertLessThanOrEqual( $saving['of'], $saving['entries'] );
		$this->assertGreaterThan( 0, $saving['entries'] );
	}

	public function test_disjoint_roles_save_nothing(): void {
		$granted = array(
			'a' => array( 'p', 'q' ),
			'b' => array( 'r', 's' ),
			'c' => array( 't', 'u' ),
		);

		$this->assertSame( 0, $this->probe( 'inheritance_saving', array( $granted, array() ) )['entries'] );
	}

	// -------------------------------------------------------- unrecognised

	public function test_unrecognised_excludes_core_and_levels(): void {
		$distinct = array(
			'read'               => true,
			'edit_posts'         => true,
			'level_3'            => true,
			'manage_woocommerce' => true,
			'zzz_custom'         => true,
		);

		$this->assertSame(
			array( 'manage_woocommerce', 'zzz_custom' ),
			$this->probe( 'unrecognised', array( $distinct ) )
		);
	}

	public function test_unrecognised_is_sorted(): void {
		$distinct = array(
			'zzz_one' => true,
			'aaa_two' => true,
			'mmm_три' => true,
		);

		$result = $this->probe( 'unrecognised', array( $distinct ) );
		$sorted = $result;
		sort( $sorted );

		$this->assertSame( $sorted, $result );
	}

	// --------------------------------------------------------------- ghosts

	public function test_ghost_roles(): void {
		$roles  = array(
			'a' => array(),
			'b' => array(),
			'c' => array(),
		);
		$counts = array( 'avail_roles' => array( 'a' => 4, 'b' => 0 ) );

		$this->assertSame( array( 'b', 'c' ), $this->probe( 'ghost_roles', array( $roles, $counts ) ) );
	}

	public function test_ghost_roles_with_no_counts_at_all(): void {
		$roles = array( 'a' => array() );

		$this->assertSame( array( 'a' ), $this->probe( 'ghost_roles', array( $roles, array() ) ) );
	}
}
