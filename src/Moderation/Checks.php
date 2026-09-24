<?php
/**
 * Automatic checks run against a submission before a human reads it.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Moderation;

use DGL\Dashboard\Wizard;
use DGL\Meta;
use DGL\Org\Org;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use DGL\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * The cheap things a machine can notice, so the reviewer spends their attention
 * on the things only a person can judge.
 *
 * These never block a decision. A moderator can approve something with every
 * check failing, because the checks are advice and the moderator is in charge.
 * Their job is to stop the same three corrections being typed out by hand a
 * hundred times.
 */
final class Checks {

	public const PASS    = 'pass';
	public const WARN    = 'warn';
	public const FAIL    = 'fail';
	public const UNKNOWN = 'unknown';

	/** Where the last link check result is kept. */
	public const LINK_RESULT_META = 'dgl_link_check';

	/** Reachability is slow, so a result is reused for this long. */
	private const LINK_CACHE_SECONDS = 43200;

	/** Hard caps, because a moderator is waiting on this. */
	private const LINK_MAX   = 5;
	private const LINK_TIMEOUT = 4;

	/**
	 * Run every check.
	 *
	 * @return array<int, array{key:string, label:string, status:string, detail:string}>
	 */
	public static function run( int $post_id, string $post_type ): array {
		return [
			self::required_fields( $post_id, $post_type ),
			self::image( $post_id, $post_type ),
			self::duplicates( $post_id, $post_type ),
			// Last, so the row sits right above the button that runs it.
			self::links( $post_id, $post_type ),
		];
	}

	/**
	 * Everything the form marks as required is actually filled in.
	 *
	 * A member can only submit a complete item, but a moderator can be looking
	 * at something submitted before a field was added, or restored from the
	 * archive, so it is worth saying rather than assuming.
	 */
	private static function required_fields( int $post_id, string $post_type ): array {
		$missing = Wizard::validate_all( $post_id, $post_type );

		return [
			'key'    => 'required',
			'label'  => __( 'Required fields complete', 'dgl-platform' ),
			'status' => empty( $missing ) ? self::PASS : self::FAIL,
			'detail' => empty( $missing )
				? __( 'Nothing missing.', 'dgl-platform' )
				: sprintf(
					/* translators: %s: comma separated field labels. */
					__( 'Missing: %s', 'dgl-platform' ),
					implode( ', ', self::labels_for( $post_type, array_keys( $missing ) ) )
				),
		];
	}

	/**
	 * Field labels for a set of keys.
	 *
	 * A reviewer should read "Start date and time", not "start_datetime". The
	 * database column name is not their problem.
	 *
	 * @param string[] $keys
	 * @return string[]
	 */
	private static function labels_for( string $post_type, array $keys ): array {
		$labels = [];

		foreach ( $keys as $key ) {
			$field    = FieldRegistry::find( $post_type, $key );
			$labels[] = null !== $field ? $field->label : $key;
		}

		return $labels;
	}

	/**
	 * The header image is big enough to use.
	 */
	private static function image( int $post_id, string $post_type ): array {
		$field = FieldRegistry::find( $post_type, 'image' );

		if ( null === $field ) {
			return self::skip( 'image', __( 'Image', 'dgl-platform' ) );
		}

		$attachment_id = (int) get_post_meta( $post_id, $field->meta_key(), true );

		if ( $attachment_id < 1 ) {
			return [
				'key'    => 'image',
				'label'  => __( 'Header image', 'dgl-platform' ),
				'status' => self::WARN,
				'detail' => __( 'None supplied. The listing will use a placeholder.', 'dgl-platform' ),
			];
		}

		$meta  = wp_get_attachment_metadata( $attachment_id );
		$width = (int) ( $meta['width'] ?? 0 );

		if ( $width < 1 ) {
			return self::skip( 'image', __( 'Header image', 'dgl-platform' ) );
		}

		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		if ( '' === $alt ) {
			return [
				'key'    => 'image',
				'label'  => __( 'Header image', 'dgl-platform' ),
				'status' => self::WARN,
				'detail' => __( 'No description for people who cannot see it. Ask for one, or add it in the media library.', 'dgl-platform' ),
			];
		}

		return [
			'key'    => 'image',
			'label'  => __( 'Header image', 'dgl-platform' ),
			'status' => $width >= Uploads::MIN_WIDTH ? self::PASS : self::WARN,
			'detail' => sprintf(
				/* translators: 1: actual width, 2: minimum width, 3: the alt text. */
				__( '%1$dpx wide (the listing wants at least %2$dpx). Described as "%3$s".', 'dgl-platform' ),
				$width,
				Uploads::MIN_WIDTH,
				$alt
			),
		];
	}

