<?php
/**
 * Making WP_User_Query understand tags.
 *
 * Tags are never written to the capabilities meta, so core's role query — a
 * LIKE against `{prefix}capabilities` — cannot find them. Every `role`,
 * `role__in` and `role__not_in` argument is therefore intercepted before the
 * query is built and any tag slug is rewritten into the equivalent clause
 * against the usermeta mirror.
 *
 * The rewrite is deliberately the same shape as what core does for roles: an
 * unindexed LIKE on usermeta. No better, but no worse, and with no ceiling on
 * how many users a tag may have. Code that already filters users by role slug
 * keeps working against a converted role without being told anything changed.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Query {

	/**
	 * Attach the hooks.
	 */
	public static function boot(): void {
		add_action( 'pre_get_users', array( __CLASS__, 'translate_tag_queries' ) );
		add_action( 'pre_user_query', array( __CLASS__, 'apply_resume_cursor' ) );
	}

	/**
	 * Rewrite tag slugs found in role arguments into meta queries.
	 *
	 * @param \WP_User_Query $query Query, by reference.
	 */
	public static function translate_tag_queries( $query ): void {
		if ( ! Catalogue::all() ) {
			return;
		}

		$qv      = &$query->query_vars;
		$blog_id = isset( $qv['blog_id'] ) ? absint( $qv['blog_id'] ) : get_current_blog_id();
		$clauses = array();

		// role: every listed slug must be held (AND).
		if ( ! empty( $qv['role'] ) ) {
			list( $tags, $roles ) = Catalogue::partition( self::as_list( $qv['role'] ) );

			if ( $tags ) {
				foreach ( $tags as $slug ) {
					$clauses[] = self::mirror_clause( $slug, $blog_id );
				}

				$qv['role'] = $roles;
			}
		}

		// role__in: any one of the listed slugs (OR). Roles and tags live in
		// different meta keys, so the whole group is rebuilt by hand.
		if ( ! empty( $qv['role__in'] ) ) {
			list( $tags, $roles ) = Catalogue::partition( self::as_list( $qv['role__in'] ) );

			if ( $tags ) {
				$group = array( 'relation' => 'OR' );

				foreach ( $roles as $slug ) {
					$group[] = self::capability_clause( $slug, $blog_id );
				}

				foreach ( $tags as $slug ) {
					$group[] = self::mirror_clause( $slug, $blog_id );
				}

				$clauses[]      = $group;
				$qv['role__in'] = array();
			}
		}

		// role__not_in: none of the listed slugs (AND NOT).
		if ( ! empty( $qv['role__not_in'] ) ) {
			list( $tags, $roles ) = Catalogue::partition( self::as_list( $qv['role__not_in'] ) );

			if ( $tags ) {
				foreach ( $tags as $slug ) {
					$clauses[] = self::mirror_clause( $slug, $blog_id, 'NOT LIKE' );
				}

				$qv['role__not_in'] = $roles;
			}
		}

		// user_tags_tag: an explicit argument, for callers who would rather not
		// overload `role`.
		if ( ! empty( $qv['user_tags_tag'] ) ) {
			list( $tags ) = Catalogue::partition( self::as_list( $qv['user_tags_tag'] ) );

			foreach ( $tags as $slug ) {
				$clauses[] = self::mirror_clause( $slug, $blog_id );
			}
		}

		if ( ! $clauses ) {
			return;
		}

		$existing = isset( $qv['meta_query'] ) && is_array( $qv['meta_query'] ) ? $qv['meta_query'] : array();

		if ( $existing ) {
			$qv['meta_query'] = array(
				'relation' => 'AND',
				$existing,
				array_merge( array( 'relation' => 'AND' ), $clauses ),
			);
		} else {
			$qv['meta_query'] = array_merge( array( 'relation' => 'AND' ), $clauses );
		}
	}

	/**
	 * Support the `user_tags_id_after` cursor used by resumable batch commands.
	 *
	 * @param \WP_User_Query $query Query, by reference.
	 */
	public static function apply_resume_cursor( $query ): void {
		global $wpdb;

		$after = isset( $query->query_vars['user_tags_id_after'] ) ? (int) $query->query_vars['user_tags_id_after'] : 0;

		if ( $after > 0 ) {
			$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", $after );
		}
	}

	/**
	 * A meta clause matching the tag mirror.
	 *
	 * The mirror is a serialized list of slugs, so the quoted slug is looked
	 * for verbatim: `"gold"` matches `s:4:"gold";` and cannot match `"golden"`.
	 * Slugs are sanitize_key()'d, so they never contain a quote of their own.
	 *
	 * @param string $slug    Tag slug.
	 * @param int    $blog_id Site id. The mirror key is blog-prefixed, because
	 *                        usermeta is global on a network while tags are not.
	 * @param string $compare LIKE or NOT LIKE.
	 * @return array
	 */
	private static function mirror_clause( string $slug, int $blog_id, string $compare = 'LIKE' ): array {
		return array(
			'key'     => Store::mirror_key( $blog_id ),
			'value'   => '"' . $slug . '"',
			'compare' => $compare,
		);
	}

	/**
	 * The clause core would have built for a real role.
	 *
	 * @param string $slug    Role slug.
	 * @param int    $blog_id Site id.
	 * @return array
	 */
	private static function capability_clause( string $slug, int $blog_id ): array {
		global $wpdb;

		return array(
			'key'     => $wpdb->get_blog_prefix( $blog_id ) . 'capabilities',
			'value'   => '"' . $slug . '"',
			'compare' => 'LIKE',
		);
	}

	/**
	 * Normalize a role argument, which core accepts as a string, a
	 * comma-separated string, or an array.
	 *
	 * @param mixed $value Raw argument.
	 * @return string[]
	 */
	private static function as_list( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $value ) ) ) );
		}

		if ( is_string( $value ) && '' !== $value ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		}

		return array();
	}
}
