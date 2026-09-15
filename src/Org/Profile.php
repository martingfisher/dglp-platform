<?php
/**
 * Reading and writing an organisation's profile.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Audit\Log;
use DGL\Meta;
use DGL\Schema\Field;
use DGL\Schema\Validator;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The organisation profile, and the gate on the two fields that matter.
 *
 * Most of a profile is the organisation's own business: a new phone number does
 * not need anybody's permission. The name and the logo are different. They
 * appear on every listing the organisation has ever posted, so a change to
 * either rewrites the attribution on live content. A verified organisation
 * quietly renaming itself is the whole point of verification defeated.
 *
 * So those two are held as a proposal until the team agree, and the live values
 * carry on being used everywhere until then. Same principle as a pending edit
 * to a submission, and for the same reason.
 */
final class Profile {

	/** Meta key holding a proposed name and logo awaiting the team. */
	public const PENDING = 'dgl_org_pending';

	/** Meta key holding when the proposal was made. */
	public const PENDING_AT = 'dgl_org_pending_at';

	/** User meta for the member's own profile fields. */
	public const USER_JOB   = 'dgl_person_job';
	public const USER_PHONE = 'dgl_person_phone';

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * The live profile: what the public sees right now.
	 *
	 * @return array<string, mixed>
	 */
	public static function values( int $org_id ): array {
		$values = [];

		foreach ( Schema::fields() as $field ) {
			$values[ $field->key ] = 'org_name' === $field->key
				? (string) get_the_title( $org_id )
				: self::meta( $org_id, $field );
		}

		return $values;
	}

