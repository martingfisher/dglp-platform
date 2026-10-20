<?php
/**
 * The listing pages' own title and intro: option names, the fallback, the
 * meta description cut.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\ListingIntro;
use DGL\PostTypes;

Harness::group( 'Listing intro: names, fallback and the description' );

Harness::assert_same( 'dgl_listing_title_dgl_training', ListingIntro::option( 'title', PostTypes::TRAINING ), 'the title option is named for its type' );
Harness::assert_same( 'dgl_listing_intro_dgl_news', ListingIntro::option( 'intro', PostTypes::NEWS ), 'and so is the intro option' );
Harness::assert_same( 'dgl_listing_title_dgl_event', ListingIntro::option( 'anything-else', PostTypes::EVENT ), 'an unknown kind falls back to the title, never to an arbitrary name' );

Harness::assert_same( 'Courses', ListingIntro::resolve( 'Courses', 'Training' ), 'a custom title wins' );
Harness::assert_same( 'Training', ListingIntro::resolve( '', 'Training' ), 'empty means the standard' );
Harness::assert_same( 'Training', ListingIntro::resolve( "  \n ", 'Training' ), 'spaces alone mean the standard' );
Harness::assert_same( 'Courses', ListingIntro::resolve( '  Courses ', 'Training' ), 'and a custom one is trimmed' );

Harness::assert_same( 'Short and plain.', ListingIntro::excerpt( '<p>Short <strong>and</strong> plain.</p>' ), 'tags go and a short text is kept' );
Harness::assert_same( 'Tom & Jerry', ListingIntro::excerpt( '<p>Tom &amp; Jerry</p>' ), 'entities are decoded, because the meta tag will escape them again' );
Harness::assert_same( 'One two', ListingIntro::excerpt( "<p>One</p>\n\n<p>two</p>" ), 'paragraph breaks become one space' );
Harness::assert_same( '', ListingIntro::excerpt( '' ), 'nothing in, nothing out' );

$long = ListingIntro::excerpt( '<p>' . str_repeat( 'word ', 80 ) . '</p>' );
Harness::assert_true( mb_strlen( $long ) <= ListingIntro::DESCRIPTION_MAX, 'a long intro is cut to the description length' );
Harness::assert_true( str_ends_with( $long, '…' ) && ! str_contains( $long, 'wor…' ), 'at a whole word, with an ellipsis' );
