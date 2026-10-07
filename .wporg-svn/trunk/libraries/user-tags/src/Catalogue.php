<?php
/**
 * A cache of the tag catalogue that is readable before `init`.
 *
 * The runtime has to inject tags on `wp_roles_init`, which wp-settings.php
 * fires between `plugins_loaded` and `setup_theme` — long before taxonomies
 * are registered and therefore long before get_terms() can be called. This
 * class keeps a derived copy of the catalogue in a NON-autoloaded option so
 * the injection has something to read at that point.
 *
 * It is a cache, not a source of truth. The taxonomy always wins; this is
 * rebuilt from it on every term change and by the consumer's rebuild command.
 * It is deliberately small — slug, term id and name — so that it stays a
 * cheap read and never turns into the disease it exists to avoid.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Catalogue {

	public const OPTION = 'user_tags_catalogue';

	/**
	 * The catalogue as last read, for the rest of the request.
	 *
	 * @var array<string,array>|null
	 */
	private static $memo = null;

	/**
	 * Guards re-entry through get_option()'s own filters.
	 *
	 * @var bool
	 */
	private static $loading = false;

	/**
	 * Keep the cache in step with the taxonomy.
	 */
	public static function boot(): void {
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action(
				$hook,
				static function ( $term_id, $tt_id = 0, $taxonomy = '' ) {
					if ( Taxonomy::NAME === $taxonomy ) {
						self::rebuild();
					}
				},
				10,
				3
			);
		}
	}

	/**
	 * Build the cache on `init` if it has never been built.
	 *
	 * Covers a fresh install and an option deleted by hand. Costs nothing on
	 * every other request, because the option exists.
	 */
	public static function prime(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			self::rebuild();
		}
	}

	/**
	 * The catalogue: slug => array{term_id, name, color}.
	 *
	 * Safe to call at any point in the request, including before `init`.
	 *
	 * Cost: with a persistent object cache this is a single small cache read,
	 * because the option is not in `alloptions`; without one it is a single
	 * indexed query. That is the whole price of keeping the catalogue out of
	 * autoload, and it is stated here rather than glossed over.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		if ( self::$loading ) {
			// get_option() runs filters, and a filter that asks a capability
			// question would land back here before the memo is set. Report
			// "no tags" for the duration rather than recursing.
			return array();
		}

		self::$loading = true;
		$stored        = get_option( self::OPTION, array() );
		self::$loading = false;

		self::$memo = is_array( $stored ) ? $stored : array();

		return self::$memo;
	}

	/**
	 * Slugs as a lookup map, for array_diff_key and isset() checks.
	 *
	 * @return array<string,true>
	 */
	public static function slugs_map(): array {
		return array_fill_keys( array_keys( self::all() ), true );
	}

	/**
	 * Does this slug name a tag?
	 *
	 * @param string $slug Slug.
	 */
	public static function has( string $slug ): bool {
		$all = self::all();

		return isset( $all[ $slug ] );
	}

	/**
	 * Split a list of slugs into the ones that are tags and the ones that are not.
	 *
	 * @param string[] $slugs Slugs.
	 * @return array{0:string[],1:string[]} [tags, others]
	 */
	public static function partition( array $slugs ): array {
		$all    = self::all();
		$tags   = array();
		$others = array();

		foreach ( $slugs as $slug ) {
			if ( isset( $all[ (string) $slug ] ) ) {
				$tags[] = (string) $slug;
			} else {
				$others[] = (string) $slug;
			}
		}

		return array( $tags, $others );
	}

	/**
	 * Drop the in-request copy without writing anything.
	 *
	 * For the one caller that has just removed the option and needs the rest of
	 * the request to agree, without putting the row straight back.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$memo = null;
		Store::flush_memo();
	}

	/**
	 * Rebuild from the taxonomy.
	 *
	 * @return array<string,array> The new catalogue.
	 */
	public static function rebuild(): array {
		if ( ! taxonomy_exists( Taxonomy::NAME ) ) {
			Taxonomy::register();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
			)
		);

		$catalogue = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$catalogue[ $term->slug ] = array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'color'   => (string) get_term_meta( $term->term_id, Taxonomy::META_COLOR, true ),
				);
			}
		}

		// autoload = no. The whole point of the plugin is not adding to that blob.
		update_option( self::OPTION, $catalogue, false );

		self::$memo = $catalogue;
		Store::flush_memo();

		return $catalogue;
	}
}
