<?php
/**
 * Whether a described organisation is one already on the list.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Joining\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Pure comparisons, no WordPress calls, so every rule below is unit tested.
 *
 * A probe (what somebody typed) is compared with candidates (what is on the
 * list) on several signals at once. A HARD match is one the site will not
 * let a second record through: the same name once titles and legal suffixes
 * are stripped, the same website or email domain, the same charity or
 * company number, or the same postcode with a similar name. A SOFT match is
 * worth a question ("is it one of these?") but not a block: a similar name,
 * or the same postcode alone, because community hubs house several
 * organisations.
 *
 * A candidate in the bin (a refused registration) is only ever SOFT and is
 * never shown to the person; the review team see it with a note.
 *
 * Suspended organisations are not candidates. The loader leaves them out:
 * an organisation the team have suspended does not take new people, and
 * somebody trying to re-register it is a conversation, not a rule.
 */
final class Duplicates {

	public const HARD = 'hard';
	public const SOFT = 'soft';

	/** Token overlap at or above this is a similar name. */
	public const JACCARD_MIN = 0.6;

	/** Character similarity at or above this is a similar name. */
	public const SIMILAR_MIN = 0.85;

	/** One name starting with the whole of the other counts once the shorter is this long. */
	public const PREFIX_MIN = 8;

	/**
	 * Words at the end of a name that say what kind of body it is, not which.
	 *
	 * "Trust", "Foundation" and "Association" stay: they tell organisations
	 * apart. Compared after punctuation is gone, so "C.I.C." is "c i c".
	 *
	 * @return string[]
	 */
	public static function legal_suffixes(): array {
		return [
			'ltd',
			'limited',
			'cic',
			'c i c',
			'community interest company',
			'cio',
			'charitable incorporated organisation',
			'plc',
			'llp',
			'uk',
			'registered charity',
		];
	}

	/**
	 * Hosts where the address identifies the host, not the organisation.
	 *
	 * Two organisations with Facebook pages both have facebook.com as their
	 * "website" once the path is dropped. A subdomain host such as
	 * leedsmind.wordpress.com is kept: it is one organisation's.
	 *
	 * @return string[]
	 */
	public static function shared_hosts(): array {
		return [
			'facebook.com', 'm.facebook.com', 'en-gb.facebook.com', 'fb.com', 'fb.me',
			'instagram.com', 'twitter.com', 'x.com', 'linkedin.com', 'tiktok.com', 'youtube.com', 'youtu.be',
			'linktr.ee', 'sites.google.com', 'meetup.com', 'nextdoor.co.uk',
			'eventbrite.co.uk', 'eventbrite.com', 'justgiving.com', 'localgiving.org', 'gofundme.com',
		];
	}

	/**
	 * A name as it is compared: lower-case ASCII words, no punctuation, no
	 * leading "the", no trailing legal suffix.
	 *
	 * "The Leeds Mind Ltd." and "leeds mind" are the same organisation.
	 */
	public static function normalise_name( string $name ): string {
		$name = mb_strtolower( trim( $name ), 'UTF-8' );
		$name = self::ascii( $name );
		$name = str_replace( [ '&', '+' ], ' and ', $name );
		$name = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', $name );
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );

		if ( str_starts_with( $name, 'the ' ) ) {
			$name = substr( $name, 4 );
		}

		$suffixes = self::legal_suffixes();

		do {
			$before = $name;

			foreach ( $suffixes as $suffix ) {
				if ( $name !== $suffix && str_ends_with( $name, ' ' . $suffix ) ) {
					$name = trim( substr( $name, 0, -strlen( $suffix ) ) );
				}
			}
		} while ( $before !== $name );

