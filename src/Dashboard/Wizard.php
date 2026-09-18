<?php
/**
 * The multi-step submission form.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Schema\Store;
use DGL\Schema\Validator;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Uploads;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creating, saving and submitting a member's draft.
 *
 * Each step saves on its own, so a member who closes the tab on step three has
 * lost nothing. That matters more than it sounds: these are volunteers and
 * small charity staff filling forms between other jobs, and a form that loses
 * work is a form that stops being used.
 *
 * Validation is per step going forward and across every step at review, so a
 * missing field on step one cannot be walked past and discovered only at the
 * end.
 */
final class Wizard {

	public const NONCE = 'dgl_wizard';

	/**
	 * Start a new draft and return its ID.
	 *
	 * @return int|WP_Error
	 */
	public static function create( string $post_type, int $user_id ) {
		if ( ! PostTypes::is_submittable( $post_type ) ) {
			return new WP_Error( 'dgl_bad_type', __( 'That is not something you can submit.', 'dgl-platform' ) );
		}

		if ( ! Access::can( $user_id, Policy::CREATE_ITEM ) ) {
			return new WP_Error( 'dgl_not_allowed', __( 'You cannot start a submission.', 'dgl-platform' ) );
		}

		$org_id = Org::for_user( $user_id );

		if ( null === $org_id ) {
			return new WP_Error( 'dgl_no_org', __( 'Your account is not linked to an organisation yet.', 'dgl-platform' ) );
		}

		/*
		 * Every visit to "New event" used to insert a post, so a member who
		 * clicked it, thought better of it and went back to the list left an
		 * Untitled draft behind each time; one test account had 47. An empty
		 * draft this person already owns is reused instead.
		 */
		$existing = self::empty_draft_for( $post_type, $user_id, $org_id );

		if ( null !== $existing ) {
			// Fills blanks only, so a reused draft gets the same start as a new one.
			self::prefill_contact( $existing, $org_id, $user_id );

			return $existing;
		}

		$post_id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_status' => Statuses::DRAFT,
				'post_author' => $user_id,
				'post_title'  => '',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Written after insert, which is why Index\Sync also watches meta writes.
		update_post_meta( $post_id, Meta::ITEM_ORG, $org_id );

		self::prefill_contact( (int) $post_id, $org_id, $user_id );

		return (int) $post_id;
	}

	/**
	 * A new draft made from an existing item: the same words, picture,
	 * venue, contact and topics, with the dates left blank.
	 *
	 * The expiry email tells a member to "copy it into a new submission with
	 * the new dates". This is the copy. Dates and the repeat rule are the
	 * one thing certain to be different next time, so they start empty and
	 * step 2 asks for them; everything else is exactly what was typed last
	 * time and stays editable.
	 *
	 * @return int|WP_Error The new draft's id.
	 */
	public static function copy( int $source_id, int $user_id ) {
		$source = get_post( $source_id );

		if ( ! $source instanceof \WP_Post || ! PostTypes::is_submittable( (string) $source->post_type ) ) {
			return new WP_Error( 'dgl_bad_source', __( 'That is not something you can copy.', 'dgl-platform' ) );
		}

		if ( ! Access::can( $user_id, Policy::VIEW_ITEM, $source_id ) || ! Access::can( $user_id, Policy::CREATE_ITEM ) ) {
			return new WP_Error( 'dgl_not_allowed', __( 'You cannot copy this one.', 'dgl-platform' ) );
		}

		$org_id = Org::for_user( $user_id );

		if ( null === $org_id ) {
			return new WP_Error( 'dgl_no_org', __( 'Your account is not linked to an organisation yet.', 'dgl-platform' ) );
		}

		$post_type = (string) $source->post_type;
		$post_id   = wp_insert_post(
			[
				'post_type'    => $post_type,
				'post_status'  => Statuses::DRAFT,
				'post_author'  => $user_id,
				'post_title'   => $source->post_title,
				'post_content' => $source->post_content,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, Meta::ITEM_ORG, $org_id );

		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			if ( in_array( $field->key, [ 'title', 'body' ], true ) || $field->schedule ) {
				continue;
			}

			// The note about timing goes with the dates it described.
			if ( 'recurrence_note' === $field->key ) {
				continue;
			}

			$key = $field->meta_key();

			if ( metadata_exists( 'post', $source_id, $key ) ) {
				update_post_meta( (int) $post_id, $key, get_post_meta( $source_id, $key, true ) );
			}
		}

		$topics = wp_get_object_terms( $source_id, Taxonomies::TOPIC, [ 'fields' => 'ids' ] );

		if ( is_array( $topics ) && [] !== $topics ) {
			wp_set_object_terms( (int) $post_id, array_map( 'intval', $topics ), Taxonomies::TOPIC, false );
		}

		\DGL\Audit\Log::record(
			'copied',
			'item',
			(int) $post_id,
			$org_id,
			sprintf(
				/* translators: %s: the title of the item it was copied from. */
				__( 'Copied from "%s".', 'dgl-platform' ),
				(string) $source->post_title
			),
			[ 'from' => $source_id ],
			$user_id
		);

		return (int) $post_id;
	}

