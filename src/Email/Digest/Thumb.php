<?php
/**
 * The small square picture on a digest row.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

use DGL\Frontend\Frontend;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A square crop of an item's own picture, made for email.
 *
 * Email clients ignore object-fit, so a wide picture squeezed into a square
 * box comes out squashed. The crop has to be a real file. It is cut at twice
 * the size it is shown at so it stays sharp on a phone, kept in its own folder
 * under uploads, and named by attachment and edit time, so a replaced picture
 * gets a new file and an unchanged one is never cut twice.
 *
 * An item with no picture of its own gets none. The site shows a stand-in
 * there; in an inbox a column of identical grey boxes says less than no box.
 */
final class Thumb {

	/** Pixels the picture is shown at. The email template uses the same figure. */
	public const SHOWN = 96;

	/** Pixels the file is cut at: twice what is shown, for high-density screens. */
	public const CUT = 192;

	private const FOLDER = 'dgl-email-thumbs';

	/**
	 * The thumbnail for one item.
	 *
	 * @return array{url:string, alt:string}|null Null when there is no usable picture.
	 */
	public static function for_post( WP_Post $post ): ?array {
		$attachment_id = (int) Frontend::value( $post, 'image' );

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$url = self::url( $attachment_id );

		if ( '' === $url ) {
			return null;
		}

		return [
			'url' => $url,
			'alt' => self::alt( $post, $attachment_id ),
		];
	}

	/**
	 * What a blocked image shows in its place: the member's own words about
	 * the picture, else the one stored on the file, else nothing. Nothing is
	 * right when there is no description, because the title sits beside it.
	 */
	public static function alt( WP_Post $post, int $attachment_id ): string {
		$own = trim( (string) Frontend::value( $post, 'image_alt' ) );

		if ( '' !== $own ) {
			return $own;
		}

		return trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * The public URL of the square crop, made on first use.
	 *
	 * @return string '' when the file is missing or cannot be cut.
	 */
	public static function url( int $attachment_id ): string {
		$source = get_attached_file( $attachment_id );

		if ( ! is_string( $source ) || '' === $source || ! is_readable( $source ) ) {
			// The imported stories often name a picture whose file has gone.
			return '';
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$name = self::file_name( $attachment_id, (int) filemtime( $source ) );
		$dir  = rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::FOLDER;
		$path = $dir . '/' . $name;
		$url  = rtrim( (string) $uploads['baseurl'], '/' ) . '/' . self::FOLDER . '/' . $name;

		if ( is_readable( $path ) ) {
			return $url;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$editor = wp_get_image_editor( $source );

		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$editor->set_quality( 80 );

		// Hard crop from the centre: the middle of a photograph is the part worth keeping.
		if ( is_wp_error( $editor->resize( self::CUT, self::CUT, true ) ) ) {
			return '';
		}

		$saved = $editor->save( $path, 'image/jpeg' );

		return is_wp_error( $saved ) ? '' : $url;
	}

	/**
	 * The crop's file name. Pure, so the rule that a changed file gets a new
	 * name is asserted without WordPress.
	 */
	public static function file_name( int $attachment_id, int $modified ): string {
		return sprintf( '%d-%d-%d.jpg', $attachment_id, $modified, self::CUT );
	}
}
