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

if ( Frontend::search_request() ) {
	View::output( 'public/search', \DGL\Frontend\Search::view_data( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
} elseif ( null !== $dgl_single ) {
	View::output( 'public/single', [ 'post' => get_post(), 'type' => $dgl_single ] );
} elseif ( null !== $dgl_archive ) {
	View::output( 'public/archive', [ 'type' => $dgl_archive ] );
} elseif ( Frontend::calendar_request() ) {
	View::output( 'public/calendar', \DGL\Events\Calendar::view_data( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
} elseif ( '1' === Frontend::directory_request() ) {
	View::output( 'public/directory', [] );
} elseif ( null !== Frontend::directory_request() ) {
	View::output( 'public/organisation', [ 'org' => Frontend::directory_org() ] );
}

get_footer();