	/**
	 * The contact fields a new draft starts with.
	 *
	 * Typing the same name, email, phone and website into every story was
	 * the complaint. So a new draft starts with whatever this organisation
	 * used last time, from its most recent item of any type; failing that,
	 * the organisation profile's public email, phone and website and the
	 * member's own name. Every value stays editable on step three.
	 */
	public const CONTACT_KEYS = [ 'contact_name', 'contact_email', 'contact_phone', 'website' ];

	private static function prefill_contact( int $post_id, int $org_id, int $user_id ): void {
		$values = [];
		$latest = get_posts(
			[
				'post_type'      => PostTypes::submittable(),
				'post_status'    => 'any',
				'exclude'        => [ $post_id ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_key'       => Meta::ITEM_ORG,
				'meta_value'     => $org_id,
			]
		);

		if ( [] !== $latest ) {
			foreach ( self::CONTACT_KEYS as $key ) {
				$values[ $key ] = (string) get_post_meta( (int) $latest[0], 'dgl_' . $key, true );
			}
		}

		$profile = \DGL\Org\Profile::values( $org_id );
		$user    = get_userdata( $user_id );
		$fallback = [
			'contact_name'  => $user ? (string) $user->display_name : '',
			'contact_email' => (string) ( $profile['org_email'] ?? '' ),
			'contact_phone' => (string) ( $profile['org_phone'] ?? '' ),
			'website'       => (string) ( $profile['org_website'] ?? '' ),
		];

		foreach ( self::CONTACT_KEYS as $key ) {
			$value = trim( (string) ( $values[ $key ] ?? '' ) );

			if ( '' === $value ) {
				$value = trim( $fallback[ $key ] );
			}

			if ( '' !== $value ) {
				update_post_meta( $post_id, 'dgl_' . $key, $value );
			}
		}
	}

	/**
	 * Whether nothing has been typed into this draft yet.
	 *
	 * Title, summary and body are the three things step one asks for; a
	 * draft with none of them has never been saved, because step one will
	 * not save without them.
	 */
	public static function is_empty( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( null === $post || Statuses::DRAFT !== $post->post_status ) {
			return false;
		}

		return '' === trim( $post->post_title )
			&& '' === trim( $post->post_content )
			&& '' === trim( (string) get_post_meta( $post_id, 'dgl_summary', true ) );
	}

	/**
	 * Delete a new draft that is still empty. Returns whether it did.
	 */
	public static function discard_if_empty( int $post_id ): bool {
		if ( ! self::is_empty( $post_id ) ) {
			return false;
		}

		return false !== wp_delete_post( $post_id, true );
	}

	/**
	 * Empty drafts nobody has touched for a while. Run from the daily sweep.
	 *
	 * @return int How many were deleted.
	 */
	public static function purge_empty_drafts( int $older_than_days = 7, int $limit = 200 ): int {
		$ids = get_posts(
			[
				'post_type'      => PostTypes::submittable(),
				'post_status'    => Statuses::DRAFT,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				// post_modified, not post_modified_gmt: WordPress leaves the GMT
				// column at 0000-00-00 on a draft that has never been published,
				// which reads as older than anything and would purge a draft
				// somebody started a minute ago. Caught by the integration test.
				'date_query'     => [ [ 'column' => 'post_modified', 'before' => $older_than_days . ' days ago' ] ],
				'no_found_rows'  => true,
			]
		);
		$done = 0;

		foreach ( (array) $ids as $id ) {
			if ( self::discard_if_empty( (int) $id ) ) {
				++$done;
			}
		}

		return $done;
	}

	private static function empty_draft_for( string $post_type, int $user_id, int $org_id ): ?int {
		$ids = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => Statuses::DRAFT,
				'author'         => $user_id,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_key'       => Meta::ITEM_ORG,
				'meta_value'     => $org_id,
			]
		);

