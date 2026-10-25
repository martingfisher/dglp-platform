<?php
/**
 * The wellbeing board from Padlet: the pure parts.
 *
 * The fixtures follow the shape of Padlet's two real feeds for Leeds
 * Health and Care Academy's board as fetched on 25 October 2026: the
 * export feed (outbound link as the enclosure, numeric post id in the
 * link, "Y-m-d H:i:s UTC" dates) and the full feed (description as HTML
 * with the attachment's address printed first, picture or video as the
 * enclosure, RFC 2822 dates). Every awkward case seen in the real feeds is
 * here: column introductions with no title, Amazon book links, an uploaded
 * picture whose post was edited after it was posted (so the two feeds
 * disagree on its time), a video, a link glued to the first word, and the
 * same post twice.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Padlet\Board;

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

$export = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0">
   <channel>
      <title>Health and Wellbeing Support by Leeds Health and Care Academy</title>
      <item>
         <title>Able Futures </title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2315243333</link>
         <description><![CDATA[<div>Able futures provides support.</div>]]></description>
         <enclosure url="https://able-futures.co.uk/" />
         <pubDate>2022-09-27 09:37:10 UTC</pubDate>
      </item>
      <item>
         <title></title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2315442981</link>
         <description><![CDATA[This section includes tailored advice for men.]]></description>
         <enclosure url="https://images.unsplash.com/photo-1499209974431-9dddcece7f88?crop=entropy&amp;fm=jpg" />
         <pubDate>2022-09-27 12:15:39 UTC</pubDate>
      </item>
      <item>
         <title>Rich Dad Poor Dad - Robert T. Kiyosaki</title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2703972238</link>
         <description><![CDATA[]]></description>
         <enclosure url="https://www.amazon.co.uk/Rich-Dad-Poor-Teach-Middle/dp/1612680178/ref=asc_df_1612680178/?tag=googshopuk-21" />
         <pubDate>2023-09-14 10:00:00 UTC</pubDate>
      </item>
      <item>
         <title>Short link book</title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2703972239</link>
         <enclosure url="https://amzn.to/3xYz" />
         <pubDate>2023-09-14 10:01:00 UTC</pubDate>
      </item>
      <item>
         <title>Move your Way</title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2997525110</link>
         <description><![CDATA[<p>This brand new free downloadable resource.</p>]]></description>
         <enclosure url="https://padlet-uploads.storage.googleapis.com/2422934675/1dce/Screenshot_2024_05_17_131340.png" />
         <pubDate>2024-05-17 12:18:02 UTC</pubDate>
      </item>
      <item>
         <title>Menopause support</title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2349032442</link>
         <enclosure url="https://intranet.leedsth.nhs.uk/content/uploads/2022/09/menopause_in_minutes-240p.mp4" />
         <pubDate>2022-10-20 10:00:00 UTC</pubDate>
      </item>
      <item>
         <title>Leeds Mind</title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2703908140</link>
         <enclosure url="https://www.leedsmind.org.uk/" />
         <pubDate>2023-09-14 09:00:00 UTC</pubDate>
      </item>
      <item>
         <title>Leeds Mind</title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2703908141</link>
         <enclosure url="https://www.leedsmind.org.uk/" />
         <pubDate>2023-09-14 09:05:00 UTC</pubDate>
      </item>
      <item>
         <title>Sneaky</title>
         <link>https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2703908142</link>
         <enclosure url="javascript:alert(1)" />
         <pubDate>2023-09-14 09:06:00 UTC</pubDate>
      </item>
      <item>
         <title>Not a Padlet post</title>
         <link>https://example.com/elsewhere</link>
         <enclosure url="https://example.com/" />
      </item>
   </channel>
</rss>
XML;

$full = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:media="http://search.yahoo.com/mrss/" version="2.0">
  <channel>
    <title>Health and Wellbeing Support</title>
    <item>
      <title>Able Futures </title>
      <description><![CDATA[<a href='https://able-futures.co.uk/'>https://able-futures.co.uk/</a><div>Able futures provides support to people working and living with mental health difficulties.</div>]]></description>
      <pubDate>Tue, 27 Sep 2022 09:37:10 +0000</pubDate>
    </item>
    <item>
      <title>Move your Way</title>
      <description><![CDATA[<p>This brand new<a rel="noopener" href="https://www.mindwell-leeds.org.uk/wp-content/uploads/2024/05/Move-Your-Way-2024.pdf">&nbsp;free downloadable resource</a>&nbsp;is full of tips.</p>]]></description>
      <enclosure url="https://u1.padletusercontent.com/uploads/padlet-uploads/2422934675/1dce/Screenshot_2024_05_17_131340.png?expiry_token=AAA" type="image/png"/>
      <pubDate>Fri, 17 May 2024 13:46:16 +0000</pubDate>
    </item>
    <item>
      <title>Menopause support</title>
      <description><![CDATA[<iframe src="https://mediagci.padletcdn.com/v22/player.html#video"></iframe>]]></description>
      <enclosure url="https://intranet.leedsth.nhs.uk/content/uploads/2022/09/menopause_in_minutes-240p.mp4" type="video/mp4"/>
      <pubDate>Thu, 20 Oct 2022 10:00:00 +0000</pubDate>
    </item>
    <item>
      <title>Leeds Mind</title>
      <description><![CDATA[<a href='https://www.leedsmind.org.uk/'>https://www.leedsmind.org.uk/</a>Leeds Mind runs a men's group.]]></description>
      <pubDate>Thu, 14 Sep 2023 09:00:00 +0000</pubDate>
    </item>
    <item>
      <title>Leeds Mind</title>
      <description><![CDATA[Leeds Mind runs a men's group on Thursdays, with tea, in Armley.]]></description>
      <pubDate>Thu, 14 Sep 2023 09:05:00 +0000</pubDate>
    </item>
  </channel>
</rss>
XML;

Harness::group( 'Wellbeing board: reading the two feeds' );

$posts = Board::parse_export( $export );
$extra = Board::parse_full( $full );

Harness::assert_same( 9, count( (array) $posts ), 'every post with a Padlet post address is read; the one without is skipped' );
Harness::assert_same( '2315243333', $posts[0]['key'], 'the post id is the number at the end of its Padlet address' );
Harness::assert_same( 'Able Futures', $posts[0]['title'], 'the title is trimmed' );
Harness::assert_same( 'https://able-futures.co.uk/', $posts[0]['link'], 'the outbound link is the enclosure' );
Harness::assert_same( '', $posts[8]['link'], 'a javascript: link is not kept' );
Harness::assert_true( null === Board::parse_export( '<html><body>Rate limited</body></html>' ), 'an HTML error page is not a feed' );
Harness::assert_true( null === Board::parse_export( '' ), 'nothing is not a feed' );
Harness::assert_same( 5, count( (array) $extra ), 'the full feed is read' );

Harness::group( 'Wellbeing board: what is shown and what is left out' );

$board = Board::merge( (array) $posts, (array) $extra );
$by    = [];

foreach ( $board['items'] as $item ) {
	$by[ $item['title'] ] = $item;
}

$out = [];

foreach ( $board['left_out'] as $post ) {
	$out[ $post['key'] ] = $post['why'];
}

Harness::assert_same( Board::LEFT_SHOP, $out['2703972238'] ?? '', 'an Amazon book link is left out' );
Harness::assert_same( Board::LEFT_SHOP, $out['2703972239'] ?? '', 'an Amazon short link is left out' );
Harness::assert_same( Board::LEFT_UNTITLED, $out['2315442981'] ?? '', 'a column introduction, which has no title, is left out' );
Harness::assert_same( 3, count( $board['left_out'] ), 'nothing else is left out' );

Harness::assert_same( 'Able futures provides support to people working and living with mental health difficulties.', $by['Able Futures']['text'] ?? '', 'the full feed\'s words are used, without the attachment address printed above them' );
Harness::assert_same( "Leeds Mind runs a men's group on Thursdays, with tea, in Armley.", $by['Leeds Mind']['text'] ?? '', 'the same post twice is one card, with the fuller words' );
Harness::assert_same( 1, count( array_filter( $board['items'], static fn( array $i ): bool => 'Leeds Mind' === $i['title'] ) ), 'and only one card' );

$move = $by['Move your Way'] ?? [];
Harness::assert_same( 'https://www.mindwell-leeds.org.uk/wp-content/uploads/2024/05/Move-Your-Way-2024.pdf', $move['link'] ?? '', 'an uploaded picture is not the destination: the link in its words is' );
Harness::assert_true( str_starts_with( (string) ( $move['picture'] ?? '' ), 'https://u1.padletusercontent.com/' ), 'its picture is the full feed\'s copy, found by title though the two feeds disagree on its time' );
Harness::assert_same( 'PDF', $move['kind'] ?? '', 'a PDF says so' );
Harness::assert_same( 'This brand new free downloadable resource is full of tips.', $move['text'] ?? '', 'words around a link keep their spaces' );

$meno = $by['Menopause support'] ?? [];
Harness::assert_same( 'https://intranet.leedsth.nhs.uk/content/uploads/2022/09/menopause_in_minutes-240p.mp4', $meno['link'] ?? '', 'a video leads to the video' );
Harness::assert_same( 'Video', $meno['kind'] ?? '', 'and says it is one' );
Harness::assert_same( '', $meno['text'] ?? 'x', 'an embedded player is not words' );
Harness::assert_same( 'intranet.leedsth.nhs.uk', $meno['host'] ?? '', 'the site it leads to is shown as a reader would say it' );

Harness::assert_same( 'https://padlet.com/lhca1/3sjincg4tfx4hxlm/wish/2703908142', $by['Sneaky']['link'] ?? '', 'a post whose link is unsafe leads to the post on Padlet instead' );

Harness::group( 'Wellbeing board: links' );

Harness::assert_true( Board::is_shop( 'https://www.amazon.co.uk/dp/1' ), 'amazon.co.uk is a shop' );
Harness::assert_true( Board::is_shop( 'https://smile.amazon.com/dp/1' ), 'any Amazon domain is a shop' );
Harness::assert_true( Board::is_shop( 'https://a.co/d/abc' ), 'Amazon\'s a.co short link is a shop' );
Harness::assert_false( Board::is_shop( 'https://www.notamazon.com/' ), 'a name ending in amazon is not Amazon' );
Harness::assert_false( Board::is_shop( 'https://www.nhs.uk/' ), 'the NHS is not a shop' );
Harness::assert_same( '', Board::safe_url( 'javascript:alert(1)' ), 'no javascript: links' );
Harness::assert_same( '', Board::safe_url( 'https://user:pw@example.org/' ), 'no logins in links' );
Harness::assert_same( '', Board::safe_url( 'https://example.org:8080/' ), 'no ports in links' );
Harness::assert_same( '', Board::safe_url( 'https://example.org/"onmouseover="x' ), 'nothing that breaks out of an attribute' );
Harness::assert_same( 'http://example.org/', Board::safe_url( 'http://example.org/' ), 'plain http is a link' );
Harness::assert_same( '', Board::safe_https( 'http://example.org/a.png' ), 'but a picture is only fetched over https' );
Harness::assert_same( 'Podcast', Board::kind( 'https://open.spotify.com/show/1' ), 'Spotify is a podcast' );
Harness::assert_same( 'Video', Board::kind( 'https://www.ted.com/talks/x' ), 'a TED talk is a video' );
Harness::assert_same( 'Document', Board::kind( 'https://drive.google.com/file/d/1' ), 'a Drive file is a document' );
Harness::assert_same( '', Board::kind( 'https://www.leedsmind.org.uk/' ), 'a website is just a website' );

Harness::group( 'Wellbeing board: pictures' );

$a = Board::pic_name( 'https://u1.padletusercontent.com/uploads/x/Screenshot.png?expiry_token=AAA' );
$b = Board::pic_name( 'https://u1.padletusercontent.com/uploads/x/Screenshot.png?expiry_token=BBB' );
Harness::assert_true( 1 === preg_match( '/^[a-f0-9]{32}\.png$/', $a ), 'a picture is kept under a hash of its address' );
Harness::assert_same( $a, $b, 'a fresh token tomorrow is the same picture, not a new copy' );
Harness::assert_same( 'jpg', pathinfo( Board::pic_name( 'https://example.org/a.jpeg' ), PATHINFO_EXTENSION ), 'jpeg is kept as jpg' );
Harness::assert_same( '', Board::pic_name( 'https://example.org/a.svg' ), 'an SVG is not a picture we keep' );
Harness::assert_same( '', Board::pic_name( 'https://example.org/page' ), 'a page is not a picture' );

Harness::group( 'Wellbeing board: groups' );

$clean = Board::clean_grouping(
	[
		'groups' => [
			[ 'id' => 'mental', 'name' => 'Mental health', 'intro' => '<b>Support</b> for the mind.' ],
			[ 'id' => '', 'name' => 'Money & bills!', 'intro' => '' ],
			[ 'id' => 'mental', 'name' => 'Duplicate', 'intro' => '' ],
			[ 'id' => 'gone', 'name' => '   ', 'intro' => 'No name, so dropped.' ],
			[ 'id' => 'more', 'name' => 'Trying to take the default', 'intro' => '' ],
			'not a group',
		],
		'assign' => [
			'111'    => 'mental',
			'222'    => 'money-bills',
			'333'    => 'gone',
			'444'    => 'more',
			'<x>'    => 'mental',
			'555'    => 'nowhere',
		],
	]
);

Harness::assert_same( [ 'mental', 'money-bills' ], array_column( $clean['groups'], 'id' ), 'a new group\'s id is made from its name; duplicates, nameless groups and the reserved id are dropped' );
Harness::assert_same( 'Support for the mind.', $clean['groups'][0]['intro'], 'the line under a heading is plain text' );
Harness::assert_same( [ '111' => 'mental', '222' => 'money-bills', '444' => 'more' ], $clean['assign'], 'a post is only placed in a group that exists, or in More support by choice' );

$items = [
	[ 'key' => '111', 'title' => 'Zebra crossing' ],
	[ 'key' => '112', 'title' => 'New post' ],
	[ 'key' => '113', 'title' => 'apple' ],
	[ 'key' => '444', 'title' => 'Chosen for More' ],
];
$grouped = Board::grouped( $items, [ 'groups' => $clean['groups'], 'assign' => [ '111' => 'mental', '113' => 'mental', '444' => 'more' ] ] );

Harness::assert_same( [ 'mental', 'more' ], array_column( $grouped, 'id' ), 'groups in their order, empty ones left out, More support last' );
Harness::assert_same( [ 'apple', 'Zebra crossing' ], array_column( $grouped[0]['items'], 'title' ), 'a group\'s posts by title, ignoring case' );
Harness::assert_same( [ 'Chosen for More', 'New post' ], array_column( $grouped[1]['items'], 'title' ), 'a post nobody has placed is in More support' );

$seed = Board::seed_assign();
Harness::assert_same( count( $seed ), count( array_unique( array_keys( $seed ) ) ), 'the seed places each post once' );
Harness::assert_true( [] === array_diff( array_unique( array_values( $seed ) ), array_column( Board::seed_groups(), 'id' ) ), 'the seed only uses groups it defines' );

Harness::group( 'Wellbeing board: stale and fresh' );

$now = 1_800_000_000;
Harness::assert_same( 1, count( Board::fresh( [ 'items' => [ [ 'key' => '1' ] ], 'fetched' => $now - 10 * DAY_IN_SECONDS ], $now ) ), 'a board ten days old is still shown' );
Harness::assert_same( 0, count( Board::fresh( [ 'items' => [ [ 'key' => '1' ] ], 'fetched' => $now - 31 * DAY_IN_SECONDS ], $now ) ), 'a board over a month old is not' );
Harness::assert_same( Board::GOOD, Board::verdict( [ 'at' => $now, 'ok' => true, 'count' => 1, 'left_out' => 0, 'error' => '' ], $now - 3600, $now ), 'fetched an hour ago is good' );
Harness::assert_same( Board::WARN, Board::verdict( [ 'at' => $now, 'ok' => false, 'count' => 0, 'left_out' => 0, 'error' => 'x' ], $now - DAY_IN_SECONDS, $now ), 'a failed night with a current board is amber' );
Harness::assert_same( Board::BAD, Board::verdict( [ 'at' => $now, 'ok' => false, 'count' => 0, 'left_out' => 0, 'error' => 'x' ], $now - 40 * DAY_IN_SECONDS, $now ), 'a stale board is red' );
Harness::assert_same( Board::WARN, Board::verdict( null, 0, $now ), 'never fetched yet is amber, not red' );
