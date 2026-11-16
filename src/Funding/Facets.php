<?php
/**
 * The filters on the Grants and Funding list.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

/**
 * What a visitor can narrow funding by, beyond topic:
 *
 *   ?kind=    what kind of funding (grant, loan, award...)
 *   ?who=     which kind of organisation can apply
 *   ?pays=    what it will pay for
 *   ?for=     who the work is with ("open to anyone" funds always match)
 *   ?area=    where the work must happen
 *   ?amount=  a band; a fund matches when its range overlaps the band
 *   ?closing= closing within two weeks, a month, or open all year
 *   ?fit=mine funds a signed-in member's organisation can apply for, from its
 *             directory profile (legal status and who it works with)
 *
 * Choices are kept as serialised arrays of keys, so a facet matches with a
 * LIKE on the quoted key: `"cic"` can only match the whole key cic.
 *
 * The request parsing and the query clauses are built from plain arrays, so
 * they are tested without WordPress; only fit_for_user() reads the database.
 */
final class Facets {

	/** URL parameter => facet in the vocabulary. */
	public const PARAMS = [
		'kind' => 'fund_type',
		'who'  => 'applicants',
		'pays' => 'costs',
		'for'  => 'beneficiaries',
		'area' => 'area',
	];

	/** The form's labels, in order. */
	public static function labels(): array {
		return [
			'kind'    => __( 'Kind of funding', 'dgl-platform' ),
			'who'     => __( 'Who can apply', 'dgl-platform' ),
			'pays'    => __( 'Pays for', 'dgl-platform' ),
			'for'     => __( 'Work with', 'dgl-platform' ),
			'area'    => __( 'Area', 'dgl-platform' ),
			'amount'  => __( 'Amount', 'dgl-platform' ),
			'closing' => __( 'Closing', 'dgl-platform' ),
		];
	}

	/** The options for one parameter, value => label. */
	public static function options( string $param ): array {
		if ( isset( self::PARAMS[ $param ] ) ) {
			return Vocab::options( self::PARAMS[ $param ] );
		}

		if ( 'amount' === $param ) {
			return array_map( static fn( array $band ): string => $band['label'], Vocab::amounts() );
		}

		if ( 'closing' === $param ) {
			return Vocab::options( 'closing' );
		}

		return [];
	}

	/**
	 * The request, cleaned: each parameter one known value or ''.
	 *
	 * @param array<string, mixed> $request Usually $_GET.
	 * @return array<string, string>
	 */
	public static function from_request( array $request ): array {
		$out = [];

		foreach ( array_merge( array_keys( self::PARAMS ), [ 'amount', 'closing' ] ) as $param ) {
			$value         = isset( $request[ $param ] ) && is_scalar( $request[ $param ] ) ? strtolower( trim( (string) $request[ $param ] ) ) : '';
			$out[ $param ] = isset( self::options( $param )[ $value ] ) ? $value : '';
		}

		$out['fit'] = isset( $request['fit'] ) && 'mine' === $request['fit'] ? 'mine' : '';

		// One funder's page, from the count on the team's Funding screen.
		$source        = isset( $request['source'] ) && is_scalar( $request['source'] ) ? strtolower( (string) $request['source'] ) : '';
		$out['source'] = preg_match( '/^[a-z0-9][a-z0-9-]{0,119}$/', $source ) ? $source : '';

		return $out;
	}

