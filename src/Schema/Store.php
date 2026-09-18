<?php
/**
 * Writing field values to a post.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

use DGL\Content;

defined( 'ABSPATH' ) || exit;

/**
 * The one place field values are written.
 *
 * There are now two ways into a submission: the member's wizard on the front
 * end and the DGLP team's edit screen in wp-admin. Both write the same fields to
 * the same post, and if each had its own writer they would drift — a rule added
 * in one and forgotten in the other produces data that is correct depending on
 * who typed it, which is the worst kind of bug to find.
 *
 * Every rule that decides what "no answer" means lives here, and both callers
 * go through it.
 */
final class Store {

	/**
	 * Write validated values to a post and its meta.
	 *
	 * @param array<string, mixed> $values Validated values, keyed by field key.
	 * @param string[]             $skip   Keys to leave alone.
	 */
	public static function write( int $post_id, string $post_type, array $values, array $skip = [] ): void {
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
				// Filtered, not stripped: somebody pasting a formatted description
				// keeps their paragraphs, lists and links, and nothing else.
				$post_update['post_content'] = Content::clean( (string) $value );
				continue;
			}

			/*
			 * An optional field left blank has to leave no row behind. Casting
			 * '' to an int stores a real 0, which then reads back as an answer:
			 * a blank Capacity became "Capacity: 0", which is a claim nobody
			 * made.
			 */
			if ( in_array( $field->type, [ Field::CHOICES, Field::REPEAT ], true ) ? [] === (array) $value : ( Field::CHECKBOX !== $field->type && '' === (string) $value ) ) {
				delete_post_meta( $post_id, $field->meta_key() );
				continue;
			}

			/*
			 * The image control posts a hidden 0 when nothing is attached, and
			 * a stored 0 is not the same as no row. It reads back as an answer,
			 * so an item with no picture and an edit with no picture compared
			 * as different and a moderator was shown "Image: Not given"
			 * changing to "Image: Not given".
			 */
			if ( Field::IMAGE === $field->type && 0 === (int) $value ) {
				delete_post_meta( $post_id, $field->meta_key() );
				continue;
			}

			update_post_meta( $post_id, $field->meta_key(), self::sanitise( $field, $value ) );
		}

		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $post_id;
			wp_update_post( $post_update );
		}

		self::describe_image( $post_id, $post_type );
	}

	/**
	 * Put the member's description on the attachment itself, as WordPress's
	 * own alt text, so every render of the image carries it without the
	 * templates having to know. Runs after any write, so a new picture with
	 * an old description, or a new description for an old picture, both land.
	 */
	public static function describe_image( int $post_id, string $post_type ): void {
		$image = FieldRegistry::find( $post_type, 'image' );
		$alt   = FieldRegistry::find( $post_type, 'image_alt' );

		if ( null === $image || null === $alt ) {
			return;
		}

		$attachment_id = (int) get_post_meta( $post_id, $image->meta_key(), true );
		$text          = trim( (string) get_post_meta( $post_id, $alt->meta_key(), true ) );

		if ( $attachment_id > 0 && '' !== $text && 'attachment' === get_post_type( $attachment_id ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $text );
		}
	}

	/**
	 * Final sanitisation before storage.
	 *
	 * The validator has already normalised shape and format. This is the second
	 * layer: what actually goes in the database.
	 */
	/**
	 * The rule with only its known keys, each cast to its type.
	 *
	 * @param array<string, mixed> $value
	 * @return array<string, mixed>
	 */
	public static function sanitise_repeat( array $value ): array {
		$out = [];

		foreach ( [ 'freq', 'monthly', 'until' ] as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $value[ $key ] );
			}
		}

		foreach ( [ 'day', 'weekday', 'nth' ] as $key ) {
			if ( isset( $value[ $key ] ) ) {
				$out[ $key ] = (int) $value[ $key ];
			}
		}

		if ( isset( $value['weekdays'] ) ) {
			$out['weekdays'] = array_values( array_map( 'intval', (array) $value['weekdays'] ) );
		}

		if ( ! empty( $value['skip'] ) ) {
			$out['skip'] = array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) $value['skip'] ) ) );
		}

		return isset( $out['freq'] ) ? $out : [];
	}

	public static function sanitise( Field $field, mixed $value ): mixed {
		return match ( $field->type ) {
			Field::TEXTAREA => sanitize_textarea_field( (string) $value ),
			// Rich text keeps its markup, filtered to what the toolbar can make.
			// sanitize_textarea_field would strip the formatting the editor
			// exists to produce; wp_kses_post would keep a pasted document's.
			Field::RICHTEXT             => Content::clean( (string) $value ),
			Field::URL                  => esc_url_raw( (string) $value ),
			Field::EMAIL                => sanitize_email( (string) $value ),
			Field::CHECKBOX             => (bool) $value,
			Field::CHOICES              => array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) $value ) ) ),
			Field::REPEAT               => self::sanitise_repeat( (array) $value ),
			Field::NUMBER, Field::IMAGE => (int) $value,
			Field::MONEY                => (float) $value,
			default                     => sanitize_text_field( (string) $value ),
		};
	}
}
