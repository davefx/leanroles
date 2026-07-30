<?php
/**
 * Runtime injection: making a tag indistinguishable from a role without
 * writing a single byte of it to the database.
 *
 * Four hooks, and the fourth is the one everybody forgets:
 *
 *   wp_roles_init          register each tag as a zero-capability role, in
 *                          memory only, so WP_Roles::is_role() accepts it and
 *                          the slug therefore survives WP_User::get_role_caps()
 *   get_user_metadata      splice the tags into the capabilities array on read
 *   update_user_metadata   strip them out again before anything is written
 *   user_has_cap           the final word on current_user_can()
 *
 * Without the write filter the tags get persisted incidentally — every profile
 * save, every add_role() call — and then WP_User::set_role() walks $this->roles
 * (which now contains them) and unsets each one from $this->caps before saving.
 * An administrator opening a profile and pressing Update would silently drop
 * every tag the user held. Read-time injection is what makes that harmless;
 * the write filter is what keeps it that way.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	/**
	 * Guards re-entry into the metadata API from inside a metadata filter.
	 *
	 * @var bool
	 */
	private static $reading = false;

	/**
	 * Guards re-entry into update_metadata() from inside its own filter.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Blog id to capabilities meta key.
	 *
	 * @var array<int,string>
	 */
	private static $cap_keys = array();

	/**
	 * Memoized result of the user_tags_inject_as_roles filter.
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	/**
	 * Tag slugs that are also the slug of a real role.
	 *
	 * Creating a tag whose slug is already a role is refused up front, but a
	 * role can be created afterwards — by a plugin update, by an import — and
	 * from that moment injecting the slug would hand every tagged user that
	 * role's capabilities. These are never injected.
	 *
	 * Null until wp_roles_init has run.
	 *
	 * @var array<string,true>|null
	 */
	private static $shadowed = null;

	/**
	 * Attach the hooks.
	 *
	 * Called at plugin load rather than on an action: WP_Roles is constructed
	 * by wp-settings.php immediately after `plugins_loaded`, and plenty of
	 * plugins build a WP_User before that.
	 */
	public static function boot(): void {
		add_action( 'wp_roles_init', array( __CLASS__, 'register_tag_roles' ) );
		add_filter( 'get_user_metadata', array( __CLASS__, 'inject_on_read' ), 10, 4 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'strip_on_update' ), 10, 5 );
		add_filter( 'add_user_metadata', array( __CLASS__, 'strip_on_add' ), 10, 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'assert_tags' ), 20, 4 );

		// If the catalogue was still cold on wp_roles_init — a fresh install,
		// or someone deleted the cache option — pick the tags up once the
		// taxonomy is available.
		add_action( 'init', array( __CLASS__, 'register_tag_roles_late' ), 5 );
	}

	/**
	 * Is the role shim switched on?
	 */
	public static function enabled(): bool {
		if ( null === self::$enabled ) {
			/**
			 * Filter whether tags are injected into WP_Roles and the capabilities array.
			 *
			 * Switching this off leaves tags as pure metadata: the API and the
			 * admin screens keep working, but $user->roles and
			 * current_user_can() stop seeing them.
			 */
			self::$enabled = (bool) apply_filters( 'user_tags_inject_as_roles', true );
		}

		return self::$enabled;
	}

	/**
	 * Register every tag as a role with no capabilities.
	 *
	 * Written straight into the WP_Roles properties rather than through
	 * add_role(), which would call update_option() and persist the very thing
	 * this plugin exists to avoid persisting.
	 *
	 * @param \WP_Roles $wp_roles Roles object.
	 */
	public static function register_tag_roles( $wp_roles ): void {
		if ( ! $wp_roles instanceof \WP_Roles ) {
			return;
		}

		self::$shadowed = array();

		if ( ! self::enabled() ) {
			return;
		}

		foreach ( Catalogue::all() as $slug => $tag ) {
			if ( isset( $wp_roles->roles[ $slug ] ) ) {
				// A real role owns this slug. It wins, and the tag is never
				// injected anywhere — see self::$shadowed.
				self::$shadowed[ $slug ] = true;
				continue;
			}

			$wp_roles->roles[ $slug ]        = array(
				'name'         => $tag['name'],
				'capabilities' => array(),
			);
			$wp_roles->role_objects[ $slug ] = new \WP_Role( $slug, array() );
			$wp_roles->role_names[ $slug ]   = $tag['name'];
		}
	}

	/**
	 * Second chance at role registration, on `init`.
	 */
	public static function register_tag_roles_late(): void {
		if ( ! self::enabled() || ! isset( $GLOBALS['wp_roles'] ) ) {
			return;
		}

		self::register_tag_roles( $GLOBALS['wp_roles'] );
	}

	/**
	 * Splice tags into the capabilities array as it is read.
	 *
	 * Returning a non-null value short-circuits get_metadata_raw(), which then
	 * takes element zero when $single is true — so the payload is wrapped in an
	 * array either way.
	 *
	 * @param mixed  $value    Short-circuit value, null by default.
	 * @param int    $user_id  User id.
	 * @param string $meta_key Meta key.
	 * @param bool   $single   Whether a single value was requested.
	 * @return mixed
	 */
	public static function inject_on_read( $value, $user_id, $meta_key, $single ) {
		if ( null !== $value || self::$reading || ! self::enabled() ) {
			return $value;
		}

		if ( ! self::is_caps_key( (string) $meta_key ) ) {
			return $value;
		}

		$tags = self::injectable( Store::runtime_tags( (int) $user_id ) );

		if ( ! $tags ) {
			return $value;
		}

		self::$reading = true;
		$caps          = get_user_meta( (int) $user_id, $meta_key, true );
		self::$reading = false;

		if ( ! is_array( $caps ) ) {
			$caps = array();
		}

		foreach ( $tags as $slug ) {
			if ( ! isset( $caps[ $slug ] ) ) {
				$caps[ $slug ] = true;
			}
		}

		return array( $caps );
	}

	/**
	 * Tag slugs that a real role has taken over.
	 *
	 * @return array<string,true>
	 */
	private static function shadowed(): array {
		if ( null === self::$shadowed ) {
			// Force WP_Roles to initialize, which fills the list.
			wp_roles();
		}

		return (array) self::$shadowed;
	}

	/**
	 * Drop tag slugs that a real role has since taken over.
	 *
	 * @param string[] $tags Tag slugs.
	 * @return string[]
	 */
	private static function injectable( array $tags ): array {
		if ( ! $tags ) {
			return $tags;
		}

		$shadowed = self::shadowed();

		if ( ! $shadowed ) {
			return $tags;
		}

		return array_values( array_diff( $tags, array_keys( $shadowed ) ) );
	}

	/**
	 * Strip tags out of the capabilities array before it is written.
	 *
	 * `update_user_metadata` is a short-circuit filter, not a value filter, so
	 * the only way to alter what gets stored is to cancel the original call and
	 * make the write ourselves.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $user_id    User id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value about to be written (already unslashed).
	 * @param mixed  $prev_value Previous value, if the caller narrowed the update.
	 * @return mixed
	 */
	public static function strip_on_update( $check, $user_id, $meta_key, $meta_value, $prev_value ) {
		if ( null !== $check || self::$writing || ! self::enabled() ) {
			return $check;
		}

		if ( ! self::is_caps_key( (string) $meta_key ) || ! is_array( $meta_value ) ) {
			return $check;
		}

		$clean = self::without_tags( $meta_value );

		if ( count( $clean ) === count( $meta_value ) ) {
			return $check;
		}

		self::$writing = true;
		update_metadata( 'user', (int) $user_id, $meta_key, $clean, $prev_value );
		self::$writing = false;

		Store::flush_memo( (int) $user_id );

		/*
		 * update_metadata() returns false when the stored value already matches,
		 * which is a no-op rather than a failure. The caller asked for the
		 * capabilities to end up in a given state and they have, so this reports
		 * success either way.
		 */
		return true;
	}

	/**
	 * Same treatment for the insert path.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $user_id    User id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value about to be written.
	 * @param bool   $unique     Whether the key must be unique.
	 * @return mixed
	 */
	public static function strip_on_add( $check, $user_id, $meta_key, $meta_value, $unique ) {
		if ( null !== $check || self::$writing || ! self::enabled() ) {
			return $check;
		}

		if ( ! self::is_caps_key( (string) $meta_key ) || ! is_array( $meta_value ) ) {
			return $check;
		}

		$clean = self::without_tags( $meta_value );

		if ( count( $clean ) === count( $meta_value ) ) {
			return $check;
		}

		self::$writing = true;
		$result        = add_metadata( 'user', (int) $user_id, $meta_key, $clean, (bool) $unique );
		self::$writing = false;

		Store::flush_memo( (int) $user_id );

		return false === $result ? false : $result;
	}

	/**
	 * The last word on current_user_can().
	 *
	 * Injection through the capabilities array already puts tag slugs in
	 * allcaps, the same way role slugs get there. This restates it, so that a
	 * plugin which rebuilt allcaps from somewhere else cannot quietly drop them
	 * — and so that the guarantee does not depend on core's merge order.
	 *
	 * @param array    $allcaps All capabilities of the user.
	 * @param array    $caps    Required primitive capabilities.
	 * @param array    $args    Context.
	 * @param \WP_User $user    The user.
	 * @return array
	 */
	public static function assert_tags( $allcaps, $caps, $args, $user ) {
		if ( ! self::enabled() || ! $user instanceof \WP_User || ! $user->ID ) {
			return $allcaps;
		}

		foreach ( self::injectable( Store::runtime_tags( (int) $user->ID ) ) as $slug ) {
			if ( ! isset( $allcaps[ $slug ] ) ) {
				$allcaps[ $slug ] = true;
			}
		}

		return $allcaps;
	}

	/**
	 * Drop every injected tag slug from a capabilities array.
	 *
	 * Shadowed slugs are excluded from the strip. Where a real role owns the
	 * name, the entry in the capabilities array belongs to that role and not to
	 * us — removing it would quietly delete a genuine role assignment, which is
	 * a worse failure than the one this filter exists to prevent.
	 *
	 * @param array $caps Capabilities.
	 * @return array
	 */
	private static function without_tags( array $caps ): array {
		$tags = array_diff_key( Catalogue::slugs_map(), self::shadowed() );

		if ( ! $tags ) {
			return $caps;
		}

		return array_diff_key( $caps, $tags );
	}

	/**
	 * The capabilities meta key for the site currently in scope.
	 *
	 * Recomputed per blog rather than cached once: switch_to_blog() changes it,
	 * and getting this wrong on multisite means injecting one site's tags into
	 * another site's permissions.
	 *
	 * @param string $meta_key Meta key.
	 */
	private static function is_caps_key( string $meta_key ): bool {
		global $wpdb;

		$blog_id = get_current_blog_id();

		if ( ! isset( self::$cap_keys[ $blog_id ] ) ) {
			self::$cap_keys[ $blog_id ] = $wpdb->get_blog_prefix() . 'capabilities';
		}

		return $meta_key === self::$cap_keys[ $blog_id ];
	}
}
