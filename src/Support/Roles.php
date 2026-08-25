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
	 * Change a role's display name, or what it grants.
	 *
	 * The primitive the free tier was missing. §4.2 of the product plan is
	 * emphatic about why it belongs there: a plugin that diagnoses and lets you
	 * touch nothing reads as crippled, and the directory punishes that without
	 * appeal.
	 *
	 * Command line only, deliberately. The product is an auditor; a screen of
	 * capability checkboxes would anchor it in a market priced at a third of
	 * this one. So the capability exists and the screen does not.
	 *
	 * A restore point is taken first, exactly as deletion does. Editing a role
	 * is the other way to lose a configuration by accident.
	 *
	 * @param string             $slug   Role to change.
	 * @param string|null        $name   New display name, or null to leave it.
	 * @param array<string,bool> $caps   Capabilities to set; true grants, false
	 *                                   denies. Absent keys are left alone.
	 * @param string[]           $remove Capability names to drop entirely.
	 * @return array{name:string,granted:int,denied:int}|\WP_Error
	 */
	public static function update_role( string $slug, ?string $name = null, array $caps = array(), array $remove = array() ) {
		$roles = self::stored_roles();

		if ( ! isset( $roles[ $slug ] ) ) {
			return new \WP_Error( 'leanroles_unknown_role', __( 'That role does not exist.', 'leanroles' ) );
		}

		/*
		 * Administrator is protected from editing as well as deletion, and the
		 * failure mode is worse here: removing a capability from it can lock out
		 * the only account that could put it back.
		 */
		$protected = (array) apply_filters( 'leanroles_protected_roles', array( 'administrator' ) );

		if ( in_array( $slug, $protected, true ) ) {
			return new \WP_Error(
				'leanroles_protected_role',
				sprintf(
					/* translators: %s: role slug. */
					__( 'The role "%s" is protected and cannot be edited.', 'leanroles' ),
					$slug
				)
			);
		}

		if ( null === $name && ! $caps && ! $remove ) {
			return new \WP_Error( 'leanroles_nothing_to_do', __( 'Nothing to change.', 'leanroles' ) );
		}

		self::create_backup( 'update_role:' . $slug );

		$role         = $roles[ $slug ];
		$capabilities = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();

		if ( null !== $name && '' !== $name ) {
			$role['name'] = $name;
		}

		foreach ( $caps as $cap => $granted ) {
			$capabilities[ $cap ] = (bool) $granted;
		}

		foreach ( $remove as $cap ) {
			unset( $capabilities[ $cap ] );
		}

		$role['capabilities'] = $capabilities;
		$roles[ $slug ]       = $role;

		update_option( self::option_name(), $roles );
		unset( $GLOBALS['wp_roles'] );

		$granted = count( array_filter( $capabilities ) );

		return array(
			'name'    => (string) $role['name'],
			'granted' => $granted,
			'denied'  => count( $capabilities ) - $granted,
		);
	}

	/**
	 * The stored role configuration, in a form that survives a trip through a
	 * file and into another site.
	 *
	 * Not the same thing as a restore point. A backup is a snapshot of this site
	 * meant to come back to this site; an export is meant to leave. So it says
	 * where it came from and when, which is what lets a receiving site refuse
	 * sensibly, and carries none of the internals a restore relies on.
	 *
	 * @return array{generated:string,site:string,option:string,roles:array}
	 */
	public static function export_roles(): array {
		return array(
			'generated' => gmdate( 'c' ),
			'site'      => home_url(),
			'option'    => self::option_name(),
			'roles'     => self::stored_roles(),
		);
	}

	/**
	 * Apply an exported configuration.
	 *
	 * Merge adds and overwrites the roles the file names and leaves the rest of
	 * the site alone. Replace makes the site's roles exactly the file's, which is
	 * the dangerous one and is why a restore point is taken first.
	 *
	 * Protected roles are never touched in either mode: importing a file that
	 * omits `administrator` must not be a way to delete it.
	 *
	 * @param array  $payload Decoded export.
	 * @param string $mode    'merge' or 'replace'.
	 * @param bool   $dry_run Work the change out and apply nothing.
	 * @return array{added:string[],changed:string[],removed:string[],kept:string[]}|\WP_Error
	 */
	public static function import_roles( array $payload, string $mode = 'merge', bool $dry_run = false ) {
		if ( empty( $payload['roles'] ) || ! is_array( $payload['roles'] ) ) {
			return new \WP_Error( 'leanroles_bad_export', __( 'That file carries no roles.', 'leanroles' ) );
		}

		if ( ! in_array( $mode, array( 'merge', 'replace' ), true ) ) {
			return new \WP_Error( 'leanroles_bad_mode', __( 'Mode must be merge or replace.', 'leanroles' ) );
		}

		$incoming  = $payload['roles'];
		$current   = self::stored_roles();
		$protected = (array) apply_filters( 'leanroles_protected_roles', array( 'administrator' ) );

		$added   = array();
		$changed = array();
		$removed = array();
		$kept    = array();

		foreach ( $incoming as $slug => $role ) {
			if ( ! is_array( $role ) || ! isset( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
				return new \WP_Error(
					'leanroles_bad_export',
					sprintf(
						/* translators: %s: role slug. */
						__( 'The role "%s" in that file has no capabilities array.', 'leanroles' ),
						$slug
					)
				);
			}

			if ( in_array( $slug, $protected, true ) ) {
				$kept[] = $slug;
			} elseif ( ! isset( $current[ $slug ] ) ) {
				$added[] = $slug;
			} elseif ( $current[ $slug ] !== $role ) {
				$changed[] = $slug;
			}
		}

		if ( 'replace' === $mode ) {
			foreach ( array_keys( $current ) as $slug ) {
				if ( ! isset( $incoming[ $slug ] ) && ! in_array( $slug, $protected, true ) ) {
					$removed[] = $slug;
				}
			}
		}

		if ( $dry_run ) {
			return compact( 'added', 'changed', 'removed', 'kept' );
		}

		self::create_backup( 'import_roles:' . $mode );

		$result = array();

		if ( 'replace' === $mode ) {
			foreach ( $protected as $slug ) {
				if ( isset( $current[ $slug ] ) ) {
					$result[ $slug ] = $current[ $slug ];
				}
			}
		} else {
			$result = $current;
		}

		foreach ( $incoming as $slug => $role ) {
			if ( ! in_array( $slug, $protected, true ) ) {
				$result[ $slug ] = $role;
			}
		}

		update_option( self::option_name(), $result );
		unset( $GLOBALS['wp_roles'] );

		return compact( 'added', 'changed', 'removed', 'kept' );
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
