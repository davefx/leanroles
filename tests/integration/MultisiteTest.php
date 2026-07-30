<?php
/**
 * Network behaviour.
 *
 * Usermeta is global; term relationships are not. That asymmetry is the whole
 * reason the mirror key is blog-prefixed, and it is the thing most likely to be
 * got wrong by anyone editing this later.
 *
 * Run with: composer test:multisite
 *
 * @group ms-required
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Audit\SizeProbe;
use LeanRoles\Support\Roles;
use UserTags\Store;
use UserTags\Taxonomy;
use LeanRoles\Tests\TestCase;

class MultisiteTest extends TestCase {

	private $second_site;

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite install.' );
		}

		$this->second_site = self::factory()->blog->create();
	}

	public function test_the_mirror_key_differs_between_sites(): void {
		$main = Store::mirror_key();

		switch_to_blog( $this->second_site );
		$this->reset_plugin_state();
		$other = Store::mirror_key();
		restore_current_blog();

		$this->assertNotSame(
			$main,
			$other,
			'An unprefixed key would leak one site\'s tags across the whole network.'
		);
	}

	public function test_a_tag_on_one_site_does_not_appear_on_another(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->second_site, $user_id, 'subscriber' );

		Store::add( $user_id, 'gold' );

		$this->assertSame( array( 'gold' ), Store::get_tags( $user_id ) );

		switch_to_blog( $this->second_site );
		$this->reset_plugin_state();

		$leaked = Store::get_tags( $user_id );

		restore_current_blog();
		$this->reset_plugin_state();

		$this->assertSame( array(), $leaked );
	}

	public function test_each_site_has_its_own_catalogue(): void {
		$this->make_tag( 'gold' );

		switch_to_blog( $this->second_site );
		$this->reset_plugin_state();
		Taxonomy::register();
		$other_catalogue = \UserTags\Catalogue::rebuild();
		restore_current_blog();
		$this->reset_plugin_state();

		$this->assertArrayNotHasKey( 'gold', $other_catalogue );
		$this->assertTrue( \UserTags\Catalogue::has( 'gold' ) );
	}

	public function test_the_same_slug_can_be_a_tag_on_two_sites_independently(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->second_site, $user_id, 'subscriber' );
		Store::add( $user_id, 'gold' );

		switch_to_blog( $this->second_site );
		$this->reset_plugin_state();
		Taxonomy::register();
		Taxonomy::create( 'gold', array( 'name' => 'Gold on site two' ) );
		\UserTags\Catalogue::rebuild();
		$this->reset_plugin_state();

		$before = Store::get_tags( $user_id );

		Store::add( $user_id, 'gold' );
		$after = Store::get_tags( $user_id );

		restore_current_blog();
		$this->reset_plugin_state();

		$this->assertSame( array(), $before, 'The tag exists on site two but is not yet assigned there.' );
		$this->assertSame( array( 'gold' ), $after );

		// And the first site is unaffected.
		$this->assertSame( array( 'gold' ), Store::get_tags( $user_id ) );
	}

	public function test_the_auditor_reads_the_role_option_of_the_current_site(): void {
		$main_option = Roles::option_name();

		switch_to_blog( $this->second_site );
		$this->reset_plugin_state();
		$other_option = SizeProbe::run()['option_name'];
		restore_current_blog();
		$this->reset_plugin_state();

		$this->assertNotSame( $main_option, $other_option );
		$this->assertStringEndsWith( 'user_roles', $other_option );
	}

	public function test_removing_a_user_from_a_site_clears_their_tags_there(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		$this->assertSame( array( 'gold' ), Store::get_tags( $user_id ) );

		remove_user_from_blog( $user_id, get_current_blog_id() );
		Store::flush_memo();

		$this->assertSame( array(), Store::get_tags( $user_id ) );
	}

	public function test_erasing_the_library_network_wide_walks_every_site(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->second_site, $user_id, 'subscriber' );
		Store::add( $user_id, 'gold' );

		switch_to_blog( $this->second_site );
		$this->reset_plugin_state();
		Taxonomy::register();
		Taxonomy::create( 'gold', array( 'name' => 'Gold on site two' ) );
		\UserTags\Catalogue::rebuild();
		$this->reset_plugin_state();
		Store::add( $user_id, 'gold' );
		$second_mirror_key = Store::mirror_key();
		restore_current_blog();
		$this->reset_plugin_state();

		global $wpdb;

		$before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s )",
				Store::mirror_key(),
				$second_mirror_key
			)
		);

		$this->assertSame( 2, $before, 'Both sites should have a mirror row for this user.' );

		// The plugin's own uninstall leaves shared tag data alone, so erasing it
		// is the library's explicit, opt-in call.
		user_tags_uninstall( true );

		$after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s )",
				Store::mirror_key(),
				$second_mirror_key
			)
		);

		$this->assertSame(
			0,
			$after,
			'A per-site key means a per-site cleanup, so the network sweep has to visit each one.'
		);
	}

	public function test_capability_injection_uses_the_current_sites_key(): void {
		global $wpdb;

		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->second_site, $user_id, 'subscriber' );
		Store::add( $user_id, 'gold' );

		switch_to_blog( $this->second_site );
		$this->reset_plugin_state();

		$caps = get_user_meta( $user_id, $wpdb->get_blog_prefix() . 'capabilities', true );

		restore_current_blog();
		$this->reset_plugin_state();

		$this->assertIsArray( $caps );
		$this->assertArrayNotHasKey(
			'gold',
			$caps,
			'A tag assigned on one site must not become a role on another.'
		);
	}
}
