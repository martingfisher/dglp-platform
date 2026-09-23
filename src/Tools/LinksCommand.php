<?php
/**
 * `wp dgl links`: take dead links out of the site's content.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

use DGL\Audit\Log;
use DGL\News\LegacyImport;
use DGL\Org\Org;
use DGL\PostTypes;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class LinksCommand {

	/** Forum Central's old hosting address. A story that came here keeps its link; the rest are unlinked. */
	public const DEAD_HOST = 'forumcentral.wordifysites.com';

	/** Joins the existing `dgl links` command (audit lives in Schema\LinksCommand). */
	public static function register(): void {
		WP_CLI::add_command( 'dgl links unlink', [ new self(), 'unlink' ] );
	}

	/**
	 * Unlink every address on a list, keeping the link text.
	 *
	 * The list is one address per line, fetched from a URL (the console cannot
	 * take a file). Links to Forum Central's old hosting address are pointed
	 * at the story here when it was migrated, and unlinked otherwise.
	 * Cloudflare email-protection links are never touched: they work.
	 *
	 * Dry run unless --apply. With --apply, every changed post is saved and
	 * gets an audit row naming each address.
	 *
	 * ## OPTIONS
	 *
	 * --from=<url>
	 * : Where to fetch the list.
	 *
	 * [--apply]
	 * : Write the changes.
	 *
	 * [--actor=<id>]
	 * : User to record the change against. Default 0.
	 *
	 * [--empty]
	 * : Also unlink anchors whose href is empty.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl links unlink --from=https://example.test/dead-links.txt
	 *     wp dgl links unlink --from=https://example.test/dead-links.txt --apply
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function unlink( array $args, array $assoc ): void {
		$from = (string) ( $assoc['from'] ?? '' );

		if ( '' === $from ) {
			WP_CLI::error( 'Give --from=<url>, the list of dead addresses, one per line.' );
		}

		$response = wp_remote_get( $from, [ 'timeout' => 20 ] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::error( 'Could not fetch the list: ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ) ) );
		}

		$dead = [];

		foreach ( preg_split( '/\R/', (string) wp_remote_retrieve_body( $response ) ) ?: [] as $line ) {
			$line = trim( html_entity_decode( $line, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( '' !== $line && ! str_starts_with( $line, '#' ) && ! str_contains( $line, '/cdn-cgi/' ) ) {
				$dead[ $line ] = true;
			}
		}

		if ( [] === $dead ) {
			WP_CLI::error( 'The list is empty.' );
		}

		$apply  = isset( $assoc['apply'] );
		$empty  = isset( $assoc['empty'] );
		$actor  = (int) ( $assoc['actor'] ?? 0 );
		$decide = static function ( string $href ) use ( $dead, $empty ): ?string {
			if ( '' === $href ) {
				return $empty ? '' : null;
			}

			// The old host no longer resolves, so every link to it is dead
			// whether or not the list happens to name it.
			if ( self::DEAD_HOST === DeadLinks::host_of( $href ) ) {
				$slug = DeadLinks::slug_of( $href );
				$live = '' !== $slug ? LegacyImport::live_by_slug( $slug ) : null;

				return null !== $live ? (string) get_permalink( $live ) : '';
			}

			return isset( $dead[ $href ] ) ? '' : null;
		};

		$types    = [ PostTypes::NEWS, PostTypes::EVENT, PostTypes::TRAINING, PostTypes::VOLUNTEERING, 'page', 'post' ];
		$statuses = array_values( array_diff( array_keys( get_post_stati() ), [ 'trash', 'auto-draft', 'inherit' ] ) );
		$rows     = [];
		$posts    = 0;
		$links    = 0;
		$page     = 1;

		do {
			$batch = get_posts(
				[
					'post_type'        => $types,
					'post_status'      => $statuses,
					'posts_per_page'   => 200,
					'paged'            => $page,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
				]
			);

			foreach ( $batch as $post ) {
				if ( ! str_contains( (string) $post->post_content, '<a' ) && ! str_contains( (string) $post->post_content, '<A' ) ) {
					continue;
				}

				$result = DeadLinks::strip( (string) $post->post_content, $decide );

				if ( [] === $result['changes'] ) {
					continue;
				}

				++$posts;
				$links += count( $result['changes'] );

				foreach ( $result['changes'] as $change ) {
					$rows[] = [
						'post'   => (int) $post->ID,
						'type'   => (string) $post->post_type,
						'title'  => mb_substr( (string) $post->post_title, 0, 50 ),
						'action' => $change['action'],
						'href'   => mb_substr( $change['href'], 0, 70 ),
						'to'     => mb_substr( $change['to'], 0, 60 ),
					];
				}

				if ( $apply ) {
					$saved = wp_update_post( [ 'ID' => (int) $post->ID, 'post_content' => wp_slash( $result['html'] ) ], true );

					if ( is_wp_error( $saved ) ) {
						WP_CLI::warning( sprintf( 'Post %d not saved: %s', (int) $post->ID, $saved->get_error_message() ) );
						continue;
					}

					$changes = [];

					foreach ( $result['changes'] as $i => $change ) {
						$changes[ 'link_' . $i ] = [ $change['href'], 'rewritten' === $change['action'] ? $change['to'] : '' ];
					}

					Log::record(
						'links_unlinked',
						PostTypes::is_submittable( (string) $post->post_type ) ? 'item' : 'page',
						(int) $post->ID,
						PostTypes::is_submittable( (string) $post->post_type ) ? Org::for_item( (int) $post->ID ) : 0,
						sprintf( '%d dead link(s) taken out, text kept.', count( $result['changes'] ) ),
						$changes,
						$actor
					);
				}
			}

			++$page;
		} while ( 200 === count( $batch ) );

		if ( [] !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'post', 'type', 'title', 'action', 'href', 'to' ] );
		}

		WP_CLI::success( sprintf(
			'%s: %d link(s) in %d post(s), from a list of %d addresses.',
			$apply ? 'Applied' : 'Dry run, nothing written',
			$links,
			$posts,
			count( $dead )
		) );
	}
}
