<?php
/**
 * CSV exports for the review team.
 *
 * One file per content type with every listing the organisation has ever
 * sent, and one of the month's decisions. Built as rows first, written
 * second, so the same rows are tested and downloaded. UTF-8 with a byte
 * order mark, which is what makes Excel read accents correctly.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Reports;

use DGL\Audit\Table as AuditTable;
use DGL\Dashboard\View;
use DGL\Dashboard\Wizard;
use DGL\Events\Wording;
use DGL\Index\ItemsTable;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Workflow\StateMachine;

defined( 'ABSPATH' ) || exit;

final class Csv {

	public const DECISIONS = 'decisions';

	/** At most this many rows in one file. */
	public const MAX_ROWS = 5000;

	/**
	 * Every listing of one type, newest first: the fixed columns then the
	 * schema's CSV fields.
	 *
	 * @return array<int, array<int, string>> The header row, then one row per listing.
	 */
	public static function listings( string $post_type ): array {
		global $wpdb;

		$fields = FieldRegistry::csv_fields( $post_type );
		$header = array_merge(
			[
				__( 'ID', 'dgl-platform' ),
				__( 'Status', 'dgl-platform' ),
				__( 'Organisation', 'dgl-platform' ),
				__( 'Topics', 'dgl-platform' ),
				__( 'Submitted', 'dgl-platform' ),
				__( 'Approved', 'dgl-platform' ),
				__( 'Comes off', 'dgl-platform' ),
				__( 'Link', 'dgl-platform' ),
			],
			array_map( static fn( Field $f ): string => $f->label, $fields )
		);

		$items = ItemsTable::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, status, org_id, submitted_at, approved_at, expires_at FROM {$items} WHERE post_type = %s ORDER BY updated_at DESC LIMIT %d", $post_type, self::MAX_ROWS ),
			ARRAY_A
		);

		$out = [ $header ];

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$values  = Wizard::values( $post_id, $post_type );
			$topics  = wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'names' ] );

			$line = [
				(string) $post_id,
				Statuses::label( (string) $row['status'] ),
				(string) get_the_title( (int) $row['org_id'] ),
				is_array( $topics ) ? implode( '; ', array_map( 'strval', $topics ) ) : '',
				(string) ( $row['submitted_at'] ?? '' ),
				(string) ( $row['approved_at'] ?? '' ),
				(string) ( $row['expires_at'] ?? '' ),
				Statuses::LIVE === (string) $row['status'] ? (string) get_permalink( $post_id ) : '',
			];

			foreach ( $fields as $field ) {
				$line[] = self::cell( $field, $values[ $field->key ] ?? '' );
			}

			$out[] = $line;
		}

		return $out;
	}

	/**
	 * The month's decisions on items and edits: when, what, by whom, with the note.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function decisions( string $ym ): array {
		global $wpdb;

		$w     = Monthly::window( $ym );
		$audit = AuditTable::name();
		$posts = $wpdb->posts;

		$actions = [ StateMachine::SUBMIT, StateMachine::APPROVE, StateMachine::REQUEST_CHANGES, StateMachine::REJECT, StateMachine::TAKE_DOWN, StateMachine::REOPEN, StateMachine::EXPIRE, StateMachine::ARCHIVE, StateMachine::RESTORE ];
		$marks   = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.logged_at, a.action, a.object_type, a.object_id, a.org_id, a.actor_id, a.note, p.post_title, p.post_type FROM {$audit} a LEFT JOIN {$posts} p ON p.ID = a.object_id"
				. " WHERE a.object_type IN ('item', 'revision') AND a.action IN ({$marks}) AND a.logged_at BETWEEN %s AND %s ORDER BY a.logged_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...array_merge( $actions, [ $w['from_utc'], $w['to_utc'], self::MAX_ROWS ] )
			),
			ARRAY_A
		);

		$out = [
			[
				__( 'When', 'dgl-platform' ),
				__( 'Decision', 'dgl-platform' ),
				__( 'What', 'dgl-platform' ),
				__( 'Title', 'dgl-platform' ),
				__( 'Organisation', 'dgl-platform' ),
				__( 'By', 'dgl-platform' ),
				__( 'Note', 'dgl-platform' ),
			],
		];

		foreach ( (array) $rows as $row ) {
			$type = 'revision' === (string) $row['object_type'] || PostTypes::REVISION === (string) $row['post_type']
				? __( 'Edit', 'dgl-platform' )
				: (string) ( PostTypes::definitions()[ (string) $row['post_type'] ]['singular'] ?? $row['post_type'] );
			$by   = (int) $row['actor_id'] > 0 ? get_userdata( (int) $row['actor_id'] ) : null;

			$out[] = [
				View::date( (string) $row['logged_at'], true ),
				self::decision_label( (string) $row['action'] ),
				$type,
				(string) ( $row['post_title'] ?? '' ),
				(string) get_the_title( (int) $row['org_id'] ),
				$by instanceof \WP_User ? (string) $by->display_name : ( 0 === (int) $row['actor_id'] ? __( 'The system', 'dgl-platform' ) : '' ),
				(string) ( $row['note'] ?? '' ),
			];
		}

		return $out;
	}

	public static function decision_label( string $action ): string {
		return match ( $action ) {
			StateMachine::SUBMIT          => __( 'Sent for review', 'dgl-platform' ),
			StateMachine::APPROVE         => __( 'Approved', 'dgl-platform' ),
			StateMachine::REQUEST_CHANGES => __( 'Sent back', 'dgl-platform' ),
			StateMachine::REJECT          => __( 'Refused', 'dgl-platform' ),
			StateMachine::TAKE_DOWN       => __( 'Taken off the site', 'dgl-platform' ),
			StateMachine::REOPEN          => __( 'Reopened', 'dgl-platform' ),
			StateMachine::EXPIRE          => __( 'Came off on its date', 'dgl-platform' ),
			StateMachine::ARCHIVE         => __( 'Archived', 'dgl-platform' ),
			StateMachine::RESTORE         => __( 'Restored', 'dgl-platform' ),
			'pinned'                      => __( 'Featured', 'dgl-platform' ),
			'cancelled'                   => __( 'Marked cancelled by the organisation', 'dgl-platform' ),
			'date_cancelled'              => __( 'One date cancelled by the organisation', 'dgl-platform' ),
			'series_extended'             => __( 'Event kept on for six more months', 'dgl-platform' ),
			'listing_extended'            => __( 'News kept on for three more months', 'dgl-platform' ),
			'schedule_changed'            => __( 'Dates changed by the organisation', 'dgl-platform' ),
			default                       => $action,
		};
	}

	/**
	 * One field's value as a plain string.
	 */
	public static function cell( Field $field, mixed $value ): string {
		return match ( $field->type ) {
			Field::CHECKBOX => $value ? __( 'Yes', 'dgl-platform' ) : __( 'No', 'dgl-platform' ),
			Field::SELECT   => (string) ( $field->options[ (string) $value ] ?? $value ),
			Field::CHOICES  => implode( '; ', array_map( static fn( $v ): string => (string) ( $field->options[ (string) $v ] ?? $v ), (array) $value ) ),
			Field::IMAGE    => (int) $value > 0 ? (string) wp_get_attachment_url( (int) $value ) : '',
			Field::REPEAT   => is_array( $value ) && [] !== $value ? Wording::describe( $value ) : '',
			Field::RICHTEXT => trim( wp_strip_all_tags( (string) $value ) ),
			default         => is_array( $value ) ? implode( '; ', array_map( 'strval', $value ) ) : (string) $value,
		};
	}

	/**
	 * The rows as a file: BOM, CRLF, every cell quoted as needed.
	 *
	 * A cell that starts with =, +, - or @ gets a leading apostrophe, so a
	 * member's text cannot become a formula when the file opens in Excel.
	 *
	 * @param array<int, array<int, string>> $rows
	 */
	public static function write( array $rows ): string {
		$fh = fopen( 'php://temp', 'w+' );

		if ( false === $fh ) {
			return '';
		}

		fwrite( $fh, "\xEF\xBB\xBF" );

		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( [ self::class, 'safe' ], $row ), ',', '"', '\\', "\r\n" );
		}

		rewind( $fh );
		$out = (string) stream_get_contents( $fh );
		fclose( $fh );

		return $out;
	}

	public static function safe( string $cell ): string {
		return 1 === preg_match( '/^[=+\-@\t\r]/', $cell ) ? "'" . $cell : $cell;
	}

	/** The file name for a type's export, or for the month's decisions. */
	public static function filename( string $what, string $ym = '' ): string {
		$stamp = (string) wp_date( 'Y-m-d' );

		if ( self::DECISIONS === $what ) {
			return sanitize_file_name( 'dglp-decisions-' . $ym . '.csv' );
		}

		$slug = (string) ( PostTypes::definitions()[ $what ]['slug'] ?? $what );

		return sanitize_file_name( 'dglp-' . $slug . '-' . $stamp . '.csv' );
	}
}
