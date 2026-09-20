<?php
/**
 * One field in a submission form.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * A single field definition.
 *
 * Every surface reads these rather than restating the form: the wizard renders
 * from them, the validator checks against them, `register_post_meta` is
 * generated from them, the wp-admin meta boxes are built from them, the
 * moderator's raw-fields view lists them, the digest picks its summary line
 * from them and the CSV export takes its columns from them.
 *
 * That single source is the whole reason this plugin does not need ACF.
 */
final readonly class Field {

	public const TEXT     = 'text';
	public const TEXTAREA = 'textarea';
	public const RICHTEXT = 'richtext';
	public const DATE     = 'date';
	public const DATETIME = 'datetime';
	public const URL      = 'url';
	public const EMAIL    = 'email';
	public const TEL      = 'tel';
	public const NUMBER   = 'number';
	public const MONEY    = 'money';
	public const SELECT   = 'select';
	public const CHECKBOX = 'checkbox';
	public const IMAGE    = 'image';
	public const POSTCODE = 'postcode';
	/** Several of a fixed list. Stored as an array of option keys. */
	public const CHOICES  = 'choices';
	/** How an event repeats. Stored as the rule array; see Events\Rule. */
	public const REPEAT   = 'repeat';

	/**
	 * @param string                $key       Meta key, without the plugin prefix.
	 * @param string                $label     Shown above the control.
	 * @param string                $type      One of the class constants.
	 * @param bool                  $required  Whether submission is blocked without it.
	 * @param int                   $step      Wizard step, 1 to 3. Step 4 is review.
	 * @param string                $help      Guidance under the control.
	 * @param array<string, string> $options   Value => label, for SELECT.
	 * @param int|null              $max_length Character cap for text types.
	 * @param bool                  $public    Whether it renders on the public listing.
	 * @param bool                  $in_digest Whether it can appear in a digest summary.
	 * @param bool                  $in_csv    Whether it appears in the CSV export.
	 * @param bool                  $schedule  Part of an event's schedule: the
	 *        organisation may change it on a live item at once, without review,
	 *        because it is a fact about the world they know and the team does not.
	 * @param string|null $required_with Required only when this other field
	 *        holds something: the description of a picture is needed when
	 *        there is a picture, and nothing otherwise.
	 * @param array{field:string, value:mixed}|null $depends_on Show only when
	 *        another field on the same step holds one of these values. Purely a
	 *        display nicety: the field still validates and saves normally, so
	 *        the form works identically with JavaScript turned off.
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $type = self::TEXT,
		public bool $required = false,
		public int $step = 2,
		public string $help = '',
		public array $options = [],
		public ?int $max_length = null,
		public bool $public = true,
		public bool $in_digest = false,
		public bool $in_csv = true,
		public ?array $depends_on = null,
		public bool $schedule = false,
		public ?string $required_with = null,
	) {}

	/**
	 * The dependency as data attributes for the client.
	 *
	 * @return array<string, string>
	 */
	/**
	 * Whether this field is in play, given the other values.
	 *
	 * A field that depends on another is only asked, required, stored or
	 * shown when the controlling value is one it is declared for. Without
	 * this the venue was demanded of an online event and "Not given" was
	 * printed under fields nobody was asked.
	 *
	 * @param array<string, mixed> $values Every value by field key.
	 */
	public function applies( array $values ): bool {
		if ( null === $this->depends_on ) {
			return true;
		}

		$controller = (string) ( $this->depends_on['field'] ?? '' );
		$current    = $values[ $controller ] ?? '';
		$current    = is_bool( $current ) ? ( $current ? '1' : '0' ) : ( is_scalar( $current ) ? (string) $current : '' );

		foreach ( (array) ( $this->depends_on['value'] ?? [] ) as $allowed ) {
			$allowed = is_bool( $allowed ) ? ( $allowed ? '1' : '0' ) : (string) $allowed;

			if ( $allowed === $current ) {
				return true;
			}
		}

		return false;
	}

	public function depends_attrs(): array {
		if ( null === $this->depends_on ) {
			return [];
		}

		$values = (array) ( $this->depends_on['value'] ?? [] );

		return [
			'data-dgl-depends'    => (string) ( $this->depends_on['field'] ?? '' ),
			'data-dgl-depends-on' => implode( '|', array_map( static fn( $v ): string => is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v, $values ) ),
		];
	}

	/**
	 * Every field type the system knows.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return [
			self::TEXT,
			self::TEXTAREA,
			self::RICHTEXT,
			self::DATE,
			self::DATETIME,
			self::URL,
			self::EMAIL,
			self::TEL,
			self::NUMBER,
			self::MONEY,
			self::SELECT,
			self::CHECKBOX,
			self::IMAGE,
			self::POSTCODE,
			self::CHOICES,
			self::REPEAT,
		];
	}

	/**
	 * The prefixed meta key this field stores under.
	 */
	public function meta_key(): string {
		return 'dgl_' . $this->key;
	}

	/**
	 * The `register_post_meta` arguments for this field.
	 *
	 * @return array<string, mixed>
	 */
	public function meta_args(): array {
		$type = $this->meta_type();

		return [
			'type'         => $type,
			'single'       => true,
			// The default has to match the declared type or WordPress logs a
			// _doing_it_wrong on every single registration.
			'default'      => match ( $type ) {
				'integer' => 0,
				'number'  => 0.0,
				'boolean' => false,
				default   => '',
			},
			'description'  => $this->label,
			'show_in_rest' => false,
		];
	}

	/**
	 * The scalar type this field stores.
	 */
	public function meta_type(): string {
		return match ( $this->type ) {
			self::NUMBER, self::IMAGE => 'integer',
			self::MONEY               => 'number',
			self::CHECKBOX            => 'boolean',
			default                   => 'string',
		};
	}
}
