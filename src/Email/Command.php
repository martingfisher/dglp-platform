<?php
/**
 * WP-CLI access to the transactional email.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use DGL\Logo;
use DGL\Workflow\Plan;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp dgl mail`.
 *
 * Email is the part of this plugin that cannot be checked by looking at a
 * screen. Nothing renders until a real transition happens on a real item, and
 * by then it has already gone to somebody. So these commands exist to render
 * and send every template on demand, against made-up content, without touching
 * a single member record.
 *
 * `preview` writes the HTML to a file so it can be opened in a browser or
 * pasted into a client tester. `send` puts one through the site's actual mail
 * path, which is the only way to find out whether SMTP2GO, SPF and DKIM are
 * really working.
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl mail', self::class );
	}

	/**
	 * Show how mail is configured on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl mail status
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		$redirect = Routing::configured_redirect();

		$rows = [
			[
				'setting' => 'Sending',
				'value'   => Routing::is_enabled() ? 'on' : 'off (nothing will be sent)',
			],
			[
				'setting' => 'Redirect',
				'value'   => '' !== $redirect ? 'ALL MAIL GOES TO ' . $redirect : 'none, mail goes to the real recipients',
			],
			[
				'setting' => 'From address',
				'value'   => Routing::from_address() ?: '(WordPress default)',
			],
			[
				'setting' => 'From name',
				'value'   => Routing::from_name(),
			],
			[
				'setting' => 'Email logo',
				'value'   => Logo::email_url() ?? 'none, the header falls back to text',
			],
		];

		WP_CLI\Utils\format_items( 'table', $rows, [ 'setting', 'value' ] );
	}

	/**
	 * List every message the workflow can send.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl mail list
	 *
	 * @when after_wp_load
	 */
	public function list(): void {
		$rows = [];

		foreach ( Copy::keys() as $key ) {
			foreach ( [ Plan::NOTIFY_MEMBER, Plan::NOTIFY_MODERATORS ] as $audience ) {
				$message = Copy::compose( $key, $audience, self::sample() );

				if ( null === $message ) {
					continue;
				}

				$rows[] = [
					'key'      => $key,
					'audience' => $audience,
					'subject'  => $message->subject,
				];
			}
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'audience', 'subject' ] );
	}

	/**
	 * Render one message to a file, using made-up content.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<key>]
	 * : Which message. Omit to render every one.
	 *
	 * [--audience=<audience>]
	 * : member or moderators. Default: member.
	 *
	 * [--dir=<dir>]
	 * : Where to write. Default: the uploads directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl mail preview --key=changes_requested
	 *     wp dgl mail preview --dir=/tmp/dgl-email
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function preview( array $args, array $assoc ): void {
		$audience = $assoc['audience'] ?? Plan::NOTIFY_MEMBER;
		$keys     = isset( $assoc['key'] ) ? [ $assoc['key'] ] : Copy::keys();

		$uploads = wp_upload_dir();
		$dir     = rtrim( $assoc['dir'] ?? ( $uploads['basedir'] . '/dgl-email-preview' ), '/' );

		if ( ! wp_mkdir_p( $dir ) ) {
			WP_CLI::error( 'Cannot write to ' . $dir );
		}

		$written = 0;

		foreach ( $keys as $key ) {
			foreach ( [ Plan::NOTIFY_MEMBER, Plan::NOTIFY_MODERATORS ] as $each ) {
				if ( isset( $assoc['key'] ) && $each !== $audience ) {
					continue;
				}

				$message = Copy::compose( $key, $each, self::sample() );

				if ( null === $message ) {
					continue;
				}

				$html = Template::render( $message, Logo::email_url() );
				$path = $dir . '/' . $key . '-' . $each . '.html';
				$text = $dir . '/' . $key . '-' . $each . '.txt';

				file_put_contents( $path, $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $text, $message->to_text() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

				WP_CLI::log( $path );
				++$written;
			}
		}

		if ( 0 === $written ) {
			WP_CLI::error( 'Nothing matched. Run `wp dgl mail list` to see what exists.' );
		}

		WP_CLI::success( sprintf( '%d message(s) written to %s', $written, $dir ) );
	}

	/**
	 * Send one message to an address, using made-up content.
	 *
	 * This goes through the site's real mail path, so it proves delivery rather
	 * than rendering. It bypasses the redirect deliberately: the whole point is
	 * to send to an address you name.
	 *
	 * ## OPTIONS
	 *
	 * <address>
	 * : Where to send it.
	 *
	 * [--key=<key>]
	 * : Which message. Default: approved.
	 *
	 * [--audience=<audience>]
	 * : member or moderators. Default: member.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl mail send you@example.com --key=changes_requested
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function send( array $args, array $assoc ): void {
		$address = (string) ( $args[0] ?? '' );

		if ( ! is_email( $address ) ) {
			WP_CLI::error( 'That is not an email address.' );
		}

		if ( ! Routing::is_enabled() ) {
			WP_CLI::error( 'Sending is off. Set the ' . Routing::OPTION_ENABLED . ' option or the DGL_MAIL_ENABLED constant first.' );
		}

		$key      = $assoc['key'] ?? 'approved';
		$audience = $assoc['audience'] ?? Plan::NOTIFY_MEMBER;
		$message  = Copy::compose( $key, $audience, self::sample() );

		if ( null === $message ) {
			WP_CLI::error( sprintf( 'There is no "%s" message for the "%s" audience.', $key, $audience ) );
		}

		$sent = Mailer::send( $message->for_recipients( [ $address ] ) );

		if ( ! $sent ) {
			WP_CLI::error( 'WordPress refused it. Check the mail plugin and the site log.' );
		}

		WP_CLI::success( 'Handed to WordPress for delivery. That is not proof it arrived, so check the inbox.' );
	}

	/**
	 * Made-up content, clearly marked as made up.
	 *
	 * Nothing here touches a real organisation, a real member or a real item, so
	 * a preview can never leak one member's content into a test.
	 */
	private static function sample(): Context {
		return new Context(
			title: 'Sample: Volunteer drop-in at Leeds Central Library',
			type_label: 'Event',
			org_name: 'Sample Organisation',
			actor_name: 'Sample Reviewer',
			site_name: (string) get_bloginfo( 'name' ),
			item_url: home_url( '/dashboard/item/0' ),
			review_url: home_url( '/dashboard/review/0' ),
			public_url: home_url( '/' ),
			queue_url: home_url( '/dashboard/review' ),
			expires_on: wp_date( (string) get_option( 'date_format', 'j F Y' ) ) ?: '',
			note: "This is sample text standing in for a reviewer's message.\n\nThe second paragraph is here to prove line breaks survive.",
		);
	}
}
