<?php
/**
 * Bring stories published on the old site since a date into the platform.
 *
 * The old site is still being written to, and its stories reach here only
 * when somebody asks. This reads its public REST API, writes each story it
 * has not seen before as a post of the old kind (same slug, dates, words,
 * picture and categories), then hands it to LegacyImport. A fetched story
 * and one that came over in the first import are the same thing afterwards:
 * same meta, same audit row, same redirect from the old address.
 *
 * Nothing here runs on a schedule. The team asks for it with
 * `wp dgl news fetch --since=<date> --org=<id>`, and asking twice is safe:
 * a story already here, by the old site's id, its old address or its slug,
 * is left alone.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\News;

use DGL\PostTypes;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RemoteFetch {

	/** The old site's REST address for stories. */
	public const POSTS_URL = 'https://doinggoodleeds.org.uk/wp-json/wp/v2/posts';

	/** The old site's id for a story, kept on the item it became. */
	public const META_REMOTE_ID = 'dgl_legacy_remote_id';

	/** Stories per request. */
	public const PAGE_SIZE = 20;

	/** A day, as the --since option takes it. */
	public static function valid_since( string $since ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) && false !== strtotime( $since );
	}

	/** The address of one page of stories. Pure. */
	public static function page_url( string $since, int $page ): string {
		return self::POSTS_URL . '?' . http_build_query(
			[
				'after'    => $since . 'T00:00:00',
				'per_page' => self::PAGE_SIZE,
				'page'     => max( 1, $page ),
				'orderby'  => 'date',
				'order'    => 'asc',
				'_embed'   => 'wp:featuredmedia,wp:term',
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * One page of published stories since a date, oldest first, with the
	 * featured picture and the categories embedded. An empty list past the
	 * last page: WordPress answers that with a 400 the code below recognises.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function page( string $since, int $page ): array|WP_Error {
		$response = wp_remote_get(
			self::page_url( $since, $page ),
			[
				'timeout'    => 30,
				'user-agent' => 'DGL platform (news fetch)',
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 400 === $code && is_array( $body ) && 'rest_post_invalid_page_number' === ( $body['code'] ?? '' ) ) {
			return [];
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'dgl_fetch_http', sprintf( 'The old site answered HTTP %d.', $code ) );
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'dgl_fetch_body', 'The old site did not answer with a list of stories.' );
		}

		return array_values( array_filter( $body, 'is_array' ) );
	}

	/**
	 * The local post that already holds a remote story, else 0. By the old
	 * site's id first; then by the old address, which the first import
	 * recorded; then by slug among stories and posts of the old kind.
	 */
	public static function existing( array $record ): int {
		global $wpdb;

		$remote_id = (int) ( $record['id'] ?? 0 );

		if ( $remote_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META_REMOTE_ID, (string) $remote_id ) );

			if ( $found ) {
				return (int) $found;
			}
		}

		$link = trim( (string) ( $record['link'] ?? '' ) );

		if ( '' !== $link ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (%s, %s) LIMIT 1", LegacyImport::META_URL, trailingslashit( $link ), untrailingslashit( $link ) ) );

			if ( $found ) {
				return (int) $found;
			}
		}

		$slug = sanitize_title( (string) ( $record['slug'] ?? '' ) );

		if ( '' !== $slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('post', %s) AND post_status <> 'trash' LIMIT 1", $slug, PostTypes::NEWS ) );

			if ( $found ) {
				return (int) $found;
			}
		}

		return 0;
	}

	/** The old category slugs on a remote story, from the embedded terms. */
	public static function categories( array $record ): array {
		$out = [];

		foreach ( (array) ( $record['_embedded']['wp:term'] ?? [] ) as $group ) {
			foreach ( (array) $group as $term ) {
				if ( is_array( $term ) && 'category' === ( $term['taxonomy'] ?? '' ) && '' !== (string) ( $term['slug'] ?? '' ) ) {
					$out[] = sanitize_title( (string) $term['slug'] );
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/** The excerpt the old site shows, as plain words without its "[…]" tail. */
	public static function excerpt( array $record ): string {
		$text = html_entity_decode( trim( wp_strip_all_tags( (string) ( $record['excerpt']['rendered'] ?? '' ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s*\[…\]\s*$/u', '…', $text ) );
	}

	/**
	 * Write one remote story as a post of the old kind, picture and all.
	 *
	 * @return int|WP_Error The new post's id.
	 */
	public static function store( array $record ): int|WP_Error {
		$remote_id = (int) ( $record['id'] ?? 0 );
		$title     = html_entity_decode( trim( (string) ( $record['title']['rendered'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( $remote_id <= 0 || '' === $title ) {
			return new WP_Error( 'dgl_fetch_record', 'A story without an id or a title.' );
		}

		$date     = str_replace( 'T', ' ', (string) ( $record['date'] ?? '' ) );
		$date_gmt = str_replace( 'T', ' ', (string) ( $record['date_gmt'] ?? '' ) );

		$post_id = wp_insert_post(
			[
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_name'     => sanitize_title( (string) ( $record['slug'] ?? $title ) ),
				'post_content'  => (string) ( $record['content']['rendered'] ?? '' ),
				'post_excerpt'  => self::excerpt( $record ),
				'post_date'     => '' !== $date ? $date : current_time( 'mysql' ),
				'post_date_gmt' => '' !== $date_gmt ? $date_gmt : current_time( 'mysql', true ),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, self::META_REMOTE_ID, (string) $remote_id );

		$link = trim( (string) ( $record['link'] ?? '' ) );

		if ( '' !== $link ) {
			// The address the story had: LegacyImport keeps it and the redirect reads it.
			update_post_meta( $post_id, LegacyImport::META_URL, $link );
		}

		$categories = self::categories( $record );

		if ( [] !== $categories ) {
			wp_set_object_terms( $post_id, $categories, 'category', false );
		}

		$picture = self::picture( $record, $post_id );

		if ( $picture > 0 ) {
			set_post_thumbnail( $post_id, $picture );
		}

		return $post_id;
	}

	/**
	 * Copy the featured picture into the media library, attached to the
	 * post. 0 when there is none, or the copy failed: a story without its
	 * picture is still a story.
	 */
	public static function picture( array $record, int $post_id ): int {
		$media = $record['_embedded']['wp:featuredmedia'][0] ?? null;
		$url   = is_array( $media ) ? trim( (string) ( $media['source_url'] ?? '' ) ) : '';

		if ( '' === $url ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$caption = html_entity_decode( (string) ( $media['title']['rendered'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$id      = media_sideload_image( $url, $post_id, '' !== $caption ? $caption : null, 'id' );

		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			return 0;
		}

		$alt = trim( (string) ( $media['alt_text'] ?? '' ) );

		if ( '' !== $alt ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
		}

		return (int) $id;
	}

	/**
	 * Fetch and convert every story since a date, page by page until the old
	 * site runs out. With $dry_run, counts what would happen and writes nothing.
	 *
	 * @param array<string, int> $owners Old category slug to organisation id.
	 * @return array{added:int, skipped:int, failed:int, no_picture:int, no_topic:int, titles:string[], errors:string[]}|WP_Error
	 */
	public static function run( string $since, int $default_org, array $owners = [], int $actor_id = 0, bool $dry_run = false, int $limit = 0 ): array|WP_Error {
		if ( ! self::valid_since( $since ) ) {
			return new WP_Error( 'dgl_fetch_since', 'Give --since as a day, like 2026-09-10.' );
		}

		$out = [
			'added'      => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'no_picture' => 0,
			'no_topic'   => 0,
			'titles'     => [],
			'errors'     => [],
		];

		for ( $page = 1; $page <= 50; $page++ ) {
			$records = self::page( $since, $page );

			if ( is_wp_error( $records ) ) {
				return $records;
			}

			if ( [] === $records ) {
				break;
			}

			foreach ( $records as $record ) {
				if ( $limit > 0 && $out['added'] >= $limit ) {
					break 2;
				}

				$title = html_entity_decode( (string) ( $record['title']['rendered'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( self::existing( $record ) > 0 ) {
					++$out['skipped'];
					continue;
				}

				if ( $dry_run ) {
					++$out['added'];
					$out['titles'][] = $title;

					if ( '' === (string) ( $record['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '' ) ) {
						++$out['no_picture'];
					}

					if ( [] === LegacyImport::topics_for( self::categories( $record ) ) ) {
						++$out['no_topic'];
					}

					continue;
				}

				$post_id = self::store( $record );

				if ( is_wp_error( $post_id ) ) {
					++$out['failed'];
					$out['errors'][] = sprintf( '%s: %s', $title, $post_id->get_error_message() );
					continue;
				}

				$plan = LegacyImport::convert( $post_id, $default_org, $owners, $actor_id );

				if ( is_wp_error( $plan ) ) {
					// Leave nothing half done: the next run then tries again.
					wp_delete_post( $post_id, true );
					++$out['failed'];
					$out['errors'][] = sprintf( '%s: %s', $title, $plan->get_error_message() );
					continue;
				}

				++$out['added'];
				$out['titles'][] = $title;

				if ( $plan['image'] <= 0 ) {
					++$out['no_picture'];
				}

				if ( [] === $plan['topics'] ) {
					++$out['no_topic'];
				}
			}

			if ( count( $records ) < self::PAGE_SIZE ) {
				break;
			}
		}

		return $out;
	}
}
