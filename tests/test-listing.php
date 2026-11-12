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

Harness::group( 'News, events and training invite members to add their own' );

$signin = 'https://example.test/dashboard/';

foreach ( [ PostTypes::NEWS, PostTypes::EVENT, PostTypes::TRAINING ] as $kind ) {
	$html = ListingIntro::default_intro_html( $kind, $signin );

	Harness::assert_true( str_contains( $html, '<a href="' . $signin . '">Sign in to add your own</a>' ), $kind . ' ends with a link to sign in and add your own' );
	Harness::assert_true( str_starts_with( $html, '<p>' ) && str_ends_with( $html, '.</p>' ), $kind . ' is one paragraph, with the full stop outside the link' );
	Harness::assert_true( str_ends_with( ListingIntro::default_intro( $kind ), 'Sign in to add your own.' ), $kind . ' has the same words as plain text for the settings screen' );
}

Harness::assert_true( str_contains( ListingIntro::default_intro_html( PostTypes::NEWS, $signin ), 'Catch up on the latest news from and for the third sector in Leeds. Have news to share?' ), 'news says what the page is, then asks' );
Harness::assert_true( str_contains( ListingIntro::default_intro_html( PostTypes::EVENT, $signin ), esc_html( "Find out what's happening across the third sector in Leeds. Have an event to promote?" ) ), 'events does too, with its apostrophe escaped in the HTML' );
Harness::assert_true( str_starts_with( ListingIntro::default_intro( PostTypes::EVENT ), "Find out what's happening across the third sector in Leeds." ), 'and as plain text it reads as written' );
Harness::assert_true( str_contains( ListingIntro::default_intro_html( PostTypes::TRAINING, $signin ), 'Find training for the third sector in Leeds. Have training to promote?' ), 'and training' );
Harness::assert_same( '<p>Posted by organisations in the Doing Good Leeds Partnership.</p>', ListingIntro::default_intro_html( PostTypes::GRANT, $signin ), 'a listing that does not invite keeps the standard line' );
Harness::assert_false( str_contains( ListingIntro::default_intro_html( PostTypes::GRANT, $signin ), '<a ' ), 'with no link' );
Harness::assert_false( str_contains( ListingIntro::default_intro_html( PostTypes::NEWS, 'javascript:alert(1)' ), 'javascript:' ), 'an address that is not http or https never reaches the link' );
