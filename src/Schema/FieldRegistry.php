<?php
/**
 * The single source of truth for what each content type captures.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

use DGL\PostTypes;
use DGL\Schema\Types\Event;
use DGL\Schema\Types\Grant;
use DGL\Schema\Types\News;
use DGL\Schema\Types\Training;
use DGL\Schema\Types\Volunteering;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the full field set for a content type.
 *
 * Steps 1 and 3 of the wizard never change: every submission needs a headline,
 * a summary, a story and a way to get in touch. Step 2 is where the five types
 * diverge, which is why the wireframe labels it "fields specific to events".
 *
 * Nothing else in the plugin should hardcode a field list.
 */
final class FieldRegistry {

	public const STEP_BASICS  = 1;
	public const STEP_DETAILS = 2;
	public const STEP_CONTACT = 3;
	public const STEP_REVIEW  = 4;

	/**
	 * Wizard steps and their labels, as the progress rail in wireframe 1f.
	 *
	 * @return array<int, string>
	 */
	public static function steps(): array {
		return [
			self::STEP_BASICS  => __( 'Basics', 'dgl-platform' ),
			self::STEP_DETAILS => __( 'Details', 'dgl-platform' ),
			self::STEP_CONTACT => __( 'Contact and links', 'dgl-platform' ),
			self::STEP_REVIEW  => __( 'Review and submit', 'dgl-platform' ),
		];
	}

	/**
	 * Which type definition backs each post type.
	 *
	 * @return array<string, class-string<TypeDefinition>>
	 */
	public static function definitions(): array {
		return [
			PostTypes::NEWS         => News::class,
			PostTypes::EVENT        => Event::class,
			PostTypes::TRAINING     => Training::class,
			PostTypes::GRANT        => Grant::class,
			PostTypes::VOLUNTEERING => Volunteering::class,
		];
	}

