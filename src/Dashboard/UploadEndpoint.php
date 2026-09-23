<?php
/**
 * A picture goes up the moment it is chosen.
 *
 * The wizard used to send the file with the step, so nothing could describe
 * it until the member had pressed Save, and the description box sat empty
 * with a warning. Now the browser posts the file here as soon as it is
 * picked, the attachment is made (AltText.ai describes it on the way in),
 * and the reply carries the id, a preview and the description, which lands
 * in the box while the member is still on the step. The ordinary save path
 * is unchanged and still works without JavaScript.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Uploads;
use DGL\Workflow\Revisions;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class UploadEndpoint {

	public const ACTION     = 'dgl_upload_image';
	public const ALT_ACTION = 'dgl_image_alt';

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'upload' ] );
		add_action( 'wp_ajax_' . self::ALT_ACTION, [ self::class, 'alt' ] );
	}

	/**
	 * Whether this user may put a picture on this item, and which field it is.
	 *
	 * @return Field|WP_Error
	 */
	public static function may_upload( int $user_id, int $post_id, string $key ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! PostTypes::is_reviewable( (string) $post->post_type ) ) {
			return new WP_Error( 'dgl_not_item', __( 'Not an item.', 'dgl-platform' ), [ 'status' => 404 ] );
		}

		if ( $user_id <= 0 || ! Access::can( $user_id, Policy::EDIT_ITEM, $post_id ) ) {
			return new WP_Error( 'dgl_forbidden', __( 'You cannot change this item.', 'dgl-platform' ), [ 'status' => 403 ] );
		}

		$type  = PostTypes::REVISION === $post->post_type ? Revisions::type_of( $post_id ) : (string) $post->post_type;
		$field = FieldRegistry::find( $type, $key );

		if ( null === $field || Field::IMAGE !== $field->type ) {
			return new WP_Error( 'dgl_not_image', __( 'Not a picture field.', 'dgl-platform' ), [ 'status' => 400 ] );
		}

		return $field;
	}

	/** What the browser needs back about an attachment. */
	public static function describe( int $attachment_id ): array {
		return [
			'id'      => $attachment_id,
			'alt'     => trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ),
			'preview' => (string) wp_get_attachment_image( $attachment_id, 'medium', false, [ 'class' => 'dgl-image-preview', 'alt' => '' ] ),
		];
	}

	public static function upload(): void {
		if ( ! check_ajax_referer( Wizard::NONCE, '_wpnonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'The page has expired. Reload it and try again.', 'dgl-platform' ) ], 403 );
		}

		$post_id = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$key     = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$field   = self::may_upload( get_current_user_id(), $post_id, $key );

		if ( is_wp_error( $field ) ) {
			wp_send_json_error( [ 'message' => $field->get_error_message() ], (int) ( $field->get_error_data()['status'] ?? 400 ) );
		}

		$attachment_id = Uploads::store( $post_id, FieldRenderer::INPUT_NAME . '_file_' . $field->key );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ], 400 );
		}

		// Kept now, so leaving the step does not lose the picture.
		update_post_meta( $post_id, $field->meta_key(), (int) $attachment_id );

		wp_send_json_success( self::describe( (int) $attachment_id ) );
	}

	/** The description of a picture, for a browser waiting on AltText.ai. */
	public static function alt(): void {
		if ( ! check_ajax_referer( Wizard::NONCE, '_wpnonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'The page has expired.', 'dgl-platform' ) ], 403 );
		}

		$post_id       = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$attachment_id = isset( $_POST['attachment'] ) ? (int) $_POST['attachment'] : 0;
		$key           = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$field         = self::may_upload( get_current_user_id(), $post_id, $key );

		if ( is_wp_error( $field ) ) {
			wp_send_json_error( [ 'message' => $field->get_error_message() ], (int) ( $field->get_error_data()['status'] ?? 400 ) );
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) || (int) get_post_meta( $post_id, $field->meta_key(), true ) !== $attachment_id ) {
			wp_send_json_error( [ 'message' => __( 'Not this item\'s picture.', 'dgl-platform' ) ], 400 );
		}

		wp_send_json_success( self::describe( $attachment_id ) );
	}
}
