<?php
/**
 * The schema fields on the wp-admin edit screen.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Schema\Store;
use DGL\Schema\Validator;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * What DGLP's own team see when they open a submission.
 *
 * Without this, wp-admin shows a title, an editor and nothing else: no venue,
 * no dates, no cost, no contact. The data is all there in post meta, but the
 * screen does not know the fields exist, so a member's event opens looking
 * like an empty blog post. That is the state this fixes.
 *
 * Generated from the same field registry the member's wizard renders from, so
 * a field added to a content type appears on both screens without anybody
 * remembering to add it twice. That is the whole argument for the registry.
 *
 * Saving goes through {@see Validator} and {@see Store}, the same two the
 * wizard uses, so a value typed by DGLP and the same value typed by a member
 * are stored identically.
 */
final class MetaBoxes {

	private const NONCE = 'dgl_admin_fields';

	public static function init(): void {
		add_action( 'add_meta_boxes', [ self::class, 'register' ] );
		add_action( 'save_post', [ self::class, 'save' ], 10, 2 );
	}

	public static function register(): void {
		foreach ( PostTypes::submittable() as $post_type ) {
			$def = PostTypes::definitions()[ $post_type ] ?? null;

			if ( null === $def ) {
				continue;
			}

			add_meta_box(
				'dgl-fields-' . $post_type,
				sprintf(
					/* translators: %s: content type name, for example "Event". */
					__( '%s details', 'dgl-platform' ),
					$def['singular']
				),
				[ self::class, 'render' ],
				$post_type,
				'normal',
				'high'
			);

			add_meta_box(
				'dgl-submitter-' . $post_type,
				__( 'Who submitted this', 'dgl-platform' ),
				[ self::class, 'render_submitter' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Every field except the two the editor already owns.
	 */
	public static function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$steps = [];

		foreach ( FieldRegistry::for_type( (string) $post->post_type ) as $field ) {
			// `title` is the post title box and `body` is the editor. Rendering
			// them again would give the screen two of each.
			if ( in_array( $field->key, [ 'title', 'body' ], true ) ) {
				continue;
			}

			$steps[ $field->step ][] = $field;
		}

		$labels = FieldRegistry::steps();

		echo '<div class="dgl-admin-fields">';

		foreach ( $steps as $step => $fields ) {
			echo '<h2 class="dgl-admin-fields__heading">' . esc_html( $labels[ $step ] ?? '' ) . '</h2>';
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $fields as $field ) {
				self::row( $field, $post );
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * One field as a wp-admin form-table row.
	 */
	private static function row( Field $field, WP_Post $post ): void {
		$id    = 'dgl-' . $field->key;
		$name  = 'dgl[' . $field->key . ']';
		$value = metadata_exists( 'post', (int) $post->ID, $field->meta_key() )
			? get_post_meta( (int) $post->ID, $field->meta_key(), true )
			: '';

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field->label ) . '</label>';

		if ( $field->required ) {
			echo ' <span class="dgl-admin-required" aria-hidden="true">*</span>';
		}

		echo '</th><td>';

		echo self::control( $field, $id, $name, $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.

		if ( '' !== $field->help ) {
			echo '<p class="description">' . esc_html( $field->help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * The control for one field.
	 *
	 * Deliberately plain wp-admin markup rather than the dashboard's own
	 * controls. DGLP's team are in WordPress here and everything should behave
	 * the way the rest of WordPress does.
	 */
	private static function control( Field $field, string $id, string $name, mixed $value ): string {
		$common = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';

		switch ( $field->type ) {
			case Field::TEXTAREA:
			case Field::RICHTEXT:
				return '<textarea' . $common . ' rows="4" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';

			case Field::CHECKBOX:
				return '<label><input type="checkbox"' . $common . ' value="1" ' . checked( (bool) $value, true, false ) . ' /> '
					. esc_html__( 'Yes', 'dgl-platform' ) . '</label>';

			case Field::SELECT:
				$out = '<select' . $common . '><option value="">' . esc_html__( '— none —', 'dgl-platform' ) . '</option>';

				foreach ( $field->options as $key => $label ) {
					$out .= '<option value="' . esc_attr( (string) $key ) . '" ' . selected( (string) $value, (string) $key, false ) . '>'
						. esc_html( (string) $label ) . '</option>';
				}

				return $out . '</select>';

			case Field::IMAGE:
				$id_value = is_numeric( $value ) ? (int) $value : 0;
				$preview  = $id_value > 0 ? wp_get_attachment_image( $id_value, 'thumbnail' ) : '';

				/*
				 * A plain attachment ID, not a media picker. The picker is a
				 * chunk of JavaScript to maintain for a field DGLP will rarely
				 * change, and an ID with the image shown next to it is honest
				 * about what is stored.
				 */
				return $preview
					. '<p><input type="number"' . $common . ' value="' . esc_attr( (string) $id_value ) . '" class="small-text" min="0" /> '
					. '<span class="description">' . esc_html__( 'Attachment ID. 0 for none.', 'dgl-platform' ) . '</span></p>';

			default:
				$types = [
					Field::DATE     => 'date',
					Field::DATETIME => 'datetime-local',
					Field::URL      => 'url',
					Field::EMAIL    => 'email',
					Field::TEL      => 'tel',
					Field::NUMBER   => 'number',
					Field::MONEY    => 'number',
				];

				$input = $types[ $field->type ] ?? 'text';
				$extra = Field::MONEY === $field->type ? ' step="0.01"' : '';

				// datetime-local will not accept the space MySQL stores.
				$shown = Field::DATETIME === $field->type
					? str_replace( ' ', 'T', substr( (string) $value, 0, 16 ) )
					: (string) $value;

				return '<input type="' . esc_attr( $input ) . '"' . $common . $extra
					. ' value="' . esc_attr( $shown ) . '" class="regular-text" />';
		}
	}

	/**
	 * Who sent it and which organisation it belongs to.
	 *
	 * The author column says which WordPress account typed it. What a reviewer
	 * actually needs to know is which organisation it goes out under, and that
	 * is meta, not the author.
	 */
	public static function render_submitter( WP_Post $post ): void {
		$org_id = Org::for_item( (int) $post->ID );
		$author = get_userdata( (int) $post->post_author );

		echo '<p><strong>' . esc_html__( 'Organisation', 'dgl-platform' ) . '</strong><br />';

		if ( $org_id > 0 ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $org_id ) ) . '">' . esc_html( get_the_title( $org_id ) ) . '</a>';
		} else {
			echo esc_html__( 'None. This item is not linked to an organisation.', 'dgl-platform' );
		}

		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Submitted by', 'dgl-platform' ) . '</strong><br />'
			. esc_html( $author ? (string) $author->display_name : __( 'Unknown', 'dgl-platform' ) ) . '</p>';
	}

	/**
	 * Save the fields on the edit screen.
	 *
	 * @param int     $post_id The post being saved.
	 * @param WP_Post $post    The post object.
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! PostTypes::is_submittable( (string) $post->post_type ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the validator sanitises every declared field.
		$input = isset( $_POST['dgl'] ) ? (array) wp_unslash( $_POST['dgl'] ) : [];

		if ( [] === $input ) {
			return;
		}

		$fields = array_values(
			array_filter(
				FieldRegistry::for_type( (string) $post->post_type ),
				static fn( Field $f ): bool => ! in_array( $f->key, [ 'title', 'body' ], true )
			)
		);

		$result = Validator::validate( $fields, $input );

		/*
		 * A validation error is not allowed to silently drop a field. wp-admin
		 * has no natural place to show one during save_post, so the valid
		 * values are written and the rest are held for a notice on the next
		 * screen load.
		 */
		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'dgl_admin_errors_' . $post_id, $result['errors'], 60 );
		} else {
			delete_transient( 'dgl_admin_errors_' . $post_id );
		}

		Store::write( $post_id, (string) $post->post_type, $result['values'] );
	}

	/**
	 * Show anything the last save refused.
	 */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'post' !== $screen->base || ! PostTypes::is_submittable( (string) $screen->post_type ) ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$errors  = $post_id > 0 ? get_transient( 'dgl_admin_errors_' . $post_id ) : false;

		if ( ! is_array( $errors ) || [] === $errors ) {
			return;
		}

		delete_transient( 'dgl_admin_errors_' . $post_id );

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Some fields were not saved:', 'dgl-platform' )
			. '</strong></p><ul style="list-style:disc;margin-left:20px">';

		foreach ( $errors as $message ) {
			echo '<li>' . esc_html( (string) $message ) . '</li>';
		}

		echo '</ul></div>';
	}
}
