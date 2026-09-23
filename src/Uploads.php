<?php
/**
 * What members are allowed to upload, and what happens to it.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

use DGL\Dashboard\FieldRenderer;
use DGL\Org\Org;
use DGL\Schema\Field;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist for member-submitted images, and the one path they take in.
 *
 * This site permits SVG in the media library, which is fine for brand assets an
 * administrator uploads. It is not fine for files arriving from a few thousand
 * member organisations: an SVG is an XML document that can carry script, and
 * WordPress serves uploads from the same origin as the site, so a malicious
 * one becomes stored cross-site scripting against the DGLP team.
 *
 * So member uploads are rasters only. This is an allowlist rather than a
 * blocklist, because a blocklist is a list of the attacks somebody already
 * thought of.
 *
 * Every image is also cut down before it is kept. A 4MB photograph straight
 * off a phone is 4000 pixels wide and carries the camera's metadata, sometimes
 * including where it was taken. The site needs neither: it is rewritten at no
 * more than MAX_EDGE on its long side, re-encoded, and stored without the
 * metadata. The listing and the organisation logo go through the same door.
 */
final class Uploads {

	/**
	 * 20MB. The file is cut to MAX_EDGE and re-encoded before it is kept, so
	 * the ceiling only has to admit what a phone produces, and a 12MB photo
	 * straight off one is normal. It was 8MB, which refused exactly the
	 * uploads the shrink exists for.
	 */
	public const MAX_BYTES = 20971520;

	/** Listings want a usable header image, so hold a floor on width. */
	public const MIN_WIDTH = 1200;

	/**
	 * The most pixels an image keeps on its long side. A 1600px image fills a
	 * listing header on any screen the site is designed for and is under a
	 * fifth of the weight of the phone original.
	 */
	public const MAX_EDGE = 1600;

	/**
	 * Image types a member may submit.
	 *
	 * @return array<string, string> Extension pattern => MIME type.
	 */
	public static function allowed_mimes(): array {
		return [
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
		];
	}

	/**
	 * Whether a MIME type may be uploaded by a member.
	 */
	public static function is_allowed_mime( string $mime ): bool {
		return in_array( strtolower( trim( $mime ) ), array_values( self::allowed_mimes() ), true );
	}

	/**
	 * Handle any file uploads among a set of fields.
	 *
	 * @param int                  $post_id The listing or organisation the image belongs to.
	 * @param Field[]              $fields  Fields on the form.
	 * @param array<string, mixed> $files   $_FILES.
	 * @param array<string, mixed> $input   Raw POST, for the remove checkbox.
	 * @param array<string, mixed> $values  Validated values, updated by reference.
	 * @return array<string, string> Field key => error.
	 */
	public static function handle( int $post_id, array $fields, array $files, array $input, array &$values ): array {
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

			$attachment_id = self::store( $post_id, $file_key );

			if ( is_wp_error( $attachment_id ) ) {
				$errors[ $field->key ] = $attachment_id->get_error_message();
				continue;
			}

			$values[ $field->key ] = $attachment_id;
		}

		self::adopt_alt( $fields, $values );

