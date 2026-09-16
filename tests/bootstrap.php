<?php
/**
 * Standalone harness for the pure-logic classes.
 *
 * The access policy and the state machine are deliberately free of WordPress,
 * so they can be exercised without a database. Everything that does touch
 * WordPress is covered by the integration suite that runs on staging.
 *
 * @package DGL
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

// The handful of WordPress functions the pure classes reach for.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

/*
 * The handful of WordPress escaping and formatting functions the email
 * template reaches for. Faithful enough to test string assembly against, and
 * deliberately no more: what these tests check is the template's own logic,
 * not WordPress's escaping, which is asserted in the integration suite where
 * the real functions are loaded.
 */
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		$url = str_replace( [ '"', "'", '<', '>' ], '', trim( $url ) );

		// Only the schemes an email is allowed to link to.
		return preg_match( '#^(https?:|mailto:|/)#i', $url ) ? $url : '';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'Test Site';
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $html ): string {
		return $html;
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'DGL\\' ) ) {
			return;
		}
		$path = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, 4 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * The three bits of $wpdb the schema builders touch. Enough to assert on the
 * generated SQL without a database.
 */
final class FakeWpdb {

	public string $prefix = 'wptest_';

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}
}

$GLOBALS['wpdb'] = new FakeWpdb();


final class Harness {

	private static int $passed = 0;

	/** @var string[] */
	private static array $failures = [];

	private static string $group = '';

	public static function group( string $name ): void {
		self::$group = $name;
		echo "\n" . $name . "\n";
	}

	public static function assert_true( bool $actual, string $what ): void {
		self::record( true === $actual, $what, 'true', var_export( $actual, true ) );
	}

	public static function assert_false( bool $actual, string $what ): void {
		self::record( false === $actual, $what, 'false', var_export( $actual, true ) );
	}

	public static function assert_same( mixed $expected, mixed $actual, string $what ): void {
		self::record(
			$expected === $actual,
			$what,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}

	private static function record( bool $ok, string $what, string $expected, string $actual ): void {
		if ( $ok ) {
			++self::$passed;
			echo "  ok    " . $what . "\n";
			return;
		}

		self::$failures[] = sprintf( '%s: %s (expected %s, got %s)', self::$group, $what, $expected, $actual );
		echo "  FAIL  " . $what . "  expected " . $expected . ", got " . $actual . "\n";
	}

	public static function finish(): never {
		$failed = count( self::$failures );

		echo "\n" . str_repeat( '-', 60 ) . "\n";
		printf( "%d passed, %d failed\n", self::$passed, $failed );

		if ( $failed > 0 ) {
			echo "\nFailures:\n";
			foreach ( self::$failures as $failure ) {
				echo '  - ' . $failure . "\n";
			}
		}

		exit( $failed > 0 ? 1 : 0 );
	}
}
