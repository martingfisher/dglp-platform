<?php
/**
 * WP-CLI: change one exact piece of text inside one post.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

use DGL\Audit\Log;
use DGL\Org\Org;
use DGL\PostTypes;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * The hosting tools edit pages and posts in place, but not theme content
 * blocks (the mega menu panels), the Customizer's Additional CSS or any
 * other post type, and a whole body passed on the command line is capped
 * at 1000 characters. This does the one small job those cannot: find an
 * exact piece of text in one post and swap it, refusing unless it occurs
 * exactly as many times as expected.
 */
final class ContentCommand {

	public static function register(): void {
		WP_CLI::add_command( 'dgl content', self::class );
	}

	/**
	 * Replace exact text inside one post's content.
	 *
	 * Dry run unless --apply is given. Case-sensitive, no patterns.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The post, page, content block or CSS post to change.
	 *
	 * [--search=<text>]
	 * : The exact text to find.
	 *
	 * [--from=<text>]
	 * : Instead of --search: the start of a span to replace, for example a
	 * block's opening comment. Use with --to.
	 *
	 * [--to=<text>]
	 * : The end of the span: the first occurrence after --from, included.
	 *
	 * [--replace=<text>]
	 * : What to put in its place. Leave out to delete the text.
	 *
	 * [--expect=<count>]
	 * : How many times the text must occur. Anything else is refused. Default 1.
	 *
	 * [--apply]
	 * : Write the change. Without it nothing is saved.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl content replace 1292 --search='"content":"Jobs","link":""' --replace='"content":"Jobs","link":"https://example.org/jobs/"'
	 *     wp dgl content replace 7226 --from='<!-- wp:greenshift-blocks/buttonbox {"id":"gsbp-99706a1"' --to='<!-- /wp:greenshift-blocks/buttonbox -->'
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function replace( array $args, array $assoc ): void {
		$post_id = (int) ( $args[0] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( 'No such post.' );
		}

		$plan = isset( $assoc['from'] )
			? self::plan_span(
				(string) $post->post_content,
				(string) $assoc['from'],
				(string) ( $assoc['to'] ?? '' ),
				(string) ( $assoc['replace'] ?? '' ),
				(int) ( $assoc['expect'] ?? 1 )
			)
			: self::plan(
				(string) $post->post_content,
				(string) ( $assoc['search'] ?? '' ),
				(string) ( $assoc['replace'] ?? '' ),
				(int) ( $assoc['expect'] ?? 1 )
			);

		WP_CLI::log( sprintf( '%s %d (%s): %d match(es), %d expected.', $post->post_type, $post->ID, $post->post_title, $plan['count'], $plan['expect'] ) );

		foreach ( $plan['context'] as $i => $snippet ) {
			WP_CLI::log( sprintf( '--- match %d ---', $i + 1 ) );
			WP_CLI::log( $snippet );
		}

		if ( '' !== $plan['error'] ) {
			WP_CLI::error( $plan['error'] );
		}

		if ( ! isset( $assoc['apply'] ) ) {
			WP_CLI::success( 'Dry run. Nothing was written. Add --apply to save.' );
			return;
		}

		// WP-CLI runs without a user, so the content filters would strip the
		// markup the blocks need (inline SVG, styles, iframes). The text has
		// been matched exactly above; it is saved as it stands.
		kses_remove_filters();

		$saved = wp_update_post(
			[
				'ID'           => $post->ID,
				'post_content' => wp_slash( $plan['result'] ),
			],
			true
		);

		if ( is_wp_error( $saved ) ) {
			WP_CLI::error( $saved->get_error_message() );
		}

		clean_post_cache( $post->ID );

		$stored = (string) get_post_field( 'post_content', $post->ID, 'raw' );

		if ( $stored !== $plan['result'] ) {
			WP_CLI::error( 'Saved, but the stored content differs from what was sent. Check the post and its revisions.' );
		}

		WP_CLI::success( sprintf( 'Replaced %d. Length %d to %d.', $plan['count'], strlen( (string) $post->post_content ), strlen( $stored ) ) );
	}

	/**
	 * Put a post's content back to one of its own revisions.
	 *
	 * Dry run unless --apply is given. The restore is itself saved as a new
	 * revision, so it can be undone the same way.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The post or page.
	 *
	 * --revision=<id>
	 * : The revision to go back to. Must belong to this post.
	 *
	 * [--apply]
	 * : Write the change. Without it nothing is saved.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl content restore 7226 --revision=10394
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function restore( array $args, array $assoc ): void {
		$post        = get_post( (int) ( $args[0] ?? 0 ) );
		$revision_id = (int) ( $assoc['revision'] ?? 0 );
		$revision    = wp_get_post_revision( $revision_id );

		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( 'No such post.' );
		}

		if ( ! $revision instanceof \WP_Post || (int) $revision->post_parent !== $post->ID ) {
			WP_CLI::error( 'No such revision of this post.' );
		}

		$now  = (string) $post->post_content;
		$then = (string) $revision->post_content;

		WP_CLI::log( sprintf( '%s %d (%s): content now %d characters; revision %d from %s GMT by user %d has %d.', $post->post_type, $post->ID, $post->post_title, strlen( $now ), $revision->ID, $revision->post_date_gmt, (int) $revision->post_author, strlen( $then ) ) );

		if ( $now === $then ) {
			WP_CLI::success( 'Already the same. Nothing to do.' );
			return;
		}

		if ( ! isset( $assoc['apply'] ) ) {
			WP_CLI::success( 'Dry run. Nothing was written. Add --apply to save.' );
			return;
		}

		// As in replace(): WP-CLI has no user, so the filters would strip markup.
		kses_remove_filters();

		$saved = wp_restore_post_revision( $revision->ID, [ 'post_content' ] );

		if ( ! $saved || is_wp_error( $saved ) ) {
			WP_CLI::error( is_wp_error( $saved ) ? $saved->get_error_message() : 'The restore was not saved.' );
		}

		clean_post_cache( $post->ID );

		if ( (string) get_post_field( 'post_content', $post->ID, 'raw' ) !== $then ) {
			WP_CLI::error( 'Saved, but the stored content differs from the revision. Check the post and its revisions.' );
		}

		WP_CLI::success( sprintf( 'Restored revision %d. Length %d to %d.', $revision->ID, strlen( $now ), strlen( $then ) ) );
	}

	/**
	 * What a replacement would do, without touching anything.
	 *
	 * @return array{count:int, expect:int, result:string, context:string[], error:string}
	 */
	public static function plan( string $content, string $search, string $replace, int $expect = 1 ): array {
		$expect = max( 1, $expect );
		$out    = [
			'count'   => 0,
			'expect'  => $expect,
			'result'  => $content,
			'context' => [],
			'error'   => '',
		];

		if ( '' === $search ) {
			$out['error'] = 'No --search text given.';
			return $out;
		}

		$offset = 0;

		while ( false !== ( $at = strpos( $content, $search, $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$out['count'];

			if ( count( $out['context'] ) < 5 ) {
				$start            = max( 0, $at - 80 );
				$out['context'][] = substr( $content, $start, $at - $start ) . '[[' . $search . ']]' . substr( $content, $at + strlen( $search ), 80 );
			}

			$offset = $at + strlen( $search );
		}

		if ( $out['count'] !== $expect ) {
			$out['error'] = sprintf( 'Found %d, expected %d. Nothing changed.', $out['count'], $expect );
			return $out;
		}

		$out['result'] = str_replace( $search, $replace, $content );

		return $out;
	}

	/**
	 * Like plan(), for a span: from each `$from` to the end of the first
	 * `$to` after it. Spans must not overlap; an unclosed one is refused.
	 *
	 * @return array{count:int, expect:int, result:string, context:string[], error:string}
	 */
	public static function plan_span( string $content, string $from, string $to, string $replace, int $expect = 1 ): array {
		$expect = max( 1, $expect );
		$out    = [
			'count'   => 0,
			'expect'  => $expect,
			'result'  => $content,
			'context' => [],
			'error'   => '',
		];

		if ( '' === $from || '' === $to ) {
			$out['error'] = 'Both --from and --to are needed.';
			return $out;
		}

		$spans  = [];
		$offset = 0;

		while ( false !== ( $start = strpos( $content, $from, $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$end = strpos( $content, $to, $start + strlen( $from ) );

			if ( false === $end ) {
				$out['error'] = 'A --from has no --to after it. Nothing changed.';
				return $out;
			}

			$end     = $end + strlen( $to );
			$spans[] = [ $start, $end ];
			$offset  = $end;
		}

		$out['count'] = count( $spans );

		foreach ( array_slice( $spans, 0, 5 ) as [ $start, $end ] ) {
			$length           = $end - $start;
			$shown            = $length > 400 ? substr( $content, $start, 200 ) . ' … ' . substr( $content, $end - 200, 200 ) : substr( $content, $start, $length );
			$out['context'][] = substr( $content, max( 0, $start - 80 ), min( 80, $start ) ) . '[[' . $shown . ']]' . substr( $content, $end, 80 ) . sprintf( ' (%d characters)', $length );
		}

		if ( $out['count'] !== $expect ) {
			$out['error'] = sprintf( 'Found %d, expected %d. Nothing changed.', $out['count'], $expect );
			return $out;
		}

		$result = $content;

		foreach ( array_reverse( $spans ) as [ $start, $end ] ) {
			$result = substr( $result, 0, $start ) . $replace . substr( $result, $end );
		}

		$out['result'] = $result;

		return $out;
	}
	/**
	 * Take the styling a paste from Word, Google Docs or the old theme left
	 * in stories, events, training, volunteering and old posts.
	 *
	 * Unwraps pasted spans and font tags, turns b and i into strong and em,
	 * keeps bold and italic a span's style gave, keeps text alignment and the
	 * site's own classes, drops pasted fonts, sizes, colours, spacing, Word's
	 * classes and data attributes, and removes empty spacer paragraphs.
	 * Pages and anything built with page-builder blocks are left alone.
	 * Each change is saved as a new revision, so it can be undone with
	 * `wp dgl content restore`. Dry run unless --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Save the tidied words.
	 *
	 * [--post=<id>]
	 * : Only this post. With it, the dry run prints the words before and after.
	 *
	 * [--limit=<n>]
	 * : Stop after this many changed posts.
	 *
	 * [--actor=<id>]
	 * : User to record the change against. Default 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl content tidy
	 *     wp dgl content tidy --post=6918
	 *     wp dgl content tidy --apply --limit=50 --actor=1
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function tidy( array $args, array $assoc ): void {
		$apply  = isset( $assoc['apply'] );
		$only   = (int) ( $assoc['post'] ?? 0 );
		$limit  = (int) ( $assoc['limit'] ?? 0 );
		$actor  = (int) ( $assoc['actor'] ?? 0 );
		$types  = [ PostTypes::NEWS, PostTypes::EVENT, PostTypes::TRAINING, PostTypes::VOLUNTEERING, 'post' ];
		$status = array_values( array_diff( array_keys( get_post_stati() ), [ 'trash', 'auto-draft', 'inherit' ] ) );
		$rows   = [];
		$sum    = [ 'spans' => 0, 'styles' => 0, 'attributes' => 0, 'bold_italic' => 0, 'spacers' => 0 ];
		$posts  = 0;
		$page   = 1;

		do {
			$batch = get_posts( [ 'post_type' => $types, 'post_status' => $status, 'posts_per_page' => 200, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'include' => $only > 0 ? [ $only ] : [], 'suppress_filters' => true ] );

			foreach ( $batch as $post ) {
				$before = (string) $post->post_content;

				if ( ! PasteTidy::needs_tidy( $before ) || str_contains( $before, 'wp:greenshift' ) || str_contains( $before, 'gspb_' ) ) {
					continue;
				}

				$result = PasteTidy::tidy( $before );

				if ( $result['html'] === rtrim( $before ) ) {
					continue;
				}

				if ( $limit > 0 && $posts >= $limit ) {
					break 2;
				}

				++$posts;

				foreach ( $result['changes'] as $k => $n ) {
					$sum[ $k ] += $n;
				}

				$rows[] = [
					'post'    => (int) $post->ID,
					'type'    => (string) $post->post_type,
					'title'   => mb_substr( (string) $post->post_title, 0, 50 ),
					'spans'   => $result['changes']['spans'],
					'styles'  => $result['changes']['styles'],
					'attrs'   => $result['changes']['attributes'],
					'b/i'     => $result['changes']['bold_italic'],
					'spacers' => $result['changes']['spacers'],
					'length'  => strlen( $before ) . ' to ' . strlen( $result['html'] ),
				];

				if ( $only > 0 && ! $apply ) {
					WP_CLI::line( "--- before\n" . $before . "\n--- after\n" . $result['html'] . "\n---" );
				}

				if ( ! $apply ) {
					continue;
				}

				kses_remove_filters();
				$saved = wp_update_post( [ 'ID' => (int) $post->ID, 'post_content' => wp_slash( $result['html'] ) ], true );
				kses_init_filters();

				if ( is_wp_error( $saved ) ) {
					WP_CLI::warning( sprintf( 'Post %d not saved: %s', (int) $post->ID, $saved->get_error_message() ) );
					continue;
				}

				clean_post_cache( (int) $post->ID );

				if ( (string) get_post_field( 'post_content', (int) $post->ID, 'raw' ) !== $result['html'] ) {
					WP_CLI::warning( sprintf( 'Post %d: saved, but the stored words differ from what was sent.', (int) $post->ID ) );
				}

				$submittable = PostTypes::is_submittable( (string) $post->post_type );

				Log::record( 'content_tidied', $submittable ? 'item' : 'page', (int) $post->ID, $submittable ? Org::for_item( (int) $post->ID ) : 0, 'Pasted styling taken out of the words; the previous version is a revision.', [ 'changes' => [ '', wp_json_encode( $result['changes'] ) ] ], $actor );
			}

			++$page;
		} while ( 200 === count( $batch ) && 0 === $only );

		if ( [] !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'post', 'type', 'title', 'spans', 'styles', 'attrs', 'b/i', 'spacers', 'length' ] );
		}

		WP_CLI::success( sprintf(
			'%s: %d post(s). Spans unwrapped %d, styles taken off %d, paste attributes %d, bold/italic kept as strong/em %d, spacer lines %d.',
			$apply ? 'Applied' : 'Dry run, nothing written',
			$posts,
			$sum['spans'],
			$sum['styles'],
			$sum['attributes'],
			$sum['bold_italic'],
			$sum['spacers']
		) );
	}
}
