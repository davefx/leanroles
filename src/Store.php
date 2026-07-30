<?php
/**
 * Tag assignment: term relationships as the source of truth, one usermeta key
 * as a read mirror.
 *
 * The split is not indecision. The reverse lookup ("every user carrying tag
 * X") is an index seek on term_taxonomy_id and nothing else comes close. The
 * forward lookup ("what does this user carry") happens on every capability
 * check of every request, and usermeta is already in the metadata cache that
 * WP_User_Query and cache_users() prime for free — so the mirror makes the
 * hot path cost zero additional queries.
 *
 * If the two ever disagree, the taxonomy is right.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Store {

	/**
	 * Per-request memo for the runtime hot path.
	 *
	 * @var array<int,string[]>
	 */
	private static $memo = array();

	/**
	 * Blog id to mirror meta key.
	 *
	 * @var array<int,string>
	 */
	private static $keys = array();

	/**
	 * The mirror meta key for the site currently in scope.
	 *
	 * Blog-prefixed, exactly as core prefixes `capabilities` and `user_level`,
	 * and for exactly the same reason: usermeta is global on a network while
	 * term relationships are not. An unprefixed key would leak one site's tags
	 * into every other site on the network.
	 *
	 * @param int|null $blog_id Blog id.
	 */
	public static function mirror_key( ?int $blog_id = null ): string {
		global $wpdb;

		$blog_id = null === $blog_id ? get_current_blog_id() : $blog_id;

		if ( ! isset( self::$keys[ $blog_id ] ) ) {
			self::$keys[ $blog_id ] = $wpdb->get_blog_prefix( $blog_id ) . 'user_tags';
		}

		return self::$keys[ $blog_id ];
	}

	/**
	 * Tags for the hot path: mirror only, no fallback, no queries of its own.
	 *
	 * Called from `user_has_cap`, which fires hundreds of times per admin
	 * request, so it must never do anything but read a cache. Slugs are
	 * intersected with the catalogue, which is what makes a stale mirror
	 * (a tag deleted while assignments remained) inert rather than dangerous.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function runtime_tags( int $user_id ): array {
		if ( isset( self::$memo[ $user_id ] ) ) {
			return self::$memo[ $user_id ];
		}

		if ( $user_id <= 0 ) {
			return array();
		}

		$raw  = get_user_meta( $user_id, self::mirror_key(), true );
		$tags = is_array( $raw ) ? $raw : array();

		if ( $tags ) {
			$known = Catalogue::slugs_map();
			$tags  = array_values( array_filter( $tags, static fn( $slug ) => isset( $known[ $slug ] ) ) );
		}

		self::$memo[ $user_id ] = $tags;

		return $tags;
	}

	/**
	 * Tags for a user, repairing the mirror if it has never been written.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function get_tags( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::mirror_key(), true );

		if ( ! is_array( $raw ) ) {
			return self::sync_mirror( $user_id );
		}

		return self::runtime_tags( $user_id );
	}

	/**
	 * Replace a user's tags outright.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $slugs   Tag slugs. Unknown ones are dropped.
	 * @return string[]|\WP_Error The slugs now held.
	 */
	public static function set_tags( int $user_id, array $slugs ) {
		if ( ! taxonomy_exists( Taxonomy::NAME ) ) {
			Taxonomy::register();
		}

		if ( ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'user_tags_unknown_user', __( 'That user does not exist.', 'user-tags-lib' ) );
		}

		$catalogue = Catalogue::all();
		$before    = self::get_tags( $user_id );

		$term_ids = array();
		$clean    = array();

		foreach ( array_unique( array_map( 'strval', $slugs ) ) as $slug ) {
			if ( isset( $catalogue[ $slug ] ) ) {
				$term_ids[] = (int) $catalogue[ $slug ]['term_id'];
				$clean[]    = $slug;
			}
		}

		// Integers, so wp_set_object_terms() resolves them as ids rather than names.
		$result = wp_set_object_terms( $user_id, $term_ids, Taxonomy::NAME, false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		sort( $clean );
		self::write_mirror( $user_id, $clean );

		foreach ( array_diff( $clean, $before ) as $added ) {
			/**
			 * Fires after a tag is added to a user.
			 */
			do_action( 'user_tags_added', $user_id, $added );
		}

		foreach ( array_diff( $before, $clean ) as $removed ) {
			/**
			 * Fires after a tag is removed from a user.
			 */
			do_action( 'user_tags_removed', $user_id, $removed );
		}

		return $clean;
	}

	/**
	 * Add one or more tags, leaving the rest alone.
	 *
	 * @param int             $user_id User id.
	 * @param string|string[] $tags    Slugs.
	 * @return string[]|\WP_Error
	 */
	public static function add( int $user_id, $tags ) {
		$current = self::get_tags( $user_id );

		return self::set_tags( $user_id, array_merge( $current, (array) $tags ) );
	}

	/**
	 * Remove one or more tags.
	 *
	 * @param int             $user_id User id.
	 * @param string|string[] $tags    Slugs.
	 * @return string[]|\WP_Error
	 */
	public static function remove( int $user_id, $tags ) {
		$current = self::get_tags( $user_id );

		return self::set_tags( $user_id, array_values( array_diff( $current, (array) $tags ) ) );
	}

	/**
	 * Rebuild one user's mirror from the taxonomy.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function sync_mirror( int $user_id ): array {
		if ( ! taxonomy_exists( Taxonomy::NAME ) ) {
			Taxonomy::register();
		}

		$terms = wp_get_object_terms( $user_id, Taxonomy::NAME, array( 'fields' => 'slugs' ) );
		$slugs = is_wp_error( $terms ) ? array() : array_values( $terms );

		sort( $slugs );
		self::write_mirror( $user_id, $slugs );

		return $slugs;
	}

	/**
	 * Users carrying a tag.
	 *
	 * Goes through term_taxonomy_id, which is indexed, rather than through the
	 * usermeta mirror, which is not.
	 *
	 * @param string $slug Tag slug.
	 * @param array  $args number, offset, fields.
	 * @return int[]|\WP_User[]
	 */
	public static function users_by_tag( string $slug, array $args = array() ): array {
		$term = Taxonomy::get_by_slug( $slug );

		if ( ! $term ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'number' => 0,
				'offset' => 0,
				'fields' => 'ids',
			)
		);

		global $wpdb;

		/*
		 * Two whole statements rather than one built by concatenating prepared
		 * fragments. Gluing prepare() output together happens to be safe, but
		 * it is safe only as long as every future edit keeps it so, and neither
		 * a reader nor a static analyser can check that at a glance.
		 */
		if ( $args['number'] > 0 ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$wpdb->term_relationships}
					 WHERE term_taxonomy_id = %d
					 ORDER BY object_id ASC
					 LIMIT %d OFFSET %d",
					$term->term_taxonomy_id,
					(int) $args['number'],
					(int) $args['offset']
				)
			);
		} else {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$wpdb->term_relationships}
					 WHERE term_taxonomy_id = %d
					 ORDER BY object_id ASC",
					$term->term_taxonomy_id
				)
			);
		}

		$ids = array_map( 'intval', (array) $rows );

		if ( 'ids' === $args['fields'] || 'ID' === $args['fields'] ) {
			return $ids;
		}

		return array_map( static fn( $id ) => new \WP_User( $id ), $ids );
	}

	/**
	 * Assign a tag to every user holding a given role, in batches.
	 *
	 * A command that dies halfway through leaving a half-applied tag is worse
	 * than no command, so this is resumable: it walks user ids in ascending
	 * order and reports where it stopped.
	 *
	 * @param string   $slug       Tag slug.
	 * @param string   $role       Role slug.
	 * @param int      $batch_size Users per pass.
	 * @param int      $after_id   Resume point; only users with a greater id are touched.
	 * @param callable $progress   Optional callback, receives (processed, last_id).
	 * @return array{processed:int,last_id:int}
	 */
	public static function assign_by_role( string $slug, string $role, int $batch_size = 200, int $after_id = 0, ?callable $progress = null ): array {
		$processed = 0;
		$last_id   = $after_id;

		do {
			$query = new \WP_User_Query(
				array(
					'role'               => $role,
					'fields'             => 'ID',
					'number'             => $batch_size,
					'orderby'            => 'ID',
					'order'              => 'ASC',
					'count_total'        => false,
					'user_tags_id_after' => $last_id,
				)
			);

			$ids = array_map( 'intval', (array) $query->get_results() );

			if ( $ids ) {
				// Two queries for the batch instead of two per user.
				cache_users( $ids );
			}

			foreach ( $ids as $user_id ) {
				self::add( $user_id, $slug );
				$last_id = $user_id;
				++$processed;
			}

			if ( $progress ) {
				$progress( $processed, $last_id );
			}

			// Long runs otherwise accumulate every user object touched so far.
			self::flush_memo();
			self::forget_users( $ids );
		} while ( count( $ids ) === $batch_size );

		return array(
			'processed' => $processed,
			'last_id'   => $last_id,
		);
	}

	/**
	 * Drop tags that no longer exist from the mirrors.
	 *
	 * Correctness does not depend on this — runtime_tags() intersects with the
	 * catalogue — but leaving dead slugs in usermeta forever is untidy.
	 */
	public static function prune_mirrors(): void {
		global $wpdb;

		$known = Catalogue::slugs_map();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 5000",
				self::mirror_key()
			)
		);

		foreach ( $rows as $row ) {
			$slugs = maybe_unserialize( $row->meta_value );

			if ( ! is_array( $slugs ) ) {
				continue;
			}

			$clean = array_values( array_filter( $slugs, static fn( $slug ) => isset( $known[ $slug ] ) ) );

			if ( count( $clean ) !== count( $slugs ) ) {
				self::write_mirror( (int) $row->user_id, $clean );
			}
		}

		self::flush_memo();
	}

	/**
	 * Forget the per-request memo, entirely or for one user.
	 *
	 * @param int|null $user_id User id.
	 */
	public static function flush_memo( ?int $user_id = null ): void {
		if ( null === $user_id ) {
			self::$memo = array();
			return;
		}

		unset( self::$memo[ $user_id ] );
	}

	/**
	 * Write the mirror and invalidate the memo.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $slugs   Slugs.
	 */
	private static function write_mirror( int $user_id, array $slugs ): void {
		update_user_meta( $user_id, self::mirror_key(), $slugs );
		self::flush_memo( $user_id );
	}

	/**
	 * Release the users a batch just touched.
	 *
	 * A long run would otherwise hold every user object it has ever seen, and
	 * a command that runs out of memory two thirds of the way through a
	 * conversion is the failure mode this whole method exists to prevent.
	 *
	 * clean_user_cache() rather than wp_cache_flush_group(): the group flush
	 * only arrived in WordPress 6.1, and the obvious fallback — reaching into
	 * WP_Object_Cache::$cache — is an overloaded property on older versions,
	 * where `unset()` on an element of it raises a notice and silently does
	 * nothing. This is public API on every supported version, and it drops
	 * exactly the entries this batch created rather than everyone else's too.
	 *
	 * @param int[] $user_ids Users to release.
	 */
	private static function forget_users( array $user_ids ): void {
		foreach ( $user_ids as $user_id ) {
			clean_user_cache( (int) $user_id );
		}
	}
}
