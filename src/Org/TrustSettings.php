<?php
/**
 * What an organisation is trusted to publish without review.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

defined( 'ABSPATH' ) || exit;

/**
 * One master switch, one switch per content type, one for edits.
 *
 * Pure: no WordPress calls, so every rule is unit tested. Fails closed:
 * anything it cannot read as a setting is "not trusted", and when the
 * master is off nothing underneath it can be on, so a switch hidden on the
 * screen is never quietly still in force.
 */
final readonly class TrustSettings {

	/**
	 * @param bool                $on    The master switch.
	 * @param array<string, bool> $types Post type => trusted for new items of that type (and edits to them).
	 * @param bool                $edits Trusted for edits to already approved items, whatever the type.
	 */
	public function __construct(
		public bool $on = false,
		public array $types = [],
		public bool $edits = false,
	) {}

	public static function off(): self {
		return new self();
	}

	/**
	 * Read what is stored. The array shape, or a legacy level 0, 1 or 2.
	 * Anything else is off. Unknown values are off. Types the site knows
	 * about are always present in the result.
	 *
	 * @param string[] $known_types Post types the site currently offers.
	 */
	public static function from_meta( mixed $raw, array $known_types ): self {
		if ( ! is_array( $raw ) ) {
			$is_level = is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) );

			return $is_level ? self::from_legacy( (int) $raw, $known_types ) : self::off();
		}

		$on    = self::truthy( $raw['on'] ?? false );
		$types = [];

		foreach ( (array) ( $raw['types'] ?? [] ) as $type => $flag ) {
			if ( is_string( $type ) && '' !== $type ) {
				$types[ $type ] = $on && self::truthy( $flag );
			}
		}

		foreach ( $known_types as $type ) {
			$types[ $type ] = $types[ $type ] ?? false;
		}

		return new self( $on, $types, $on && self::truthy( $raw['edits'] ?? false ) );
	}

	/**
	 * The old three levels: 2 trusted everything, 1 edits only, 0 nothing.
	 *
	 * @param string[] $known_types
	 */
	public static function from_legacy( int $level, array $known_types ): self {
		if ( $level >= 2 ) {
			return new self( true, array_fill_keys( $known_types, true ), true );
		}

		if ( 1 === $level ) {
			return new self( true, array_fill_keys( $known_types, false ), true );
		}

		return new self( false, array_fill_keys( $known_types, false ), false );
	}

	/**
	 * @return array{on: bool, types: array<string, bool>, edits: bool}
	 */
	public function to_meta(): array {
		$types = $this->types;
		ksort( $types );

		return [
			'on'    => $this->on,
			'types' => array_map( static fn( $v ): bool => (bool) $v, $types ),
			'edits' => $this->edits,
		];
	}

	public function is_on(): bool {
		return $this->on;
	}

	public function trusts( string $post_type ): bool {
		return $this->on && ! empty( $this->types[ $post_type ] );
	}

	public function trusts_edits(): bool {
		return $this->on && $this->edits;
	}

	/**
	 * Whether a submission goes live without being read first.
	 */
	public function skips_review( string $post_type, bool $is_edit ): bool {
		return $this->trusts( $post_type ) || ( $is_edit && $this->trusts_edits() );
	}

	/**
	 * Which switch let it through: 'type', 'edits', or '' for neither.
	 * The type switch wins when both apply.
	 */
	public function why( string $post_type, bool $is_edit ): string {
		if ( $this->trusts( $post_type ) ) {
			return 'type';
		}

		return $is_edit && $this->trusts_edits() ? 'edits' : '';
	}

	/**
	 * The old level this amounts to, for the index table: 2 if any type is
	 * trusted, 1 if only edits, 0 otherwise.
	 */
	public function legacy_level(): int {
		if ( ! $this->on ) {
			return 0;
		}

		if ( in_array( true, $this->types, true ) ) {
			return 2;
		}

		return $this->edits ? 1 : 0;
	}

	/**
	 * The same settings with the master switched. Off clears everything.
	 */
	public function with_master( bool $on ): self {
		return $on ? new self( true, $this->types, $this->edits ) : self::off();
	}

	/**
	 * In words, for a list row, an audit note or an email.
	 *
	 * @param array<string, string> $labels Post type => plural label, in display order. Only these are named.
	 */
	public function summary( array $labels ): string {
		if ( ! $this->on ) {
			return __( 'Not trusted', 'dgl-platform' );
		}

		$named = [];

		foreach ( $labels as $type => $label ) {
			if ( $this->trusts( $type ) ) {
				$named[] = $label;
			}
		}

		if ( [] === $named && ! $this->edits ) {
			return __( 'Trusted: nothing yet', 'dgl-platform' );
		}

		if ( [] !== $labels && count( $named ) === count( $labels ) && $this->edits ) {
			return __( 'Trusted: everything', 'dgl-platform' );
		}

		if ( [] === $named ) {
			return __( 'Trusted: edits only', 'dgl-platform' );
		}

		$list = implode( ', ', $named );

		return $this->edits
			/* translators: %s: list of content types. */
			? sprintf( __( 'Trusted: %s, and edits', 'dgl-platform' ), $list )
			/* translators: %s: list of content types. */
			: sprintf( __( 'Trusted: %s', 'dgl-platform' ), $list );
	}

	public function equals( self $other ): bool {
		return $this->to_meta() === $other->to_meta();
	}

	private static function truthy( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value;
	}
}
