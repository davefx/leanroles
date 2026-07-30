<?php
/**
 * Base class for the WP-CLI commands.
 *
 * The commands are driven in-process against the real WP_CLI class rather than
 * a hand-rolled double, so what is under test is the actual contract: which
 * calls end the command, what reaches stdout versus stderr, how
 * Utils\get_flag_value resolves a default.
 *
 * Two things have to be arranged for that to work inside PHPUnit. WP_CLI::error()
 * and friends end in exit(), which WP-CLI itself turns into an ExitException
 * when its private $capture_exit flag is set — the same mechanism
 * WP_CLI::runcommand() uses. And the Execution logger, which WP-CLI ships for
 * exactly this purpose, collects output into strings instead of writing to the
 * terminal.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests;

defined( 'ABSPATH' ) || exit;

abstract class CliTestCase extends TestCase {

	/** @var \WP_CLI\Loggers\Execution */
	protected $logger;

	/** @var string Whatever the command echoed directly, e.g. through format_items(). */
	protected $printed = '';

	/**
	 * Memory stream standing in for php-cli-tools' STDOUT.
	 *
	 * Process-wide and never restored. cli\Streams::setStream() registers a
	 * shutdown function that fcloses whatever it is handed, so putting STDOUT
	 * back would queue up an fclose(STDOUT) — and closing it ourselves would
	 * make that same shutdown handler fatal on an already-closed resource.
	 *
	 * @var resource|null
	 */
	private static $cli_out = null;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( '\\WP_CLI' ) ) {
			$this->markTestSkipped( 'WP-CLI is not installed; run composer install.' );
		}

		$this->logger = new \WP_CLI\Loggers\Execution();
		\WP_CLI::set_logger( $this->logger );

		$this->set_capture_exit( true );
		$this->printed = '';

		/*
		 * Progress bars and anything else routed through php-cli-tools write to
		 * the STDOUT stream directly, sailing past output buffering. Pointing
		 * that stream at memory keeps the run quiet and lets it be inspected.
		 */
		if ( null === self::$cli_out && class_exists( '\\cli\\Streams' ) ) {
			self::$cli_out = fopen( 'php://memory', 'w+' );
			\cli\Streams::setStream( 'out', self::$cli_out );
		}

		$this->drain_stream();
	}

	public function tear_down(): void {
		if ( class_exists( '\\WP_CLI' ) ) {
			$this->set_capture_exit( false );
		}

		parent::tear_down();
	}

	/**
	 * Read back whatever php-cli-tools wrote to its output stream.
	 */
	private function drain_stream(): string {
		if ( ! is_resource( self::$cli_out ) ) {
			return '';
		}

		rewind( self::$cli_out );
		$contents = stream_get_contents( self::$cli_out );

		// Leave it empty so the next command starts clean.
		ftruncate( self::$cli_out, 0 );
		rewind( self::$cli_out );

		return (string) $contents;
	}

	/**
	 * Toggle WP-CLI's own exit-capturing mode.
	 *
	 * @param bool $capture Whether exits should throw instead.
	 */
	private function set_capture_exit( bool $capture ): void {
		$property = new \ReflectionProperty( '\\WP_CLI', 'capture_exit' );

		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		$property->setValue( null, $capture );
	}

	/**
	 * Run a command method, collecting everything it produced.
	 *
	 * @param object $command    Command instance.
	 * @param string $method     Method to call.
	 * @param array  $args       Positional arguments.
	 * @param array  $assoc_args Options.
	 * @return array{stdout:string,stderr:string,printed:string,exit_code:int|null}
	 */
	protected function run_command( $command, string $method, array $args = array(), array $assoc_args = array() ): array {
		$exit_code = null;

		ob_start();

		try {
			$command->$method( $args, $assoc_args );
		} catch ( \WP_CLI\ExitException $e ) {
			$exit_code = $e->getCode();
		} finally {
			$this->printed = (string) ob_get_clean() . $this->drain_stream();
		}

		return array(
			'stdout'    => $this->logger->stdout,
			'stderr'    => $this->logger->stderr,
			'printed'   => $this->printed,
			'exit_code' => $exit_code,
		);
	}

	/**
	 * Everything the command said, however it said it.
	 *
	 * @param array $result Result of run_command().
	 */
	protected function all_output( array $result ): string {
		return $result['stdout'] . "\n" . $result['stderr'] . "\n" . $result['printed'];
	}

	/**
	 * Decode the rows a command produced with --format=json.
	 *
	 * JSON rather than CSV on purpose: WP-CLI's csv formatter calls
	 * Utils\write_csv() against the STDOUT *constant*, which no amount of output
	 * buffering or stream swapping can intercept from inside the process. The
	 * json and table formats go through echo and can be read back. What is under
	 * test is the rows and fields the command hands the formatter, which is the
	 * same either way.
	 *
	 * @param array $result Result of run_command().
	 * @return array
	 */
	protected function decode_rows( array $result ): array {
		$json = trim( $result['printed'] );

		$this->assertNotSame( '', $json, 'The command produced no output to decode.' );

		$rows = json_decode( $json, true );

		$this->assertIsArray( $rows, 'The command did not produce valid JSON: ' . $json );

		return $rows;
	}

	/**
	 * Assert the command ended in WP_CLI::error().
	 *
	 * @param array  $result   Result of run_command().
	 * @param string $contains Substring the message should contain.
	 */
	protected function assertCommandFailed( array $result, string $contains = '' ): void {
		$this->assertNotNull( $result['exit_code'], 'The command was expected to fail but ran to completion.' );
		$this->assertSame( 1, $result['exit_code'] );

		if ( '' !== $contains ) {
			$this->assertStringContainsString( $contains, $result['stderr'] );
		}
	}

	/**
	 * Assert the command ran to completion.
	 *
	 * @param array  $result   Result of run_command().
	 * @param string $contains Substring the output should contain.
	 */
	protected function assertCommandSucceeded( array $result, string $contains = '' ): void {
		$this->assertNull(
			$result['exit_code'],
			'The command exited unexpectedly: ' . $result['stderr']
		);

		if ( '' !== $contains ) {
			$this->assertStringContainsString( $contains, $this->all_output( $result ) );
		}
	}
}
