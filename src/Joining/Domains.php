<?php
/**
 * Email domains, and which ones can never identify an organisation.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules for matching an address to an organisation by its domain.
 *
 * No WordPress calls, so the whole table below is tested without one.
 */
final class Domains {

	/**
	 * Providers whose addresses belong to a person, not an organisation.
	 *
	 * An organisation that has recorded one of these as its domain, by
	 * mistake or by habit, must not admit everybody with a Gmail address.
	 * This is a hard rule, not a warning.
	 *
	 * @return string[]
	 */
	public static function public_providers(): array {
		return [
			'gmail.com', 'googlemail.com',
			'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'outlook.co.uk', 'live.com', 'live.co.uk', 'msn.com',
			'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
			'icloud.com', 'me.com', 'mac.com',
			'aol.com', 'aol.co.uk',
			'btinternet.com', 'btopenworld.com', 'talktalk.net', 'sky.com', 'virginmedia.com', 'ntlworld.com', 'blueyonder.co.uk',
			'plus.net', 'plusnet.com', 'tiscali.co.uk', 'orange.net', 'o2.co.uk', 'ee.co.uk', 'vodafone.co.uk',
			'protonmail.com', 'proton.me', 'pm.me', 'tutanota.com', 'zoho.com', 'gmx.com', 'gmx.co.uk', 'mail.com', 'yandex.com', 'fastmail.com',
		];
	}

	/**
	 * The domain part of an address, lower-cased, or '' if it is not one.
	 */
	public static function of( string $email ): string {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );

		if ( false === $at || $at === strlen( $email ) - 1 ) {
			return '';
		}

		$domain = substr( $email, $at + 1 );

		return self::normalise( $domain );
	}

	/**
	 * One recorded domain, as it is stored and compared: lower-case, no
	 * scheme, no path, no leading "www.", no trailing dot.
	 */
	public static function normalise( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		$domain = (string) preg_replace( '#^[a-z]+://#', '', $domain );
		$domain = (string) preg_replace( '#[/?\#].*$#', '', $domain );
		$domain = (string) preg_replace( '#^www\.#', '', $domain );
		$domain = rtrim( $domain, '.' );

		return self::is_plausible( $domain ) ? $domain : '';
	}

	/**
	 * Several recorded domains, one per line or comma, cleaned and unique.
	 *
	 * @return string[]
	 */
	public static function list( string $raw ): array {
		$out = [];

		foreach ( preg_split( '/[\s,;]+/', $raw ) ?: [] as $piece ) {
			$domain = self::normalise( $piece );

			if ( '' !== $domain && ! in_array( $domain, $out, true ) ) {
				$out[] = $domain;
			}
		}

		return $out;
	}

	public static function is_public( string $domain ): bool {
		return in_array( self::normalise( $domain ), self::public_providers(), true );
	}

	/**
	 * Whether an address can identify an organisation at all.
	 *
	 * Exact match only. `jo@mail.charity.org.uk` does not match
	 * `charity.org.uk`; the organisation lists every domain it uses.
	 * Guessing at parent domains is how `leeds.gov.uk` matches things it
	 * should not.
	 */
	public static function can_match( string $email ): bool {
		$domain = self::of( $email );

		return '' !== $domain && ! self::is_public( $domain );
	}

	private static function is_plausible( string $domain ): bool {
		return (bool) preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain );
	}
}
