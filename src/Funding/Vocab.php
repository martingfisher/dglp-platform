<?php
/**
 * The categories a funding opportunity is filed under.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

/**
 * Read from vocab.json beside this file, the same file the weekly import
 * Worker builds its instructions from, so the categories the model is told to
 * choose from and the ones the site filters on are one list.
 *
 * Pure: no WordPress, so the tests run without it.
 */
final class Vocab {

	/** The facets a fund is filed under, in the order the filters show them. */
	public const FACETS = [ 'fund_type', 'applicants', 'costs', 'beneficiaries', 'area' ];

	/** @var array<string, mixed>|null */
	private static ?array $data = null;

	/**
	 * The whole file.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		if ( null === self::$data ) {
			$raw        = file_get_contents( __DIR__ . '/vocab.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file shipped with the plugin.
			$decoded    = is_string( $raw ) ? json_decode( $raw, true ) : null;
			self::$data = is_array( $decoded ) ? $decoded : [];
		}

		return self::$data;
	}

	/**
	 * One facet's options, key => label.
	 *
	 * @return array<string, string>
	 */
	public static function options( string $facet ): array {
		$list = self::data()[ $facet ] ?? [];

		if ( ! is_array( $list ) ) {
			return [];
		}

		$out = [];

		foreach ( $list as $key => $value ) {
			if ( '_' === substr( (string) $key, 0, 1 ) ) {
				continue;
			}

			$out[ (string) $key ] = is_array( $value ) ? (string) ( $value['label'] ?? $key ) : (string) $value;
		}

		return $out;
	}

	/**
	 * The amount bands, key => [label, min, max]. Max null is open-ended.
	 *
	 * @return array<string, array{label:string, min:float, max:float|null}>
	 */
	public static function amounts(): array {
		$out = [];

		foreach ( (array) ( self::data()['amount'] ?? [] ) as $key => $band ) {
			if ( ! is_array( $band ) ) {
				continue;
			}

			$out[ (string) $key ] = [
				'label' => (string) ( $band['label'] ?? $key ),
				'min'   => (float) ( $band['min'] ?? 0 ),
				'max'   => isset( $band['max'] ) ? (float) $band['max'] : null,
			];
		}

		return $out;
	}

	/**
	 * Only the values a facet knows, in the order given, without repeats.
	 *
	 * @param mixed $values Anything; usually a list from the import.
	 * @return string[]
	 */
	public static function keep( string $facet, $values ): array {
		$known = self::options( $facet );
		$out   = [];

		foreach ( (array) $values as $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';

			if ( isset( $known[ $value ] ) && ! in_array( $value, $out, true ) ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	/** Topic slugs that are about the site, not a subject: never put on a fund. */
	public static function topics_excluded(): array {
		return array_values( array_map( 'strval', (array) ( self::data()['topics_excluded'] ?? [] ) ) );
	}

	/**
	 * What an organisation's directory profile says it can apply for and who
	 * it works with, in fund categories.
	 *
	 * @param string[] $legal         Keys from Org\Options::legal().
	 * @param string[] $service_users Keys from Org\Options::service_users().
	 * @return array{applicants: string[], beneficiaries: string[]}
	 */
	public static function fit_from_org( array $legal, array $service_users ): array {
		$map        = (array) ( self::data()['from_org'] ?? [] );
		$legal_map  = (array) ( $map['legal'] ?? [] );
		$prefix_map = (array) ( $map['service_users_prefix'] ?? [] );
		$applicants = [];
		$benefits   = [];

		foreach ( $legal as $key ) {
			foreach ( (array) ( $legal_map[ (string) $key ] ?? [] ) as $value ) {
				$applicants[] = (string) $value;
			}
		}

		// Longest prefix first, so "access_a_learning_disability" wins over "access_".
		$prefixes = array_keys( $prefix_map );
		usort( $prefixes, static fn( $a, $b ): int => strlen( (string) $b ) <=> strlen( (string) $a ) );

		foreach ( $service_users as $key ) {
			$key = (string) $key;

			foreach ( $prefixes as $prefix ) {
				$prefix = (string) $prefix;

				if ( '_' !== $prefix[0] && str_starts_with( $key, $prefix ) ) {
					$benefits[] = (string) $prefix_map[ $prefix ];
					break;
				}
			}
		}

		return [
			'applicants'    => self::keep( 'applicants', $applicants ),
			'beneficiaries' => self::keep( 'beneficiaries', $benefits ),
		];
	}
}
