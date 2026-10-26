<?php
/**
 * `wp dgl media`: find and bring in the files the site takes from other sites.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class MediaCommand {

	public static function register(): void {
		WP_CLI::add_command( 'dgl media', self::class );
	}

	/**
	 * Count every file the site's words take from another site.
	 *
	 * Walks every post type's words (stories, events, pages, menus' content
	 * blocks, synced patterns), plus post meta and options, which are only
	 * counted. Nothing is written.
	 *
	 * ## OPTIONS
	 *
	 * [--detail]
	 * : List each reference: post, type, use, address.
	 *
	 * [--host=<host>]
	 * : Only this host, with --detail.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl media audit
	 *     wp dgl media audit --detail --host=doinggoodleeds.org.uk
	 *
	 * @when after_wp_load
	 */
	public function audit( array $args, array $assoc ): void {
		global $wpdb;

		$detail = isset( $assoc['detail'] );
		$only   = strtolower( (string) ( $assoc['host'] ?? '' ) );
		$hosts  = [];
		$rows   = [];
		$posts  = 0;
		$types  = [];

		foreach ( MediaLocaliser::walk() as $hit ) {
			++$posts;
			$type           = (string) $hit['post']->post_type;
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;

			foreach ( $hit['refs'] as $ref ) {
				$h = $ref['host'];

				$hosts[ $h ] ??= [ 'host' => $h, 'embeds' => 0, 'links' => 0, 'posts' => [], 'action' => '' ];
				++$hosts[ $h ][ 'embed' === $ref['kind'] ? 'embeds' : 'links' ];
				$hosts[ $h ]['posts'][ (int) $hit['post']->ID ] = true;

				if ( $detail && ( '' === $only || $only === $h ) ) {
					$rows[] = [ 'post' => (int) $hit['post']->ID, 'type' => $type, 'use' => $ref['kind'], 'copy' => MediaLocaliser::wanted( $ref ) ? 'yes' : 'no', 'url' => mb_substr( $ref['url'], 0, 110 ) ];
				}
			}
		}

		$table = [];

		foreach ( $hosts as $h => $row ) {
			$old     = in_array( $h, MediaLocaliser::OLD_HOSTS, true );
			$table[] = [
				'host'   => $h,
				'old'    => $old ? 'old site' : '',
				'embeds' => $row['embeds'],
				'links'  => $row['links'],
				'posts'  => count( $row['posts'] ),
				'copied' => $old ? 'all' : ( $row['embeds'] > 0 ? 'embeds; links listed' : 'listed only' ),
			];
		}

		usort( $table, static fn( array $a, array $b ): int => ( $b['embeds'] + $b['links'] ) <=> ( $a['embeds'] + $a['links'] ) );

		if ( $detail && [] !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'post', 'type', 'use', 'copy', 'url' ] );
		}

		if ( [] !== $table ) {
			\WP_CLI\Utils\format_items( 'table', $table, [ 'host', 'old', 'embeds', 'links', 'posts', 'copied' ] );
		}

		arsort( $types );
		WP_CLI::line( 'Posts with references, by type: ' . implode( ', ', array_map( static fn( $t, $n ): string => $t . ' ' . $n, array_keys( $types ), $types ) ) );

		$like = '%/wp-content/uploads/%';
		$own  = MediaLocaliser::own_hosts();
		$not  = implode( ' AND ', array_map( static fn( string $h ): string => $wpdb->prepare( 'meta_value NOT LIKE %s', '%//' . $wpdb->esc_like( $h ) . '/%' ), $own ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_value REGEXP 'https?:' AND {$not} GROUP BY meta_key ORDER BY n DESC LIMIT 20", $like ), ARRAY_A );
		$optn = str_replace( 'meta_value', 'option_value', $not );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$opts = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_value REGEXP 'https?:' AND {$optn} LIMIT 30", $like ) );

		WP_CLI::line( 'Post meta holding another site\'s uploads (counted, not changed): ' . ( [] === $meta ? 'none' : implode( ', ', array_map( static fn( array $r ): string => $r['meta_key'] . ' ' . $r['n'], $meta ) ) ) );
		WP_CLI::line( 'Options holding another site\'s uploads (counted, not changed): ' . ( [] === $opts ? 'none' : implode( ', ', $opts ) ) );

		$embeds = array_sum( array_column( $table, 'embeds' ) );
		$links  = array_sum( array_column( $table, 'links' ) );

		WP_CLI::success( sprintf( '%d reference(s) in %d post(s) across %d host(s): %d shown on the page, %d linked.', $embeds + $links, $posts, count( $table ), $embeds, $links ) );
	}

	/**
	 * Copy the files the site takes from other sites into the media library
	 * and point the words at the copies.
	 *
	 * Copied: every file a page shows, from any site, and every file linked
	 * on one of the old sites. Links to documents on other organisations'
	 * sites are listed and left. Each file is copied once, full size, with
	 * its alt text; a later run skips what is done. Dry run unless --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Copy and rewrite.
	 *
	 * [--post=<id>]
	 * : Only this post.
	 *
	 * [--limit=<n>]
	 * : Stop after this many posts, to keep a run short. Run again for the rest.
	 *
	 * [--feature-news]
	 * : A news item with no picture of its own takes the first picture in its words.
	 *
	 * [--actor=<id>]
	 * : User to record the change against. Default 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl media localise
	 *     wp dgl media localise --apply --limit=25 --feature-news
	 *
	 * @when after_wp_load
	 */
	public function localise( array $args, array $assoc ): void {
		$apply   = isset( $assoc['apply'] );
		$feature = isset( $assoc['feature-news'] );
		$limit   = (int) ( $assoc['limit'] ?? 0 );
		$actor   = (int) ( $assoc['actor'] ?? 0 );
		$only    = (int) ( $assoc['post'] ?? 0 );
		$rows    = [];
		$done    = 0;
		$tally   = [ 'copied' => 0, 'reused' => 0, 'failed' => 0, 'left' => 0, 'would' => 0, 'featured' => 0, 'no_alt' => 0, 'posts' => 0 ];

		foreach ( MediaLocaliser::walk( $only ) as $hit ) {
			// Only links to other organisations' documents: nothing to copy.
			if ( [] === array_filter( $hit['refs'], [ MediaLocaliser::class, 'wanted' ] ) ) {
				$tally['left'] += count( $hit['refs'] );
				continue;
			}

			if ( $limit > 0 && $done >= $limit ) {
				break;
			}

			++$done;
			$result = MediaLocaliser::localise_post( $hit['post'], $hit['refs'], $apply, $feature, $actor );

			if ( '' !== $result['error'] ) {
				WP_CLI::warning( sprintf( 'Post %d not saved: %s', (int) $hit['post']->ID, $result['error'] ) );
			}

			$tally['posts'] += $result['changed'] ? 1 : 0;
			$tally['featured'] += 0 !== $result['featured'] ? 1 : 0;
			$tally['no_alt'] += $result['no_alt'];

			foreach ( $result['files'] as $file ) {
				$how = $file['how'];
				$key = match ( true ) {
					str_starts_with( $how, 'FAILED' ) => 'failed',
					str_starts_with( $how, 'left' )   => 'left',
					'would copy' === $how             => 'would',
					str_starts_with( $how, 'copied from' ), 'copied' === $how => 'copied',
					default                           => 'reused',
				};
				++$tally[ $key ];

				$rows[] = [
					'post' => (int) $hit['post']->ID,
					'type' => (string) $hit['post']->post_type,
					'use'  => $file['kind'],
					'how'  => mb_substr( $how, 0, 60 ),
					'url'  => mb_substr( $file['url'], 0, 90 ),
				];
			}
		}

		if ( [] !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'post', 'type', 'use', 'how', 'url' ] );
		}

		WP_CLI::success( sprintf(
			'%s: %d post(s) looked at, %d rewritten. Files: %d copied, %d already here, %d would copy, %d failed, %d links to other sites left. News pictures set: %d. Copied pictures still without alt text: %d.',
			$apply ? 'Applied' : 'Dry run, nothing written',
			$done,
			$tally['posts'],
			$tally['copied'],
			$tally['reused'],
			$tally['would'],
			$tally['failed'],
			$tally['left'],
			$tally['featured'],
			$tally['no_alt']
		) );
	}

	/**
	 * Fill empty alt text on the site's own pictures in every post's words,
	 * from each picture's alt text in the media library. Never overwrites
	 * alt text already written. Dry run unless --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the alt text in.
	 *
	 * [--actor=<id>]
	 * : User to record the change against. Default 0.
	 *
	 * @when after_wp_load
	 */
	public function alts( array $args, array $assoc ): void {
		$apply   = isset( $assoc['apply'] );
		$actor   = (int) ( $assoc['actor'] ?? 0 );
		$filled  = 0;
		$posts   = 0;
		$missing = [];

		foreach ( MediaLocaliser::with_images() as $post ) {
			$result = MediaLocaliser::fill_alts( $post, $apply, $actor );

			if ( '' !== $result['error'] ) {
				WP_CLI::warning( sprintf( 'Post %d not saved: %s', (int) $post->ID, $result['error'] ) );
			}

			if ( $result['filled'] > 0 ) {
				++$posts;
				$filled += $result['filled'];
			}

			foreach ( $result['missing'] as $id ) {
				$missing[ $id ] = true;
			}
		}

		WP_CLI::success( sprintf(
			'%s: %d picture(s) in %d post(s) %s their alt text from the library. Pictures shown in posts whose library entry has no alt text either: %d%s',
			$apply ? 'Applied' : 'Dry run, nothing written',
			$filled,
			$posts,
			$apply ? 'given' : 'would take',
			count( $missing ),
			[] === $missing ? '.' : ' (ids ' . implode( ',', array_slice( array_keys( $missing ), 0, 60 ) ) . ( count( $missing ) > 60 ? ', ...' : '' ) . ').'
		) );
	}
}
