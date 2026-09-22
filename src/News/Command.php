<?php
/**
 * `wp dgl news`: bring the old site's posts over.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\News;

use DGL\Org\Org;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl news', self::class );
	}

	/**
	 * Convert the old site's published posts into news items, in place.
	 *
	 * ## OPTIONS
	 *
	 * --org=<id>
	 * : The organisation that owns every story not claimed by --owner.
	 *
	 * [--owner=<pairs>]
	 * : Old category slug to organisation id, comma separated. A story's first
	 *   category with an owner here wins. Example: forumcentral:12,val:13
	 *
	 * [--limit=<n>]
	 * : Stop after this many, oldest first.
	 *
	 * [--dry-run]
	 * : Report what would happen and change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl news import --org=12 --dry-run
	 *     wp dgl news import --org=12 --owner=forumcentral:12,val:13
	 *
	 * @subcommand import
	 */
	public function import( array $args, array $assoc ): void {
		$default_org = (int) ( $assoc['org'] ?? 0 );
		$dry_run     = isset( $assoc['dry-run'] );
		$limit       = (int) ( $assoc['limit'] ?? 0 );
		$owners      = self::owners( (string) ( $assoc['owner'] ?? '' ) );

		if ( $default_org <= 0 || ! Org::exists( $default_org ) ) {
			WP_CLI::error( 'Give --org=<id>: an organisation that exists.' );
		}

		foreach ( $owners as $slug => $org_id ) {
			if ( ! Org::exists( $org_id ) ) {
				WP_CLI::error( sprintf( 'Owner for %s: organisation %d does not exist.', $slug, $org_id ) );
			}
		}

		$ids = LegacyImport::candidates( $limit );

		if ( [] === $ids ) {
			WP_CLI::success( 'No published posts of the old kind. Nothing to do.' );
			return;
		}

		$done     = 0;
		$failed   = 0;
		$by_topic = [];
		$by_org   = [];
		$no_topic = 0;
		$no_image = 0;

		foreach ( $ids as $post_id ) {
			$plan = $dry_run
				? LegacyImport::plan( $post_id, $default_org, $owners )
				: LegacyImport::convert( $post_id, $default_org, $owners, get_current_user_id() );

			if ( is_wp_error( $plan ) ) {
				++$failed;
				WP_CLI::warning( sprintf( '#%d: %s', $post_id, $plan->get_error_message() ) );
				continue;
			}

			++$done;

			if ( [] === $plan['topics'] ) {
				++$no_topic;
			}

			if ( $plan['image'] <= 0 ) {
				++$no_image;
			}

			foreach ( $plan['topics'] as $slug ) {
				$by_topic[ $slug ] = ( $by_topic[ $slug ] ?? 0 ) + 1;
			}

			$by_org[ $plan['org'] ] = ( $by_org[ $plan['org'] ] ?? 0 ) + 1;
		}

		arsort( $by_topic );

		WP_CLI::line( ( $dry_run ? 'Would convert ' : 'Converted ' ) . $done . ' post(s); ' . $failed . ' skipped.' );
		WP_CLI::line( 'Without a topic: ' . $no_topic . '. Without a picture: ' . $no_image . '.' );

		foreach ( $by_org as $org_id => $count ) {
			WP_CLI::line( sprintf( 'Owner %s (#%d): %d', get_the_title( $org_id ), $org_id, $count ) );
		}

		foreach ( $by_topic as $slug => $count ) {
			WP_CLI::line( sprintf( '  %-36s %d', $slug, $count ) );
		}

		if ( ! $dry_run ) {
			WP_CLI::success( 'Done. Old addresses redirect to the new ones.' );
		}
	}

	/** "slug:id,slug:id" to [slug => id]. */
	public static function owners( string $raw ): array {
		$out = [];

		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $pair ) {
			$bits = explode( ':', $pair, 2 );

			if ( 2 === count( $bits ) && '' !== trim( $bits[0] ) && (int) $bits[1] > 0 ) {
				$out[ sanitize_title( $bits[0] ) ] = (int) $bits[1];
			}
		}

		return $out;
	}

	/**
	 * Archive converted stories that were filed under old categories.
	 *
	 * The old site announced events as posts under its Events categories,
	 * with the date in the title. They are all past. This takes them off
	 * /news/ through the ordinary archive transition.
	 *
	 * ## OPTIONS
	 *
	 * --actor=<user-id>
	 * : The moderator or administrator doing it; the audit rows carry them.
	 *
	 * [--categories=<slugs>]
	 * : Old category slugs, comma separated. Default: events,events-2
	 *
	 * [--dry-run]
	 * : List what would be archived and change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl news archive-legacy --actor=1 --dry-run
	 *     wp dgl news archive-legacy --actor=1
	 *
	 * @subcommand archive-legacy
	 */
	public function archive_legacy( array $args, array $assoc ): void {
		$actor      = (int) ( $assoc['actor'] ?? 0 );
		$categories = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['categories'] ?? 'events,events-2' ) ) ) );
		$dry_run    = isset( $assoc['dry-run'] );

		if ( $actor <= 0 || ! get_userdata( $actor ) ) {
			WP_CLI::error( 'Give --actor=<user-id>: a moderator or administrator who exists.' );
		}

		if ( [] === $categories ) {
			WP_CLI::error( 'Give at least one old category slug.' );
		}

		$ids = LegacyImport::converted_in( $categories );

		if ( [] === $ids ) {
			WP_CLI::success( 'Nothing live from those categories. Nothing to do.' );
			return;
		}

		if ( $dry_run ) {
			foreach ( $ids as $post_id ) {
				WP_CLI::line( sprintf( '#%d  %s  (%s)', $post_id, get_the_title( $post_id ), get_the_date( 'Y-m-d', $post_id ) ) );
			}

			WP_CLI::line( 'Would archive ' . count( $ids ) . ' item(s).' );
			return;
		}

		wp_set_current_user( $actor );

		$result = LegacyImport::archive_in( $categories, $actor, __( 'An event announcement from the old site, now past.', 'dgl-platform' ) );

		foreach ( $result['failed'] as $post_id => $why ) {
			WP_CLI::warning( sprintf( '#%d: %s', $post_id, $why ) );
		}

		WP_CLI::success( sprintf( 'Archived %d item(s); %d failed.', count( $result['archived'] ), count( $result['failed'] ) ) );
	}

	/**
	 * List the live news items that have no topic, with their old categories.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl news topicless
	 *
	 * @subcommand topicless
	 */
	public function topicless( array $args, array $assoc ): void {
		$items = LegacyImport::topicless();

		if ( [] === $items ) {
			WP_CLI::success( 'Every live news item has a topic.' );
			return;
		}

		$by_cats = [];

		foreach ( $items as $post_id => $cats ) {
			WP_CLI::line( sprintf( '#%d  %s  (%s)  [%s]', $post_id, get_the_title( $post_id ), get_the_date( 'Y-m-d', $post_id ), [] === $cats ? 'not from the old site' : implode( ', ', $cats ) ) );
			$key             = [] === $cats ? '(none)' : implode( ', ', $cats );
			$by_cats[ $key ] = ( $by_cats[ $key ] ?? 0 ) + 1;
		}

		arsort( $by_cats );
		WP_CLI::line( '' );

		foreach ( $by_cats as $key => $count ) {
			WP_CLI::line( sprintf( '  %-40s %d', $key, $count ) );
		}

		WP_CLI::line( count( $items ) . ' live news item(s) without a topic.' );
	}

	/**
	 * Suggest topics for the live news items that have none, from their
	 * headline and summary. Prints the suggestions; applies them only with
	 * --apply, and only to items that still have no topic.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Set the suggested topics. Without it, a report only.
	 *
	 * [--actor=<user-id>]
	 * : Who is applying them; the audit rows carry them. Required with --apply.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl news suggest-topics
	 *     wp dgl news suggest-topics --apply --actor=1
	 *
	 * @subcommand suggest-topics
	 */
	public function suggest_topics( array $args, array $assoc ): void {
		$apply = isset( $assoc['apply'] );
		$actor = (int) ( $assoc['actor'] ?? 0 );

		if ( $apply && ( $actor <= 0 || ! get_userdata( $actor ) ) ) {
			WP_CLI::error( 'Give --actor=<user-id> with --apply.' );
		}

		$items = LegacyImport::topicless();

		if ( [] === $items ) {
			WP_CLI::success( 'Every live news item has a topic.' );
			return;
		}

		$names   = \DGL\Topics\Topics::all();
		$matched = 0;
		$applied = 0;
		$none    = [];

		foreach ( array_keys( $items ) as $post_id ) {
			$post = get_post( $post_id );
			$text = (string) $post->post_title . ' ' . (string) get_post_meta( $post_id, 'dgl_summary', true );
			$slugs = TopicSuggest::suggest( $text );

			if ( [] === $slugs ) {
				$none[] = $post_id;
				WP_CLI::line( sprintf( '#%d  %s', $post_id, $post->post_title ) );
				WP_CLI::line( '      -> no suggestion' );
				continue;
			}

			++$matched;
			WP_CLI::line( sprintf( '#%d  %s', $post_id, $post->post_title ) );
			WP_CLI::line( '      -> ' . implode( ', ', array_map( static fn( string $s ): string => $names[ $s ] ?? $s, $slugs ) ) );

			if ( ! $apply ) {
				continue;
			}

			$term_ids = [];

			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, \DGL\Taxonomies::TOPIC );

				if ( $term instanceof \WP_Term ) {
					$term_ids[] = (int) $term->term_id;
				}
			}

			if ( [] === $term_ids ) {
				continue;
			}

			wp_set_object_terms( $post_id, $term_ids, \DGL\Taxonomies::TOPIC, false );
			\DGL\Audit\Log::record(
				'topics_suggested',
				'item',
				$post_id,
				Org::for_item( $post_id ),
				sprintf(
					/* translators: %s: topic names. */
					__( 'Topics set from the headline: %s. Worth a check.', 'dgl-platform' ),
					implode( ', ', array_map( static fn( string $s ): string => $names[ $s ] ?? $s, $slugs ) )
				),
				[ 'topics' => $slugs ],
				$actor
			);
			++$applied;
		}

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '%d item(s): %d with a suggestion, %d without.', count( $items ), $matched, count( $none ) ) );

		if ( $apply ) {
			WP_CLI::success( sprintf( 'Applied to %d item(s).', $applied ) );
		} else {
			WP_CLI::line( 'Nothing changed. Add --apply --actor=<id> to set them.' );
		}
	}
}
