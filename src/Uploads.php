<?php
/**
 * What members are allowed to upload.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist for member-submitted images.
 *
 * This site permits SVG in the media library, which is fine for brand assets an
 * administrator uploads. It is not fine for files arriving from a few thousand
 * member organisations: an SVG is an XML document that can carry script, and
 * WordPress serves uploads from the same origin as the site, so a malicious
 * one becomes stored cross-site scripting against the DGLP team.
 *
 * So member uploads are rasters only. This is an allowlist rather than a
 * blocklist, because a blocklist is a list of the attacks somebody already
 * thought of.
 */
final class Uploads {

	/** 8MB. Comfortable for a 1600px featured image, hostile to abuse. */
	public const MAX_BYTES = 8388608;

	/** Listings want a usable header image, so hold a floor on width. */
	public const MIN_WIDTH = 1200;

	/**
	 * Image types a member may submit.
	 *
	 * @return array<string, string> Extension pattern => MIME type.
	 */
	public static function allowed_mimes(): array {
		return [
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
		];
	}

	/**
	 * Whether a MIME type may be uploaded by a member.
	 */
	public static function is_allowed_mime( string $mime ): bool {
		return in_array( strtolower( trim( $mime ) ), array_values( self::allowed_mimes() ), true );
	}
}
