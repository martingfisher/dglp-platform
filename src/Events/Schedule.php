<?php
/**
 * Changing a live event's dates and times without review.
 *
 * The three schedule fields (start, end, repeat) are saved straight onto
 * the live post, restamped and logged. The card is locked while an edit is
 * open for review, because an approved edit copies its fields onto the
 * parent and would overwrite a newer schedule with an older one.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Audit\Log;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Schema\Store;
use DGL\Schema\Validator;
use DGL\Workflow\Revisions;

defined( 'ABSPATH' ) || exit;

final class Schedule {

	/**
	 * The fields a schedule change may touch.
	 *
	 * @return Field[]
	 */
	public static function fields( string $post_type ): array {
		return array_values( array_filter( FieldRegistry::for_type( $post_type ), static fn( Field $f ): bool => $f->schedule ) );
	}

	/**
	 * Whether this person may change this item's schedule right now.
	 */
	public static function can_change( int $user_id, int $post_id ): bool {
		return Access::can( $user_id, Policy::CHANGE_SCHEDULE, $post_id ) && [] !== self::fields( (string) get_post_type( $post_id ) );
	}

	/**
	 * Locked while an edit is open, whether waiting for review or unfinished.
	 */
	public static function is_locked( int $post_id ): bool {
		return null !== Revisions::open_for( $post_id );
	}

	/**
	 * Validate and apply a schedule change.
	 *
	 * @param array<string, mixed> $input Raw `dgl` POST values.
	 * @return array<string, string> Field key => error. Empty means saved.
	 */
	public static function save( int $post_id, array $input, int $actor_id ): array {
		$post_type = (string) get_post_type( $post_id );
		$fields    = self::fields( $post_type );
		$result    = Validator::validate( $fields, $input );

		if ( [] !== $result['errors'] ) {
			return $result['errors'];
		}

		$before = self::wording( $post_id );

		Store::write( $post_id, $post_type, $result['values'] );
		Series::stamp( $post_id, $post_type );

		$after = self::wording( $post_id );

		Log::record(
			'schedule_changed',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			$after,
			[ 'before' => $before, 'after' => $after ],
			$actor_id
		);

		return [];
	}

	/**
	 * The schedule in words, for the audit row and the notification.
	 */
	public static function wording( int $post_id ): string {
		if ( PostTypes::EVENT !== get_post_type( $post_id ) ) {
			return '';
		}

		$rule  = Series::rule_for( $post_id );
		$start = (string) get_post_meta( $post_id, 'dgl_start_datetime', true );
		$end   = (string) get_post_meta( $post_id, 'dgl_end_datetime', true );

		if ( null !== $rule ) {
			return Wording::long( $rule->to_meta(), $start, $end, static fn( string $wall ): string => \DGL\Dashboard\View::wall_date( $wall, false ) );
		}

		if ( '' === $start ) {
			return '';
		}

		$out = \DGL\Dashboard\View::wall_date( $start, true );

		return '' !== $end ? $out . ' ' . __( 'to', 'dgl-platform' ) . ' ' . \DGL\Dashboard\View::wall_date( $end, true ) : $out;
	}
}
