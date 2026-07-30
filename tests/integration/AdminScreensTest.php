<?php
/**
 * The audit screen.
 *
 * The tag screens are not here: they belong to the bundled library and are
 * tested with it. What is left is the one screen the plugin still owns.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Admin\AuditPage;
use LeanRoles\Admin\Menu;
use UserTags\Store;
use LeanRoles\Tests\TestCase;

class AdminScreensTest extends TestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		$this->clear_request();

		parent::tear_down();
	}

	// ----------------------------------------------------------------- menu

	public function test_menu_urls(): void {
		$url = Menu::url( Menu::AUDIT_SLUG, array( 'edit' => 'gold' ) );

		$this->assertStringContainsString( 'page=' . Menu::AUDIT_SLUG, $url );
		$this->assertStringContainsString( 'edit=gold', $url );
		$this->assertStringContainsString( 'admin.php', $url );
	}

	// -------------------------------------------------------------- badges




	// ---------------------------------------------------------- tags screen


	public function test_the_audit_screen_renders(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['benchmark'] = '0';

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'Role audit', $html );
		$this->assertStringContainsString( 'Findings', $html );
		$this->assertStringContainsString( 'Roles', $html );
		$this->assertStringContainsString( 'administrator', $html );
	}

	public function test_the_audit_screen_states_that_it_writes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['benchmark'] = '0';

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'Nothing here writes', $html );
	}

	public function test_the_audit_screen_honours_its_inputs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET = array(
			'benchmark' => '0',
			'rps'       => '100',
			'ram'       => '8192',
			'worker'    => '128',
		);

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'value="100"', $html );
		$this->assertStringContainsString( 'value="8192"', $html );
		$this->assertStringContainsString( 'value="128"', $html );
	}

	public function test_the_audit_screen_runs_the_benchmark_by_default(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'Measured cost', $html );
		$this->assertStringContainsString( 'Unserialization, per request', $html );
	}

	public function test_the_audit_screen_is_denied_without_list_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );

		AuditPage::render();
	}

	public function test_the_audit_screen_carries_the_orphan_caveat(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_role( 'lr_odd', 'Odd', array( 'read' => true, 'acme_unknown_cap' => true ) );

		$_GET['benchmark'] = '0';

		$html = $this->capture_output( array( AuditPage::class, 'render' ) );

		$this->assertStringContainsString( 'not mean orphaned', $html );

		remove_role( 'lr_odd' );
	}
}
