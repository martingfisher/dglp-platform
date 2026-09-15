<?php
/**
 * Where plugin email is actually allowed to go.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

/**
 * The guard between a staging site and real members' inboxes.
 *
 * Staging is a clone of production, which means it is a clone of production's
 * user table. Every real member address is sitting in it. One approval on
 * staging and a live organisation is told their event is on a site that does
 * not exist.
 *
 * So the redirect is enforced here, at the plugin, not left to an SMTP plugin
 * setting that somebody can switch off without realising what it was for. When
 * a redirect address is configured, every transactional email goes there and
 * nowhere else, with the addresses it would have gone to written into the
 * subject line so a test is still readable.
 *
 * Production has no redirect configured and mail goes where it was addressed.
 */
final class Routing {

	/** Site option holding the address all mail is diverted to. */
	public const OPTION_REDIRECT = 'dgl_mail_redirect';

	/** Site option holding the From address, when it is being overridden. */
	public const OPTION_FROM = 'dgl_mail_from';

	/** Site option holding the From name. */
	public const OPTION_FROM_NAME = 'dgl_mail_from_name';

	/**
	 * Whether the plugin sends at all.
	 *
	 * A site that has not been through mail setup should be silent rather than
	 * sending from `wordpress@` and landing in spam.
	 */
	public const OPTION_ENABLED = 'dgl_mail_enabled';

	/**
	 * The addresses a message should be delivered to.
	 *
	 * Pure, so the rule can be tested without a mail server. Passing an empty
	 * redirect leaves the recipients alone.
	 *
	 * Deduplication ignores case. The local part of an address is
	 * case-sensitive by the letter of RFC 5321, but no mail provider in real
	 * use treats it that way, and an org owner listed once as `Jo@` and once as
	 * `jo@` getting two copies of the same approval is the worse outcome. The
	 * first spelling seen is the one kept.
	 *
	 * @param string[] $to
	 * @return string[]
	 */
	public static function deliver_to( array $to, string $redirect ): array {
		$redirect = trim( $redirect );

		if ( '' !== $redirect ) {
			return [ $redirect ];
		}

		$seen = [];

		foreach ( $to as $address ) {
			$address = trim( $address );

			if ( '' === $address ) {
				continue;
			}

			$key = strtolower( $address );

			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = $address;
			}
		}

		return array_values( $seen );
	}

	/**
	 * The subject line as it should be sent.
	 *
	 * On a redirected site the intended recipients go into the subject. Without
	 * that, twenty diverted emails in one test inbox are indistinguishable and
	 * nobody can tell whether the right person would have been told.
	 *
	 * @param string[] $intended
	 */
	public static function subject( string $subject, array $intended, string $redirect ): string {
		if ( '' === trim( $redirect ) ) {
			return $subject;
		}

		$intended = array_values( array_filter( array_map( 'trim', $intended ), static fn( string $a ): bool => '' !== $a ) );

		if ( [] === $intended ) {
			return '[DIVERTED: no recipients] ' . $subject;
		}

		// Long lists get truncated rather than pushing the real subject out of view.
		$shown = array_slice( $intended, 0, 3 );
		$label = implode( ', ', $shown );

		if ( count( $intended ) > count( $shown ) ) {
			$label .= sprintf( ' +%d more', count( $intended ) - count( $shown ) );
		}

		return '[DIVERTED: ' . $label . '] ' . $subject;
	}

	/**
	 * Whether a redirect is in force, given a configured value.
	 */
	public static function is_diverted( string $redirect ): bool {
		return '' !== trim( $redirect );
	}

	/* ---------------------------------------------------------------------
	 * Configuration. The only part that touches WordPress.
	 * ------------------------------------------------------------------ */

	/**
	 * The configured redirect address, or an empty string on a live site.
	 *
	 * A constant in `wp-config.php` wins over the option, so the safety of a
	 * staging clone does not depend on a database row that a database pull from
	 * production would overwrite. That is exactly how a staging site ends up
	 * mailing real members: somebody refreshes staging from live and the option
	 * that was protecting everybody comes across with it.
	 */
	public static function configured_redirect(): string {
		if ( defined( 'DGL_MAIL_REDIRECT' ) && is_string( constant( 'DGL_MAIL_REDIRECT' ) ) ) {
			$constant = trim( (string) constant( 'DGL_MAIL_REDIRECT' ) );

			if ( '' !== $constant && is_email( $constant ) ) {
				return $constant;
			}
		}

		$option = trim( (string) get_option( self::OPTION_REDIRECT, '' ) );

		return '' !== $option && is_email( $option ) ? $option : '';
	}

	/**
	 * Whether the plugin is allowed to send.
	 *
	 * Defaults to off. Mail that goes out before somebody has decided what it
	 * sends from is mail that trains a spam filter against the domain.
	 */
	public static function is_enabled(): bool {
		if ( defined( 'DGL_MAIL_ENABLED' ) ) {
			return (bool) constant( 'DGL_MAIL_ENABLED' );
		}

		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * The From address, or an empty string to leave WordPress's default alone.
	 */
	public static function from_address(): string {
		$from = trim( (string) get_option( self::OPTION_FROM, '' ) );

		return '' !== $from && is_email( $from ) ? $from : '';
	}

	/**
	 * The From name, falling back to the site name.
	 */
	public static function from_name(): string {
		$name = trim( (string) get_option( self::OPTION_FROM_NAME, '' ) );

		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}
}
