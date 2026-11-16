<?php
/**
 * The funders' pages the weekly import reads, kept on the site so the team
 * can add and remove them.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

use DGL\Meta;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * The list lives in one option. The Worker asks for it at the start of every
 * run (GET /wp-json/dgl/v1/funding/sources); the first time, when the list is
 * still empty, it seeds it from the list it was built with. From then on this
 * is the list: what the team adds is read on the next run, what they delete
 * is not read again and its funds come off the site at once.
 *
 * Each source: id (stable, used as the key of its funds), name, url, notes,
 * added (date) and by (user id, 0 for the seed).
 */
final class Sources {

	public const OPTION = 'dgl_funding_sources';

	/** At most this many: well beyond what one weekly run is built for. */
	public const MAX = 400;

	// ------------------------------------------------------------------
	// Pure.
	// ------------------------------------------------------------------

	/** An https address with a host, else ''. */
	public static function clean_url( string $url ): string {
		$url = trim( $url );

		// Another scheme (javascript:, mailto:, ftp://) is never a funder's page.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) && ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$url  = (string) preg_replace( '#^http://#i', 'https://', $url );
		$host = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, tested without WordPress.

		// A real host: a dot, letters, digits and dashes, no spaces.
		if ( ! preg_match( '/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/i', $host ) ) {
			return '';
		}

		return Import::https( $url );
	}

	/**
	 * A short, stable id from the name, not already taken.
	 *
	 * @param string[] $taken
	 */
	public static function make_id( string $name, array $taken ): string {
		$base = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', $name ), '-' ) );
		$base = '' !== $base ? substr( $base, 0, 50 ) : 'source';
		$id   = $base;
		$n    = 2;

		while ( in_array( $id, $taken, true ) ) {
			$id = $base . '-' . $n++;
		}

		return $id;
	}

	/**
	 * A source to add, cleaned, or an error message.
	 *
	 * @param array<int, array<string, mixed>> $existing
	 * @return array{id:string, name:string, url:string, notes:string}|string
	 */
	public static function prepare( string $name, string $url, string $notes, array $existing ) {
		$name  = trim( strip_tags( $name ) );
		$notes = trim( strip_tags( $notes ) );
		$url   = self::clean_url( $url );

		if ( '' === $name ) {
			return __( 'Give the funder a name.', 'dgl-platform' );
		}

		if ( '' === $url ) {
			return __( 'That does not look like a web address. Paste the full address of the funder\'s page.', 'dgl-platform' );
		}

		foreach ( $existing as $source ) {
			if ( self::same_page( (string) ( $source['url'] ?? '' ), $url ) ) {
				/* translators: %s: the name it is already listed under. */
				return sprintf( __( 'That page is already on the list, as %s.', 'dgl-platform' ), (string) ( $source['name'] ?? '' ) );
			}
		}

		if ( count( $existing ) >= self::MAX ) {
			return __( 'The list is full. Delete a source before adding another.', 'dgl-platform' );
		}

		return [
			'id'    => self::make_id( $name, array_map( static fn( array $s ): string => (string) ( $s['id'] ?? '' ), $existing ) ),
			'name'  => mb_substr( $name, 0, 120 ),
			'url'   => $url,
			'notes' => mb_substr( $notes, 0, 300 ),
		];
	}

	/** Whether two addresses are the same page, ignoring scheme, www, case and a trailing slash. */
	public static function same_page( string $a, string $b ): bool {
		$norm = static fn( string $u ): string => rtrim( strtolower( (string) preg_replace( '#^https?://(www\.)?#i', '', trim( $u ) ) ), '/' );

		return '' !== $a && $norm( $a ) === $norm( $b );
	}

	// ------------------------------------------------------------------
	// WordPress.
	// ------------------------------------------------------------------

	/**
	 * Every source, in name order.
	 *
	 * @return array<int, array{id:string, name:string, url:string, notes:string, added:string, by:int}>
	 */
	public static function all(): array {
		$list = get_option( self::OPTION, [] );
		$list = is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : [];

		usort( $list, static fn( array $a, array $b ): int => strnatcasecmp( (string) $a['name'], (string) $b['name'] ) );

		return $list;
	}

