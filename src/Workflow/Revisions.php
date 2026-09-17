<?php
/**
 * Edits to published content, held back until somebody has read them.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

use DGL\Index\Sync;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use DGL\Taxonomies;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The answer to "they submitted something clean and then edited it".
 *
 * An edit to a published item never touches the published item. It is written
 * to a separate `dgl_revision` post hanging off the live one, and it reaches
 * the public only when a moderator approves it. So the attack is closed at
 * source: the inappropriate text sits in the queue, not on the site.
 *
 * The live version stays up the whole time. A member fixing a typo does not
 * take their own event off the site for three days, and nothing is gained by
 * making them, because the public never sees the pending edit either way.
 *
 * One revision per item, ever. A second "edit" reopens the one that already
 * exists rather than creating a rival, because two pending edits to the same
 * item is a question with no good answer: whichever a moderator approves
 * second silently discards the other one's work.
 */
final class Revisions {

	/**
	 * Wired at priority 5, ahead of the mailer, so the edit has already been
	 * applied by the time anybody is told it was.
	 */
	public static function init(): void {
		add_action( 'dgl_item_transitioned', [ self::class, 'on_transition' ], 5, 4 );
		add_action( 'before_delete_post', [ self::class, 'on_parent_deleted' ], 10, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Finding
	 * ------------------------------------------------------------------ */

	public static function is_revision( ?WP_Post $post ): bool {
		return $post instanceof WP_Post && PostTypes::REVISION === $post->post_type;
	}

	/**
	 * The live item an edit would replace, or 0.
	 */
	public static function target( int $revision_id ): int {
		$post = get_post( $revision_id );

		if ( ! self::is_revision( $post ) ) {
			return 0;
		}

		/*
		 * `post_parent` is the link the database enforces; the meta key is a
		 * second copy for anything reading rows rather than posts. Parent wins
		 * where they disagree, because that is the one WordPress maintains.
		 */
		$parent = (int) $post->post_parent;

		return $parent > 0 ? $parent : (int) get_post_meta( $revision_id, Meta::REVISION_TARGET, true );
	}

	/**
	 * The open edit against an item, whatever state it is in, or null.
	 *
	 * Applied and rejected edits are kept as a record but are not open, so they
	 * never come back as the thing a member is editing.
	 */
	public static function open_for( int $item_id ): ?WP_Post {
		$found = get_posts(
			[
				'post_type'        => PostTypes::REVISION,
				'post_parent'      => $item_id,
				'post_status'      => [ Statuses::DRAFT, Statuses::CHANGES, Statuses::PENDING ],
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			]
		);

		return $found[0] ?? null;
	}

	/**
	 * Every edit ever made against an item, newest first.
	 *
	 * @return int[] Revision post IDs.
	 */
	public static function all_for( int $item_id ): array {
		/*
		 * Every status by name, never `any`. WP_Query's `any` quietly drops
		 * statuses registered with `exclude_from_search`, which is most of
		 * this plugin's. An approved edit is archived, so `any` found nothing
		 * and an item's history went blank the moment its edit was approved.
		 */
		$found = get_posts(
			[
				'post_type'      => PostTypes::REVISION,
				'post_parent'    => $item_id,
				'post_status'    => Statuses::all(),
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return array_map( 'intval', $found );
	}

	/**
	 * One item's history, with its edits folded in.
	 *
	 * The audit trail records an edit against the edit, which is correct: the
	 * thing that was approved or refused was the edit. But a member looking at
	 * their own listing wants one timeline, not two, and "Nothing yet" on an
	 * item they sent an edit for an hour ago reads as lost work.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function history_for( int $item_id ): array {
		$entries = \DGL\Audit\Log::for_object( 'item', $item_id );

		foreach ( self::all_for( $item_id ) as $revision_id ) {
			foreach ( \DGL\Audit\Log::for_object( 'revision', $revision_id ) as $entry ) {
				$entry['is_edit'] = true;
				$entries[]        = $entry;
			}
		}

		usort(
			$entries,
			static fn( array $a, array $b ): int => strcmp( (string) ( $a['logged_at'] ?? '' ), (string) ( $b['logged_at'] ?? '' ) )
		);

		return $entries;
	}

	/**
	 * The type of content an edit is an edit to.
	 *
	 * A revision carries no field schema of its own. Everything that renders,
	 * validates or compares one has to ask its parent what shape it is.
	 */
	public static function type_of( int $revision_id ): string {
		$parent = self::target( $revision_id );

		return $parent > 0 ? (string) get_post_type( $parent ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Opening
	 * ------------------------------------------------------------------ */

	/**
	 * Start, or reopen, an edit to a published item.
	 *
	 * @return int|WP_Error The revision's post ID.
	 */
	public static function open( int $item_id, int $user_id ) {
		$item = get_post( $item_id );

		if ( ! $item instanceof WP_Post || ! PostTypes::is_submittable( $item->post_type ) ) {
			return new WP_Error( 'dgl_not_an_item', __( 'That is not a submission.', 'dgl-platform' ) );
		}

		if ( ! self::needs_revision( (string) $item->post_status ) ) {
			return new WP_Error(
				'dgl_edit_in_place',
				__( 'That one is edited directly. Only content that is already on the site goes through a review.', 'dgl-platform' )
			);
		}

		$existing = self::open_for( $item_id );

		if ( $existing instanceof WP_Post ) {
			if ( Statuses::PENDING === $existing->post_status ) {
				return new WP_Error(
					'dgl_revision_pending',
					__( 'Your last edit to this is still with the review team, so it is locked until they have read it.', 'dgl-platform' )
				);
			}

			return (int) $existing->ID;
		}

		$revision_id = wp_insert_post(
			[
				'post_type'    => PostTypes::REVISION,
				'post_status'  => Statuses::DRAFT,
				'post_parent'  => $item_id,
				'post_author'  => $user_id,
				'post_title'   => $item->post_title,
				'post_content' => $item->post_content,
			],
			true
		);

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		$revision_id = (int) $revision_id;

		update_post_meta( $revision_id, Meta::REVISION_TARGET, $item_id );
		update_post_meta( $revision_id, Meta::ITEM_ORG, (int) get_post_meta( $item_id, Meta::ITEM_ORG, true ) );

		self::copy_content( $item_id, $revision_id, (string) $item->post_type );

		// The parent's row now has an edit against it, which the list screens show.
		Sync::sync( $item_id );

		return $revision_id;
	}

	/**
	 * Whether editing an item in this state has to go through review.
	 *
	 * Drafts, change requests and rejections are edited in place: nothing about
	 * them is on the site, so there is nothing to protect. An expired item is
	 * included because its old version is still readable on its own page.
	 */
	public static function needs_revision( string $status ): bool {
		return in_array( $status, [ Statuses::LIVE, Statuses::EXPIRED ], true );
	}

	/* ---------------------------------------------------------------------
	 * Applying
	 * ------------------------------------------------------------------ */

	/**
	 * React to a revision's own transition.
	 *
	 * @param int    $post_id  The thing that moved. Only revisions are handled.
	 * @param Plan   $plan     What happened.
	 * @param int    $actor_id Who did it.
	 * @param string $note     Their message, if any.
	 */
	public static function on_transition( int $post_id, Plan $plan, int $actor_id, string $note = '' ): void {
		$post = get_post( $post_id );

		if ( ! self::is_revision( $post ) ) {
			return;
		}

		if ( $plan->publishes() ) {
			self::apply( $post_id );
			return;
		}

		/*
		 * Everything else leaves the edit where it is. A change request keeps
		 * it so the member can fix it; a rejection keeps it as the record of
		 * what was refused. Neither touches the live version, which is the
		 * whole point.
		 */
		Sync::sync( self::target( $post_id ) );
	}

	/**
	 * Write an approved edit onto the live item.
	 *
	 * The live item's status is deliberately left alone. An edit approved
	 * against an expired listing does not resurrect it, and an edit approved
	 * against a live one does not republish it, because it never came down.
	 *
	 * @return true|WP_Error
	 */
	public static function apply( int $revision_id ) {
		$parent_id = self::target( $revision_id );
		$parent    = $parent_id > 0 ? get_post( $parent_id ) : null;

		if ( ! $parent instanceof WP_Post ) {
			return new WP_Error( 'dgl_no_target', __( 'The item this edit belonged to has gone.', 'dgl-platform' ) );
		}

		self::copy_content( $revision_id, $parent_id, (string) $parent->post_type );

		/*
		 * `approved_at` is not restamped. It drives the digest, and an edit is
		 * not a reason to put an item people have already been emailed about
		 * back in front of them.
		 */
		self::recompute_expiry( $parent_id, (string) $parent->post_type );

		/*
		 * The edit is kept rather than deleted, at a status that is out of the
		 * queue and out of every live query, so the history of what changed
		 * survives. `archived` is used rather than `publish` on purpose: a
		 * published revision would be picked up by the expiry sweep and by
		 * anything else that means "live item".
		 */
		/*
		 * Flagged as ours. This is the workflow resolving an edit it just
		 * applied, not somebody changing a status by hand, and the audit guard
		 * would otherwise record every approved edit as having gone round the
		 * side of the workflow.
		 */
		Transition::$in_progress = true;

		wp_update_post(
			[
				'ID'          => $revision_id,
				'post_status' => Statuses::ARCHIVED,
			]
		);

		Transition::$in_progress = false;

		Sync::sync( $revision_id );
		Sync::sync( $parent_id );

		/**
		 * Fires once an edit has been written onto the item it replaced.
		 *
		 * @param int $parent_id   The live item, now carrying the edit.
		 * @param int $revision_id The edit that was applied.
		 */
		do_action( 'dgl_revision_applied', $parent_id, $revision_id );

		return true;
	}

	/**
	 * Throw an edit away without applying it.
	 *
	 * Used when a member abandons their own edit, which is theirs to do. A
	 * moderator refusing one rejects it instead, so the refusal leaves a record.
	 */
	public static function discard( int $revision_id ): bool {
		$parent_id = self::target( $revision_id );

		$deleted = wp_delete_post( $revision_id, true );

		if ( $parent_id > 0 ) {
			Sync::sync( $parent_id );
		}

		return false !== $deleted && null !== $deleted;
	}

	/**
	 * An item being deleted takes its edits with it.
	 *
	 * Without this, a revision outlives its parent and becomes a row in the
	 * moderation queue pointing at nothing, which a reviewer cannot act on and
	 * cannot clear.
	 */
	public static function on_parent_deleted( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! PostTypes::is_submittable( $post->post_type ) ) {
			return;
		}

		// Named statuses for the same reason as `all_for()`: `any` would leave
		// the archived and rejected edits behind, pointing at nothing.
		$children = get_posts(
			[
				'post_type'      => PostTypes::REVISION,
				'post_parent'    => $post_id,
				'post_status'    => Statuses::all(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		foreach ( $children as $child_id ) {
			wp_delete_post( (int) $child_id, true );
		}
	}

	/* ---------------------------------------------------------------------
	 * Comparing
	 * ------------------------------------------------------------------ */

	/**
	 * What this edit would change, field by field.
	 *
	 * This is the moderator's whole job on a revision. Reading a form twice and
	 * spotting the one altered sentence is not review, it is proofreading, and
	 * at volume nobody does it reliably. So the screen is handed the difference
	 * rather than the document.
	 *
	 * @return array<int, array{key: string, label: string, field: ?Field, before: mixed, after: mixed}>
	 */
	public static function changed_fields( int $revision_id ): array {
		$parent_id = self::target( $revision_id );
		$post_type = self::type_of( $revision_id );

		if ( $parent_id <= 0 || '' === $post_type ) {
			return [];
		}

		$changes = [];

		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			$before = self::field_value( $parent_id, $field );
			$after  = self::field_value( $revision_id, $field );

			if ( self::same( $before, $after, $field ) ) {
				continue;
			}

			$changes[] = [
				'key'    => $field->key,
				'label'  => $field->label,
				// Carried so the screen renders each side the way that field is
				// normally shown: a date as a date, money as money, an image as
				// a thumbnail rather than an attachment ID.
				'field'  => $field,
				'before' => $before,
				'after'  => $after,
			];
		}

		$before_topics = self::topic_names( $parent_id );
		$after_topics  = self::topic_names( $revision_id );

		if ( $before_topics !== $after_topics ) {
			$changes[] = [
				'key'    => 'dgl_topics',
				'label'  => __( 'Topics', 'dgl-platform' ),
				// Topics are a taxonomy, not a schema field, so there is no
				// Field to render them with. The screen falls back to text.
				'field'  => null,
				'before' => implode( ', ', $before_topics ),
				'after'  => implode( ', ', $after_topics ),
			];
		}

		return $changes;
	}

	/**
	 * Whether an edit actually alters anything.
	 *
	 * A member who opens an edit, changes their mind and submits it unchanged
	 * should not consume a moderator's attention.
	 */
	public static function is_empty( int $revision_id ): bool {
		return [] === self::changed_fields( $revision_id );
	}

	/**
	 * Loose comparison, because the two sides come from different places.
	 *
	 * A value typed into a form and the same value read back out of the
	 * database differ in type all the time: "10" against 10, "" against null.
	 * Comparing strictly would report a change on every field of every edit.
	 */
	private static function same( mixed $before, mixed $after, ?Field $field = null ): bool {
		if ( null !== $field && Field::RICHTEXT === $field->type ) {
			return self::normalise_html( (string) $before ) === self::normalise_html( (string) $after );
		}

		// List-valued fields: no row yet and an empty list are the same answer.
		if ( null !== $field && in_array( $field->type, [ Field::CHOICES, Field::REPEAT ], true ) ) {
			return ( '' === $before || null === $before ? [] : (array) $before ) === ( '' === $after || null === $after ? [] : (array) $after );
		}

		if ( is_scalar( $before ) || null === $before ) {
			return (string) $before === (string) $after;
		}

		return $before === $after;
	}

	/**
	 * Rich text with the editor's own noise taken out.
	 *
	 * The visual editor reflows whitespace and line endings every time it
	 * serialises, so a description nobody touched comes back byte-different.
	 * Reported as a change, that trains moderators to skim the list, which
	 * defeats the point of having one.
	 *
	 * Tags are deliberately kept. A description losing its links or its bold is
	 * a real change and has to show as one.
	 */
	private static function normalise_html( string $html ): string {
		$html = str_replace( [ "\r\n", "\r" ], "\n", $html );
		$html = (string) preg_replace( '/\s+/u', ' ', $html );

		return trim( $html );
	}

	private static function field_value( int $post_id, Field $field ): mixed {
		$post = get_post( $post_id );

		return match ( $field->key ) {
			'title' => null !== $post ? $post->post_title : '',
			'body'  => null !== $post ? $post->post_content : '',
			default => metadata_exists( 'post', $post_id, $field->meta_key() )
				? get_post_meta( $post_id, $field->meta_key(), true )
				: '',
		};
	}

	/* ---------------------------------------------------------------------
	 * Copying
	 * ------------------------------------------------------------------ */

	/**
	 * Copy every field of one post onto another.
	 *
	 * Driven by the field registry rather than by "copy all meta", so plugin
	 * bookkeeping — the organisation, the submission stamps, the fixture flags
	 * the test suite uses — never travels with the content.
	 */
	private static function copy_content( int $from, int $to, string $post_type ): void {
		$source = get_post( $from );

		if ( ! $source instanceof WP_Post ) {
			return;
		}

		wp_update_post(
			[
				'ID'           => $to,
				'post_title'   => $source->post_title,
				'post_content' => $source->post_content,
			]
		);

		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			if ( in_array( $field->key, [ 'title', 'body' ], true ) ) {
				continue;
			}

			$key = $field->meta_key();

			// A field cleared on one side has to be cleared on the other, not
			// left showing the value it used to have.
			if ( ! metadata_exists( 'post', $from, $key ) ) {
				delete_post_meta( $to, $key );
				continue;
			}

			update_post_meta( $to, $key, get_post_meta( $from, $key, true ) );
		}

		$thumbnail = (int) get_post_thumbnail_id( $from );

		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $to, $thumbnail );
		} else {
			delete_post_thumbnail( $to );
		}

		wp_set_object_terms( $to, self::topic_ids( $from ), Taxonomies::TOPIC, false );
	}

	/**
	 * @return int[]
	 */
	private static function topic_ids( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'ids' ] );

		return is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
	}

	/**
	 * @return string[] Sorted, so a reordering is not reported as a change.
	 */
	private static function topic_names( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'names' ] );

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$names = array_map( 'strval', $terms );
		sort( $names );

		return $names;
	}

	/**
	 * Rebuild an item's expiry from whatever dates it now carries.
	 *
	 * The same job {@see Transition} does on its own transitions, needed here
	 * because an approved edit can move an event's end date and the listing has
	 * to come off on the new one.
	 */
	private static function recompute_expiry( int $post_id, string $post_type ): void {
		// Series is the one writer of the expiry and next-occurrence stamps.
		\DGL\Events\Series::stamp( $post_id, $post_type );
	}
}
