<?php
/**
 * The version registry. FROZEN — see the warning below.
 *
 * Every plugin that bundles User Tags includes its own copy. They all reach
 * this file, but only the first one to be included actually defines this class,
 * and from then on that copy's registry is the one arbitrating for the whole
 * request. If a site has an old plugin with an old copy, an *old* registry is
 * in charge — no matter how new the winning library turns out to be.
 *
 * That is the single sharpest edge in the bundled-library model, and Action
 * Scheduler lives with it too. Two things blunt it here:
 *
 *   1. This file is frozen. It collects (version, bootstrap, source) and picks
 *      the highest version. It must never grow a feature, because a five-year-
 *      old copy of it has to keep behaving identically. Everything that might
 *      ever need to change lives in the versioned bootstrap instead.
 *
 *   2. The state lives in $GLOBALS, not in a class static. Action Scheduler
 *      locks its registry inside its class, so a future redesign can never read
 *      what an older registry collected. A plain global array can be read by
 *      anything, including a replacement registry that has not been written yet.
 *
 * @package UserTags
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'UserTags_Versions', false ) ) {
	return;
}

/**
 * Collects every bundled copy and boots the newest.
 */
final class UserTags_Versions {

	/**
	 * Shape of the global this registry keeps its state in.
	 *
	 * Bumped only if the *shape* changes, which it should not. A newer registry
	 * reading an older one's state can branch on this.
	 */
	const REGISTRY_VERSION = 1;

	/**
	 * When the newest copy is booted.
	 *
	 * Early enough that the capability filters are attached before WP_Roles is
	 * constructed, which wp-settings.php does immediately after this action
	 * finishes. Anything later would miss the first capability read of the
	 * request.
	 */
	const BOOT_PRIORITY = -9999;

	/**
	 * The registry state, created on first use.
	 *
	 * @return array
	 */
	private static function &state() {
		if ( ! isset( $GLOBALS['user_tags_registry'] ) || ! is_array( $GLOBALS['user_tags_registry'] ) ) {
			$GLOBALS['user_tags_registry'] = array(
				'registry_version' => self::REGISTRY_VERSION,
				'copies'           => array(),
				'duplicates'       => array(),
				'booted'           => null,
				'booted_early'     => false,
			);
		}

		return $GLOBALS['user_tags_registry'];
	}

	/**
	 * Announce a bundled copy.
	 *
	 * Called at include time by every copy's entry file. Deliberately cheap: no
	 * files are loaded, nothing is decided. Whoever turns out to be newest gets
	 * loaded later, and the rest are never touched.
	 *
	 * @param string $version   Semantic version of this copy.
	 * @param string $bootstrap Absolute path to this copy's bootstrap file.
	 * @param string $source    Absolute path to the file that registered it, for support.
	 * @return void
	 */
	public static function register( $version, $bootstrap, $source = '' ) {
		$state = &self::state();

		$version = (string) $version;

		if ( isset( $state['copies'][ $version ] ) ) {
			// Same version bundled twice. The first registration keeps the seat
			// so the outcome does not depend on plugin order, but the collision
			// is recorded because it is worth seeing in a bug report.
			$state['duplicates'][] = array(
				'version' => $version,
				'source'  => $source,
			);

			return;
		}

		$state['copies'][ $version ] = array(
			'bootstrap' => $bootstrap,
			'source'    => $source,
		);

		self::schedule_boot();
	}

	/**
	 * Arrange for the boot to happen at the latest moment that still gathers
	 * every copy.
	 *
	 * Normally that is `plugins_loaded`, by which point every active plugin has
	 * been included and has registered. A copy that arrives after it — a plugin
	 * activated mid-request, a theme, a late require — falls through to the next
	 * hook that has not fired yet, so any siblings arriving in the same burst
	 * still get counted.
	 *
	 * Booting on the spot instead would hand the request to whichever copy
	 * happened to be included first, which is not the same thing as the newest.
	 * Only when nothing early is left to wait for is that the best available
	 * answer.
	 *
	 * @return void
	 */
	private static function schedule_boot() {
		foreach ( array( 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init' ) as $hook ) {
			if ( did_action( $hook ) ) {
				continue;
			}

			if ( ! has_action( $hook, array( __CLASS__, 'boot_latest' ) ) ) {
				add_action( $hook, array( __CLASS__, 'boot_latest' ), self::BOOT_PRIORITY, 0 );
			}

			return;
		}

		/*
		 * Every early hook has been and gone. Boot now rather than staying
		 * dormant until the next page load — which is what Action Scheduler
		 * does, and what produces "it starts working after a refresh" reports.
		 * The flag records that the decision was made without a full view.
		 */
		$state                 = &self::state();
		$state['booted_early'] = true;

		self::boot_latest();
	}

	/**
	 * Load the newest registered copy. Idempotent.
	 *
	 * @return string|null The version that booted, or null if none could.
	 */
	public static function boot_latest() {
		$state = &self::state();

		if ( null !== $state['booted'] ) {
			return $state['booted'];
		}

		if ( ! $state['copies'] ) {
			return null;
		}

		$versions = array_keys( $state['copies'] );
		usort( $versions, 'version_compare' );

		$winner = end( $versions );

		if ( ! is_readable( $state['copies'][ $winner ]['bootstrap'] ) ) {
			return null;
		}

		// Marked before the require, so a bootstrap that reaches back into the
		// registry sees a settled answer instead of recursing.
		$state['booted'] = $winner;

		require_once $state['copies'][ $winner ]['bootstrap'];

		return $winner;
	}

	/**
	 * The version currently running, or null before boot.
	 *
	 * @return string|null
	 */
	public static function booted() {
		$state = &self::state();

		return $state['booted'];
	}

	/**
	 * Every copy that announced itself, newest first.
	 *
	 * The answer to "which plugin's copy is actually running?", which in Action
	 * Scheduler takes a debugger to work out.
	 *
	 * @return array<string,array{bootstrap:string,source:string,active:bool}>
	 */
	public static function copies() {
		$state = &self::state();

		$versions = array_keys( $state['copies'] );
		usort( $versions, 'version_compare' );
		$versions = array_reverse( $versions );

		$out = array();

		foreach ( $versions as $version ) {
			$out[ $version ]           = $state['copies'][ $version ];
			$out[ $version ]['active'] = ( $version === $state['booted'] );
		}

		return $out;
	}

	/**
	 * Copies that collided on a version number.
	 *
	 * @return array[]
	 */
	public static function duplicates() {
		$state = &self::state();

		return $state['duplicates'];
	}

	/**
	 * Whether boot happened outside the normal hook.
	 *
	 * True means a copy registered after `plugins_loaded`, so the winner was
	 * decided without seeing every copy on the site.
	 *
	 * @return bool
	 */
	public static function booted_early() {
		$state = &self::state();

		return $state['booted_early'];
	}
}
