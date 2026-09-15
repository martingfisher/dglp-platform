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

		return (int) $post_id;
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
		$upload_errors = self::handle_uploads( $post_id, $fields, $files, $input, $result['values'] );

		$errors = array_merge( $result['errors'], $upload_errors );

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
		$post_update = [];

		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			if ( ! array_key_exists( $field->key, $values ) || in_array( $field->key, $skip, true ) ) {
				continue;
			}

			$value = $values[ $field->key ];

			if ( 'title' === $field->key ) {
				$post_update['post_title'] = sanitize_text_field( (string) $value );
				continue;
			}

			if ( 'body' === $field->key ) {
				// wp_kses_post rather than stripping tags: a member pasting a
				// formatted description should keep their paragraphs and links.
				$post_update['post_content'] = wp_kses_post( (string) $value );
				continue;
			}

			/*
			 * An optional field left blank has to leave no row behind. Casting
			 * '' to an int stores a real 0, which then reads back as an answer:
			 * a blank Capacity became "Capacity: 0", which is a claim the
			 * member never made.
			 */
			if ( Field::CHECKBOX !== $field->type && '' === (string) $value ) {
				delete_post_meta( $post_id, $field->meta_key() );
				continue;
			}

			update_post_meta( $post_id, $field->meta_key(), self::sanitise( $field, $value ) );
		}

		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $post_id;
			wp_update_post( $post_update );
		}
	}

	/**
	 * Final sanitisation before storage.
	 *
	 * The validator has already normalised shape and format. This is the second
	 * layer: what actually goes in the database.
	 */
	private static function sanitise( Field $field, mixed $value ): mixed {
		return match ( $field->type ) {
			Field::TEXTAREA, Field::RICHTEXT => sanitize_textarea_field( (string) $value ),
			Field::URL                       => esc_url_raw( (string) $value ),
			Field::EMAIL                     => sanitize_email( (string) $value ),
			Field::CHECKBOX                  => (bool) $value,
			Field::NUMBER, Field::IMAGE      => (int) $value,
			Field::MONEY                     => (float) $value,
			default                          => sanitize_text_field( (string) $value ),
		};
	}

	/**
	 * Handle any file uploads on this step.
	 *
	 * @param Field[]              $fields Fields on this step.
	 * @param array<string, mixed> $files  $_FILES.
	 * @param array<string, mixed> $input  Raw POST, for the remove checkbox.
	 * @param array<string, mixed> $values Validated values, updated by reference.
	 * @return array<string, string> Field key => error.
	 */
	private static function handle_uploads( int $post_id, array $fields, array $files, array $input, array &$values ): array {
		$errors = [];

		foreach ( $fields as $field ) {
			if ( Field::IMAGE !== $field->type ) {
				continue;
			}

			$remove_key = FieldRenderer::INPUT_NAME . '_remove_' . $field->key;

			if ( ! empty( $input[ $remove_key ] ) || ! empty( $_POST[ $remove_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
				$values[ $field->key ] = 0;
				continue;
			}

			$file_key = FieldRenderer::INPUT_NAME . '_file_' . $field->key;
			$file     = $files[ $file_key ] ?? null;

			if ( ! is_array( $file ) || empty( $file['name'] ) || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				continue; // Nothing uploaded, keep whatever is already there.
			}

			$attachment_id = self::store_upload( $post_id, $file_key );

			if ( is_wp_error( $attachment_id ) ) {
				$errors[ $field->key ] = $attachment_id->get_error_message();
				continue;
			}

			$values[ $field->key ] = $attachment_id;
		}

		return $errors;
	}

	/**
	 * Validate and store one uploaded image.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	private static function store_upload( int $post_id, string $file_key ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
		$file = $_FILES[ $file_key ] ?? null;

		if ( ! is_array( $file ) ) {
			return new WP_Error( 'dgl_no_file', __( 'No file arrived. Try again.', 'dgl-platform' ) );
		}

		if ( (int) ( $file['size'] ?? 0 ) > Uploads::MAX_BYTES ) {
			return new WP_Error(
				'dgl_too_big',
				sprintf(
					/* translators: %s: maximum size, already formatted. */
					__( 'That image is too large. The limit is %s.', 'dgl-platform' ),
					size_format( Uploads::MAX_BYTES )
				)
			);
		}

		/*
		 * Check the real type from the file's contents, not the name or the
		 * browser-supplied type. Both are attacker controlled.
		 */
		$check = wp_check_filetype_and_ext( $file['tmp_name'] ?? '', $file['name'] ?? '' );

		if ( empty( $check['type'] ) || ! Uploads::is_allowed_mime( (string) $check['type'] ) ) {
			return new WP_Error(
				'dgl_bad_type',
				__( 'That file type is not allowed. Use a JPEG, PNG or WebP image.', 'dgl-platform' )
			);
		}

		$attachment_id = media_handle_upload(
			$file_key,
			$post_id,
			[],
			[
				'test_form' => false,
				'mimes'     => Uploads::allowed_mimes(),
			]
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Stamp the organisation so attachment access can be scoped like items.
		$org_id = Org::for_item( $post_id );

		if ( $org_id > 0 ) {
			update_post_meta( $attachment_id, Meta::ITEM_ORG, $org_id );
		}

		return (int) $attachment_id;
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
