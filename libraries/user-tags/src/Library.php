<?php
/**
 * What the library does when it wins, and what it will tell you about itself.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Library {

	/**
	 * Version of the copy that booted.
	 *
	 * @var string
	 */
	private static $version = '';

	/**
	 * Directory the booted copy was loaded from.
	 *
	 * @var string
	 */
	private static $path = '';

	/**
	 * Features this version answers for.
	 *
	 * A consumer bundling a newer copy can find itself running against an older
	 * one — its own plugin might be the deactivated one. Action Scheduler makes
	 * callers compare version strings, which is a guess about what a version
	 * contained; naming the capability is not.
	 *
	 * Entries are added, never removed or renamed.
	 *
	 * @var string[]
	 */
	private const FEATURES = array(
		'tags',            // Create, assign, remove, read.
		'role-shim',       // Tags appear in $user->roles and answer current_user_can().
		'user-query',      // WP_User_Query understands tag slugs in role arguments.
		'bulk-assign',     // Resumable assignment by source role.
		'csv',             // Import and export.
		'multisite',       // Per-site tags on a network.
		'i18n',            // Ships and loads its own translations.
		'admin-ui',        // Optional screens, behind user_tags_enable_admin.
	);

	/**
	 * Boot the library. Called only by the winning copy's bootstrap.
	 *
	 * @param string $version Version of this copy.
	 * @param string $path    Directory it lives in.
	 * @return void
	 */
	public static function boot( string $version, string $path ): void {
		if ( '' !== self::$version ) {
			return;
		}

		self::$version = $version;
		self::$path    = $path;

		/*
		 * Attached now, not on a later hook. WP_Roles is constructed by
		 * wp-settings.php as soon as `plugins_loaded` finishes, and plenty of
		 * plugins build a WP_User before that; a capability filter registered
		 * any later would miss the first read of the request.
		 */
		Runtime::boot();

		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		add_action( 'init', array( Taxonomy::class, 'register' ), 0 );
		add_action( 'init', array( Catalogue::class, 'prime' ), 1 );

		Catalogue::boot();
		Cleanup::boot();
		Query::boot();

		add_action( 'user_tags_prune_mirrors', array( Store::class, 'prune_mirrors' ) );

		// Screens, if a consumer asked for them. Nothing is loaded from disk
		// otherwise — see UserTags\Admin\Admin.
		add_action( 'init', array( __CLASS__, 'maybe_boot_admin' ), 5 );

		/**
		 * Fires once the library is ready to answer.
		 *
		 * The moment to register tags from code. Firing this rather than making
		 * callers guess a hook is the difference between an API and a folklore.
		 *
		 * @param string $version Version that booted.
		 */
		do_action( 'user_tags_ready', $version );
	}

	/**
	 * Load the optional admin screens.
	 *
	 * @return void
	 */
	public static function maybe_boot_admin(): void {
		require_once self::$path . '/Admin/Admin.php';

		Admin\Admin::maybe_boot();
	}

	/**
	 * The library's own root directory, one level above src/.
	 */
	public static function root(): string {
		return '' === self::$path ? '' : dirname( self::$path );
	}

	/**
	 * Is a copy of this library installed as a plugin in its own right?
	 *
	 * Every registered copy is considered, not just the one that booted. If a
	 * site owner activated the User Tags plugin, they asked for its screens —
	 * and it would be baffling for those screens to disappear because some other
	 * plugin happened to bundle a newer copy that ends up running instead.
	 *
	 * Where the entry file sits is the test: a plugin's own directory lives
	 * directly inside wp-content/plugins, while a bundled copy is further down,
	 * inside the directory of the plugin that carries it.
	 */
	public static function is_standalone(): bool {
		if ( ! class_exists( 'UserTags_Versions', false ) ) {
			return false;
		}

		$roots = array();

		foreach ( array( 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
			if ( defined( $constant ) ) {
				$real = realpath( constant( $constant ) );

				if ( $real ) {
					$roots[] = $real;
				}
			}
		}

		if ( ! $roots ) {
			return false;
		}

		/*
		 * Duplicates as well as copies. Two plugins bundling the identical
		 * version means the second registration is recorded as a duplicate
		 * rather than given a seat — and if the one that lost the coin toss is
		 * the standalone plugin, its screens would vanish for no reason the
		 * site owner could see.
		 */
		$registered = array_merge(
			array_values( \UserTags_Versions::copies() ),
			\UserTags_Versions::duplicates()
		);

		foreach ( $registered as $copy ) {
			if ( empty( $copy['source'] ) ) {
				continue;
			}

			$source = realpath( $copy['source'] );

			if ( ! $source ) {
				continue;
			}

			if ( in_array( dirname( $source, 2 ), $roots, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load the library's translations.
	 *
	 * A bundled library cannot rely on WordPress finding its strings. Just-in-time
	 * loading looks in wp-content/languages and in the plugin directory that owns
	 * the text domain, and this library owns `user-tags-lib` while living at an
	 * arbitrary path inside somebody else's plugin. So it loads its own file, from
	 * its own directory, explicitly.
	 *
	 * On `init` rather than earlier: the locale is not settled before that, and
	 * WordPress 6.7 warns about text domains loaded too soon.
	 *
	 * Two locations are tried, in WordPress's own order of precedence:
	 *
	 *   1. wp-content/languages/plugins/leanroles-<locale>.mo — where the
	 *      wordpress.org translation platform puts files, and where a site owner
	 *      puts an override. Checked first so either can win.
	 *   2. The copy shipped inside this library.
	 *
	 * The first location is how Action Scheduler's strings reach a site at all:
	 * it ships none and loads nothing, relying on its own wordpress.org listing
	 * to populate that directory. That is enough for a library whose name is on
	 * wordpress.org and whose strings number two. A bundled library used by a
	 * plugin that is not listed there would leave every string untranslated, so
	 * this one also carries its own.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		if ( is_textdomain_loaded( 'leanroles' ) ) {
			return;
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		$candidates = array();

		if ( defined( 'WP_LANG_DIR' ) ) {
			$candidates[] = WP_LANG_DIR . '/plugins/leanroles-' . $locale . '.mo';
		}

		/**
		 * Filter the directory the library reads its bundled translations from.
		 *
		 * @param string $dir    Absolute path, no trailing slash.
		 * @param string $locale Locale being loaded.
		 */
		$bundled = apply_filters( 'user_tags_languages_dir', self::root() . '/languages', $locale );

		$candidates[] = $bundled . '/leanroles-' . $locale . '.mo';

		foreach ( $candidates as $mofile ) {
			if ( is_readable( $mofile ) && load_textdomain( 'leanroles', $mofile, $locale ) ) {
				return;
			}
		}
	}

	/**
	 * Is the library loaded and ready?
	 *
	 * Consumers should check this, or `function_exists( 'user_tags_add' )`,
	 * before calling anything — a bundled library can always be absent.
	 */
	public static function is_ready(): bool {
		return '' !== self::$version;
	}

	/**
	 * The version currently running.
	 */
	public static function version(): string {
		return self::$version;
	}

	/**
	 * The directory the running copy was loaded from.
	 *
	 * "Which plugin's copy is actually active?" is the first question on every
	 * bundled-library support thread.
	 */
	public static function path(): string {
		return self::$path;
	}

	/**
	 * Does the running version answer for a named feature?
	 *
	 * @param string $feature Feature name.
	 */
	public static function supports( string $feature ): bool {
		return self::is_ready() && in_array( $feature, self::FEATURES, true );
	}

	/**
	 * Everything worth putting in a bug report.
	 *
	 * @return array
	 */
	public static function diagnostics(): array {
		return array(
			'version'      => self::$version,
			'path'         => self::$path,
			'ready'        => self::is_ready(),
			'features'     => self::FEATURES,
			'copies'       => class_exists( 'UserTags_Versions', false ) ? \UserTags_Versions::copies() : array(),
			'duplicates'   => class_exists( 'UserTags_Versions', false ) ? \UserTags_Versions::duplicates() : array(),
			'booted_early' => class_exists( 'UserTags_Versions', false ) ? \UserTags_Versions::booted_early() : false,
			'standalone'   => self::is_standalone(),
			'tag_count'    => count( Catalogue::all() ),
		);
	}

	/**
	 * Schedule the housekeeping job.
	 *
	 * Called from a consumer's activation hook. Safe to call from several
	 * plugins: the schedule is shared and only ever set once.
	 */
	public static function activate(): void {
		Taxonomy::register();
		Catalogue::rebuild();

		if ( ! wp_next_scheduled( 'user_tags_prune_mirrors' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'user_tags_prune_mirrors' );
		}
	}

	/**
	 * Erase every tag, every assignment, and the library's own options.
	 *
	 * Deliberately NOT wired to any consumer's uninstall hook.
	 *
	 * Action Scheduler leaves its tables behind when the last plugin bundling
	 * it is removed, because no single consumer can know it was the last: the
	 * version registry only sees copies loaded in the current request, and a
	 * plugin that happens to be deactivated today is still a plugin whose data
	 * this is. Guessing wrong destroys somebody's segments.
	 *
	 * So the data outlives its consumers by design, and this is the documented,
	 * explicit way to remove it — a site owner's decision, not a side effect of
	 * uninstalling one plugin.
	 *
	 * Scoped to the current site by default. Tags are per site — the taxonomy
	 * lives in each site's own tables and the mirror key is blog-prefixed — so
	 * erasing the whole network is a separate, explicit decision rather than
	 * something that happens because somebody happened to be on the main site.
	 *
	 * @param bool $network_wide Walk every site on the network.
	 * @return int Number of terms removed.
	 */
	public static function uninstall( bool $network_wide = false ): int {
		if ( $network_wide && is_multisite() ) {
			$removed = 0;

			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				$removed += self::uninstall_site();
				restore_current_blog();
			}

			return $removed;
		}

		return self::uninstall_site();
	}

	/**
	 * Erase the current site's tags.
	 *
	 * @return int Number of terms removed.
	 */
	private static function uninstall_site(): int {
		global $wpdb;

		Taxonomy::register();

		$removed = 0;

		foreach ( Taxonomy::all_terms() as $term ) {
			wp_delete_term( $term->term_id, Taxonomy::NAME );
			++$removed;
		}

		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => Store::mirror_key() ), array( '%s' ) );

		delete_option( Catalogue::OPTION );
		wp_clear_scheduled_hook( 'user_tags_prune_mirrors' );

		// Forget rather than rebuild: rebuilding would put the row straight
		// back, and an uninstall that leaves a row behind is not an uninstall.
		Catalogue::forget();

		return $removed;
	}
}