		return $name;
	}

	/**
	 * The words that carry meaning in a normalised name, once each.
	 *
	 * @return string[]
	 */
	public static function tokens( string $normalised ): array {
		$out = [];

		foreach ( explode( ' ', $normalised ) as $word ) {
			if ( '' !== $word && ! in_array( $word, [ 'of', 'and', 'for', 'in', 'at' ], true ) && ! in_array( $word, $out, true ) ) {
				$out[] = $word;
			}
		}

		return $out;
	}

	/**
	 * Character similarity of two normalised names, 0 to 1.
	 */
	public static function name_similarity( string $a, string $b ): float {
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}

		similar_text( $a, $b, $percent );

		return round( $percent / 100, 4 );
	}

	/**
	 * Shared words over all words, 0 to 1.
	 *
	 * @param string[] $a
	 * @param string[] $b
	 */
	public static function jaccard( array $a, array $b ): float {
		$union = array_unique( array_merge( $a, $b ) );

		if ( [] === $union ) {
			return 0.0;
		}

		return round( count( array_intersect( $a, $b ) ) / count( $union ), 4 );
	}

	/**
	 * A charity or company number as compared: the digits and letters only,
	 * upper-case, with "Charity no." and the like taken off the front.
	 * Anything without a digit is not a number ("none", "n/a", "TBC").
	 */
	public static function normalise_number( string $number ): string {
		$number = trim( $number );
		$number = (string) preg_replace( '/^\s*(registered\s+)?(charity|company|companies\s+house|reg(istration)?|ref(erence)?)?\.?\s*(no|number|num)?\.?\s*:?\s*/i', '', $number );
		$number = strtoupper( (string) preg_replace( '/[^a-z0-9]/i', '', $number ) );

		return preg_match( '/\d/', $number ) && strlen( $number ) >= 5 ? $number : '';
	}

	/**
	 * A postcode as compared: upper-case, no space, and only if it is one.
	 */
	public static function normalise_postcode( string $postcode ): string {
		$compact = strtoupper( (string) preg_replace( '/[^a-z0-9]/i', '', $postcode ) );

		return preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $compact ) ? $compact : '';
	}

	/**
	 * The domain a website address identifies, or '' when it identifies
	 * nobody in particular: a public email provider or a shared host.
	 */
	public static function website_domain( string $website ): string {
		$domain = Domains::normalise( $website );

		if ( '' === $domain || Domains::is_public( $domain ) || in_array( $domain, self::shared_hosts(), true ) ) {
			return '';
		}

		return $domain;
	}

	/**
	 * Every candidate the probe could be, strongest first.
	 *
	 * @param array{name?: string, website?: string, email_domain?: string, number?: string, postcode?: string} $probe What was typed.
	 * @param array<int, array{id: int, name: string, domains?: string[], website?: string, number?: string, postcode?: string, status?: string, trashed?: bool}> $candidates The list.
	 * @param int $exclude_id A candidate to skip: the record being checked against the rest.
	 * @return array<int, array{id: int, name: string, status: string, trashed: bool, strength: string, reasons: string[]}>
	 */
	public static function find( array $probe, array $candidates, int $exclude_id = 0 ): array {
		$p_name   = self::normalise_name( (string) ( $probe['name'] ?? '' ) );
		$p_tokens = self::tokens( $p_name );
		$p_web    = self::website_domain( (string) ( $probe['website'] ?? '' ) );
		$p_email  = Domains::normalise( (string) ( $probe['email_domain'] ?? '' ) );
		$p_email  = '' !== $p_email && ! Domains::is_public( $p_email ) ? $p_email : '';
		$p_number = self::normalise_number( (string) ( $probe['number'] ?? '' ) );
		$p_pc     = self::normalise_postcode( (string) ( $probe['postcode'] ?? '' ) );

		$out = [];

		foreach ( $candidates as $candidate ) {
			$id = (int) ( $candidate['id'] ?? 0 );

			if ( $id === $exclude_id ) {
				continue;
			}

			$reasons  = [];
			$hard     = false;
			$name_hit = false;
			$c_name   = self::normalise_name( (string) ( $candidate['name'] ?? '' ) );
			$c_web    = self::website_domain( (string) ( $candidate['website'] ?? '' ) );
			$c_domains = array_values( array_filter( array_map( [ Domains::class, 'normalise' ], (array) ( $candidate['domains'] ?? [] ) ) ) );

			if ( '' !== $p_name && '' !== $c_name ) {
				if ( $p_name === $c_name ) {
					$reasons[] = 'name_exact';
					$hard      = true;
					$name_hit  = true;
				} elseif ( self::names_similar( $p_name, $p_tokens, $c_name ) ) {
					$reasons[] = 'name_similar';
					$name_hit  = true;
				}
			}

			if ( '' !== $p_web && ( in_array( $p_web, $c_domains, true ) || $p_web === $c_web ) ) {
				$reasons[] = 'website';
				$hard      = true;
			}

			if ( '' !== $p_email && ( in_array( $p_email, $c_domains, true ) || $p_email === $c_web ) ) {
				$reasons[] = 'email_domain';
				$hard      = true;
			}

			if ( '' !== $p_number && $p_number === self::normalise_number( (string) ( $candidate['number'] ?? '' ) ) ) {
				$reasons[] = 'number';
				$hard      = true;
			}

			if ( '' !== $p_pc && $p_pc === self::normalise_postcode( (string) ( $candidate['postcode'] ?? '' ) ) ) {
				if ( $name_hit ) {
					$reasons[] = 'postcode_and_name';
					$hard      = true;
				} else {
					$reasons[] = 'postcode';
				}
			}

			if ( [] === $reasons ) {
				continue;
			}

			$trashed = (bool) ( $candidate['trashed'] ?? false );

			$out[] = [
				'id'       => $id,
				'name'     => (string) ( $candidate['name'] ?? '' ),
				'status'   => (string) ( $candidate['status'] ?? '' ),
				'trashed'  => $trashed,
				'strength' => $hard && ! $trashed ? self::HARD : self::SOFT,
				'reasons'  => $reasons,
			];
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				if ( $a['strength'] !== $b['strength'] ) {
					return self::HARD === $a['strength'] ? -1 : 1;
				}

				if ( $a['trashed'] !== $b['trashed'] ) {
					return $a['trashed'] ? 1 : -1;
				}

				if ( count( $a['reasons'] ) !== count( $b['reasons'] ) ) {
					return count( $b['reasons'] ) <=> count( $a['reasons'] );
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * The matches that block a new record. Never a trashed one.
	 *
	 * @param array<int, array{strength: string, trashed: bool}> $matches
	 * @return array<int, array<string, mixed>>
	 */
	public static function hard( array $matches ): array {
		return array_values( array_filter( $matches, static fn( array $m ): bool => self::HARD === $m['strength'] && ! $m['trashed'] ) );
	}

	/**
	 * The matches worth a question, not a block.
	 *
	 * @param array<int, array{strength: string, trashed: bool}> $matches
	 * @return array<int, array<string, mixed>>
	 */
	public static function soft( array $matches, bool $include_trashed = false ): array {
		return array_values( array_filter( $matches, static fn( array $m ): bool => self::SOFT === $m['strength'] && ( $include_trashed || ! $m['trashed'] ) ) );
	}

	/**
	 * Whether the address of somebody claiming an organisation is a domain
	 * match, a subdomain of one, the organisation's website, a public
	 * provider, or nothing to do with it.
	 *
	 * @param string[] $org_domains The organisation's recorded domains.
	 * @return string 'same' | 'subdomain' | 'website' | 'public' | 'none'
	 */
	public static function domain_closeness( string $email_domain, array $org_domains, string $website ): string {
		$email = Domains::normalise( $email_domain );

		if ( '' === $email ) {
			return 'none';
		}

		if ( Domains::is_public( $email ) ) {
			return 'public';
		}

		$domains = array_values( array_filter( array_map( [ Domains::class, 'normalise' ], $org_domains ) ) );

		if ( in_array( $email, $domains, true ) ) {
			return 'same';
		}

		foreach ( $domains as $domain ) {
			if ( self::is_subdomain( $email, $domain ) ) {
				return 'subdomain';
			}
		}

		$site = self::website_domain( $website );

		if ( '' !== $site && ( $email === $site || self::is_subdomain( $email, $site ) ) ) {
			return 'website';
		}

		return 'none';
	}

	/**
	 * A reason as a person reads it.
	 */
	public static function reason_label( string $reason ): string {
		return match ( $reason ) {
			'name_exact'        => __( 'same name', 'dgl-platform' ),
			'name_similar'      => __( 'similar name', 'dgl-platform' ),
			'website'           => __( 'same website', 'dgl-platform' ),
			'email_domain'      => __( 'same email domain', 'dgl-platform' ),
			'number'            => __( 'same charity or company number', 'dgl-platform' ),
			'postcode_and_name' => __( 'same postcode and a similar name', 'dgl-platform' ),
			'postcode'          => __( 'same postcode', 'dgl-platform' ),
			default             => $reason,
		};
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @param string[] $p_tokens
	 */
	private static function names_similar( string $p_name, array $p_tokens, string $c_name ): bool {
		if ( self::jaccard( $p_tokens, self::tokens( $c_name ) ) >= self::JACCARD_MIN ) {
			return true;
		}

		if ( self::name_similarity( $p_name, $c_name ) >= self::SIMILAR_MIN ) {
			return true;
		}

		$shorter = strlen( $p_name ) <= strlen( $c_name ) ? $p_name : $c_name;
		$longer  = $shorter === $p_name ? $c_name : $p_name;

		return strlen( $shorter ) >= self::PREFIX_MIN && str_starts_with( $longer, $shorter . ' ' );
	}

	private static function is_subdomain( string $host, string $domain ): bool {
		return '' !== $domain && $host !== $domain && str_ends_with( $host, '.' . $domain );
	}

	/**
	 * Accented letters to plain ones, without depending on the locale iconv
	 * happens to have. Anything else non-ASCII is dropped afterwards.
	 */
	private static function ascii( string $text ): string {
		static $map = [
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ā' => 'a',
			'ç' => 'c', 'č' => 'c', 'ć' => 'c',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
			'ñ' => 'n', 'ń' => 'n',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'œ' => 'oe',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
			'ý' => 'y', 'ÿ' => 'y',
			'ß' => 'ss', 'ł' => 'l', 'ś' => 's', 'š' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ð' => 'd', 'þ' => 'th',
			'’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-',
		];

		$text = strtr( $text, $map );

		if ( preg_match( '/[^\x00-\x7F]/', $text ) && function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a locale that cannot transliterate is a fallback case, not an error.

			if ( false !== $converted ) {
				$text = $converted;
			}
		}

		return $text;
	}
}