	/**
	 * The proposal waiting on the team, or an empty array.
	 *
	 * @return array<string, mixed>
	 */
	public static function pending( int $org_id ): array {
		$raw = get_post_meta( $org_id, self::PENDING, true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		// Only the gated fields are ever held here. Anything else in the row is
		// stale data from an older version and is ignored rather than applied.
		$allowed = [];

		foreach ( Schema::approval_fields() as $field ) {
			if ( array_key_exists( $field->key, $raw ) ) {
				$allowed[ $field->key ] = $raw[ $field->key ];
			}
		}

		return $allowed;
	}

	public static function has_pending( int $org_id ): bool {
		return [] !== self::pending( $org_id );
	}

	/**
	 * When the proposal was made, as a UTC datetime, or an empty string.
	 */
	public static function pending_at( int $org_id ): string {
		return (string) get_post_meta( $org_id, self::PENDING_AT, true );
	}

	/**
	 * The proposed changes as before-and-after pairs, for the review screen.
	 *
	 * @return array<int, array{key:string, label:string, field:?Field, before:mixed, after:mixed}>
	 */
	public static function pending_changes( int $org_id ): array {
		$pending = self::pending( $org_id );
		$live    = self::values( $org_id );
		$changes = [];

		foreach ( Schema::approval_fields() as $field ) {
			if ( ! array_key_exists( $field->key, $pending ) ) {
				continue;
			}

			$before = $live[ $field->key ] ?? '';
			$after  = $pending[ $field->key ];

			if ( (string) $before === (string) $after ) {
				continue;
			}

			$changes[] = [
				'key'    => $field->key,
				'label'  => $field->label,
				'field'  => $field,
				'before' => $before,
				'after'  => $after,
			];
		}

		return $changes;
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Save a submitted profile form.
	 *
	 * Open fields are written straight away. Gated fields are compared against
	 * what is live and only held as a proposal if they actually differ, because
	 * somebody saving their phone number should not put their own name back
	 * into a review queue.
	 *
	 * @param array<string, mixed> $input Raw form values.
	 * @return array{errors: array<string, string>, held: string[]} Field errors,
	 *         and the keys that are now waiting on the team.
	 */
	public static function save( int $org_id, array $input, int $actor_id ): array {
		$result = Validator::validate( Schema::fields(), $input );

		if ( ! empty( $result['errors'] ) ) {
			return [ 'errors' => $result['errors'], 'held' => [] ];
		}

		$values  = $result['values'];
		$live    = self::values( $org_id );
		$held    = self::pending( $org_id );
		$changed = [];

		foreach ( Schema::open_fields() as $field ) {
			if ( ! array_key_exists( $field->key, $values ) ) {
				continue;
			}

			if ( (string) ( $live[ $field->key ] ?? '' ) !== (string) $values[ $field->key ] ) {
				$changed[ $field->key ] = [ $live[ $field->key ] ?? '', $values[ $field->key ] ];
			}

			self::write_meta( $org_id, $field, $values[ $field->key ] );
		}

		foreach ( Schema::approval_fields() as $field ) {
			if ( ! array_key_exists( $field->key, $values ) ) {
				continue;
			}

			$proposed = $values[ $field->key ];

			if ( (string) ( $live[ $field->key ] ?? '' ) === (string) $proposed ) {
				// Back to what is already live, so there is nothing to ask for.
				unset( $held[ $field->key ] );
				continue;
			}

			$held[ $field->key ] = $proposed;
		}

		if ( [] === $held ) {
			delete_post_meta( $org_id, self::PENDING );
			delete_post_meta( $org_id, self::PENDING_AT );
		} else {
			update_post_meta( $org_id, self::PENDING, $held );

			// Only stamped when the proposal first appears, so the team's queue
			// stays in the order things were actually asked for.
			if ( '' === self::pending_at( $org_id ) ) {
				update_post_meta( $org_id, self::PENDING_AT, current_time( 'mysql', true ) );
			}
		}

		if ( [] !== $changed ) {
			Log::record( 'org_updated', 'org', $org_id, $org_id, '', $changed, $actor_id );
		}

		if ( [] !== $held ) {
			Log::record(
				'org_change_requested',
				'org',
				$org_id,
				$org_id,
				__( 'Waiting for the review team.', 'dgl-platform' ),
				array_map( static fn( $v ): array => [ '', $v ], $held ),
				$actor_id
			);
		}

		return [ 'errors' => [], 'held' => array_keys( $held ) ];
	}

	/**
	 * Accept a proposed name or logo change.
	 *
	 * @return true|\WP_Error
	 */
	public static function approve_pending( int $org_id, int $actor_id ) {
		$pending = self::pending( $org_id );

		if ( [] === $pending ) {
			return new \WP_Error( 'dgl_nothing_pending', __( 'There is no change waiting on this organisation.', 'dgl-platform' ) );
		}

		$before = self::values( $org_id );

		foreach ( Schema::approval_fields() as $field ) {
			if ( ! array_key_exists( $field->key, $pending ) ) {
				continue;
			}

			if ( 'org_name' === $field->key ) {
				wp_update_post(
					[
						'ID'         => $org_id,
						'post_title' => sanitize_text_field( (string) $pending[ $field->key ] ),
					]
				);
				continue;
			}

			self::write_meta( $org_id, $field, $pending[ $field->key ] );
		}

		delete_post_meta( $org_id, self::PENDING );
		delete_post_meta( $org_id, self::PENDING_AT );

		$after = self::values( $org_id );

		Log::record(
			'org_change_approved',
			'org',
			$org_id,
			$org_id,
			'',
			self::diff( $before, $after, array_keys( $pending ) ),
			$actor_id
		);

		/**
		 * Fires when an organisation's name or logo change is accepted.
		 *
		 * @param int      $org_id
		 * @param string[] $keys   Which gated fields changed.
		 */
		do_action( 'dgl_org_change_approved', $org_id, array_keys( $pending ) );

		return true;
	}

	/**
	 * Refuse a proposed change. The live values are untouched.
	 *
	 * @return true|\WP_Error
	 */
	public static function reject_pending( int $org_id, int $actor_id, string $note ) {
		$pending = self::pending( $org_id );

		if ( [] === $pending ) {
			return new \WP_Error( 'dgl_nothing_pending', __( 'There is no change waiting on this organisation.', 'dgl-platform' ) );
		}

		if ( '' === trim( $note ) ) {
			return new \WP_Error(
				'dgl_note_required',
				__( 'Say why. The organisation sees this, and a refusal with no reason just produces another request.', 'dgl-platform' )
			);
		}

		delete_post_meta( $org_id, self::PENDING );
		delete_post_meta( $org_id, self::PENDING_AT );

		Log::record( 'org_change_rejected', 'org', $org_id, $org_id, trim( $note ), [], $actor_id );

		/**
		 * Fires when an organisation's proposed change is refused.
		 *
		 * @param int    $org_id
		 * @param string $note   The reason, shown to the organisation.
		 */
		do_action( 'dgl_org_change_rejected', $org_id, trim( $note ) );

		return true;
	}

	/**
	 * Every organisation with a change waiting, oldest request first.
	 *
	 * @return int[]
	 */
	public static function awaiting_review(): array {
		$found = get_posts(
			[
				'post_type'      => \DGL\PostTypes::ORG,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => self::PENDING_AT,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		// `meta_key` on its own matches rows that exist but are empty, so the
		// list is filtered against what is actually held rather than trusted.
		return array_values(
			array_filter(
				array_map( 'intval', $found ),
				static fn( int $id ): bool => self::has_pending( $id )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * The member's own details
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed>
	 */
	public static function person_values( int $user_id ): array {
		$user = get_userdata( $user_id );

		return [
			'person_name'  => $user ? (string) $user->display_name : '',
			'person_job'   => (string) get_user_meta( $user_id, self::USER_JOB, true ),
			'person_phone' => (string) get_user_meta( $user_id, self::USER_PHONE, true ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, string> Field errors. Empty means saved.
	 */
	public static function save_person( int $user_id, array $input ): array {
		$result = Validator::validate( Schema::person_fields(), $input );

		if ( ! empty( $result['errors'] ) ) {
			return $result['errors'];
		}

		$values = $result['values'];

		wp_update_user(
			[
				'ID'           => $user_id,
				'display_name' => sanitize_text_field( (string) ( $values['person_name'] ?? '' ) ),
			]
		);

		update_user_meta( $user_id, self::USER_JOB, sanitize_text_field( (string) ( $values['person_job'] ?? '' ) ) );
		update_user_meta( $user_id, self::USER_PHONE, sanitize_text_field( (string) ( $values['person_phone'] ?? '' ) ) );

		return [];
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function meta( int $org_id, Field $field ): mixed {
		$key = $field->meta_key();

		return metadata_exists( 'post', $org_id, $key ) ? get_post_meta( $org_id, $key, true ) : '';
	}

	/**
	 * Write one field, deleting rather than storing an empty answer.
	 *
	 * Same rule as the submission wizard: a blank optional field leaves no row,
	 * and an image with no attachment stores nothing rather than a zero that
	 * reads back as an answer.
	 */
	private static function write_meta( int $org_id, Field $field, mixed $value ): void {
		$key = $field->meta_key();

		if ( '' === (string) $value || ( Field::IMAGE === $field->type && 0 === (int) $value ) ) {
			delete_post_meta( $org_id, $key );
			return;
		}

		update_post_meta( $org_id, $key, $value );
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @param string[]             $keys
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private static function diff( array $before, array $after, array $keys ): array {
		$diff = [];

		foreach ( $keys as $key ) {
			$diff[ $key ] = [ $before[ $key ] ?? '', $after[ $key ] ?? '' ];
		}

		return $diff;
	}
}
