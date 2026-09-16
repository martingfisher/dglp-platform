<?php
/**
 * Logo resolution and upload allowlist tests.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Logo;
use DGL\Uploads;

Harness::group( 'Email rejects image types that email clients drop' );

Harness::assert_true( Logo::is_email_safe_mime( 'image/png' ), 'PNG is safe in email' );
Harness::assert_true( Logo::is_email_safe_mime( 'image/jpeg' ), 'JPEG is safe in email' );
Harness::assert_true( Logo::is_email_safe_mime( 'image/gif' ), 'GIF is safe in email' );
Harness::assert_true( Logo::is_email_safe_mime( 'IMAGE/PNG' ), 'the check is case insensitive' );
Harness::assert_true( Logo::is_email_safe_mime( ' image/png ' ), 'surrounding whitespace is tolerated' );

/*
 * The one that matters. The landscape lockup on the site is an SVG, so without
 * this rule the obvious configuration produces an invisible logo in Gmail.
 */
Harness::assert_false( Logo::is_email_safe_mime( 'image/svg+xml' ), 'SVG is refused for email, Gmail strips it' );
Harness::assert_false( Logo::is_email_safe_mime( 'image/webp' ), 'WebP is refused for email, older Outlook cannot render it' );
Harness::assert_false( Logo::is_email_safe_mime( 'image/avif' ), 'AVIF is refused for email' );
Harness::assert_false( Logo::is_email_safe_mime( '' ), 'an empty mime type is refused' );
Harness::assert_false( Logo::is_email_safe_mime( 'text/html' ), 'a non-image type is refused' );

Harness::group( 'Member uploads are rasters only' );

/*
 * This site allows SVG in the media library, which is fine for brand assets an
 * administrator uploads. An SVG arriving from one of a few thousand member
 * organisations is an XML document that can carry script, served from the
 * site's own origin. That is stored XSS against the DGLP review team.
 */
Harness::assert_false( Uploads::is_allowed_mime( 'image/svg+xml' ), 'members cannot upload SVG' );
Harness::assert_false( Uploads::is_allowed_mime( 'text/html' ), 'members cannot upload HTML' );
Harness::assert_false( Uploads::is_allowed_mime( 'application/pdf' ), 'members cannot upload PDF through the image field' );
Harness::assert_false( Uploads::is_allowed_mime( 'application/x-php' ), 'members cannot upload PHP' );
Harness::assert_false( Uploads::is_allowed_mime( 'image/svg' ), 'the short SVG spelling is refused too' );

Harness::assert_true( Uploads::is_allowed_mime( 'image/jpeg' ), 'JPEG is allowed' );
Harness::assert_true( Uploads::is_allowed_mime( 'image/png' ), 'PNG is allowed' );
Harness::assert_true( Uploads::is_allowed_mime( 'image/webp' ), 'WebP is allowed for uploads, unlike email' );

Harness::assert_false(
	in_array( 'image/svg+xml', array_values( Uploads::allowed_mimes() ), true ),
	'the allowlist itself contains no SVG entry'
);

Harness::group( 'Upload limits are set' );

Harness::assert_same( 20971520, Uploads::MAX_BYTES, 'the size cap is 20MB, because the file is shrunk on arrival' );
Harness::assert_true( Uploads::MAX_BYTES < 64 * 1024 * 1024, 'the cap is well inside the 64MB PHP upload limit' );
Harness::assert_same( 1200, Uploads::MIN_WIDTH, 'listings require at least a 1200px wide image' );
