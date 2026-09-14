<?php
/**
 * One field in a submission form.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

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
	) {}

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
