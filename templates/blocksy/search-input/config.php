<?php
/**
 * The theme's "Search Box" header element, as this plugin renders it: the
 * magnifier in a navy disc, which opens the site search dialog. Registered in place of the Blocksy
 * Pro element of the same id, so the header layout keeps its name.
 *
 * @package DGL
 */

defined( 'ABSPATH' ) || exit;

$config = [
	'name'              => __( 'Search (the magnifier that opens the site search)', 'dgl-platform' ),
	'devices'           => [ 'desktop', 'mobile' ],
	'selective_refresh' => [],
];
