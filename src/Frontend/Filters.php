<?php
/**
 * Topic and date filters on a public list.
 *
 * `?topic=<slug>` narrows any list to one topic. `?when=<spell>` narrows
 * the events list to a window: today, the next seven days, this weekend,
 * this month, next month. A series counts when its next date falls in the
 * window, which is what "runs this week" means to a visitor. The window
 * arithmetic is pure and unit-tested; the query bits sit on the main
 * query so paging and autoload carry the filters for free.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DateTimeImmutable;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Taxonomies;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class Filters {

	public const PARAM_TOPIC = 'topic';
	public const PARAM_WHEN  = 'when';

	/**
	 * The date windows on offer, in order.
	 *
	 * @return array<string, string> Spell => label.
	 */
	public static function whens(): array {
		return [
			'today'      => __( 'Today', 'dgl-platform' ),
			'week'       => __( 'Next 7 days', 'dgl-platform' ),
			'weekend'    => __( 'This weekend', 'dgl-platform' ),
			'month'      => __( 'This month', 'dgl-platform' ),
			'next-month' => __( 'Next month', 'dgl-platform' ),
		];
	}

	/**
	 * The window a spell means, from a given day. Null for an unknown spell.
	 *
	 * "This weekend" is the coming Saturday and Sunday; on a Sunday it is
	 * today alone, because that is the weekend somebody on a Sunday means.
	 *
	 * @return array{from: DateTimeImmutable, to: DateTimeImmutable}|null
	 */
	public static function window( string $when, DateTimeImmutable $today ): ?array {
		$day = $today->setTime( 0, 0, 0 );
		$end = static fn( DateTimeImmutable $d ): DateTimeImmutable => $d->setTime( 23, 59, 59 );

		return match ( $when ) {
			'today'      => [ 'from' => $day, 'to' => $end( $day ) ],
			'week'       => [ 'from' => $day, 'to' => $end( $day->modify( '+6 days' ) ) ],
			'weekend'    => [
				'from' => 7 === (int) $day->format( 'N' ) ? $day : $day->modify( 'saturday this week' ),
				'to'   => $end( 7 === (int) $day->format( 'N' ) ? $day : $day->modify( 'sunday this week' ) ),
			],
			'month'      => [ 'from' => $day, 'to' => $end( $day->modify( 'last day of this month' ) ) ],
			'next-month' => [ 'from' => $day->modify( 'first day of next month' ), 'to' => $end( $day->modify( 'last day of next month' ) ) ],
			default      => null,
		};
	}

	/**
	 * Clean the request. A topic that is not a real term, or a spell that
	 * is not on offer, is dropped rather than carried.
	 *
	 * @param array<string, mixed> $request Usually $_GET.
	 * @return array{topic: string, when: string}
	 */
	public static function args_from( array $request, string $post_type ): array {
		$topic = isset( $request[ self::PARAM_TOPIC ] ) && is_scalar( $request[ self::PARAM_TOPIC ] ) ? sanitize_title( (string) $request[ self::PARAM_TOPIC ] ) : '';
		$when  = isset( $request[ self::PARAM_WHEN ] ) && is_scalar( $request[ self::PARAM_WHEN ] ) ? sanitize_key( (string) $request[ self::PARAM_WHEN ] ) : '';

		if ( '' !== $topic && ! term_exists( $topic, Taxonomies::TOPIC ) ) {
			$topic = '';
		}

		if ( PostTypes::EVENT !== $post_type || ! isset( self::whens()[ $when ] ) ) {
			$when = '';
		}

		return [ 'topic' => $topic, 'when' => $when ];
	}

	/**
	 * The topics worth offering: those with something published under them.
	 *
	 * @return array<string, string> Slug => name.
	 */
	public static function topics(): array {
		$terms = get_terms( [ 'taxonomy' => Taxonomies::TOPIC, 'hide_empty' => true, 'orderby' => 'name' ] );
		$out   = [];

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ (string) $term->slug ] = (string) $term->name;
			}
		}

		return $out;
	}

	/**
	 * Narrow the main query. Called from the archive ordering, after it has
	 * set its own meta_query, so the date window is ANDed with the
	 * dated-or-undated clause the ordering needs.
	 *
	 * @param array{topic: string, when: string} $args
	 */
	public static function apply( WP_Query $query, array $args ): void {
		if ( '' !== $args['topic'] ) {
			$query->set(
				'tax_query',
				[
					[ 'taxonomy' => Taxonomies::TOPIC, 'field' => 'slug', 'terms' => $args['topic'] ],
				]
			);
		}

		if ( '' === $args['when'] ) {
			return;
		}

		$window = self::window( $args['when'], new DateTimeImmutable( 'today', wp_timezone() ) );

		if ( null === $window ) {
			return;
		}

		$existing = $query->get( 'meta_query' );
		$range    = [
			'key'     => Meta::ITEM_NEXT_AT,
			'value'   => [ $window['from']->format( 'Y-m-d H:i:s' ), $window['to']->format( 'Y-m-d H:i:s' ) ],
			'compare' => 'BETWEEN',
			'type'    => 'CHAR',
		];

		$query->set(
			'meta_query',
			is_array( $existing ) && [] !== $existing
				? [ 'relation' => 'AND', 'dgl_range' => $range, $existing ]
				: [ 'dgl_range' => $range ]
		);
	}

	/**
	 * The list's address with these filters, and nothing else.
	 *
	 * @param array{topic: string, when: string} $args
	 */
	public static function url( string $base, array $args ): string {
		$query = array_filter( [ self::PARAM_TOPIC => $args['topic'], self::PARAM_WHEN => $args['when'] ], static fn( string $v ): bool => '' !== $v );

		return [] === $query ? $base : add_query_arg( array_map( 'rawurlencode', $query ), $base );
	}

	/** @param array{topic: string, when: string} $args */
	public static function is_active( array $args ): bool {
		return '' !== $args['topic'] || '' !== $args['when'];
	}
}
