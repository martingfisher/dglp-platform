<?php
/**
 * Turning a field definition into a form control.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Schema\Field;

defined( 'ABSPATH' ) || exit;

/**
 * One control per field type, generated from the schema.
 *
 * Nothing here knows which content type it is rendering. Add a field to a type
 * definition and it appears in the wizard, validates, saves and shows on the
 * review step without another line of code.
 *
 * Controls are plain HTML with real labels and real `required` attributes.
 * Browser validation is a convenience on top of the server-side check in
 * {@see \DGL\Schema\Validator}, never a substitute for it.
 */
final class FieldRenderer {

	/** Name prefix, so a field can never collide with a WordPress POST key. */
	public const INPUT_NAME = 'dgl';

	/**
	 * Render one field, label, control, help text and error.
	 *
	 * @param Field  $field The definition.
	 * @param mixed  $value Current value.
	 * @param string $error Validation message, if the last attempt failed.
	 */
	public static function render( Field $field, mixed $value = '', string $error = '' ): string {
		$id       = 'dgl-' . $field->key;
		$name     = self::INPUT_NAME . '[' . $field->key . ']';
		$has_err  = '' !== $error;
		$describe = [];

		if ( '' !== $field->help ) {
			$describe[] = $id . '-help';
		}

		if ( $has_err ) {
			$describe[] = $id . '-error';
		}

		$aria = $describe ? ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"' : '';

		$row_attrs = '';

		foreach ( $field->depends_attrs() as $attr => $attr_value ) {
			$row_attrs .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $attr_value ) );
		}

		/*
		 * TinyMCE will not accept a hyphen in its editor id, so a rich text
		 * control cannot use the shared dgl-{key} id. The row carries it
		 * instead, which keeps the error summary's jump links working for every
		 * field type.
		 */
		if ( Field::RICHTEXT === $field->type ) {
			$row_attrs .= sprintf( ' id="%s"', esc_attr( $id ) );
			$id         = 'dgl' . preg_replace( '/[^a-z0-9]/', '', strtolower( $field->key ) );
		}

		$out = '<div class="dgl-field-row' . ( $has_err ? ' dgl-field-row--error' : '' ) . '"' . $row_attrs . '>';

		if ( Field::CHECKBOX === $field->type ) {
			$out .= self::checkbox( $field, $id, $name, $value, $aria );
		} else {
			$out .= sprintf(
				'<label class="dgl-label" for="%s">%s%s</label>',
				esc_attr( $id ),
				esc_html( $field->label ),
				$field->required ? ' <span class="dgl-req" aria-hidden="true">*</span>' : ''
			);
			$out .= self::control( $field, $id, $name, $value, $aria );
		}

		if ( '' !== $field->help ) {
			$out .= sprintf(
				'<p class="dgl-help" id="%s-help">%s</p>',
				esc_attr( $id ),
				esc_html( $field->help )
			);
		}

		if ( $has_err ) {
			$out .= sprintf(
				'<p class="dgl-error" id="%s-error">%s</p>',
				esc_attr( $id ),
				esc_html( $error )
			);
		}

		return $out . '</div>';
	}

	/**
	 * The control itself.
	 */
	private static function control( Field $field, string $id, string $name, mixed $value, string $aria ): string {
		$required = $field->required ? ' required' : '';
		$maxlen   = null !== $field->max_length ? ' maxlength="' . (int) $field->max_length . '"' : '';
		$common   = sprintf( ' id="%s" name="%s"', esc_attr( $id ), esc_attr( $name ) );

		return match ( $field->type ) {
			Field::RICHTEXT => self::rich_text( $id, $name, $value ),
			Field::TEXTAREA => sprintf(
				'<textarea class="dgl-field dgl-field--area" rows="4"%s%s%s%s>%s</textarea>',
				$common,
				$required,
				$maxlen,
				$aria,
				esc_textarea( is_scalar( $value ) ? (string) $value : '' )
			),
			Field::SELECT   => self::select( $field, $id, $name, $value, $aria ),
			Field::IMAGE    => self::image( $field, $id, $name, $value, $aria ),
			default         => sprintf(
				'<input class="dgl-field" type="%s"%s value="%s"%s%s%s%s>',
				esc_attr( self::input_type( $field->type ) ),
				$common,
				esc_attr( is_scalar( $value ) ? (string) $value : '' ),
				$required,
				$maxlen,
				self::step_attr( $field->type ),
				$aria
			),
		};
	}

	/**
	 * WordPress's own editor, cut down to what a member actually needs.
	 *
	 * Deliberately minimal: bold, italic, lists, links, undo. No media button,
	 * no heading dropdown, no kitchen sink. Members are volunteers and small
	 * charity staff, and every extra button is another thing that can produce a
	 * listing nobody meant to publish.
	 *
	 * Falls back to a plain textarea when the editor cannot load, so the form
	 * still works rather than showing an empty box.
	 */
	private static function rich_text( string $id, string $name, mixed $value ): string {
		$content = is_scalar( $value ) ? (string) $value : '';

		if ( ! function_exists( 'wp_editor' ) ) {
			return sprintf(
				'<textarea class="dgl-field dgl-field--area" rows="10" id="%s" name="%s">%s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_textarea( $content )
			);
		}

		ob_start();

		wp_editor(
			$content,
			$id,
			[
				'textarea_name' => $name,
				'textarea_rows' => 12,
				'media_buttons' => false,
				'teeny'         => true,
				'quicktags'     => [ 'buttons' => 'strong,em,ul,ol,li,link' ],
				'tinymce'       => [
					'toolbar1'     => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
					'toolbar2'     => '',
					'statusbar'    => false,
					'branding'     => false,
					'paste_as_text' => true,
				],
			]
		);

		return '<div class="dgl-editor">' . (string) ob_get_clean() . '</div>';
	}

	/**
	 * The HTML input type for a field type.
	 */
	private static function input_type( string $type ): string {
		return match ( $type ) {
			Field::DATE     => 'date',
			Field::DATETIME => 'datetime-local',
			Field::EMAIL    => 'email',
			Field::URL      => 'url',
			Field::TEL      => 'tel',
			Field::NUMBER,
			Field::MONEY    => 'number',
			default         => 'text',
		};
	}

	/**
	 * Money wants pennies, a count does not.
	 */
	private static function step_attr( string $type ): string {
		return match ( $type ) {
			Field::MONEY  => ' step="0.01" min="0"',
			Field::NUMBER => ' step="1" min="0"',
			default       => '',
		};
	}

	private static function select( Field $field, string $id, string $name, mixed $value, string $aria ): string {
		$out = sprintf(
			'<select class="dgl-field" id="%s" name="%s"%s%s>',
			esc_attr( $id ),
			esc_attr( $name ),
			$field->required ? ' required' : '',
			$aria
		);

		$out .= '<option value="">' . esc_html__( 'Choose one', 'dgl-platform' ) . '</option>';

		foreach ( $field->options as $option_value => $label ) {
			$out .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option_value ),
				selected( (string) $value, (string) $option_value, false ),
				esc_html( $label )
			);
		}

		return $out . '</select>';
	}

	private static function checkbox( Field $field, string $id, string $name, mixed $value, string $aria ): string {
		return sprintf(
			'<label class="dgl-check" for="%s"><input type="checkbox" id="%s" name="%s" value="1"%s%s> <span>%s</span></label>',
			esc_attr( $id ),
			esc_attr( $id ),
			esc_attr( $name ),
			checked( (bool) $value, true, false ),
			$aria,
			esc_html( $field->label )
		);
	}

	/**
	 * Image upload, with the current attachment shown if there is one.
	 *
	 * The hidden field carries the existing attachment ID so that saving a step
	 * without touching the file input does not silently drop the image.
	 */
	private static function image( Field $field, string $id, string $name, mixed $value, string $aria ): string {
		$attachment_id = is_numeric( $value ) ? (int) $value : 0;
		$out           = '';

		if ( $attachment_id > 0 ) {
			$thumb = wp_get_attachment_image( $attachment_id, 'medium', false, [ 'class' => 'dgl-image-preview' ] );

			if ( '' !== $thumb ) {
				$out .= '<div class="dgl-image-current">' . $thumb
					. '<label class="dgl-check"><input type="checkbox" name="' . esc_attr( self::INPUT_NAME . '_remove_' . $field->key ) . '" value="1"> <span>'
					. esc_html__( 'Remove this image', 'dgl-platform' ) . '</span></label></div>';
			}
		}

		$out .= sprintf(
			'<input class="dgl-field dgl-field--file" type="file" id="%s" name="%s" accept="%s"%s>',
			esc_attr( $id ),
			esc_attr( self::INPUT_NAME . '_file_' . $field->key ),
			esc_attr( implode( ',', array_values( \DGL\Uploads::allowed_mimes() ) ) ),
			$aria
		);

		$out .= sprintf(
			'<input type="hidden" name="%s" value="%d">',
			esc_attr( $name ),
			$attachment_id
		);

		return $out;
	}
}