	/**
	 * Fill an empty list from the Worker's own. Refused once there is a list,
	 * so the Worker can never overwrite the team's changes.
	 *
	 * @param array<int, mixed> $sources
	 * @return int How many were stored.
	 */
	public static function seed( array $sources ): int {
		if ( [] !== self::all() ) {
			return 0;
		}

		$out = [];

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$id  = Import::slug_key( (string) ( $source['id'] ?? '' ) );
			$url = self::clean_url( (string) ( $source['url'] ?? '' ) );

			if ( '' === $id || '' === $url || isset( $out[ $id ] ) ) {
				continue;
			}

			$out[ $id ] = [
				'id'    => $id,
				'name'  => mb_substr( trim( wp_strip_all_tags( (string) ( $source['name'] ?? $id ) ) ), 0, 120 ),
				'url'   => $url,
				'notes' => mb_substr( trim( wp_strip_all_tags( (string) ( $source['notes'] ?? '' ) ) ), 0, 300 ),
				'added' => gmdate( 'Y-m-d' ),
				'by'    => 0,
			];

			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}

		update_option( self::OPTION, array_values( $out ), false );

		return count( $out );
	}

	/**
	 * Add one. Returns the stored source, or an error message.
	 *
	 * @return array<string, mixed>|string
	 */
	public static function add( string $name, string $url, string $notes, int $user_id ) {
		$list   = self::all();
		$source = self::prepare( $name, $url, $notes, $list );

		if ( is_string( $source ) ) {
			return $source;
		}

		$source += [ 'added' => gmdate( 'Y-m-d' ), 'by' => $user_id ];
		$list[]  = $source;
		update_option( self::OPTION, $list, false );

		\DGL\Audit\Log::record( 'funding_source_added', 'setting', 0, 0, sprintf( '%s: %s', $source['name'], $source['url'] ), [], $user_id );

		return $source;
	}

	/**
	 * Delete one, and take its funds off the site now: a live fund expires
	 * today, one waiting for review leaves the queue.
	 *
	 * @return int|null How many funds were taken off, or null when there is no such source.
	 */
	public static function remove( string $id, int $user_id ): ?int {
		$list = self::all();
		$keep = array_values( array_filter( $list, static fn( array $s ): bool => $s['id'] !== $id ) );

		if ( count( $keep ) === count( $list ) ) {
			return null;
		}

		$gone = array_values( array_filter( $list, static fn( array $s ): bool => $s['id'] === $id ) )[0];
		update_option( self::OPTION, $keep, false );

		$taken = 0;

		foreach ( self::fund_ids( $id, [ Statuses::LIVE, Statuses::PENDING ] ) as $post_id ) {
			if ( Statuses::LIVE === get_post_status( $post_id ) ) {
				update_post_meta( $post_id, Meta::ITEM_EXPIRES_AT, current_time( 'mysql' ) );
				wp_update_post( [ 'ID' => $post_id, 'post_status' => Statuses::EXPIRED ] );
			} else {
				wp_update_post( [ 'ID' => $post_id, 'post_status' => Statuses::EXPIRED ] );
			}

			\DGL\Index\Sync::sync( $post_id );
			++$taken;
		}

		\DGL\Audit\Log::record( 'funding_source_removed', 'setting', 0, 0, sprintf( '%s: %s (%d funds taken off)', $gone['name'], $gone['url'], $taken ), [], $user_id );

		return $taken;
	}

	/**
	 * Funds from one source, by status.
	 *
	 * @param string[] $statuses
	 * @return int[]
	 */
	public static function fund_ids( string $source_id, array $statuses ): array {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'      => PostTypes::GRANT,
					'post_status'    => $statuses,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => Import::META_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- an admin screen.
					'meta_value'     => $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- an admin screen.
				]
			)
		);
	}

	/**
	 * Live funds per source id, in one query.
	 *
	 * @return array<string, int>
	 */
	public static function live_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value AS source, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status = %s GROUP BY m.meta_value",
				Import::META_SOURCE,
				PostTypes::GRANT,
				Statuses::LIVE
			),
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['source'] ] = (int) $row['n'];
		}

		return $out;
	}
}
