<?php
/**
 * WP-CLI: load the organisation list.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Audit\Log;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Schema\Store;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class ImportCommand {

	public static function register(): void {
		WP_CLI::add_command( 'dgl org', self::class );
	}

	/**
	 * Import organisations from Forum Central's CSV export.
	 *
	 * Matches on the Contact ID first, then on the exact name, so running
	 * it again updates rather than duplicates. Every mapped field is written
	 * from the file; fields the file leaves blank are cleared. The directory
	 * flag is never touched: nobody appears without choosing to.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV.
	 *
	 * [--dry-run]
	 * : Report what would happen and write nothing.
	 *
	 * [--approve]
	 * : Mark newly created organisations as verified. Existing ones keep
	 * their status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl org import members.csv --dry-run
	 *     wp dgl org import members.csv --approve
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$file    = (string) ( $args[0] ?? '' );
		$dry_run = isset( $assoc['dry-run'] );
		$approve = isset( $assoc['approve'] );

		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( 'Give a readable CSV file.' );
		}

		$rows = self::read( $file );

		if ( [] === $rows ) {
			WP_CLI::error( 'The file has no rows.' );
		}

		$missing = array_diff( Import::required_columns(), array_keys( $rows[0] ) );

		if ( [] !== $missing ) {
			WP_CLI::error( 'Not the expected export. Missing columns: ' . implode( ', ', $missing ) );
		}

		$created   = 0;
		$updated   = 0;
		$skipped   = 0;
		$unmatched = [];
		$no_domain = [];
		$problems  = [];

		foreach ( $rows as $n => $row ) {
			$mapped = Import::map( $row );
			$line   = $n + 2;

			if ( [] !== $mapped['problems'] ) {
				foreach ( $mapped['problems'] as $problem ) {
					$problems[] = sprintf( 'line %d, %s: %s', $line, $mapped['name'] ?: '(no name)', $problem );
				}

				if ( '' === $mapped['name'] ) {
					++$skipped;
					continue;
				}
			}

			foreach ( $mapped['unmatched'] as $column => $values ) {
				foreach ( $values as $value ) {
					$unmatched[ $column ][ $value ] = ( $unmatched[ $column ][ $value ] ?? 0 ) + 1;
				}
			}

			if ( [] === $mapped['domains'] ) {
				$no_domain[] = $mapped['name'];
			}

			$existing = self::find( $mapped['fc_id'], $mapped['name'] );

			if ( $dry_run ) {
				null === $existing ? ++$created : ++$updated;
				continue;
			}

			$org_id = $existing ?? self::create( $mapped['name'], $approve );

			if ( null === $existing ) {
				++$created;
			} else {
				++$updated;
				wp_update_post( [ 'ID' => $org_id, 'post_title' => $mapped['name'] ] );
			}

			self::write( $org_id, $mapped );
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '%s%d organisations: %d new, %d already known, %d skipped.', $dry_run ? 'DRY RUN. ' : '', count( $rows ), $created, $updated, $skipped ) );
		WP_CLI::log( sprintf( '%d have no email domain to join on (no address, or a public provider); colleagues there will need invitations.', count( $no_domain ) ) );

		if ( [] !== $unmatched ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Values in the file that match no option and were left out:' );

			foreach ( $unmatched as $column => $values ) {
				foreach ( $values as $value => $count ) {
					WP_CLI::log( sprintf( '  %s: "%s" (%d)', $column, $value, $count ) );
				}
			}
		}

		if ( [] !== $problems ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Rows with problems:' );

			foreach ( $problems as $problem ) {
				WP_CLI::log( '  ' . $problem );
			}
		}

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::success( 'Nothing was written.' );
			return;
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function read( string $file ): array {
		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return [];
		}

		$rows    = [];
		$headers = null;

		// A UTF-8 byte order mark, which Excel and CiviCRM both write, sits
		// before the first quote and stops fgetcsv seeing it as a quote.
		if ( "\xEF\xBB\xBF" !== fread( $handle, 3 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			rewind( $handle );
		}

		while ( ( $line = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( null === $headers ) {
				$headers = array_map( 'trim', $line );
				continue;
			}

			if ( 1 === count( $line ) && null === $line[0] ) {
				continue;
			}

			$row = [];
			foreach ( $headers as $i => $header ) {
				$row[ $header ] = (string) ( $line[ $i ] ?? '' );
			}
			$rows[] = $row;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * An organisation already holding this Contact ID, or with exactly this
	 * name, or null.
	 */
	private static function find( string $fc_id, string $name ): ?int {
		if ( '' !== $fc_id ) {
			$found = get_posts(
				[
					'post_type'      => PostTypes::ORG,
					'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
					'meta_key'       => Meta::ORG_FC_ID,
					'meta_value'     => $fc_id,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				]
			);

			if ( [] !== $found ) {
				return (int) $found[0];
			}
		}

		$found = get_posts(
			[
				'post_type'      => PostTypes::ORG,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'title'          => $name,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);

		return [] === $found ? null : (int) $found[0];
	}

	private static function create( string $name, bool $approve ): int {
		$id = wp_insert_post(
			[
				'post_type'   => PostTypes::ORG,
				'post_title'  => $name,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		update_post_meta( (int) $id, Meta::ORG_STATUS, $approve ? Meta::ORG_APPROVED : Meta::ORG_PENDING );

		return (int) $id;
	}

	/**
	 * @param array<string, mixed> $mapped Output of {@see Import::map()}.
	 */
	private static function write( int $org_id, array $mapped ): void {
		foreach ( Schema::open_fields() as $field ) {
			if ( ! array_key_exists( $field->key, $mapped['fields'] ) ) {
				continue;
			}

			$value = Store::sanitise( $field, $mapped['fields'][ $field->key ] );
			$blank = is_array( $value ) ? [] === $value : '' === (string) $value;

			if ( $blank ) {
				delete_post_meta( $org_id, $field->meta_key() );
			} else {
				update_post_meta( $org_id, $field->meta_key(), $value );
			}
		}

		Org::set_domains( $org_id, $mapped['domains'] );

		$facts = $mapped['facts'];
		$keys  = [
			Meta::ORG_FC_ID         => $facts['fc_id'],
			Meta::ORG_FC_VOLITION   => $facts['volition'],
			Meta::ORG_FC_LOPF       => $facts['lopf'],
			Meta::ORG_FC_PERMISSION => $facts['permission'],
			Meta::ORG_AGE_FRIENDLY  => $facts['age_friendly'],
			Meta::ORG_LAT           => $facts['lat'],
			Meta::ORG_LNG           => $facts['lng'],
		];

		foreach ( $keys as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $org_id, $key );
			} else {
				update_post_meta( $org_id, $key, $value );
			}
		}

		update_post_meta( $org_id, Meta::ORG_IMPORTED_AT, current_time( 'mysql', true ) );

		Log::record( 'org_imported', 'org', $org_id, $org_id, 'Forum Central export', [], 0 );
	}
}
