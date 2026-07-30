<?php
/**
 * Access to the raw role option, plus restore points.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Support;

defined( 'ABSPATH' ) || exit;

final class Roles {

	/** Option holding the timestamped restore points. Never autoloaded. */
	public const BACKUP_OPTION = 'leanroles_roles_backup';

	/** How many restore points to keep. */
	public const BACKUP_LIMIT = 10;

	/**
	 * Name of the core role option for the current site.
	 *
	 * Multisite stores one per site, prefixed with the blog prefix.
	 *
	 * @param int|null $blog_id Blog id.
	 */
	public static function option_name( ?int $blog_id = null ): string {
		global $wpdb;
		return $wpdb->get_blog_prefix( $blog_id ) . 'user_roles';
	}

	/**
	 * The raw serialized option_value straight from the database.
	 *
	 * Deliberately not get_option(): the auditor measures what MySQL stores and
	 * what PHP has to unserialize, not what a round trip through the object
	 * cache happens to hand back.
	 *
	 * @param int|null $blog_id Blog id.
	 * @return string|null Null when the row does not exist.
	 */
	public static function raw_option_value( ?int $blog_id = null ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::option_name( $blog_id )
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Role definitions as stored, without the in-memory additions made by
	 * LeanRoles or anyone else on `wp_roles_init`.
	 *
	 * @param int|null $blog_id Blog id.
	 * @return array<string,array{name:string,capabilities:array<string,bool>}>
	 */
	public static function stored_roles( ?int $blog_id = null ): array {
		$raw = self::raw_option_value( $blog_id );

		if ( null === $raw ) {
			return array();
		}

		$roles = maybe_unserialize( $raw );

		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * Capabilities of a role that are actually granted (value truthy).
	 *
	 * @param array $role Role definition.
	 * @return string[] Sorted capability names.
	 */
	public static function granted_caps( array $role ): array {
		$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
		$caps = array_keys( array_filter( $caps ) );
		sort( $caps );

		return $caps;
	}

	/**
	 * Take a restore point of the core role option.
	 *
	 * @param string $reason Free-text label.
	 * @return array The stored entry.
	 */
	public static function create_backup( string $reason = 'manual' ): array {
		$raw = self::raw_option_value();

		$entry = array(
			'id'        => uniqid( 'lr', true ),
			'timestamp' => time(),
			'reason'    => $reason,
			'blog_id'   => get_current_blog_id(),
			'option'    => self::option_name(),
			'bytes'     => null === $raw ? 0 : strlen( $raw ),
			'sha256'    => null === $raw ? '' : hash( 'sha256', $raw ),
			'value'     => $raw,
		);

		$backups   = self::backups();
		$backups[] = $entry;

		if ( count( $backups ) > self::BACKUP_LIMIT ) {
			$backups = array_slice( $backups, -self::BACKUP_LIMIT );
		}

		update_option( self::BACKUP_OPTION, $backups, false );

		return $entry;
	}

	/**
	 * All restore points, oldest first.
	 */
	public static function backups(): array {
		$backups = get_option( self::BACKUP_OPTION, array() );

		return is_array( $backups ) ? $backups : array();
	}

	/**
	 * Restore a previously taken restore point.
	 *
	 * @param string $id Restore point id, or an empty string for the most recent.
	 * @return true|\WP_Error
	 */
	public static function restore_backup( string $id = '' ) {
		$backups = self::backups();

		if ( ! $backups ) {
			return new \WP_Error( 'leanroles_no_backup', __( 'There are no restore points to restore from.', 'leanroles' ) );
		}

		$entry = null;

		if ( '' === $id ) {
			$entry = end( $backups );
		} else {
			foreach ( $backups as $candidate ) {
				if ( $candidate['id'] === $id ) {
					$entry = $candidate;
					break;
				}
			}
		}

		if ( ! $entry ) {
			return new \WP_Error( 'leanroles_unknown_backup', __( 'That restore point does not exist.', 'leanroles' ) );
		}

		if ( null === $entry['value'] ) {
			return new \WP_Error( 'leanroles_empty_backup', __( 'That restore point holds no role option; refusing to restore it.', 'leanroles' ) );
		}

		if ( hash( 'sha256', $entry['value'] ) !== $entry['sha256'] ) {
			return new \WP_Error( 'leanroles_corrupt_backup', __( 'The restore point failed its integrity check and has not been applied.', 'leanroles' ) );
		}

		$roles = maybe_unserialize( $entry['value'] );

		if ( ! is_array( $roles ) || ! $roles ) {
			return new \WP_Error( 'leanroles_corrupt_backup', __( 'The restore point did not unserialize to a role array.', 'leanroles' ) );
		}

		update_option( self::option_name(), $roles );

		// The in-memory WP_Roles instance is now stale.
		unset( $GLOBALS['wp_roles'] );

		return true;
	}

	/**
	 * Delete a role, moving its users to another role.
	 *
	 * This is a primitive, not a conversion: it does not analyse what those
	 * users lose. Callers are expected to have read the audit first.
	 *
	 * @param string $slug     Role to delete.
	 * @param string $reassign Role to move its users to, or '' to leave them roleless.
	 * @return int|\WP_Error Number of users moved.
	 */
	public static function delete_role( string $slug, string $reassign = '' ) {
		$wp_roles = wp_roles();

		if ( ! isset( $wp_roles->roles[ $slug ] ) ) {
			return new \WP_Error( 'leanroles_unknown_role', __( 'That role does not exist.', 'leanroles' ) );
		}

		$protected = (array) apply_filters( 'leanroles_protected_roles', array( 'administrator' ) );

		if ( in_array( $slug, $protected, true ) ) {
			return new \WP_Error(
				'leanroles_protected_role',
				sprintf(
					/* translators: %s: role slug. */
					__( 'The role "%s" is protected and cannot be deleted.', 'leanroles' ),
					$slug
				)
			);
		}

		if ( '' !== $reassign && ! isset( $wp_roles->roles[ $reassign ] ) ) {
			return new \WP_Error( 'leanroles_unknown_role', __( 'The reassignment target role does not exist.', 'leanroles' ) );
		}

		self::create_backup( 'delete_role:' . $slug );

		$moved  = 0;
		$passes = 0;

		do {
			// Always page one: every pass shrinks the result set.
			$users = get_users(
				array(
					'role'    => $slug,
					'fields'  => 'ID',
					'number'  => 200,
					'orderby' => 'ID',
				)
			);

			foreach ( $users as $user_id ) {
				$user = new \WP_User( $user_id );
				$user->remove_role( $slug );

				if ( '' !== $reassign && ! in_array( $reassign, (array) $user->roles, true ) ) {
					$user->add_role( $reassign );
				}

				++$moved;
			}

			++$passes;
			// Guard against a filter that keeps re-adding the role.
		} while ( count( $users ) > 0 && $passes < 10000 );

		remove_role( $slug );

		return $moved;
	}
}