	/**
	 * External links resolve.
	 *
	 * Not run on page load. Fetching five URLs with a four second timeout each
	 * is twenty seconds a moderator spends staring at a blank screen, on a host
	 * that kills the request at thirty. The reviewer asks for it, and the answer
	 * is kept for half a day.
	 */
	private static function links( int $post_id, string $post_type ): array {
		$urls = self::external_urls( $post_id, $post_type );

		if ( empty( $urls ) ) {
			return [
				'key'    => 'links',
				'label'  => __( 'External links', 'dgl-platform' ),
				'status' => self::PASS,
				'detail' => __( 'None to check.', 'dgl-platform' ),
			];
		}

		$cached = get_post_meta( $post_id, self::LINK_RESULT_META, true );

		if ( ! is_array( $cached ) || ( $cached['checked_at'] ?? 0 ) < time() - self::LINK_CACHE_SECONDS ) {
			return [
				'key'    => 'links',
				'label'  => __( 'External links', 'dgl-platform' ),
				'status' => self::UNKNOWN,
				'detail' => sprintf(
					/* translators: %d: number of links. */
					_n( '%d link, not checked yet.', '%d links, not checked yet.', count( $urls ), 'dgl-platform' ),
					count( $urls )
				),
			];
		}

		$broken   = (array) ( $cached['broken'] ?? [] );
		$insecure = (array) ( $cached['insecure'] ?? [] );
		$detail   = [];

		if ( [] === $broken ) {
			/* translators: %d: number of links. */
			$detail[] = sprintf( _n( '%d link responded.', 'All %d links responded.', count( $urls ), 'dgl-platform' ), count( $urls ) );
		} else {
			/* translators: %s: comma separated URLs. */
			$detail[] = sprintf( __( 'No response from: %s', 'dgl-platform' ), implode( ', ', array_map( 'esc_url_raw', $broken ) ) );
		}

		if ( [] !== $insecure ) {
			/* translators: %s: comma separated URLs. */
			$detail[] = sprintf( __( 'Plain http, which the site does not link to; send it back to be changed to https: %s', 'dgl-platform' ), implode( ', ', array_map( 'esc_url_raw', $insecure ) ) );
		}

		return [
			'key'    => 'links',
			'label'  => __( 'External links', 'dgl-platform' ),
			'status' => [] !== $insecure ? self::FAIL : ( [] === $broken ? self::PASS : self::WARN ),
			'detail' => implode( ' ', $detail ),
		];
	}

	/**
	 * Actually fetch the links. Called only when a moderator asks.
	 *
	 * @return array{checked_at:int, broken:string[]}
	 */
	public static function check_links_now( int $post_id, string $post_type ): array {
		$urls     = array_slice( self::external_urls( $post_id, $post_type ), 0, self::LINK_MAX );
		$broken   = [];
		$insecure = [];

		foreach ( $urls as $url ) {
			$code = self::head( $url );

			// A HEAD refusal is common and not the same as a dead link, so only
			// a real failure or a 4xx/5xx counts.
			if ( 0 === $code || $code >= 400 ) {
				$broken[] = $url;
				continue;
			}

		}

		// The site lists nothing served over plain http. New submissions cannot
		// carry one; an older listing can, and the reviewer should see it.
		$insecure = array_values( array_filter( $urls, static fn( string $u ): bool => ! \DGL\Schema\Links::is_secure( $u ) ) );

		$result = [
			'checked_at' => time(),
			'broken'     => $broken,
			'insecure'   => $insecure,
		];

		update_post_meta( $post_id, self::LINK_RESULT_META, $result );

		return $result;
	}

