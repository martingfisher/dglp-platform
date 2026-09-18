<?php
/**
 * Sending the transactional email.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use DGL\Dashboard\Router;
use DGL\Logo;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Workflow\Plan;
use DGL\Workflow\Revisions;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The thing that actually puts email on the wire.
 *
 * It listens to `dgl_item_transitioned` rather than being called from the
 * workflow, which matters more than it looks: a mail server that is down must
 * never be able to undo a decision a moderator has already made. The status has
 * changed and the audit entry is written before this runs, and if this fails it
 * fails on its own.
 *
 * Everything it needs to decide has already been decided elsewhere. The plan
 * says who to tell and which message. {@see Copy} says what it says.
 * {@see Recipients} says which addresses. {@see Routing} says whether they are
 * allowed to be used on this site. This part is assembly and PHPMailer.
 */
final class Mailer {

	/**
	 * Header marking these as automatic, so out-of-office replies and other
	 * auto-responders do not bounce back into the review queue's inbox.
	 */
	private const AUTO_HEADER = 'Auto-Submitted: auto-generated';

	public static function init(): void {
		add_action( 'dgl_item_transitioned', [ self::class, 'on_transition' ], 10, 4 );
		add_action( 'wp_mail_failed', [ self::class, 'on_failure' ] );
	}

	/**
	 * Send whatever a transition calls for.
	 *
	 * @param int    $post_id  The item.
	 * @param Plan   $plan     What happened.
	 * @param int    $actor_id Who did it, 0 for the system.
	 * @param string $note     The message to the member, if any.
	 */
	public static function on_transition( int $post_id, Plan $plan, int $actor_id, string $note = '' ): void {
		if ( [] === $plan->notify || '' === $plan->message_key ) {
			return;
		}

		if ( ! Routing::is_enabled() ) {
			return;
		}

		$context = self::context( $post_id, $actor_id, $note );

		if ( null === $context ) {
			return;
		}

		foreach ( $plan->notify as $audience ) {
			$message = Copy::compose( $plan->message_key, $audience, $context );

			if ( null === $message ) {
				/*
				 * A plan asking for an audience nobody has written copy for is
				 * a gap, not a non-event. Recorded so it surfaces in the debug
				 * log rather than being silently dropped.
				 */
				self::record_gap( $plan->message_key, $audience );
				continue;
			}

			$to = Recipients::for_audience( $audience, $post_id, $actor_id );

			if ( [] === $to ) {
				continue;
			}

			self::send( $message->for_recipients( $to ) );
		}
	}

	/**
	 * Build the facts for one item.
	 *
	 * Returns null when the post has gone, which can happen if something is
	 * deleted between the transition and the hook running.
	 */
	private static function context( int $post_id, int $actor_id, string $note ): ?Context {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		/*
		 * A pending edit borrows almost everything from the item it would
		 * replace: the type, the organisation, the page the member goes back
		 * to. What it does not borrow is the fact that it is an edit, which is
		 * the one thing the wording has to get right.
		 */
		$is_edit = PostTypes::REVISION === $post->post_type;
		$subject = $post;

		if ( $is_edit ) {
			$parent = get_post( Revisions::target( $post_id ) );

			if ( ! $parent instanceof WP_Post ) {
				return null;
			}

			$subject = $parent;
		}

		$definitions = PostTypes::definitions();
		$type_label  = $definitions[ $subject->post_type ]['singular'] ?? '';

		$org_id   = Org::for_item( (int) $subject->ID );
		$org_name = $org_id > 0 ? (string) get_the_title( $org_id ) : '';

		return new Context(
			title: (string) get_the_title( $subject ),
			type_label: (string) $type_label,
			org_name: $org_name,
			actor_name: self::actor_name( $actor_id ),
			site_name: (string) get_bloginfo( 'name' ),
			// Always the item's own page. An edit has no page of its own once
			// it has been applied, so a link to it would die on approval.
			item_url: Router::url( 'item', (string) $subject->ID ),
			// The review link is to the thing being decided, edit included.
			review_url: Router::url( 'review', (string) $post_id ),
			public_url: self::public_url( $subject ),
			queue_url: Router::url( 'review' ),
			expires_on: self::expires_on( (int) $subject->ID ),
			note: $note,
			is_edit: $is_edit,
			// "3 months" for a listing that stays up for a spell, '' for a dated one.
			lifetime: null !== \DGL\Workflow\Lifetime::days_for( (string) $subject->post_type ) ? \DGL\Workflow\Lifetime::spell_for( (string) $subject->post_type ) : '',
		);
	}