	/**
	 * Step 1. Shared by every type.
	 *
	 * The title is the post title rather than meta, but it belongs in the field
	 * set so the wizard, the validator and the review screen treat it like
	 * everything else.
	 *
	 * @return Field[]
	 */
	public static function basics(): array {
		return [
			new Field(
				key: 'title',
				label: __( 'Headline', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				step: self::STEP_BASICS,
				help: __( 'Say what it is, plainly. This is what people see in a listing.', 'dgl-platform' ),
				max_length: 120,
				in_digest: true,
			),
			new Field(
				key: 'summary',
				label: __( 'Summary', 'dgl-platform' ),
				type: Field::TEXTAREA,
				required: true,
				step: self::STEP_BASICS,
				help: __( 'One or two sentences. This is what goes in listings and emails.', 'dgl-platform' ),
				max_length: 300,
				in_digest: true,
			),
			new Field(
				key: 'body',
				label: __( 'Full description', 'dgl-platform' ),
				type: Field::RICHTEXT,
				required: true,
				step: self::STEP_BASICS,
			),
			new Field(
				key: 'image',
				label: __( 'Image', 'dgl-platform' ),
				type: Field::IMAGE,
				step: self::STEP_BASICS,
				help: __( 'At least 1200 pixels wide, JPG or PNG, up to 20MB. A placeholder is used if you do not add one.', 'dgl-platform' ),
				in_csv: false,
			),
			/*
			 * The picture in words, for people who cannot see it. Not a
			 * caption: a screen reader reads it in place of the image, so it
			 * says what the picture shows. Required whenever there is one.
			 */
			new Field(
				key: 'image_alt',
				label: __( 'What the picture shows', 'dgl-platform' ),
				type: Field::TEXT,
				step: self::STEP_BASICS,
				help: __( 'One plain sentence, read aloud to people who cannot see the picture. For example "Volunteers planting a tree in Armley Park". We suggest one from the picture where we can; change it if it does not say what the picture shows.', 'dgl-platform' ),
				max_length: 150,
				public: false,
				in_csv: false,
				required_with: 'image',
			),
		];
	}

	/**
	 * Step 3. Shared by every type.
	 *
	 * @return Field[]
	 */
	public static function contact(): array {
		return [
			new Field(
				key: 'contact_name',
				label: __( 'Contact name', 'dgl-platform' ),
				type: Field::TEXT,
				step: self::STEP_CONTACT,
				max_length: 120,
			),
			new Field(
				key: 'contact_email',
				label: __( 'Contact email', 'dgl-platform' ),
				type: Field::EMAIL,
				required: true,
				step: self::STEP_CONTACT,
				help: __( 'Shown publicly, so use an inbox somebody watches.', 'dgl-platform' ),
			),
			new Field(
				key: 'contact_phone',
				label: __( 'Contact phone', 'dgl-platform' ),
				type: Field::TEL,
				step: self::STEP_CONTACT,
				max_length: 40,
			),
			new Field(
				key: 'website',
				label: __( 'Website', 'dgl-platform' ),
				type: Field::URL,
				step: self::STEP_CONTACT,
			),
		];
	}

	/**
	 * Every field for a content type, in wizard order.
	 *
	 * @return Field[]
	 */
	public static function for_type( string $post_type ): array {
		$definition = self::definitions()[ $post_type ] ?? null;

		if ( null === $definition ) {
			return [];
		}

		return array_merge( self::basics(), $definition::fields(), self::contact() );
	}

	/**
	 * The fields on one wizard step.
	 *
	 * @return Field[]
	 */
	public static function for_step( string $post_type, int $step ): array {
		return array_values(
			array_filter(
				self::for_type( $post_type ),
				static fn( Field $field ): bool => $field->step === $step
			)
		);
	}

	/**
	 * One field by key, or null if the type does not have it.
	 */
	public static function find( string $post_type, string $key ): ?Field {
		foreach ( self::for_type( $post_type ) as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Fields shown on the public page for a type.
	 *
	 * The `public` flag on {@see Field} has existed since the registry was
	 * written and nothing read it, because until now nothing rendered a public
	 * page. It defaults to true, so a field is published unless somebody says
	 * otherwise, which is the right default for content a member wrote in order
	 * to have it published.
	 *
	 * `capacity` is the exception. A member records it so DGLP know the scale
	 * of the thing; printing "Capacity: 12" on a public listing turns a planning
	 * note into a scarcity claim the organiser never made.
	 *
	 * @return Field[]
	 */
	public static function public_fields( string $post_type ): array {
		return array_values(
			array_filter(
				self::for_type( $post_type ),
				static fn( Field $field ): bool => $field->public
			)
		);
	}

	/**
	 * Fields that may appear in a digest summary line.
	 *
	 * @return Field[]
	 */
	public static function digest_fields( string $post_type ): array {
		return array_values(
			array_filter(
				self::for_type( $post_type ),
				static fn( Field $field ): bool => $field->in_digest
			)
		);
	}

	/**
	 * CSV export columns for a type.
	 *
	 * @return Field[]
	 */
	public static function csv_fields( string $post_type ): array {
		return array_values(
			array_filter(
				self::for_type( $post_type ),
				static fn( Field $field ): bool => $field->in_csv
			)
		);
	}

	/**
	 * When an item should come off the listings, given its submitted values.
	 *
	 * Returns a `Y-m-d H:i:s` string, or null when the type never expires or the
	 * relevant date was not supplied. A date-only value expires at the end of
	 * that day, because a deadline of the 30th means the 30th is still open.
	 *
	 * @param array<string, mixed> $values Field key => value.
	 */
	public static function expiry_for( string $post_type, array $values ): ?string {
		$definition = self::definitions()[ $post_type ] ?? null;

		if ( null === $definition ) {
			return null;
		}

		/*
		 * A repeating event is listed until the end of the last day it runs,
		 * not until its first session ends. The rule carries that date.
		 */
		foreach ( self::for_type( $post_type ) as $field ) {
			if ( Field::REPEAT !== $field->type ) {
				continue;
			}

			$repeat = $values[ $field->key ] ?? null;
			$until  = is_array( $repeat ) ? trim( (string) ( $repeat['until'] ?? '' ) ) : '';

			if ( '' !== $until ) {
				return $until . ' 23:59:59';
			}
		}

		foreach ( [ $definition::expiry_field(), $definition::expiry_fallback() ] as $key ) {
			if ( null === $key ) {
				continue;
			}

			$raw = isset( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';

			if ( '' === $raw ) {
				continue;
			}

			$field = self::find( $post_type, $key );

			if ( null !== $field && Field::DATE === $field->type ) {
				return $raw . ' 23:59:59';
			}

			return $raw;
		}

		return null;
	}

	/**
	 * Register every field as post meta. Hooked on `init`.
	 */
	public static function register_meta(): void {
		foreach ( array_keys( self::definitions() ) as $post_type ) {
			foreach ( self::for_type( $post_type ) as $field ) {
				if ( 'title' === $field->key || 'body' === $field->key ) {
					continue; // Core post columns, not meta.
				}

				register_post_meta( $post_type, $field->meta_key(), $field->meta_args() );
			}
		}
	}
}