		return $errors;
	}

	/**
	 * Fill a blank picture description from the attachment's own alt text.
	 *
	 * AltText.ai writes a description into WordPress's alt field when a
	 * picture is uploaded. A member who left "What the picture shows" empty
	 * gets that description put in front of them, to keep or to change,
	 * rather than an error. A description they typed is never overwritten.
	 *
	 * @param Field[]              $fields The step's fields.
	 * @param array<string, mixed> $values Values, changed in place.
	 */
	public static function adopt_alt( array $fields, array &$values ): void {
		foreach ( $fields as $field ) {
			if ( null === $field->suggested_from || Field::TEXT !== $field->type ) {
				continue;
			}

			$image = null;

			foreach ( $fields as $candidate ) {
				if ( $candidate->key === $field->suggested_from && Field::IMAGE === $candidate->type ) {
					$image = $candidate;
					break;
				}
			}

			if ( null === $image ) {
				continue;
			}

			$attachment_id = (int) ( $values[ $image->key ] ?? 0 );

			if ( $attachment_id <= 0 || '' !== trim( (string) ( $values[ $field->key ] ?? '' ) ) ) {
				continue;
			}

			$alt = trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );

			if ( '' === $alt ) {
				continue;
			}

			if ( null !== $field->max_length && mb_strlen( $alt ) > $field->max_length ) {
				$alt = rtrim( mb_substr( $alt, 0, $field->max_length - 1 ) ) . '…';
			}

			$values[ $field->key ] = $alt;
		}
	}

	/**
	 * Validate and store one uploaded image.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public static function store( int $post_id, string $file_key ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
		$file = $_FILES[ $file_key ] ?? null;

		if ( ! is_array( $file ) ) {
			return new WP_Error( 'dgl_no_file', __( 'No file arrived. Try again.', 'dgl-platform' ) );
		}

		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new WP_Error(
				'dgl_too_big',
				sprintf(
					/* translators: %s: maximum size, already formatted. */
					__( 'That image is too large. The limit is %s.', 'dgl-platform' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		/*
		 * Check the real type from the file's contents, not the name or the
		 * browser-supplied type. Both are attacker controlled.
		 */
		$check = wp_check_filetype_and_ext( $file['tmp_name'] ?? '', $file['name'] ?? '' );

		if ( empty( $check['type'] ) || ! self::is_allowed_mime( (string) $check['type'] ) ) {
			return new WP_Error(
				'dgl_bad_type',
				__( 'That file type is not allowed. Use a JPEG, PNG or WebP image.', 'dgl-platform' )
			);
		}

		/*
		 * The shrink runs on the moved file, before WordPress reads its
		 * dimensions and metadata into the attachment, and only for this
		 * upload: an administrator's media library is not our business.
		 */
		add_filter( 'wp_handle_upload', [ self::class, 'shrink' ] );
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'sizes' ] );

		$attachment_id = media_handle_upload(
			$file_key,
			$post_id,
			[],
			[
				'test_form' => false,
				'mimes'     => self::allowed_mimes(),
			]
		);

		remove_filter( 'wp_handle_upload', [ self::class, 'shrink' ] );
		remove_filter( 'intermediate_image_sizes_advanced', [ self::class, 'sizes' ] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Stamp the organisation so attachment access can be scoped like items.
		$org_id = PostTypes::ORG === get_post_type( $post_id ) ? $post_id : Org::for_item( $post_id );

		if ( $org_id > 0 ) {
			update_post_meta( $attachment_id, Meta::ITEM_ORG, $org_id );
		}

		return (int) $attachment_id;
	}

	/**
	 * The sizes WordPress makes from a member's image.
	 *
	 * The master is at most MAX_EDGE, so the 1536 and 2048 sizes are a copy
	 * of it at 96% and an upscale that never gets made: a third of the disk
	 * one upload took, for nothing a page ever asks for.
	 *
	 * @param array<string, array<string, mixed>> $sizes
	 * @return array<string, array<string, mixed>>
	 */
	public static function sizes( array $sizes ): array {
		foreach ( $sizes as $name => $size ) {
			// 1536 is under 1600 and still a copy of the master to the eye.
			if ( (int) ( $size['width'] ?? 0 ) >= self::MAX_EDGE * 0.9 ) {
				unset( $sizes[ $name ] );
			}
		}

		return $sizes;
	}

	/**
	 * Cut an uploaded image down and drop its metadata, in place.
	 *
	 * `wp_handle_upload` filter. The file has been moved into the uploads
	 * directory and nothing has read it yet, so rewriting it here means the
	 * attachment, its sizes and its recorded dimensions all describe the
	 * reduced file, and the original never exists on disk.
	 *
	 * A larger image is resized to MAX_EDGE on its long side. A smaller one is
	 * re-encoded at its own size, which is what removes the metadata: both
	 * image libraries WordPress supports write a fresh file from pixels, and
	 * the camera's EXIF block, the colour-managed thumbnail and the print
	 * resolution do not come with it. Orientation is applied before the
	 * rewrite, so a phone photograph taken sideways comes out the right way up
	 * rather than relying on the tag that has just been removed.
	 *
	 * On a failure the upload is kept as it arrived rather than lost: a
	 * listing with a heavy image is a worse outcome than a listing with none,
	 * but a member who has just filled in four steps and lost their picture is
	 * worse than either.
	 *
	 * @param array<string, mixed> $upload file, url, type.
	 * @return array<string, mixed>
	 */
	public static function shrink( array $upload ): array {
		$path = (string) ( $upload['file'] ?? '' );

		if ( '' === $path || ! self::is_allowed_mime( (string) ( $upload['type'] ?? '' ) ) ) {
			return $upload;
		}

		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		$size = $editor->get_size();
		$w    = (int) ( $size['width'] ?? 0 );
		$h    = (int) ( $size['height'] ?? 0 );

		if ( $w < 1 || $h < 1 ) {
			return $upload;
		}

		if ( $w > self::MAX_EDGE || $h > self::MAX_EDGE ) {
			$done = $editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
		} else {
			/*
			 * Same size in, same size out. A resize to identical dimensions is
			 * refused by the editor as pointless; a crop of the whole frame is
			 * not, and it takes the same metadata-stripping path.
			 */
			$done = $editor->crop( 0, 0, $w, $h, $w, $h );
		}

		if ( is_wp_error( $done ) ) {
			return $upload;
		}

		$saved = $editor->save( $path );

		if ( is_wp_error( $saved ) ) {
			return $upload;
		}

		// The editor may have chosen a different extension for the type; it
		// does not when saving over the same path, but the return says so.
		if ( ! empty( $saved['path'] ) && $saved['path'] !== $path ) {
			$upload['file'] = $saved['path'];
			$upload['url']  = str_replace( wp_basename( $path ), wp_basename( (string) $saved['path'] ), (string) $upload['url'] );
		}

		return $upload;
	}
}
