<?php
/**
 * The public template for a submission and for a listing.
 *
 * One file, because `template_include` hands over a single path. It calls the
 * theme's own header and footer: a public page belongs to the site, and only
 * the content between them is ours.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;
use DGL\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

get_header();

$dgl_single  = Frontend::single_type();
$dgl_archive = Frontend::archive_type();

if ( null !== $dgl_single ) {
	View::output( 'public/single', [ 'post' => get_post(), 'type' => $dgl_single ] );
} elseif ( null !== $dgl_archive ) {
	View::output( 'public/archive', [ 'type' => $dgl_archive ] );
}

get_footer();
