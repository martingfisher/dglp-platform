<?php
/**
 * Crawl rules, sitemap document, offer prices and description length: all pure.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Crawl;
use DGL\Frontend\Seo;

Harness::group( 'Crawl: robots.txt' );

$out = Crawl::robots_txt_extra( "User-agent: *\nDisallow: /wp-admin/\n", 'dashboard', 'https://x.test/dgl-sitemap.xml' );
Harness::assert_true( str_contains( $out, "Disallow: /dashboard/\n" ), 'the member area is disallowed' );
Harness::assert_true( str_contains( $out, "Sitemap: https://x.test/dgl-sitemap.xml\n" ), 'the sitemap is named' );
Harness::assert_same( $out, Crawl::robots_txt_extra( $out, 'dashboard', 'https://x.test/dgl-sitemap.xml' ), 'running twice adds nothing' );
Harness::assert_true( str_contains( Crawl::robots_txt_extra( '', '/members/', 'https://x.test/s.xml' ), "User-agent: *\nDisallow: /members/\n" ), 'an empty file gets a user-agent group, and slashes are tidied' );

Harness::group( 'Crawl: sitemap' );

$entries = Crawl::normalise( [ [ 'https://x.test/a/', '' ], [ '', '' ], [ 'https://x.test/a/', '2026-01-01' ], [ 'https://x.test/b/?x=1&y=2', '2026-01-02T10:00:00+00:00' ] ] );
Harness::assert_same( 2, count( $entries ), 'blank and repeated addresses are dropped' );

$xml = Crawl::xml( $entries );
Harness::assert_true( str_contains( $xml, '<loc>https://x.test/b/?x=1&amp;y=2</loc>' ), 'ampersands are escaped' );
Harness::assert_true( str_contains( $xml, '<lastmod>2026-01-02T10:00:00+00:00</lastmod>' ), 'a last-modified date is written when known' );
Harness::assert_true( 1 === substr_count( $xml, '<lastmod>' ), 'and left out when not' );
Harness::assert_true( false !== simplexml_load_string( $xml ), 'the document is well-formed XML' );

Harness::group( 'Seo: offers need a price' );

Harness::assert_same( '0', Seo::offer( 'free', '', '', 'https://x.test/e/' )['price'], 'free is price 0' );
Harness::assert_same( '12', Seo::offer( 'paid', '£12 per person', '', 'https://x.test/e/' )['price'], 'paid takes the amount from the detail' );
Harness::assert_same( null, Seo::offer( 'paid', 'Ask us', '', 'https://x.test/e/' ), 'paid with no amount describes no offer' );
Harness::assert_same( null, Seo::offer( 'donation', '', '', 'https://x.test/e/' ), 'a donation with no amount describes no offer' );
Harness::assert_same( 'https://book.test/', Seo::offer( 'free', '', 'https://book.test/', 'https://x.test/e/' )['url'], 'the booking address is preferred' );
Harness::assert_same( '1250.50', Seo::price_from( 'From £1,250.50 a day' ), 'thousands separators go and pence stay' );
Harness::assert_same( '5', Seo::price_from( '£ 5 or £8 on the door' ), 'the first amount wins' );
Harness::assert_same( null, Seo::price_from( '10 pounds' ), 'no pound sign, no price' );

Harness::group( 'Seo: description length' );

Harness::assert_same( 'Short and fine.', Seo::clip( ' Short and fine. ' ), 'short text is unchanged' );
$long = str_repeat( 'community ', 30 );
$cut  = Seo::clip( $long );
Harness::assert_true( mb_strlen( $cut ) <= 160, 'long text is cut to 160 characters or fewer' );
Harness::assert_true( str_ends_with( $cut, 'community…' ), 'at a whole word, with an ellipsis' );
Harness::assert_true( mb_strlen( Seo::clip( str_repeat( 'x', 400 ) ) ) <= 160, 'even with no spaces to cut at' );
