<?php
/**
 * Server-side validation of a submitted step.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

use DateTimeImmutable;

/**
 * Checks raw input against a field set and normalises what survives.
 *
 * Pure: no WordPress, no database, no superglobals. That is what makes it
 * testable, and it keeps the rules in one readable place rather than spread
 * across controllers.
 *
 * This is structural validation and normalisation. Output escaping and
 * `wp_kses` on rich text happen in the storage layer, because they are a
 * different concern with a different failure mode. Both are needed; neither
 * substitutes for the other.
 */
final class Validator {

	/**
	 * UK postcode, loose enough for real ones and tight enough to catch typing
	 * an address into the postcode box.
	 */
	private const POSTCODE_PATTERN = '/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i';

	/**
	 * Validate input against a set of fields.
	 *
	 * @param Field[]              $fields The fields being submitted.
	 * @param array<string, mixed> $input  Raw values, keyed by field key.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function validate( array $fields, array $input ): array {
		$values = [];
		$errors = [];

		foreach ( $fields as $field ) {
			$raw = $input[ $field->key ] ?? null;

			[ $value, $error ] = self::check( $field, $raw );

			if ( null !== $error ) {
				$errors[ $field->key ] = $error;
				continue;
			}

			$values[ $field->key ] = $value;
		}

		return [
			'values' => $values,
			'errors' => $errors,
		];
	}

	/**
	 * Check one field.
	 *
	 * @return array{0: mixed, 1: string|null} Normalised value, then error or null.
	 */
	private static function check( Field $field, mixed $raw ): array {
		if ( Field::CHECKBOX === $field->type ) {
			return [ self::truthy( $raw ), null ];
		}

		if ( Field::CHOICES === $field->type ) {
			return self::check_choices( $field, $raw );
		}

		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		if ( '' === $value ) {
			if ( $field->required ) {
				/* translators: %s: field label. */
				return [ null, sprintf( __( '%s is needed.', 'dgl-platform' ), $field->label ) ];
			}

			return [ '', null ];
		}

		if ( null !== $field->max_length && mb_strlen( $value ) > $field->max_length ) {
			return [
				null,
				sprintf(
					/* translators: 1: field label, 2: character limit, 3: current length. */
					__( '%1$s has to be %2$d characters or fewer. It is currently %3$d.', 'dgl-platform' ),
					$field->label,
					$field->max_length,
					mb_strlen( $value )
				),
			];
		}

		return match ( $field->type ) {
			Field::EMAIL    => self::check_email( $field, $value ),
			Field::URL      => self::check_url( $field, $value ),
			Field::DATE     => self::check_date( $field, $value, 'Y-m-d' ),
			Field::DATETIME => self::check_datetime( $field, $value ),
			Field::POSTCODE => self::check_postcode( $field, $value ),
			Field::NUMBER   => self::check_number( $field, $value ),
			Field::MONEY    => self::check_money( $field, $value ),
			Field::SELECT   => self::check_select( $field, $value ),
			Field::IMAGE    => self::check_image( $field, $value ),
			default         => [ $value, null ],
		};
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_email( Field $field, string $value ): array {
		if ( false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s does not look like an email address.', 'dgl-platform' ), $field->label ) ];
		}

		return [ strtolower( $value ), null ];
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_url( Field $field, string $value ): array {
		$scheme = parse_url( $value, PHP_URL_SCHEME );

		/*
		 * Only supply a scheme when there genuinely is not one. Prepending
		 * blindly turns "javascript:alert(1)" into "https://javascript:alert(1)",
		 * which passes a naive scheme check and stores the original payload. A
		 * bare domain is what members actually type, so that case is still met
		 * halfway.
		 */
		if ( ! is_string( $scheme ) || '' === $scheme ) {
			$value  = 'https://' . ltrim( $value, '/' );
			$scheme = 'https';
		}

		if ( ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s has to be a http or https web address.', 'dgl-platform' ), $field->label ) ];
		}

		if ( false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s does not look like a web address.', 'dgl-platform' ), $field->label ) ];
		}

		return [ $value, null ];
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_date( Field $field, string $value, string $format ): array {
		$date = DateTimeImmutable::createFromFormat( '!' . $format, $value );

		if ( false === $date || $date->format( $format ) !== $value ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s needs to be a real date.', 'dgl-platform' ), $field->label ) ];
		}

		return [ $date->format( 'Y-m-d' ), null ];
	}

	/**
	 * Accepts both the HTML datetime-local format and a plain space separator.
	 *
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_datetime( Field $field, string $value ): array {
		$normalised = str_replace( 'T', ' ', $value );

		foreach ( [ 'Y-m-d H:i:s', 'Y-m-d H:i' ] as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $normalised );

			if ( false !== $date && $date->format( $format ) === $normalised ) {
				return [ $date->format( 'Y-m-d H:i:s' ), null ];
			}
		}

		/* translators: %s: field label. */
		return [ null, sprintf( __( '%s needs to be a real date and time.', 'dgl-platform' ), $field->label ) ];
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_postcode( Field $field, string $value ): array {
		$compact = strtoupper( preg_replace( '/\s+/', '', $value ) ?? '' );

		if ( ! preg_match( self::POSTCODE_PATTERN, $compact ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s does not look like a UK postcode.', 'dgl-platform' ), $field->label ) ];
		}

		// Store in the conventional spaced form: outward code, space, inward code.
		return [ substr( $compact, 0, -3 ) . ' ' . substr( $compact, -3 ), null ];
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_number( Field $field, string $value ): array {
		if ( ! preg_match( '/^\d+$/', $value ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s needs to be a whole number.', 'dgl-platform' ), $field->label ) ];
		}

		return [ (int) $value, null ];
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_money( Field $field, string $value ): array {
		$cleaned = preg_replace( '/[£,\s]/', '', $value ) ?? '';

		if ( ! is_numeric( $cleaned ) || (float) $cleaned < 0 ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s needs to be an amount in pounds.', 'dgl-platform' ), $field->label ) ];
		}

		return [ round( (float) $cleaned, 2 ), null ];
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	/**
	 * Several of a fixed list. Unknown values are dropped rather than
	 * refused: a stale option in a saved profile should not stop the member
	 * saving the rest. Order follows the option list, not the post.
	 *
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_choices( Field $field, mixed $raw ): array {
		$given = array_map( static fn( $v ): string => is_scalar( $v ) ? trim( (string) $v ) : '', is_array( $raw ) ? $raw : [ $raw ] );
		$kept  = [];

		foreach ( array_keys( $field->options ) as $option ) {
			if ( in_array( (string) $option, $given, true ) ) {
				$kept[] = (string) $option;
			}
		}

		if ( [] === $kept && $field->required ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( 'Choose at least one for %s.', 'dgl-platform' ), $field->label ) ];
		}

		return [ $kept, null ];
	}

	private static function check_select( Field $field, string $value ): array {
		if ( ! array_key_exists( $value, $field->options ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( 'Choose one of the options for %s.', 'dgl-platform' ), $field->label ) ];
		}

		return [ $value, null ];
	}

	/**
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_image( Field $field, string $value ): array {
		/*
		 * The control posts a hidden 0 when nothing is attached yet, so that
		 * saving a step without touching the file input does not wipe an
		 * existing image. Zero therefore means "no image", not "broken image".
		 */
		if ( '0' === $value ) {
			if ( $field->required ) {
				/* translators: %s: field label. */
				return [ null, sprintf( __( '%s is needed.', 'dgl-platform' ), $field->label ) ];
			}

			return [ 0, null ];
		}

		if ( ! preg_match( '/^\d+$/', $value ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s was not uploaded properly. Try again.', 'dgl-platform' ), $field->label ) ];
		}

		return [ (int) $value, null ];
	}

	/**
	 * Checkboxes arrive as "1", "on", "yes", true or absent.
	 */
	private static function truthy( mixed $raw ): bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}

		if ( ! is_scalar( $raw ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $raw ) ), [ '1', 'on', 'yes', 'true' ], true );
	}
}
