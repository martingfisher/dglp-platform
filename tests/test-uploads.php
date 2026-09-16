<?php
/**
 * What is kept from a member's image.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Uploads;

Harness::group( 'Uploads: no size that is a copy of the master' );

$core_sizes = [
	'thumbnail'    => [ 'width' => 150, 'height' => 150, 'crop' => true ],
	'medium'       => [ 'width' => 300, 'height' => 300, 'crop' => false ],
	'medium_large' => [ 'width' => 768, 'height' => 0, 'crop' => false ],
	'large'        => [ 'width' => 1024, 'height' => 1024, 'crop' => false ],
	'1536x1536'    => [ 'width' => 1536, 'height' => 1536, 'crop' => false ],
	'2048x2048'    => [ 'width' => 2048, 'height' => 2048, 'crop' => false ],
];

Harness::assert_same( [ 'thumbnail', 'medium', 'medium_large', 'large' ], array_keys( Uploads::sizes( $core_sizes ) ), 'the four sizes a page uses survive; 1536 and 2048 do not' );
Harness::assert_same( 1600, Uploads::MAX_EDGE, 'the master is 1600px on its long side' );
Harness::assert_same( 20 * 1024 * 1024, Uploads::MAX_BYTES, 'the ceiling admits a 12MB phone photo' );
Harness::assert_true( Uploads::is_allowed_mime( 'image/jpeg' ) && ! Uploads::is_allowed_mime( 'image/svg+xml' ), 'rasters only' );