	/**
	 * Who did it, in words.
	 *
	 * The system acts on its own for expiry, and "expired by nobody" is exactly
	 * what should be said there, so zero is not treated as an error.
	 */
	private static function actor_name( int $actor_id ): string {
		if ( $actor_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $actor_id );

		if ( ! $user ) {
			return '';
		}

		$name = trim( (string) $user->display_name );

		return '' !== $name ? $name : (string) $user->user_login;
	}

	/**
	 * The live page, but only when there actually is one.
	 *
	 * `get_permalink()` returns a URL for a draft too, and it 404s. Linking a
	 * member to a 404 from an email saying their work is live is the sort of
	 * thing that generates a support call.
	 */
	private static function public_url( WP_Post $post ): string {
		if ( Statuses::LIVE !== $post->post_status ) {
			return '';
		}

		$url = get_permalink( $post );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * The expiry date in the site's own timezone, formatted the way the site
	 * formats dates, or an empty string when the item does not expire.
	 */
	private static function expires_on( int $post_id ): string {
		$stamp = (string) get_post_meta( $post_id, Meta::ITEM_EXPIRES_AT, true );

		if ( '' === $stamp ) {
			return '';
		}

		try {
			$at = new \DateTimeImmutable( $stamp, wp_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}

		return (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $at->getTimestamp() );
	}

	/**
	 * Send one message.
	 *
	 * The content type, the From address and the plain-text alternative are all
	 * set through filters that are removed the moment the send returns. Leaving
	 * an HTML content type filter attached would turn every other plugin's mail
	 * on the site into HTML too, and that is a genuinely nasty bug to trace back
	 * to its source.
	 *
	 * @return bool Whether WordPress accepted it for delivery. Not whether it
	 *              arrived, which no sending code can know.
	 */
	public static function send( Message $message ): bool {
		$redirect = Routing::configured_redirect();
		$intended = $message->to;
		$to       = Routing::deliver_to( $intended, $redirect );

		if ( [] === $to ) {
			return false;
		}

		$subject = Routing::subject( $message->subject, $intended, $redirect );
		$body    = Template::render( $message, Logo::email_url() );
		$text    = $message->to_text();

		$headers = [
			self::AUTO_HEADER,
			'X-DGL-Message: ' . $message->key . '/' . $message->audience,
		];

		$html_type  = static fn(): string => 'text/html';
		$from_email = static fn( string $original ): string => Routing::from_address() ?: $original;
		$from_name  = static fn( string $original ): string => Routing::from_name() ?: $original;

		/*
		 * The text alternative has to be attached to PHPMailer directly.
		 * `wp_mail()` has no parameter for it, and a message with no plain-text
		 * part scores worse with spam filters and is unreadable to anyone whose
		 * client blocks HTML.
		 */
		$alt_body = static function ( PHPMailer $phpmailer ) use ( $text ): void {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};

		add_filter( 'wp_mail_content_type', $html_type, 99 );
		add_filter( 'wp_mail_from', $from_email, 99 );
		add_filter( 'wp_mail_from_name', $from_name, 99 );
		add_action( 'phpmailer_init', $alt_body, 99 );

		try {
			$sent = wp_mail( $to, $subject, $body, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', $html_type, 99 );
			remove_filter( 'wp_mail_from', $from_email, 99 );
			remove_filter( 'wp_mail_from_name', $from_name, 99 );
			remove_action( 'phpmailer_init', $alt_body, 99 );
		}

		/**
		 * Fires after a transactional email has been handed to WordPress.
		 *
		 * @param bool     $sent    Whether it was accepted for delivery.
		 * @param Message  $message The message, addressed.
		 * @param string[] $to      Where it actually went, after any redirect.
		 */
		do_action( 'dgl_mail_sent', $sent, $message, $to );

		return (bool) $sent;
	}

	/**
	 * Note a delivery failure where somebody will find it.
	 *
	 * @param \WP_Error $error
	 */
	public static function on_failure( $error ): void {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$data = $error->get_error_data( 'wp_mail_failed' );

		if ( ! is_array( $data ) || ! isset( $data['headers'] ) ) {
			return;
		}

		$headers = (array) $data['headers'];

		// Only this plugin's mail. Other plugins' failures are not ours to log.
		if ( ! isset( $headers['x-dgl-message'] ) && ! isset( $headers['X-DGL-Message'] ) ) {
			return;
		}

		self::debug( 'delivery failed: ' . $error->get_error_message() );
	}

	private static function record_gap( string $key, string $audience ): void {
		self::debug( sprintf( 'no copy written for message "%s" to audience "%s"', $key, $audience ) );
	}

	/**
	 * Debug output, only where debugging is on.
	 */
	private static function debug( string $line ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'DGL mail: ' . $line );
	}
}