	/**
	 * Meta query clauses for these filters, ANDed together. Empty when none.
	 *
	 * @param array<string, string> $args From from_request().
	 * @param array{applicants: string[], beneficiaries: string[]}|null $fit What the member's organisation is, for fit=mine.
	 * @return array<int|string, mixed>
	 */
	public static function meta_query( array $args, string $today, ?array $fit = null ): array {
		$clauses = [];

		foreach ( self::PARAMS as $param => $facet ) {
			$value = (string) ( $args[ $param ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			if ( 'area' === $facet ) {
				$clauses[] = [ 'key' => 'dgl_area', 'value' => $value, 'compare' => '=' ];
			} elseif ( 'beneficiaries' === $facet ) {
				$clauses[] = self::any_or_open( [ $value ] );
			} else {
				$clauses[] = [ 'key' => 'dgl_' . $facet, 'value' => '"' . $value . '"', 'compare' => 'LIKE' ];
			}
		}

		if ( '' !== (string) ( $args['source'] ?? '' ) ) {
			$clauses[] = [ 'key' => Import::META_SOURCE, 'value' => (string) $args['source'], 'compare' => '=' ];
		}

		$band = Vocab::amounts()[ (string) ( $args['amount'] ?? '' ) ] ?? null;

		if ( null !== $band ) {
			// Overlap: the fund's top is at least the band's floor, and its floor
			// (where it has one) is no more than the band's top.
			$amount = [
				'relation' => 'AND',
				[ 'key' => 'dgl_amount_max', 'value' => $band['min'], 'compare' => '>=', 'type' => 'NUMERIC' ],
			];

			if ( null !== $band['max'] ) {
				$amount[] = [
					'relation' => 'OR',
					[ 'key' => 'dgl_amount_min', 'compare' => 'NOT EXISTS' ],
					[ 'key' => 'dgl_amount_min', 'value' => $band['max'], 'compare' => '<=', 'type' => 'NUMERIC' ],
				];
			}

			$clauses[] = $amount;
		}

		$closing = (string) ( $args['closing'] ?? '' );

		if ( 'rolling' === $closing ) {
			$clauses[] = [ 'key' => 'dgl_rolling_deadline', 'value' => '1', 'compare' => '=' ];
		} elseif ( '' !== $closing ) {
			$until     = ( new \DateTimeImmutable( $today ) )->modify( '2_weeks' === $closing ? '+14 days' : '+1 month' )->format( 'Y-m-d' );
			$clauses[] = [
				'relation' => 'AND',
				[ 'key' => 'dgl_deadline', 'value' => [ $today, $until ], 'compare' => 'BETWEEN', 'type' => 'DATE' ],
				[
					'relation' => 'OR',
					[ 'key' => 'dgl_rolling_deadline', 'compare' => 'NOT EXISTS' ],
					[ 'key' => 'dgl_rolling_deadline', 'value' => '1', 'compare' => '!=' ],
				],
			];
		}

		if ( 'mine' === ( $args['fit'] ?? '' ) && null !== $fit ) {
			// Who can apply: the fund names none (anyone may), or names one of ours.
			if ( [] !== $fit['applicants'] ) {
				$who = [ 'relation' => 'OR', [ 'key' => 'dgl_applicants', 'compare' => 'NOT EXISTS' ] ];

				foreach ( $fit['applicants'] as $value ) {
					$who[] = [ 'key' => 'dgl_applicants', 'value' => '"' . $value . '"', 'compare' => 'LIKE' ];
				}

				$clauses[] = $who;
			}

			if ( [] !== $fit['beneficiaries'] ) {
				$clauses[] = self::any_or_open( $fit['beneficiaries'] );
			}
		}

		if ( [] === $clauses ) {
			return [];
		}

		return array_merge( [ 'relation' => 'AND' ], $clauses );
	}

	/**
	 * A fund for any of these people, or one open to work with anyone.
	 *
	 * @param string[] $values
	 * @return array<int|string, mixed>
	 */
	private static function any_or_open( array $values ): array {
		$clause = [ 'relation' => 'OR', [ 'key' => 'dgl_beneficiaries', 'compare' => 'NOT EXISTS' ] ];

		foreach ( $values as $value ) {
			$clause[] = [ 'key' => 'dgl_beneficiaries', 'value' => '"' . $value . '"', 'compare' => 'LIKE' ];
		}

		return $clause;
	}

	/** Whether any of these filters is set. */
	public static function is_active( array $args ): bool {
		return [] !== array_filter( $args, static fn( $v ): bool => '' !== (string) $v );
	}

	/**
	 * What the member's organisation is, for fit=mine. Null when nobody is
	 * signed in, they have no organisation, or its profile says nothing useful.
	 *
	 * @return array{applicants: string[], beneficiaries: string[]}|null
	 */
	public static function fit_for_user( int $user_id ): ?array {
		$org_id = \DGL\Org\Org::for_user( $user_id );

		if ( null === $org_id ) {
			return null;
		}

		$legal = (string) get_post_meta( $org_id, 'dgl_org_legal_status', true );
		$users = get_post_meta( $org_id, 'dgl_org_service_users', true );
		$fit   = Vocab::fit_from_org( '' !== $legal ? [ $legal ] : [], is_array( $users ) ? array_map( 'strval', $users ) : [] );

		return [] === $fit['applicants'] && [] === $fit['beneficiaries'] ? null : $fit;
	}
}
