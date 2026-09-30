<?php
/**
 * Asking a venue about hiring a space.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Stage 3 of the build fills this in. Until then a venue page shows the
 * venue's contact details and no form.
 */
final class Enquiry {

	/**
	 * What the venue page should show: a form, a "not switched on" note,
	 * or nothing.
	 *
	 * @return array<string, mixed>
	 */
	public static function state( WP_Post $venue ): array {
		return [ 'show' => false, 'off' => false ];
	}
}
