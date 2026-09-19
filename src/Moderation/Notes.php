<?php
/**
 * The review team's notes on an item, for the review team only.
 *
 * "Asked the org to confirm the venue", "second time this has come in with
 * no picture". They live on the item as post meta, never in the audit
 * trail, so nothing that lists a member's history can surface them. A
 * note on an edit is kept on the item the edit belongs to, so it is still
 * there when the next edit comes in.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Moderation;

use DGL\PostTypes;
use DGL\Workflow\Revisions;

defined( 'ABSPATH' ) || exit;

final class Notes {

	public const META = 'dgl_team_notes';

	public const MAX_LENGTH = 2000;

	/** The item a note belongs to: the edit's parent for an edit, else the post itself. */
	public static function target( int $post_id ): int {
		if ( PostTypes::REVISION === get_post_type( $post_id ) ) {
			$parent = Revisions::target( $post_id );

			return $parent > 0 ? $parent : $post_id;
		}

		return $post_id;
	}

	/**
	 * Every note, oldest first.
	 *
	 * @return array<int, array{id: string, at: string, by: int, text: string}>
	 */
	public static function all( int $post_id ): array {
		$raw = get_post_meta( self::target( $post_id ), self::META, true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $note ) {
			if ( is_array( $note ) && isset( $note['id'], $note['text'] ) ) {
				$out[] = [
					'id'   => (string) $note['id'],
					'at'   => (string) ( $note['at'] ?? '' ),
					'by'   => (int) ( $note['by'] ?? 0 ),
					'text' => (string) $note['text'],
				];
			}
		}

		return $out;
	}

	public static function count( int $post_id ): int {
		return count( self::all( $post_id ) );
	}

	/**
	 * @return string|\WP_Error The new note's id.
	 */
	public static function add( int $post_id, string $text, int $actor_id ) {
		$text = trim( sanitize_textarea_field( $text ) );

		if ( '' === $text ) {
			return new \WP_Error( 'dgl_empty_note', __( 'Write the note first.', 'dgl-platform' ) );
		}

		if ( mb_strlen( $text ) > self::MAX_LENGTH ) {
			return new \WP_Error( 'dgl_long_note', sprintf( /* translators: %d: characters. */ __( 'Keep a note under %d characters.', 'dgl-platform' ), self::MAX_LENGTH ) );
		}

		$target = self::target( $post_id );
		$notes  = self::all( $target );
		$id     = substr( md5( uniqid( (string) $actor_id, true ) ), 0, 12 );

		$notes[] = [
			'id'   => $id,
			'at'   => current_time( 'mysql', true ),
			'by'   => $actor_id,
			'text' => $text,
		];

		update_post_meta( $target, self::META, $notes );

		return $id;
	}

	public static function remove( int $post_id, string $note_id ): bool {
		$target = self::target( $post_id );
		$notes  = self::all( $target );
		$kept   = array_values( array_filter( $notes, static fn( array $n ): bool => $n['id'] !== $note_id ) );

		if ( count( $kept ) === count( $notes ) ) {
			return false;
		}

		if ( [] === $kept ) {
			delete_post_meta( $target, self::META );
		} else {
			update_post_meta( $target, self::META, $kept );
		}

		return true;
	}
}
