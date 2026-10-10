<?php
/**
 * The mail settings: read and changed from wp-admin and by site abilities.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Four addresses and a switch decide where every email goes: whether the
 * plugin sends at all, the From address and name, the inbox the review
 * team's notifications go to, and a redirect that diverts everything (for
 * staging). They can be set from the hosting console with `wp option
 * update`, on the System health page in wp-admin, or through the
 * WordPress abilities `dgl/get-mail-settings` and
 * `dgl/update-mail-settings`, so the team never need the console and an
 * agent with a site connection has a typed route as well as WP-CLI.
 *
 * A constant in wp-config.php (DGL_MAIL_ENABLED, DGL_MAIL_REDIRECT) still
 * wins over the option, and the form says so when it does.
 */
final class Settings {

	public const NONCE = 'dgl_mail_settings';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
	}

	/**
	 * The settings, keyed the way the form and the ability name them.
	 *
	 * @return array<string, array{option: string, label: string, help: string, kind: string}>
	 */
	public static function fields(): array {
		return [
			'enabled'   => [
				'option' => Routing::OPTION_ENABLED,
				'label'  => __( 'Sending', 'dgl-platform' ),
				'help'   => __( 'Off means no email of any kind leaves the site.', 'dgl-platform' ),
				'kind'   => 'bool',
			],
			'from'      => [
				'option' => Routing::OPTION_FROM,
				'label'  => __( 'From address', 'dgl-platform' ),
				'help'   => __( 'Every email is sent from this address, and replies come back to it. Use an address on the domain the mail service has verified.', 'dgl-platform' ),
				'kind'   => 'email',
			],
			'from_name' => [
				'option' => Routing::OPTION_FROM_NAME,
				'label'  => __( 'From name', 'dgl-platform' ),
				'help'   => __( 'Blank uses the site name.', 'dgl-platform' ),
				'kind'   => 'text',
			],
			'team'      => [
				'option' => Routing::OPTION_TEAM,
				'label'  => __( 'Team inbox', 'dgl-platform' ),
				'help'   => __( 'Where the review team\'s notifications go: new submissions, joining requests, organisation changes. Blank sends them to each moderator\'s own address instead.', 'dgl-platform' ),
				'kind'   => 'email',
			],
			'redirect'  => [
				'option' => Routing::OPTION_REDIRECT,
				'label'  => __( 'Redirect everything to', 'dgl-platform' ),
				'help'   => __( 'Staging only. With an address here every email goes there and nowhere else. Blank on a live site.', 'dgl-platform' ),
				'kind'   => 'email',
			],
			'smtp2go_report_key' => [
				'option' => Smtp2go::OPTION_REPORT_KEY,
				'label'  => __( 'SMTP2GO reporting key', 'dgl-platform' ),
				'help'   => __( 'A read-only SMTP2GO API key (reports permission only) so "did it arrive?" can be answered from the site: wp dgl mail trace <address>. Leave blank to keep the saved key; type "clear" to remove it. Never shown once saved.', 'dgl-platform' ),
				'kind'   => 'secret',
			],
		];
	}

	/**
	 * The current values, with a note for any a wp-config.php constant overrides.
	 *
	 * @return array<string, mixed>
	 */
	public static function read(): array {
		$out = [];

		foreach ( self::fields() as $key => $field ) {
			$raw = get_option( $field['option'], '' );

			$out[ $key ] = match ( $field['kind'] ) {
				'bool'   => (bool) $raw,
				'secret' => '' !== trim( (string) $raw ) ? 'set' : '',
				default  => trim( (string) $raw ),
			};
		}

		$out['effective'] = [
			'enabled'  => Routing::is_enabled(),
			'redirect' => Routing::configured_redirect(),
			'from'     => Routing::from_address(),
			'team'     => Routing::team_address(),
		];

		$out['overridden'] = [];

		if ( defined( 'DGL_MAIL_ENABLED' ) ) {
			$out['overridden']['enabled'] = 'DGL_MAIL_ENABLED';
		}

		if ( defined( 'DGL_MAIL_REDIRECT' ) && '' !== trim( (string) constant( 'DGL_MAIL_REDIRECT' ) ) ) {
			$out['overridden']['redirect'] = 'DGL_MAIL_REDIRECT';
		}

		return $out;
	}

	/**
	 * Change any of the settings. Keys left out are untouched; an empty
	 * string clears an address. Pure validation, then the options.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error The settings as they now are.
	 */
	public static function update( array $input ): array|WP_Error {
		$writes = [];

		foreach ( self::fields() as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];

			if ( 'bool' === $field['kind'] ) {
				$writes[ $field['option'] ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '';
				continue;
			}

			$value = trim( sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) ) );

			if ( 'secret' === $field['kind'] ) {
				if ( '' === $value ) {
					continue; // Blank keeps what is saved.
				}

				if ( 'clear' === strtolower( $value ) ) {
					$writes[ $field['option'] ] = '';
					continue;
				}

				if ( 1 !== preg_match( '/^api-[A-Za-z0-9]{32}$/', $value ) ) {
					/* translators: %s: the setting. */
					return new WP_Error( 'dgl_bad_key', sprintf( __( '%s: an SMTP2GO key reads api- followed by 32 letters and digits.', 'dgl-platform' ), $field['label'] ) );
				}

				$writes[ $field['option'] ] = $value;
				continue;
			}

			if ( 'email' === $field['kind'] && '' !== $value && ! is_email( $value ) ) {
				/* translators: 1: the setting, 2: what was typed. */
				return new WP_Error( 'dgl_bad_address', sprintf( __( '%1$s: "%2$s" is not an email address.', 'dgl-platform' ), $field['label'], $value ) );
			}

			$writes[ $field['option'] ] = $value;
		}

		foreach ( $writes as $option => $value ) {
			if ( '' === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}

		return self::read();
	}

	public static function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( 'dgl', [ 'label' => __( 'DGLP Platform', 'dgl-platform' ), 'description' => __( 'The partnership platform\'s own operations.', 'dgl-platform' ) ] );
		}
	}

	/**
	 * Read and change the mail settings as WordPress abilities, for an
	 * agent with a connection to the site.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$schema = [
			'type'       => 'object',
			'properties' => [
				'enabled'   => [ 'type' => 'boolean', 'description' => 'Whether the plugin sends email at all.' ],
				'from'      => [ 'type' => 'string', 'description' => 'The From address. Empty string clears it.' ],
				'from_name' => [ 'type' => 'string', 'description' => 'The From name. Empty string falls back to the site name.' ],
				'team'      => [ 'type' => 'string', 'description' => 'The inbox the review team\'s notifications go to. Empty string sends them to each moderator.' ],
				'redirect'  => [ 'type' => 'string', 'description' => 'Divert every email to this address (staging). Empty string switches the redirect off.' ],
				'smtp2go_report_key' => [ 'type' => 'string', 'description' => 'A read-only SMTP2GO API key (api-...) for delivery lookups. "clear" removes it. Read back only as "set" or "".' ],
			],
			'additionalProperties' => false,
		];

		$can = static fn(): bool => current_user_can( 'manage_options' );

		wp_register_ability(
			'dgl/get-mail-settings',
			[
				'label'               => __( 'Get mail settings', 'dgl-platform' ),
				'description'         => __( 'The DGLP Platform mail settings: sending on or off, From address and name, the team inbox, and any redirect, with the values in force and any wp-config.php constant overriding them.', 'dgl-platform' ),
				'category'            => 'dgl',
				'execute_callback'    => static fn(): array => self::read(),
				'permission_callback' => $can,
				'output_schema'       => [ 'type' => 'object' ],
				'meta'                => [
					'public'      => true,
					'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);

		wp_register_ability(
			'dgl/update-mail-settings',
			[
				'label'               => __( 'Update mail settings', 'dgl-platform' ),
				'description'         => __( 'Change any of the DGLP Platform mail settings. Keys left out are untouched; an empty string clears an address, so {"redirect": ""} switches a redirect off. Returns the settings as they now are.', 'dgl-platform' ),
				'category'            => 'dgl',
				'execute_callback'    => static fn( $input ): array|WP_Error => self::update( is_array( $input ) ? $input : [] ),
				'permission_callback' => $can,
				'input_schema'        => $schema,
				'output_schema'       => [ 'type' => 'object' ],
				'meta'                => [
					'public'      => true,
					'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}
}
