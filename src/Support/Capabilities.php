<?php
/**
 * What counts as a capability WordPress can account for.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/**
	 * Capabilities shipped by core, single site and network.
	 *
	 * Includes meta capabilities (edit_post, delete_user, …). They are almost
	 * never stored on a role, but a site that does store one is not carrying an
	 * unknown capability, and this list exists to avoid saying otherwise.
	 *
	 * @var string[]
	 */
	private const CORE = array(
		// Primitive, single site.
		'activate_plugins',
		'delete_others_pages',
		'delete_others_posts',
		'delete_pages',
		'delete_plugins',
		'delete_posts',
		'delete_private_pages',
		'delete_private_posts',
		'delete_published_pages',
		'delete_published_posts',
		'delete_themes',
		'delete_users',
		'create_users',
		'edit_dashboard',
		'edit_files',
		'edit_others_pages',
		'edit_others_posts',
		'edit_pages',
		'edit_plugins',
		'edit_posts',
		'edit_private_pages',
		'edit_private_posts',
		'edit_published_pages',
		'edit_published_posts',
		'edit_theme_options',
		'edit_themes',
		'edit_users',
		'export',
		'import',
		'install_plugins',
		'install_themes',
		'list_users',
		'manage_categories',
		'manage_links',
		'manage_options',
		'moderate_comments',
		'promote_users',
		'publish_pages',
		'publish_posts',
		'read',
		'read_private_pages',
		'read_private_posts',
		'remove_users',
		'switch_themes',
		'unfiltered_html',
		'unfiltered_upload',
		'update_core',
		'update_plugins',
		'update_themes',
		'upload_files',
		'customize',
		'delete_site',
		'install_languages',
		'update_php',
		'update_https',
		'export_others_personal_data',
		'erase_others_personal_data',
		'manage_privacy_options',
		'view_site_health_checks',
		'resume_plugins',
		'resume_themes',
		'deactivate_plugins',
		'upload_plugins',
		'upload_themes',
		'edit_css',

		// Network.
		'create_sites',
		'delete_sites',
		'manage_network',
		'manage_network_options',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_users',
		'manage_sites',
		'setup_network',
		'upgrade_network',

		// Meta capabilities resolved through map_meta_cap().
		'add_post_meta',
		'assign_term',
		'delete_post',
		'delete_post_meta',
		'delete_term',
		'delete_user',
		'edit_comment',
		'edit_post',
		'edit_post_meta',
		'edit_term',
		'edit_user',
		'promote_user',
		'publish_post',
		'read_post',
		'read_page',
		'edit_page',
		'delete_page',
		'remove_user',
	);

	/**
	 * Deprecated user levels, kept by core for backwards compatibility.
	 *
	 * @return string[]
	 */
	public static function levels(): array {
		$levels = array();

		for ( $i = 0; $i <= 10; $i++ ) {
			$levels[] = 'level_' . $i;
		}

		return $levels;
	}

	/**
	 * Is this capability name a deprecated user level?
	 *
	 * @param string $cap Cap.
	 */
	public static function is_level( string $cap ): bool {
		return (bool) preg_match( '/^level_(10|[0-9])$/', $cap );
	}

	/**
	 * Capabilities that grant nothing on their own: `read` plus the levels.
	 *
	 * A role whose granted capabilities are entirely contained in this set
	 * gives its holders no permission they did not already have as a
	 * Subscriber, which makes it a tag wearing a role's clothes.
	 *
	 * @return string[]
	 */
	public static function inert(): array {
		return array_merge( array( 'read' ), self::levels() );
	}

	/**
	 * Every capability this installation can account for.
	 *
	 * Core, plus everything derived from registered post types and taxonomies.
	 * Must be called after `init`, or post types registered by other plugins
	 * will be missing and their capabilities will look unrecognised.
	 *
	 * @return array<string,string> Capability name => where it came from.
	 */
	public static function recognised(): array {
		$recognised = array();

		foreach ( self::CORE as $cap ) {
			$recognised[ $cap ] = 'core';
		}

		foreach ( self::levels() as $cap ) {
			$recognised[ $cap ] = 'core (deprecated level)';
		}

		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			foreach ( (array) $post_type->cap as $cap ) {
				if ( is_string( $cap ) && ! isset( $recognised[ $cap ] ) ) {
					$recognised[ $cap ] = 'post type: ' . $post_type->name;
				}
			}
		}

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			foreach ( (array) $taxonomy->cap as $cap ) {
				if ( is_string( $cap ) && ! isset( $recognised[ $cap ] ) ) {
					$recognised[ $cap ] = 'taxonomy: ' . $taxonomy->name;
				}
			}
		}

		/**
		 * Filter the set of capabilities the auditor can account for.
		 *
		 * Register your own here rather than letting them be reported as
		 * unrecognised.
		 *
		 * @param array<string,string> $recognised Capability => source label.
		 */
		return (array) apply_filters( 'leanroles_recognised_capabilities', $recognised );
	}
}
