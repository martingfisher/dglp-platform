<?php
/**
 * Everything a transactional email needs to know, gathered once.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

/**
 * The facts behind one notification.
 *
 * {@see Mailer} reads these out of WordPress. {@see Copy} turns them into
 * words. Keeping them in a value object is what lets every line of copy be
 * tested without a post, a user or a database.
 *
 * Every field has a usable default, because an email that fails to send is a
 * worse outcome than an email that says "this item" instead of a title.
 */
final readonly class Context {

	public function __construct(
		public string $title = '',
		public string $type_label = '',
		public string $org_name = '',
		public string $actor_name = '',
		public string $site_name = '',
		public string $item_url = '',
		public string $review_url = '',
		public string $public_url = '',
		public string $queue_url = '',
		public string $expires_on = '',
		public string $note = '',
	) {}

	/**
	 * The title, or something honest when there is not one yet.
	 */
	public function title(): string {
		$title = trim( $this->title );

		return '' !== $title ? $title : __( 'Untitled submission', 'dgl-platform' );
	}

	/**
	 * The content type as it reads mid-sentence: "a news item", "an event".
	 */
	public function type(): string {
		$label = trim( $this->type_label );

		return '' !== $label ? $label : __( 'submission', 'dgl-platform' );
	}

	/**
	 * The same label in lower case, for use inside a sentence.
	 *
	 * `mb_strtolower` rather than `strtolower` so a label with an accented
	 * first letter is not mangled.
	 */
	public function type_lower(): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $this->type() ) : strtolower( $this->type() );
	}

	/**
	 * The type with the right indefinite article in front of it: "an event",
	 * "a news item".
	 *
	 * Written as one substitution rather than assembled in the sentence,
	 * because "a" and "an" is not a choice every language has, and a translator
	 * needs to be able to replace the whole phrase.
	 *
	 * The vowel test is enough here: the five labels are fixed and none of them
	 * is a "a university" or "an hour" case. If a sixth content type ever
	 * arrives with one, this is where to catch it.
	 */
	public function type_with_article(): string {
		$type = $this->type_lower();

		$article = in_array( substr( $type, 0, 1 ), [ 'a', 'e', 'i', 'o', 'u' ], true )
			? __( 'an', 'dgl-platform' )
			: __( 'a', 'dgl-platform' );

		return $article . ' ' . $type;
	}

	public function org(): string {
		$name = trim( $this->org_name );

		return '' !== $name ? $name : __( 'A member organisation', 'dgl-platform' );
	}

	public function actor(): string {
		$name = trim( $this->actor_name );

		return '' !== $name ? $name : __( 'The DGLP team', 'dgl-platform' );
	}

	public function site(): string {
		$name = trim( $this->site_name );

		return '' !== $name ? $name : __( 'the Doing Good Leeds Partnership', 'dgl-platform' );
	}

	/**
	 * Where to send a member for this item. The dashboard, never the public
	 * page, because the public page may not exist yet.
	 */
	public function member_link(): string {
		return trim( $this->item_url );
	}

	/**
	 * Where to send a moderator. The review screen, falling back to the queue.
	 */
	public function review_link(): string {
		$url = trim( $this->review_url );

		return '' !== $url ? $url : trim( $this->queue_url );
	}

	/**
	 * The live page, falling back to the dashboard when there is not one.
	 */
	public function public_link(): string {
		$url = trim( $this->public_url );

		return '' !== $url ? $url : $this->member_link();
	}
}