	/**
	 * Somebody has not posted this twice.
	 *
	 * Title match within the same content type and organisation. Deliberately
	 * narrow: a genuinely different organisation running an event with the same
	 * name is not a duplicate, it is Tuesday.
	 */
	private static function duplicates( int $post_id, string $post_type ): array {
		$post = get_post( $post_id );

		if ( null === $post || '' === trim( (string) $post->post_title ) ) {
			return self::skip( 'duplicate', __( 'Duplicate listing', 'dgl-platform' ) );
		}

		$org_id = Org::for_item( $post_id );

		$others = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => [ Statuses::LIVE, Statuses::PENDING ],
				'post__not_in'   => [ $post_id ],
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Meta::ITEM_ORG,
				'meta_value'     => (string) $org_id,
			]
		);

		$needle  = self::normalise_title( (string) $post->post_title );
		$matches = [];

		foreach ( $others as $other_id ) {
			if ( self::normalise_title( (string) get_the_title( $other_id ) ) === $needle ) {
				$matches[] = (int) $other_id;
			}
		}

		return [
			'key'    => 'duplicate',
			'label'  => __( 'Duplicate listing', 'dgl-platform' ),
			'status' => empty( $matches ) ? self::PASS : self::WARN,
			'detail' => empty( $matches )
				? __( 'None found.', 'dgl-platform' )
				: sprintf(
					/* translators: %d: how many. */
					_n(
						'The same organisation already has %d listing with this title.',
						'The same organisation already has %d listings with this title.',
						count( $matches ),
						'dgl-platform'
					),
					count( $matches )
				),
		];
	}

	/** The response code for one address, 0 when it did not answer. */
	private static function head( string $url ): int {
		$response = wp_safe_remote_head(
			$url,
			[
				'timeout'     => self::LINK_TIMEOUT,
				'redirection' => 3,
				'user-agent'  => 'DGLP Platform link check',
			]
		);

		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Every external URL on the item: the address fields, and every link in
	 * the words, which is where an editor-inserted "http://" ends up.
	 *
	 * @return string[]
	 */
	public static function external_urls( int $post_id, string $post_type ): array {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = [];
		$post = get_post( $post_id );

		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			if ( Field::URL === $field->type ) {
				$candidates = [ (string) get_post_meta( $post_id, $field->meta_key(), true ) ];
			} elseif ( Field::RICHTEXT === $field->type ) {
				$html       = 'body' === $field->key && null !== $post ? (string) $post->post_content : (string) get_post_meta( $post_id, $field->meta_key(), true );
				$candidates = \DGL\Schema\Links::hrefs( $html );
			} else {
				continue;
			}

			foreach ( $candidates as $value ) {
				if ( '' === $value || ! \DGL\Schema\Links::is_web( $value ) ) {
					continue;
				}

				$host = wp_parse_url( $value, PHP_URL_HOST );

				if ( is_string( $host ) && $host !== $home ) {
					$urls[] = $value;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	private static function normalise_title( string $title ): string {
		return trim( preg_replace( '/\s+/', ' ', strtolower( wp_strip_all_tags( $title ) ) ) ?? '' );
	}

	/**
	 * @return array{key:string, label:string, status:string, detail:string}
	 */
	private static function skip( string $key, string $label ): array {
		return [
			'key'    => $key,
			'label'  => $label,
			'status' => self::UNKNOWN,
			'detail' => __( 'Nothing to check.', 'dgl-platform' ),
		];
	}
}
