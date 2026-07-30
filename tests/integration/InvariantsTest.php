<?php
/**
 * Properties the plugin has to hold everywhere, not in one place.
 *
 * Coverage cannot see any of these: a line can be executed and still print an
 * unescaped tag name, still carry the wrong text domain, still quietly put
 * something in autoload. Each of these walks the whole surface instead.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Plugin;
use LeanRoles\Support\Roles;
use UserTags\Catalogue;
use UserTags\Store;
use LeanRoles\Tests\TestCase;

class InvariantsTest extends TestCase {

	/**
	 * Every PHP file that ships.
	 *
	 * @return string[]
	 */
	private function shipped_files(): array {
		$files = array( LEANROLES_PATH . 'leanroles.php', LEANROLES_PATH . 'uninstall.php' );

		foreach ( array( 'src', 'libraries' ) as $directory ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( LEANROLES_PATH . $directory, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Is this file part of the bundled library rather than the plugin?
	 *
	 * @param string $file Path.
	 */
	private function is_library( string $file ): bool {
		return false !== strpos( $file, '/libraries/user-tags/' );
	}

	/**
	 * A file's source with comments removed.
	 *
	 * Scanning raw source makes a test that fires on its own explanatory prose:
	 * a docblock saying "not wp_cache_flush_group(), because..." is not a call.
	 *
	 * @param string $file Path.
	 */
	private function code_only( string $file ): string {
		$out = '';

		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$out .= ' ';
					continue;
				}

				$out .= $token[1];
				continue;
			}

			$out .= $token;
		}

		return $out;
	}

	// ------------------------------------------------------------ autoload

	public function test_nothing_the_plugin_stores_is_autoloaded(): void {
		global $wpdb;

		Plugin::activate();

		$this->make_tag( 'gold' );
		Store::add( self::factory()->user->create(), 'gold' );
		Roles::create_backup( 'test' );

		$autoloaded = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE autoload IN ('yes','on','auto','auto-on')
			   AND ( option_name LIKE '%leanroles%' OR option_name LIKE '%user_tags%' )"
		);

		$this->assertSame(
			array(),
			$autoloaded,
			'Adding to autoload would be recreating the disease this plugin exists to treat.'
		);
	}

	public function test_the_backup_option_is_never_autoloaded(): void {
		Roles::create_backup( 'test' );

		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Roles::BACKUP_OPTION
			)
		);

		$this->assertNotContains( $autoload, array( 'yes', 'on', 'auto', 'auto-on' ) );
	}

	public function test_activation_adds_no_new_autoloaded_option(): void {
		global $wpdb;

		$before = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );

		Plugin::activate();
		$this->make_tag( 'gold' );

		$after = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );

		$this->assertSame( array(), array_diff( $after, $before ) );
	}

	// --------------------------------------------------------- text domain

	public function test_every_translatable_string_uses_the_plugin_text_domain(): void {
		$wrong = array();

		foreach ( $this->shipped_files() as $file ) {
			// The library must not borrow its host's domain: a plugin that
			// adopts it ships no `leanroles` translations.
			$expected = $this->is_library( $file ) ? "'user-tags-lib'" : "'leanroles'";

			foreach ( $this->i18n_calls( $file ) as $call ) {
				if ( $expected !== $call['domain'] ) {
					$wrong[] = sprintf(
						'%s:%d %s() has domain %s',
						str_replace( LEANROLES_PATH, '', $file ),
						$call['line'],
						$call['function'],
						$call['domain']
					);
				}
			}
		}

		$this->assertSame( array(), $wrong, "Strings with the wrong or a missing text domain:\n" . implode( "\n", $wrong ) );
	}

	public function test_the_scan_actually_finds_strings(): void {
		// A guard on the guard: a broken parser that found nothing would make
		// the test above pass for the wrong reason.
		$found = 0;

		foreach ( $this->shipped_files() as $file ) {
			$found += count( $this->i18n_calls( $file ) );
		}

		$this->assertGreaterThan( 100, $found );
	}

	/**
	 * Find translation calls and the domain each one passes.
	 *
	 * Tokenised rather than regexed: the arguments run across lines and contain
	 * commas and parentheses of their own.
	 *
	 * @param string $file Path.
	 * @return array<int,array{function:string,domain:string,line:int}>
	 */
	private function i18n_calls( string $file ): array {
		$translators = array(
			'__'            => 2,
			'_e'            => 2,
			'esc_html__'    => 2,
			'esc_html_e'    => 2,
			'esc_attr__'    => 2,
			'esc_attr_e'    => 2,
			'_x'            => 3,
			'esc_html_x'    => 3,
			'esc_attr_x'    => 3,
			'_n'            => 4,
			'_nx'           => 5,
		);

		$tokens = token_get_all( (string) file_get_contents( $file ) );
		$calls  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $translators[ $token[1] ] ) ) {
				continue;
			}

			// Skip a method call or a definition of the same name.
			$previous = $this->previous_meaningful( $tokens, $i );

			if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_FUNCTION, T_DOUBLE_COLON ), true ) ) {
				continue;
			}

			$open = $this->next_meaningful_index( $tokens, $i );

			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$args = $this->split_arguments( $tokens, $open );

			$calls[] = array(
				'function' => $token[1],
				'line'     => $token[2],
				'domain'   => count( $args ) === $translators[ $token[1] ] ? end( $args ) : '(missing)',
			);
		}

		return $calls;
	}

	/**
	 * Split the arguments of a call whose opening paren is at $open.
	 *
	 * @param array $tokens Token list.
	 * @param int   $open   Index of the opening parenthesis.
	 * @return string[] Each argument as trimmed source.
	 */
	private function split_arguments( array $tokens, int $open ): array {
		$depth   = 0;
		$args    = array();
		$current = '';
		$count   = count( $tokens );

		for ( $i = $open; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			$text  = is_array( $token ) ? $token[1] : $token;

			if ( '(' === $text || '[' === $text ) {
				++$depth;

				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $text || ']' === $text ) {
				--$depth;

				if ( 0 === $depth ) {
					$args[] = trim( $current );

					return array_values( array_filter( $args, static fn( $a ) => '' !== $a ) );
				}
			}

			if ( 1 === $depth && ',' === $text ) {
				$args[]  = trim( $current );
				$current = '';
				continue;
			}

			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
				$current .= ' ';
				continue;
			}

			$current .= $text;
		}

		return $args;
	}

	/**
	 * @param array $tokens Token list.
	 * @param int   $index  Current index.
	 * @return array|string|null
	 */
	private function previous_meaningful( array $tokens, int $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
				continue;
			}

			return $tokens[ $i ];
		}

		return null;
	}

	/**
	 * @param array $tokens Token list.
	 * @param int   $index  Current index.
	 */
	private function next_meaningful_index( array $tokens, int $index ): ?int {
		$count = count( $tokens );

		for ( $i = $index + 1; $i < $count; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
				continue;
			}

			return $i;
		}

		return null;
	}

	// ---------------------------------------------------------------- N + 1


	public function test_reading_tags_for_many_users_does_not_scale_with_the_count(): void {
		global $wpdb;

		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 10 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		foreach ( $ids as $id ) {
			clean_user_cache( $id );
		}

		Store::flush_memo();
		cache_users( $ids );

		$before = $wpdb->num_queries;

		foreach ( $ids as $id ) {
			leanroles_user_has_tag( $id, 'gold' );
		}

		$this->assertSame( $before, $wpdb->num_queries );
	}

	// ----------------------------------------------------- library isolation

	public function test_the_library_never_reaches_into_the_plugin(): void {
		$found = array();

		foreach ( $this->shipped_files() as $file ) {
			if ( ! $this->is_library( $file ) ) {
				continue;
			}

			if ( preg_match( '/\\bLeanRoles\\b/', $this->code_only( $file ) ) ) {
				$found[] = str_replace( LEANROLES_PATH, '', $file );
			}
		}

		$this->assertSame(
			array(),
			$found,
			"A library that reaches into the plugin bundling it is a library nobody else can adopt:\n"
			. implode( "\n", $found )
		);
	}

	public function test_the_library_stores_nothing_under_the_plugin_name(): void {
		$found = array();

		foreach ( $this->shipped_files() as $file ) {
			if ( ! $this->is_library( $file ) ) {
				continue;
			}

			if ( false !== stripos( $this->code_only( $file ), 'leanroles' ) ) {
				$found[] = str_replace( LEANROLES_PATH, '', $file );
			}
		}

		$this->assertSame( array(), $found, implode( "\n", $found ) );
	}

	public function test_the_library_creates_no_tables(): void {
		// The expensive mistake Action Scheduler made. Core storage only means
		// no schema version, no migrations, and nothing exotic left behind.
		$found = array();

		foreach ( $this->shipped_files() as $file ) {
			if ( ! $this->is_library( $file ) ) {
				continue;
			}

			foreach ( array( 'dbDelta', 'CREATE TABLE', 'DROP TABLE', 'ALTER TABLE' ) as $needle ) {
				if ( false !== stripos( $this->code_only( $file ), $needle ) ) {
					$found[] = str_replace( LEANROLES_PATH, '', $file ) . ' uses ' . $needle;
				}
			}
		}

		$this->assertSame( array(), $found, implode( "\n", $found ) );
	}

	public function test_the_library_plants_no_top_level_menu(): void {
		// Action Scheduler adds a Tools screen to every site that bundles it.
		// This library's screens are opt-in, and when they do appear they sit
		// under Users — never as an entry of their own in somebody's sidebar.
		$found = array();

		foreach ( $this->shipped_files() as $file ) {
			if ( ! $this->is_library( $file ) ) {
				continue;
			}

			foreach ( array( 'add_menu_page', 'add_management_page', 'add_options_page' ) as $needle ) {
				if ( false !== strpos( $this->code_only( $file ), $needle ) ) {
					$found[] = str_replace( LEANROLES_PATH, '', $file ) . ' calls ' . $needle . '()';
				}
			}
		}

		$this->assertSame( array(), $found, implode( "\n", $found ) );
	}

	public function test_the_library_screens_are_behind_a_filter(): void {
		$source = $this->code_only( LEANROLES_PATH . 'libraries/user-tags/src/Admin/Admin.php' );

		$this->assertStringContainsString(
			"apply_filters( 'user_tags_enable_admin'",
			$source,
			'Nothing may reach the admin menu of a site that did not ask for it.'
		);

		// And the screen files are not even read from disk before that check.
		$before = strpos( $source, "user_tags_enable_admin" );
		$after  = strpos( $source, "require_once" );

		$this->assertLessThan( $after, $before, 'The gate has to come before the loading.' );
	}

	public function test_the_registry_stays_frozen(): void {
		// Whichever copy is included first defines this class, so an old plugin
		// can leave an old registry arbitrating. It only stays correct while it
		// does nothing but collect and compare.
		$source = $this->code_only( LEANROLES_PATH . 'libraries/user-tags/src/Versions.php' );

		foreach ( array( 'get_option', 'update_option', '$wpdb', 'get_terms', 'wp_cache_' ) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$source,
				'The frozen registry must not grow behaviour; put it in the versioned bootstrap.'
			);
		}
	}

	// ------------------------------------------------- version floor hygiene

	public function test_nothing_reaches_into_the_object_cache_internals(): void {
		$found = array();

		foreach ( $this->shipped_files() as $file ) {
			$source = $this->code_only( $file );

			if ( preg_match( '/wp_object_cache\s*->\s*cache/i', $source ) ) {
				$found[] = str_replace( LEANROLES_PATH, '', $file );
			}
		}

		$this->assertSame(
			array(),
			$found,
			"WP_Object_Cache::\$cache is an overloaded property before WordPress 6.1, where\n"
			. "writing through it raises a notice and does nothing. Use the public API."
		);
	}

	public function test_no_shipped_file_calls_an_api_newer_than_the_declared_floor(): void {
		// Functions added after WordPress 5.9. Calling one unguarded is a fatal
		// on a site the plugin header says it supports.
		$too_new = array(
			'wp_cache_flush_group'      => '6.1',
			'wp_admin_notice'           => '6.4',
			'wp_get_admin_notice'       => '6.4',
			'wp_autoload_values_to_autoload' => '6.6',
			'wp_set_options_autoload'   => '6.6',
			'wp_trigger_error'          => '6.4',
			'wp_get_wp_version'         => '6.7',
			'array_is_list'             => 'PHP 8.1',
		);

		$found = array();

		foreach ( $this->shipped_files() as $file ) {
			$source = $this->code_only( $file );

			foreach ( $too_new as $function => $since ) {
				if ( ! preg_match( '/(?<![\w_$>])' . preg_quote( $function, '/' ) . '\s*\(/', $source ) ) {
					continue;
				}

				// A guarded call is fine; an unguarded one is not.
				if ( false !== strpos( $source, "function_exists( '{$function}' )" ) ) {
					continue;
				}

				$found[] = sprintf(
					'%s calls %s() unguarded (added in %s)',
					str_replace( LEANROLES_PATH, '', $file ),
					$function,
					$since
				);
			}
		}

		$this->assertSame( array(), $found, implode( "\n", $found ) );
	}

	// -------------------------------------------------------- plugin header

	public function test_the_plugin_declares_its_uninstaller_and_text_domain_directory(): void {
		$data = get_file_data(
			LEANROLES_FILE,
			array(
				'DomainPath' => 'Domain Path',
				'License'    => 'License',
			)
		);

		$this->assertSame( '/languages', $data['DomainPath'] );
		$this->assertStringContainsString( 'GPL', $data['License'] );
	}

	public function test_no_shipped_file_leaves_a_debugging_statement_behind(): void {
		$found = array();

		foreach ( $this->shipped_files() as $file ) {
			$source = $this->code_only( $file );

			$patterns = array(
				'var_dump'  => '/(?<![\w_$>])var_dump\s*\(/',
				'print_r'   => '/(?<![\w_$>])print_r\s*\(/',
				'error_log' => '/(?<![\w_$>])error_log\s*\(/',
				// Bare die(), not wp_die().
				'die'       => '/(?<![\w_$>])die\s*\(/',
			);

			foreach ( $patterns as $label => $pattern ) {
				if ( preg_match( $pattern, $source ) ) {
					$found[] = str_replace( LEANROLES_PATH, '', $file ) . ' contains ' . $label . '()';
				}
			}
		}

		$this->assertSame( array(), $found, implode( "\n", $found ) );
	}
}