		foreach ( (array) $ids as $id ) {
			if ( self::is_empty( (int) $id ) ) {
				return (int) $id;
			}
		}

		return null;
	}

	/**
	 * Current values for every field on a type, ready for the form.
	 *
	 * @return array<string, mixed>
	 */
	public static function values( int $post_id, string $post_type ): array {
		$post   = get_post( $post_id );
		$values = [];

		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			$values[ $field->key ] = match ( $field->key ) {
				'title' => null !== $post ? $post->post_title : '',
				'body'  => null !== $post ? $post->post_content : '',
				/*
				 * metadata_exists rather than get_post_meta alone: WordPress
				 * serves the registered default for a key that was never set,
				 * so an untouched number field came back as 0 and showed in the
				 * form as a real answer rather than an empty box.
				 */
				default => metadata_exists( 'post', $post_id, $field->meta_key() )
					? get_post_meta( $post_id, $field->meta_key(), true )
					: '',
			};
		}

		return $values;
	}

	/**
	 * Validate and save one step.
	 *
	 * @param array<string, mixed> $input Raw `dgl` POST values.
	 * @param array<string, mixed> $files Raw `$_FILES`.
	 * @return array<string, string> Field key => error. Empty means saved.
	 */
	public static function save_step( int $post_id, string $post_type, int $step, array $input, array $files = [] ): array {
		$fields = FieldRegistry::for_step( $post_type, $step );
		$result = Validator::validate( $fields, $input );

		// Uploads are handled before bailing on other errors, so a member does
		// not lose their file to an unrelated validation failure.
		$upload_errors = Uploads::handle( $post_id, $fields, $files, $input, $result['values'] );

		// A picture that just arrived needs its description too.
		$errors = array_merge( $result['errors'], $upload_errors, Validator::required_with_errors( $fields, $result['values'] ) );

		if ( ! empty( $errors ) ) {
			// Persist what did validate, so nothing typed is thrown away.
			self::persist( $post_id, $post_type, $result['values'], array_keys( $errors ) );

			return $errors;
		}

		self::persist( $post_id, $post_type, $result['values'] );

		if ( FieldRegistry::STEP_CONTACT === $step ) {
			self::save_topics( $post_id, $input );
		}

		return [];
	}

	/**
	 * Validate every step at once, for the review screen.
	 *
	 * @return array<string, string> Field key => error.
	 */
	public static function validate_all( int $post_id, string $post_type ): array {
		$values = self::values( $post_id, $post_type );
		$fields = FieldRegistry::for_type( $post_type );

		return Validator::validate( $fields, $values )['errors'];
	}

	/**
	 * Which step a field lives on, so the review screen can link to the fix.
	 */
	public static function step_of( string $post_type, string $key ): int {
		$field = FieldRegistry::find( $post_type, $key );

		return null !== $field ? $field->step : FieldRegistry::STEP_BASICS;
	}

	/**
	 * Write validated values to the post and its meta.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @param string[]             $skip   Keys to leave alone.
	 */
	private static function persist( int $post_id, string $post_type, array $values, array $skip = [] ): void {
		// One writer, shared with the wp-admin edit screen. See Schema\Store.
		Store::write( $post_id, $post_type, $values, $skip );
	}


	/**
	 * Save the topic terms chosen on the contact step.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function save_topics( int $post_id, array $input ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
		$raw = $input['topics'] ?? ( $_POST['dgl_topics'] ?? [] );

		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$term_ids = array_values( array_filter( array_map( 'absint', $raw ) ) );

		wp_set_object_terms( $post_id, $term_ids, Taxonomies::TOPIC, false );
	}
}
