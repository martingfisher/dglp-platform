<?php
/**
 * `wp dgl links audit`: every plain-http link already stored on a listing,
 * so the team can chase them before go-live. Read only.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

use DGL\PostTypes;
use DGL\Statuses;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class LinksCommand {

	public static function register(): void {
		WP_CLI::add_command( 'dgl links', self::class );
	}

	/**
	 * List every http:// link on every listing, live or not.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Only listings on the site now.
	 *
	 * @when after_wp_load
	 */
	public function audit( array $args, array $assoc ): void {
		$rows = [];

		foreach ( PostTypes::submittable() as $type ) {
			$ids = get_posts(
				[
					'post_type'      => $type,
					'post_status'    => isset( $assoc['live'] ) ? Statuses::LIVE : 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);

			foreach ( $ids as $id ) {
				$post = get_post( (int) $id );

				foreach ( FieldRegistry::for_type( $type ) as $field ) {
					if ( Field::URL === $field->type ) {
						$found = [ (string) get_post_meta( (int) $id, $field->meta_key(), true ) ];
					} elseif ( Field::RICHTEXT === $field->type ) {
						$found = Links::insecure_hrefs( 'body' === $field->key && null !== $post ? (string) $post->post_content : (string) get_post_meta( (int) $id, $field->meta_key(), true ) );
					} else {
						continue;
					}

					foreach ( $found as $url ) {
						if ( '' !== $url && Links::is_web( $url ) && ! Links::is_secure( $url ) ) {
							$rows[] = [ 'id' => (int) $id, 'status' => (string) get_post_status( (int) $id ), 'title' => (string) get_the_title( (int) $id ), 'field' => $field->label, 'link' => $url ];
						}
					}
				}
			}
		}

		if ( [] === $rows ) {
			WP_CLI::success( 'No plain-http links stored.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'status', 'title', 'field', 'link' ] );
		WP_CLI::warning( count( $rows ) . ' plain-http link(s). Ask the organisation to change each to https, or take it out.' );
	}
}
