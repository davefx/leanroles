<?php
/**
 * The tag catalogue: a taxonomy registered against `user`.
 *
 * Living in the taxonomy tables buys three things wp_options cannot offer:
 * the catalogue is outside autoload, a rename is a single-row UPDATE, and
 * "every user carrying tag X" is an index seek on term_taxonomy_id rather
 * than a LIKE across usermeta.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Taxonomy {

	public const NAME = 'user_tag';

	/** Term meta keys. */
	public const META_COLOR  = 'user_tags_color';
	public const META_LEGACY = 'user_tags_legacy_role';

	/**
	 * Register the taxonomy.
	 *
	 * `user` is not a real object type as far as core is concerned, so the
	 * generated admin UI would not work. It is switched off and replaced by
	 * screens of our own; everything else about the taxonomy API — terms,
	 * term meta, object relationships — works unmodified.
	 */
	public static function register(): void {
		if ( taxonomy_exists( self::NAME ) ) {
			return;
		}

		register_taxonomy(
			self::NAME,
			'user',
			array(
				'labels'                => array(
					'name'          => __( 'User tags', 'user-tags' ),
					'singular_name' => __( 'User tag', 'user-tags' ),
				),
				'public'                => false,
				'publicly_queryable'    => false,

				/*
				 * Flat, and not by accident.
				 *
				 * A hierarchical taxonomy makes core maintain a
				 * `{taxonomy}_children` option through _get_term_hierarchy(),
				 * and it writes that option with the default autoload — which
				 * is to say, into the very blob this plugin exists to shrink.
				 * Nested tags are a nice-to-have; adding to autoload to get
				 * them would be recreating the disease under a new name.
				 */
				'hierarchical'          => false,
				'show_ui'               => false,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_admin_column'     => false,
				'show_tagcloud'         => false,
				'query_var'             => false,
				'rewrite'               => false,
				// The post-count callbacks assume a posts table row. This one
				// counts relationships, which is what a user tag actually is.
				'update_count_callback' => '_update_generic_term_count',
				'capabilities'          => array(
					'manage_terms' => 'list_users',
					'edit_terms'   => 'promote_users',
					'delete_terms' => 'promote_users',
					'assign_terms' => 'promote_users',
				),
			)
		);
	}

	/**
	 * Create a tag.
	 *
	 * @param string $slug Desired slug. Sanitized with sanitize_key().
	 * @param array  $args name, description, color, legacy_role.
	 * @return int|\WP_Error Term id.
	 */
	public static function create( string $slug, array $args = array() ) {
		self::register();

		$slug = sanitize_key( $slug );

		if ( '' === $slug ) {
			return new \WP_Error( 'user_tags_invalid_slug', __( 'A tag needs a slug.', 'user-tags' ) );
		}

		$conflict = self::slug_conflict( $slug );

		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}

		$name = isset( $args['name'] ) && '' !== $args['name'] ? (string) $args['name'] : $slug;

		$result = wp_insert_term(
			$name,
			self::NAME,
			array(
				'slug'        => $slug,
				'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];

		if ( ! empty( $args['color'] ) ) {
			update_term_meta( $term_id, self::META_COLOR, sanitize_hex_color( (string) $args['color'] ) );
		}

		if ( ! empty( $args['legacy_role'] ) ) {
			update_term_meta( $term_id, self::META_LEGACY, sanitize_key( (string) $args['legacy_role'] ) );
		}

		Catalogue::rebuild();

		return $term_id;
	}

	/**
	 * Refuse slugs that would collide with something already meaningful.
	 *
	 * A tag is injected into WP_Roles at runtime. If the slug is already a
	 * real role, the injection would either be ignored or shadow a role that
	 * grants capabilities — neither is acceptable, so it is rejected up front.
	 *
	 * @param string $slug Slug.
	 * @return true|\WP_Error
	 */
	public static function slug_conflict( string $slug ) {
		if ( self::get_by_slug( $slug ) ) {
			return new \WP_Error( 'user_tags_exists', __( 'A tag with that slug already exists.', 'user-tags' ) );
		}

		if ( isset( Roles::stored_slugs()[ $slug ] ) ) {
			return new \WP_Error(
				'user_tags_role_exists',
				sprintf(
					/* translators: %s: role slug. */
					__( '"%s" is already a real role. Converting a role into a tag is not something the free tier does; create the tag under a different slug, or delete the role first.', 'user-tags' ),
					$slug
				)
			);
		}

		return true;
	}

	/**
	 * Update a tag.
	 *
	 * @param string $slug Existing slug.
	 * @param array  $args name, description, color, legacy_role.
	 * @return true|\WP_Error
	 */
	public static function update( string $slug, array $args ) {
		$term = self::get_by_slug( $slug );

		if ( ! $term ) {
			return new \WP_Error( 'user_tags_unknown_tag', __( 'That tag does not exist.', 'user-tags' ) );
		}

		$fields = array();

		if ( isset( $args['name'] ) && '' !== $args['name'] ) {
			$fields['name'] = (string) $args['name'];
		}

		if ( isset( $args['description'] ) ) {
			$fields['description'] = (string) $args['description'];
		}

		if ( $fields ) {
			$result = wp_update_term( $term->term_id, self::NAME, $fields );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'color', $args ) ) {
			$color = sanitize_hex_color( (string) $args['color'] );

			if ( $color ) {
				update_term_meta( $term->term_id, self::META_COLOR, $color );
			} else {
				delete_term_meta( $term->term_id, self::META_COLOR );
			}
		}

		if ( array_key_exists( 'legacy_role', $args ) ) {
			$legacy = sanitize_key( (string) $args['legacy_role'] );

			if ( $legacy ) {
				update_term_meta( $term->term_id, self::META_LEGACY, $legacy );
			} else {
				delete_term_meta( $term->term_id, self::META_LEGACY );
			}
		}

		Catalogue::rebuild();

		return true;
	}

	/**
	 * Delete a tag and every assignment of it.
	 *
	 * @param string $slug Slug.
	 * @return true|\WP_Error
	 */
	public static function delete( string $slug ) {
		$term = self::get_by_slug( $slug );

		if ( ! $term ) {
			return new \WP_Error( 'user_tags_unknown_tag', __( 'That tag does not exist.', 'user-tags' ) );
		}

		$result = wp_delete_term( $term->term_id, self::NAME );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Catalogue::rebuild();

		/*
		 * The usermeta mirrors still name this tag. They are harmless — the
		 * runtime intersects them with the catalogue, so a tag that no longer
		 * exists is never injected — but they are rubbish, and the daily prune
		 * clears them out.
		 */
		Store::flush_memo();

		if ( ! wp_next_scheduled( 'user_tags_prune_mirrors' ) ) {
			wp_schedule_single_event( time() + 60, 'user_tags_prune_mirrors' );
		}

		return true;
	}

	/**
	 * Fetch a tag term by slug.
	 *
	 * @param string $slug Slug.
	 * @return \WP_Term|null
	 */
	public static function get_by_slug( string $slug ): ?\WP_Term {
		self::register();

		$term = get_term_by( 'slug', sanitize_key( $slug ), self::NAME );

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Every tag term, with counts.
	 *
	 * @return \WP_Term[]
	 */
	public static function all_terms(): array {
		self::register();

		$terms = get_terms(
			array(
				'taxonomy'   => self::NAME,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}
}
