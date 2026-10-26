<?php
/**
 * External media: files content takes from other sites, found and pointed here.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Tools\ExternalMedia;

Harness::group( 'External media: find what loads from other sites' );

$own  = [ 'partnership.doinggoodleeds.org.uk', 'cdn-partnership.b-cdn.net' ];
$html = '<p><img class="alignnone wp-image-112662" src="https://doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-300x219.jpg" alt="Children at the market" srcset="https://doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-300x219.jpg 300w, https://doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-1024x747.jpg 1024w" sizes="(max-width: 509px) 100vw, 509px" width="509" height="372" /></p>'
	. '<p>Read <a href="https://forumcentral.org.uk/wp-content/uploads/2025/03/report.pdf">the report</a> or <a href="https://www.england.nhs.uk/long-term-plan.pdf">the plan</a>.</p>'
	. '<p><a href="https://www.england.nhs.uk/about/">About the NHS</a> and <img src="https://partnership.doinggoodleeds.org.uk/wp-content/uploads/2026/04/logo.png" alt="" /></p>'
	. '<!-- wp:greenshift-blocks/image {"url":"https:\/\/cdn-doinggood.b-cdn.net\/wp-content\/uploads\/2024\/05\/hero.webp","link":"https:\/\/doinggoodleeds.org.uk\/wp-content\/uploads\/2024\/05\/flyer.pdf"} /-->'
	. '<div style="background-image:url(https://observatory.leeds.gov.uk/wp-content/uploads/map.png)"></div>';

$found = ExternalMedia::find( $html, $own );
$by    = [];
foreach ( $found as $row ) {
	$by[ $row['url'] ] = $row;
}

Harness::assert_same( 'embed', $by['https://doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-300x219.jpg']['kind'] ?? '', 'an img src is a file the page shows' );
Harness::assert_same( 'embed', $by['https://doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-1024x747.jpg']['kind'] ?? '', 'so is each srcset entry' );
Harness::assert_same( 'link', $by['https://forumcentral.org.uk/wp-content/uploads/2025/03/report.pdf']['kind'] ?? '', 'an href to a PDF is a file the page links to' );
Harness::assert_same( 'link', $by['https://www.england.nhs.uk/long-term-plan.pdf']['kind'] ?? '', 'on any site, by its type' );
Harness::assert_true( ! isset( $by['https://www.england.nhs.uk/about/'] ), 'an ordinary page link is not a file' );
Harness::assert_true( [] === array_filter( $found, static fn( array $r ): bool => 'partnership.doinggoodleeds.org.uk' === $r['host'] ), 'files already on this site are left out' );
Harness::assert_same( 'embed', $by['https://cdn-doinggood.b-cdn.net/wp-content/uploads/2024/05/hero.webp']['kind'] ?? '', 'a block setting with escaped slashes is found, as a picture' );
Harness::assert_same( 'link', $by['https://doinggoodleeds.org.uk/wp-content/uploads/2024/05/flyer.pdf']['kind'] ?? '', 'and a block link setting as a link' );
Harness::assert_same( 'embed', $by['https://observatory.leeds.gov.uk/wp-content/uploads/map.png']['kind'] ?? '', 'a CSS background is shown' );
Harness::assert_same( 'cdn-doinggood.b-cdn.net', $by['https://cdn-doinggood.b-cdn.net/wp-content/uploads/2024/05/hero.webp']['host'] ?? '', 'each row carries its host' );

Harness::group( 'External media: sizes, rewrite and retag' );

Harness::assert_same( 'https://x.test/wp-content/uploads/a/photo.jpg', ExternalMedia::full_size( 'https://x.test/wp-content/uploads/a/photo-300x219.jpg' ), 'a WordPress size points at its full-size file' );
Harness::assert_same( 'https://x.test/wp-content/uploads/a/photo-scaled.jpg', ExternalMedia::full_size( 'https://x.test/wp-content/uploads/a/photo-scaled.jpg' ), 'a scaled original is left as it is' );
Harness::assert_same( 'https://x.test/a/2026-27-plan.pdf', ExternalMedia::full_size( 'https://x.test/a/2026-27-plan.pdf' ), 'a name that only looks like a size is left alone' );

$map = [
	'https://doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-300x219.jpg'  => 'https://partnership.doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market.jpg',
	'https://doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-1024x747.jpg' => 'https://partnership.doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market.jpg',
	'https://cdn-doinggood.b-cdn.net/wp-content/uploads/2024/05/hero.webp'          => 'https://partnership.doinggoodleeds.org.uk/wp-content/uploads/2026/10/hero.webp',
];
$new = ExternalMedia::rewrite( $html, $map );
Harness::assert_true( ! str_contains( $new, 'doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market-' ) && ! str_contains( $new, 'cdn-doinggood' ), 'every mapped address is swapped, escaped form included' );
Harness::assert_true( str_contains( $new, 'https:\/\/partnership.doinggoodleeds.org.uk\/wp-content\/uploads\/2026\/10\/hero.webp' ), 'the escaped form is written back escaped' );
Harness::assert_true( str_contains( $new, 'https://forumcentral.org.uk/wp-content/uploads/2025/03/report.pdf' ), 'addresses not in the map are untouched' );

$tagged = ExternalMedia::retag( $new, [ 'https://partnership.doinggoodleeds.org.uk/wp-content/uploads/2026/10/Market.jpg' => 501 ] );
Harness::assert_true( str_contains( $tagged, 'class="alignnone wp-image-501"' ) && ! str_contains( $tagged, 'wp-image-112662' ), 'the img is tagged with the new attachment, the old site\'s id gone' );
Harness::assert_true( ! str_contains( $tagged, 'srcset=' ) && ! str_contains( $tagged, 'sizes=' ) && str_contains( $tagged, 'width="509"' ), 'its old srcset and sizes go; its width stays' );
Harness::assert_true( str_contains( $tagged, 'alt="Children at the market"' ), 'its alt text stays' );

Harness::group( 'External media: alt text' );

$imgs = ExternalMedia::images( '<img src="a.jpg" alt="A cat"><img class="wp-image-7" src="b.jpg" alt=""><img src="c.jpg">' );
Harness::assert_same( [ [ 'src' => 'a.jpg', 'alt' => 'A cat', 'id' => 0 ], [ 'src' => 'b.jpg', 'alt' => '', 'id' => 7 ], [ 'src' => 'c.jpg', 'alt' => null, 'id' => 0 ] ], $imgs, 'each img with its alt text (null when there is none) and its attachment id' );

$filled = ExternalMedia::retag( '<img src="a.jpg" alt="Kept"><img src="b.jpg" alt=""><img src="c.jpg" class="x">', [], [ 'a.jpg' => 'New', 'b.jpg' => 'Seeds & soil', 'c.jpg' => 'A "quoted" word' ] );
Harness::assert_true( str_contains( $filled, 'alt="Kept"' ) && ! str_contains( $filled, 'alt="New"' ), 'written alt text is never overwritten' );
Harness::assert_true( str_contains( $filled, 'alt="Seeds &amp; soil"' ), 'empty alt text is filled, escaped' );
Harness::assert_true( str_contains( $filled, '<img alt="A &quot;quoted&quot; word" src="c.jpg"' ), 'missing alt text is added' );

Harness::assert_same( 'https://x.test/wp-content/uploads/p.png', ExternalMedia::first_picture( '<a href="https://x.test/wp-content/uploads/f.pdf">f</a><img src="https://x.test/wp-content/uploads/p.png">', [ 'y.test' ] ), 'the first picture shown is a news item\'s fallback picture' );
Harness::assert_same( '', ExternalMedia::first_picture( '<a href="https://x.test/wp-content/uploads/p.png">p</a>', [ 'y.test' ] ), 'a linked picture is not one the item shows' );

Harness::group( 'External media: a picture that is also the main picture' );

$dup_paths = [ '/wp-content/uploads/2026/10/market.jpg' ];

$d = ExternalMedia::drop_image( "<p>Words before.</p>\n\n<p><img class=\"alignnone wp-image-501\" src=\"https://site.test/wp-content/uploads/2026/10/market.jpg\" alt=\"Market\" width=\"509\" /></p>\n<p>Young Marketeers Leeds Market Day 2026</p>", 501, $dup_paths );
Harness::assert_same( "<p>Words before.</p>\n\n<p>Young Marketeers Leeds Market Day 2026</p>", $d['html'], 'the picture and the paragraph that held only it go; the words stay' );
Harness::assert_same( 1, $d['removed'], 'and it is counted' );

$d = ExternalMedia::drop_image( 'Intro.<a href="https://site.test/wp-content/uploads/2026/10/market.jpg"><img src="https://cdn.test/wp-content/uploads/2026/10/market-300x219.jpg" alt=""></a> More.', 0, $dup_paths );
Harness::assert_same( 'Intro. More.', $d['html'], 'a smaller size on the CDN, inside a link that held only it, is the same picture' );

$d = ExternalMedia::drop_image( '<!-- wp:image {"id":501} --><figure class="wp-block-image"><img src="x.jpg" class="wp-image-501"/><figcaption>Caption</figcaption></figure><!-- /wp:image --><p>Text</p>', 501, [] );
Harness::assert_same( '<p>Text</p>', $d['html'], 'an image block goes whole, caption and all' );

$d = ExternalMedia::drop_image( '[caption id="attachment_501" width="300"]<img class="wp-image-501" src="x.jpg" /> A caption[/caption]Text after', 501, [] );
Harness::assert_same( 'Text after', $d['html'], 'a [caption] shortcode goes whole' );

$keep = '<p><img class="wp-image-77" src="https://site.test/wp-content/uploads/2026/10/other.jpg" alt="Other"></p><p><a href="/x">A link <img src="https://site.test/wp-content/uploads/2026/10/market.jpg"> with words</a></p>';
$d    = ExternalMedia::drop_image( $keep, 501, $dup_paths );
Harness::assert_true( str_contains( $d['html'], 'other.jpg' ) && str_contains( $d['html'], '<a href="/x">A link ' ) && str_contains( $d['html'], ' with words</a>' ) && ! str_contains( $d['html'], 'market.jpg' ), 'other pictures stay, and a link with words keeps its words when its picture goes' );
Harness::assert_same( [ 'html' => '<p>Nothing here</p>', 'removed' => 0 ], ExternalMedia::drop_image( '<p>Nothing here</p>', 501, $dup_paths ), 'words without the picture are returned as they were' );
