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

			[ $value, $error ] = self::check( $field, $raw, $input );

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
	 * Whether a posted or stored value counts as "something there".
	 *
	 * An image is an attachment id, so "0" is nothing; a list is nothing when
	 * empty; anything else is nothing when blank.
	 */
	public static function has_value( mixed $raw ): bool {
		if ( is_array( $raw ) ) {
			return [] !== array_filter( $raw, static fn( $v ): bool => '' !== (string) $v );
		}

		if ( is_numeric( $raw ) ) {
			return (int) $raw > 0 || ( (string) $raw !== '0' && '' !== trim( (string) $raw ) );
		}

		return null !== $raw && '' !== trim( (string) $raw );
	}

	/**
	 * The errors for fields that are required only alongside another, checked
	 * against the values as they stand after uploads have landed. The wizard
	 * calls this once the file control's result is known, because the posted
	 * form only carries the previous attachment id.
	 *
	 * @param Field[]              $fields
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	public static function required_with_errors( array $fields, array $values ): array {
		$errors = [];

		foreach ( $fields as $field ) {
			if ( null === $field->required_with || ! $field->applies( $values ) ) {
				continue;
			}

			if ( self::has_value( $values[ $field->required_with ] ?? null ) && ! self::has_value( $values[ $field->key ] ?? null ) ) {
				$errors[ $field->key ] = self::required_with_message( $field );
			}
		}

		return $errors;
	}

	private static function required_with_message( Field $field ): string {
		/* translators: %s: field label. */
		return sprintf( __( '%s is needed when there is a picture.', 'dgl-platform' ), $field->label );
	}

	/**
	 * Check one field.
	 *
	 * @return array{0: mixed, 1: string|null} Normalised value, then error or null.
	 */
	private static function check( Field $field, mixed $raw, array $input = [] ): array {
		// Hidden by its controlling field: nothing to check and nothing to keep.
		if ( ! $field->applies( $input ) ) {
			return [ in_array( $field->type, [ Field::CHOICES, Field::REPEAT ], true ) ? [] : ( Field::CHECKBOX === $field->type ? false : '' ), null ];
		}

		if ( Field::CHECKBOX === $field->type ) {
			return [ self::truthy( $raw ), null ];
		}

		if ( Field::REPEAT === $field->type ) {
			return self::check_repeat( $field, $raw, $input );
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

			if ( null !== $field->required_with && self::has_value( $input[ $field->required_with ] ?? null ) ) {
				return [ null, self::required_with_message( $field ) ];
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
			Field::RICHTEXT => self::check_richtext( $field, $value ),
			default         => [ $value, null ],
		};
	}

	/**
	 * The words may carry links, and every one has to be https, like the
	 * address fields. A pasted document is the usual way an http one arrives.
	 *
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_richtext( Field $field, string $value ): array {
		$insecure = Links::insecure_hrefs( $value );

		if ( [] === $insecure ) {
			return [ $value, null ];
		}

		return [
			null,
			sprintf(
				/* translators: 1: field label, 2: the links. */
				_n(
					'%1$s has a link that starts with http:// and the site does not link to pages served over plain http. Change it to https://, or take it out: %2$s',
					'%1$s has links that start with http:// and the site does not link to pages served over plain http. Change them to https://, or take them out: %2$s',
					count( $insecure ),
					'dgl-platform'
				),
				$field->label,
				implode( ', ', $insecure )
			),
		];
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
		/*
		 * One rule for every typed address: a bare domain gets "https://",
		 * anything with a scheme keeps it. "javascript:alert(1)" therefore
		 * arrives here with its own scheme and is refused on the next line
		 * rather than hidden behind "https://".
		 */
		$value  = Links::normalise( $value );
		$scheme = (string) parse_url( $value, PHP_URL_SCHEME );

		if ( ! Links::is_web( $value ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s has to be a web address.', 'dgl-platform' ), $field->label ) ];
		}

		if ( ! Links::is_secure( $value ) ) {
			/* translators: %s: field label. */
			return [ null, sprintf( __( '%s has to start with https://. The site does not link to pages served over plain http. If the site does not work over https, ask the DGLP team about securing it.', 'dgl-platform' ), $field->label ) ];
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
	 * How an event repeats, checked against the start and end on the same step.
	 *
	 * Returns the storable rule, or [] when the box is not ticked. Day,
	 * weekday and ordinal come from the start date, never from the form, so
	 * there is nothing for a member to get wrong there. The stored value has
	 * no `on` key, so a rule read back for a full re-validation counts as on.
	 *
	 * @param array<string, mixed> $input The whole step's raw input.
	 * @return array{0: mixed, 1: string|null}
	 */
	private static function check_repeat( Field $field, mixed $raw, array $input ): array {
		if ( ! is_array( $raw ) ) {
			return [ [], null ];
		}

		$on = array_key_exists( 'posted', $raw ) ? self::truthy( $raw['on'] ?? '' ) : '' !== (string) ( $raw['freq'] ?? '' );

		if ( ! $on ) {
			return [ [], null ];
		}

		$freq = (string) ( $raw['freq'] ?? '' );

		if ( ! in_array( $freq, \DGL\Events\Rule::freqs(), true ) ) {
			return [ null, __( 'Choose how often it repeats.', 'dgl-platform' ) ];
		}

		$start_raw = is_scalar( $input['start_datetime'] ?? null ) ? trim( (string) $input['start_datetime'] ) : '';
		[ $start, $start_error ] = '' === $start_raw ? [ null, 'missing' ] : self::check_datetime( $field, $start_raw );

		if ( null !== $start_error ) {
			return [ null, __( 'Set the start date and time first, then say how it repeats.', 'dgl-platform' ) ];
		}

		$end_raw = is_scalar( $input['end_datetime'] ?? null ) ? trim( (string) $input['end_datetime'] ) : '';

		if ( '' !== $end_raw ) {
			[ $end, $end_error ] = self::check_datetime( $field, $end_raw );

			if ( null === $end_error && substr( (string) $end, 0, 10 ) !== substr( (string) $start, 0, 10 ) ) {
				return [ null, __( 'For a repeating event the end time has to be on the same day as the start.', 'dgl-platform' ) ];
			}
		}

		$start_at = new DateTimeImmutable( (string) $start );
		$rule     = [ 'freq' => $freq ];

		if ( \DGL\Events\Rule::MONTHLY === $freq ) {
			$monthly = (string) ( $raw['monthly'] ?? '' );

			if ( ! in_array( $monthly, [ \DGL\Events\Rule::BY_DAY, \DGL\Events\Rule::BY_NTH, \DGL\Events\Rule::BY_LAST ], true ) ) {
				return [ null, __( 'Choose which day of the month it repeats on.', 'dgl-platform' ) ];
			}

			$day = (int) $start_at->format( 'j' );

			if ( \DGL\Events\Rule::BY_LAST === $monthly && $day + 7 <= (int) $start_at->format( 't' ) ) {
				return [
					null,
					sprintf(
						/* translators: %s: weekday name. */
						__( 'The start date is not the last %s of its month, so choose one of the other patterns.', 'dgl-platform' ),
						\DGL\Events\Wording::weekday_name( (int) $start_at->format( 'N' ) )
					),
				];
			}

			$rule['monthly'] = $monthly;
			$rule['day']     = $day;
			$rule['weekday'] = (int) $start_at->format( 'N' );
			$rule['nth']     = intdiv( $day - 1, 7 ) + 1;
		} else {
			$weekdays = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $raw['weekdays'] ?? [] ) ), static fn( int $d ): bool => $d >= 1 && $d <= 7 ) ) );
			$weekdays[] = (int) $start_at->format( 'N' );
			$weekdays   = array_values( array_unique( $weekdays ) );
			sort( $weekdays );
			$rule['weekdays'] = $weekdays;
		}

		$until_raw = is_scalar( $raw['until'] ?? null ) ? trim( (string) $raw['until'] ) : '';

		if ( '' === $until_raw ) {
			return [ null, __( 'Say when it runs until.', 'dgl-platform' ) ];
		}

		[ $until, $until_error ] = self::check_date( $field, $until_raw, 'Y-m-d' );

		if ( null !== $until_error ) {
			return [ null, __( 'Runs until needs to be a real date.', 'dgl-platform' ) ];
		}

		if ( (string) $until < $start_at->format( 'Y-m-d' ) ) {
			return [ null, __( 'Runs until has to be on or after the start date.', 'dgl-platform' ) ];
		}

		$latest = ( new DateTimeImmutable( 'today' ) )->modify( '+' . \DGL\Events\Rule::MAX_MONTHS_AHEAD . ' months' )->format( 'Y-m-d' );

		if ( (string) $until > $latest ) {
			return [
				null,
				sprintf(
					/* translators: %s: the latest allowed date. */
					__( 'A series can run for up to six months. Set an end date on or before %s. You can extend it later with one click.', 'dgl-platform' ),
					( new DateTimeImmutable( $latest ) )->format( 'j F Y' )
				),
			];
		}

		$rule['until'] = (string) $until;

		$skip_raw = $raw['skip'] ?? [];
		$parts    = is_array( $skip_raw ) ? $skip_raw : ( preg_split( '/[\n,]+/', (string) $skip_raw ) ?: [] );
		$skip     = [];

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );

			if ( '' === $part ) {
				continue;
			}

			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $part ) ?: DateTimeImmutable::createFromFormat( '!d/m/Y', $part );

			if ( false === $date ) {
				/* translators: %s: what was typed. */
				return [ null, sprintf( __( '"%s" is not a date. Use the form 2026-12-23.', 'dgl-platform' ), $part ) ];
			}

			$ymd = $date->format( 'Y-m-d' );

			if ( $ymd < $start_at->format( 'Y-m-d' ) || $ymd > (string) $until ) {
				/* translators: %s: the date. */
				return [ null, sprintf( __( '%s is outside the dates the event runs between.', 'dgl-platform' ), $date->format( 'j F Y' ) ) ];
			}

			$skip[] = $ymd;
		}

		$skip = array_values( array_unique( $skip ) );
		sort( $skip );

		if ( count( $skip ) > \DGL\Events\Rule::MAX_SKIPS ) {
			return [ null, __( 'Up to ten dates it does not run.', 'dgl-platform' ) ];
		}

		if ( [] !== $skip ) {
			$rule['skip'] = $skip;
		}

		return [ $rule, null ];
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
