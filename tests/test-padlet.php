<?php
/**
 * The wellbeing board from Padlet: the pure parts.
 *
 * The feed fixtures follow the shape of Padlet's two real feeds for Leeds
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
Harness::assert_same( 'Podcast', Board::kind( 'https://open.spotify.com/show/1' ), 'Spotify is a podcast' );
Harness::assert_same( 'Video', Board::kind( 'https://www.ted.com/talks/x' ), 'a TED talk is a video' );
Harness::assert_same( 'Document', Board::kind( 'https://drive.google.com/file/d/1' ), 'a Drive file is a document' );
Harness::assert_same( '', Board::kind( 'https://www.leedsmind.org.uk/' ), 'a website is just a website' );

Harness::group( 'Wellbeing board: pictures' );

$a = Board::pic_base( 'https://u1.padletusercontent.com/uploads/x/Screenshot.png?expiry_token=AAA' );
$b = Board::pic_base( 'https://u1.padletusercontent.com/uploads/x/Screenshot.png?expiry_token=BBB' );
Harness::assert_true( 1 === preg_match( '/^[a-f0-9]{32}$/', $a ), 'a picture is kept under a hash of its address' );
Harness::assert_same( $a, $b, 'a fresh Padlet token tomorrow is the same picture, not a new copy' );
Harness::assert_true( Board::pic_base( 'https://images.example.org/p?id=1' ) !== Board::pic_base( 'https://images.example.org/p?id=2' ), 'elsewhere the query names the picture, so two pictures are two copies' );
Harness::assert_same( '', Board::pic_base( 'javascript:alert(1)' ), 'nothing unsafe is fetched' );

Harness::group( 'Wellbeing board: reading the board\'s own data' );

// Shaped like Padlet's /api/10/wishes and /api/10/wall_sections answers on 25 October 2026.
$sections_json = '{"data":[{"id":"137217582","type":"wall_section","attributes":{"id":137217582,"title":"Men’s Wellbeing","sort_index":4096}},{"id":"137217244","type":"wall_section","attributes":{"id":137217244,"title":"Mental Wellbeing","sort_index":0}},{"id":"x","type":"wall_section","attributes":{"id":"not-a-number","title":"Bad"}}]}';
$wish          = static fn( array $a ): array => [ 'id' => $a['id'], 'type' => 'wish', 'attributes' => $a + [ 'published' => true, 'visibility' => 'everyone', 'is_content_hidden' => false, 'wall_id' => 174909383, 'permalink' => 'https://padlet.com/lhca1/board/wish/' . $a['id'] ] ];
$preview       = static fn( string $name ): array => [ 'url' => 'https://padlet-artifacts.storage.googleapis.com/abc/' . $name . '.png', 'width' => 1200, 'height' => 630 ];
$wishes_json   = json_encode(
	[
		'data' => [
			$wish( [ 'id' => 1, 'wall_section_id' => 137217244, 'sort_index' => 900, 'subject' => '', 'body' => '<div>Mental health encompasses emotional wellbeing.</div>', 'attachment' => 'https://media3.giphy.com/media/x/giphy.gif', 'attachment_link' => [ 'content_category' => 'photo', 'content_type' => 'image/gif', 'preview_image' => $preview( 'gif' ) ] ] ),
			$wish( [ 'id' => 2, 'wall_section_id' => 137217244, 'sort_index' => 100, 'subject' => 'Calm', 'body' => '<p>Sleep, Meditation, Relaxation.</p>', 'attachment' => 'https://www.calm.com/', 'attachment_link' => [ 'content_category' => 'page', 'content_type' => 'text/html', 'title' => 'Experience Calm', 'description' => 'Calm is the #1 app.', 'preview_image' => $preview( 'calm' ) ] ] ),
			$wish( [ 'id' => 3, 'wall_section_id' => 137217244, 'sort_index' => 500, 'subject' => 'Leeds Mental Wellbeing Service ', 'body' => '', 'attachment' => 'https://www.leedscommunityhealthcare.nhs.uk/mws/', 'attachment_link' => [ 'content_category' => 'page', 'content_type' => 'text/html', 'description' => 'Talking therapies for people in Leeds.', 'preview_image' => $preview( 'lch' ) ] ] ),
			$wish( [ 'id' => 4, 'wall_section_id' => 137217582, 'sort_index' => 10, 'subject' => 'Man Down', 'body' => '', 'attachment' => 'https://www.amazon.co.uk/Man-Down/dp/1787832503/ref=asc_df', 'attachment_link' => [ 'content_category' => 'page', 'preview_image' => $preview( 'book' ) ] ] ),
			$wish( [ 'id' => 5, 'wall_section_id' => 137217582, 'sort_index' => 20, 'subject' => '', 'body' => '', 'attachment' => 'https://www.citizensadvice.org.uk/benefits/', 'attachment_link' => [ 'content_category' => 'page', 'content_type' => 'text/html', 'title' => 'Benefits', 'description' => 'Get advice on benefits.', 'preview_image' => $preview( 'cab' ) ] ] ),
			$wish( [ 'id' => 6, 'wall_section_id' => 137217582, 'sort_index' => 30, 'subject' => 'Move your Way', 'body' => '<p>A free <a href="https://www.mindwell-leeds.org.uk/move.pdf">guide</a>.</p>', 'attachment' => 'https://u1.padletusercontent.com/uploads/s.png?expiry_token=AAA', 'attachment_link' => [ 'content_category' => 'photo', 'content_type' => 'image/png', 'preview_image' => [ 'url' => 'https://u1.padletusercontent.com/uploads/s.png?expiry_token=AAA' ] ] ] ),
			$wish( [ 'id' => 7, 'wall_section_id' => 137217582, 'sort_index' => 40, 'subject' => 'Hidden draft', 'published' => false ] ),
			$wish( [ 'id' => 8, 'wall_section_id' => null, 'sort_index' => 50, 'subject' => 'Loose post <script>x</script>', 'body' => '', 'attachment' => 'https://www.ted.com/talks/x', 'attachment_link' => [ 'content_category' => 'video', 'content_type' => 'text/html' ] ] ),
			$wish( [ 'id' => 9, 'wall_section_id' => 137217244, 'sort_index' => 60, 'subject' => 'Sneaky', 'attachment' => 'javascript:alert(1)' ] ),
			[ 'id' => 'junk' ],
		],
		'meta' => [ 'next' => null ],
	]
);

Harness::assert_true( null === Board::json_rows( '<html>Rate limited</html>' ), 'an HTML error page is not the board' );
Harness::assert_true( null === Board::json_rows( '{"errors":[{"status":"400"}]}' ), 'nor is an error answer' );
Harness::assert_same( 9, count( (array) Board::json_rows( $wishes_json ) ), 'a row with no attributes is dropped' );

$pb  = Board::parse_board( (array) Board::json_rows( $wishes_json ), (array) Board::json_rows( $sections_json ) );
$pbt = array_column( $pb['items'], null, 'key' );
$pbo = array_column( $pb['left_out'], 'why', 'key' );

Harness::assert_same( [ 's137217244', 's137217582' ], array_column( $pb['sections'], 'id' ), 'the columns, in the board\'s order; a column with a bad id is dropped' );
Harness::assert_same( 'Men’s Wellbeing', $pb['sections'][1]['name'], 'a column keeps its own name' );
Harness::assert_same( 'Mental health encompasses emotional wellbeing.', $pb['sections'][0]['intro'], 'a column header post\'s words are the column\'s introduction' );
Harness::assert_same( Board::LEFT_UNTITLED, $pbo['1'] ?? '', 'and the header post is not a card' );
Harness::assert_same( Board::LEFT_SHOP, $pbo['4'] ?? '', 'an Amazon link is left out' );
Harness::assert_false( isset( $pbt['7'] ) || isset( $pbo['7'] ), 'an unpublished post is not read at all' );
Harness::assert_same( 'Benefits', $pbt['5']['title'] ?? '', 'a post with no title of its own takes its linked page\'s' );
Harness::assert_same( 'Get advice on benefits.', $pbt['5']['text'] ?? '', 'and a post with no words takes the page\'s own summary' );
Harness::assert_same( 'Leeds Mental Wellbeing Service', $pbt['3']['title'] ?? '', 'titles are trimmed' );
Harness::assert_same( 'Talking therapies for people in Leeds.', $pbt['3']['text'] ?? '', 'the page\'s summary fills an empty post' );
Harness::assert_same( 'Sleep, Meditation, Relaxation.', $pbt['2']['text'] ?? '', 'a post\'s own words come first' );
Harness::assert_same( 'https://padlet-artifacts.storage.googleapis.com/abc/calm.png', $pbt['2']['picture'] ?? '', 'the picture is Padlet\'s thumbnail' );
Harness::assert_same( 's137217244', $pbt['2']['section'] ?? '', 'a post knows its column' );
Harness::assert_same( 100, $pbt['2']['order'] ?? 0, 'and its place in the column' );
Harness::assert_same( 'https://www.mindwell-leeds.org.uk/move.pdf', $pbt['6']['link'] ?? '', 'a post whose attachment is a picture leads where its words point' );
Harness::assert_true( str_starts_with( (string) ( $pbt['6']['picture'] ?? '' ), 'https://u1.padletusercontent.com/' ), 'and shows the picture' );
Harness::assert_same( '', $pbt['8']['section'] ?? 'x', 'a post in no column has no section' );
Harness::assert_same( 'Video', $pbt['8']['kind'] ?? '', 'a video says so' );
Harness::assert_same( 'Loose post x', $pbt['8']['title'] ?? '', 'a title is plain text' );
Harness::assert_same( 'https://padlet.com/lhca1/board/wish/9', $pbt['9']['link'] ?? '', 'an unsafe link leads to the post on Padlet instead' );

Harness::group( 'Wellbeing board: columns as this site shows them' );

$cols = $pb['sections'];
$gr   = Board::grouped( $pb['items'], $cols );
Harness::assert_same( [ 's137217244', 's137217582', 'more' ], array_column( $gr, 'id' ), 'the board\'s columns in order, More support last for posts in no column' );
Harness::assert_same( [ 'Leeds Mental Wellbeing Service', 'Calm', 'Sneaky' ], array_column( $gr[0]['items'], 'title' ), 'within a column, the board\'s own order: highest place first, as Padlet shows it' );
Harness::assert_same( 'Mental Wellbeing', $gr[0]['name'], 'a column is headed with its own name' );

$ov = Board::clean_overrides(
	[
		'names'  => [ 's137217244' => '<b>Mental health</b>', 's137217582' => '   ', 'bad' => 'x' ],
		'intros' => [ 's137217582' => 'Support for men.' ],
		'assign' => [ '2' => 's137217582', '3' => 'more', '<x>' => 's137217582', '5' => 'nowhere' ],
	]
);
Harness::assert_same( [ 's137217244' => 'Mental health' ], $ov['names'], 'a heading is plain text, for a real column id; an empty one is no change' );
Harness::assert_same( [ '2' => 's137217582', '3' => 'more' ], $ov['assign'], 'a post moves only by its id, and only to a column or to More support' );

$gr2 = Board::grouped( $pb['items'], $cols, $ov );
Harness::assert_same( 'Mental health', $gr2[0]['name'], 'a heading changed on this site replaces the board\'s' );
Harness::assert_same( 'Support for men.', $gr2[1]['intro'], 'and so does an introduction' );
Harness::assert_true( in_array( 'Calm', array_column( $gr2[1]['items'], 'title' ), true ) && in_array( 'Leeds Mental Wellbeing Service', array_column( $gr2[2]['items'], 'title' ), true ), 'a moved post shows where it was moved to' );
Harness::assert_same( 'more', end( $gr2 )['id'], 'More support is still last' );

$gone = Board::grouped( $pb['items'], [ $cols[1] ], $ov );
Harness::assert_true( in_array( 'Sneaky', array_column( end( $gone )['items'], 'title' ), true ), 'a post whose column has gone shows under More support' );

Harness::group( 'Wellbeing board: links that have gone' );

Harness::assert_same( 'unknown', Board::link_verdict( [ 404 ] ), 'one "not found" is not enough to hide a card' );
Harness::assert_same( 'gone', Board::link_verdict( [ 404, 404 ] ), 'two in a row is' );
Harness::assert_same( 'gone', Board::link_verdict( [ 200, 410, 404 ] ), '"gone" and "not found" both count, and only the last two answers matter' );
Harness::assert_same( 'gone', Board::link_verdict( [ Board::NO_HOST, Board::NO_HOST ] ), 'a site whose name no longer resolves is gone' );
Harness::assert_same( 'ok', Board::link_verdict( [ 404, 404, 200 ] ), 'a page that comes back is shown again' );
Harness::assert_same( 'unknown', Board::link_verdict( [ 403, 403, 403 ] ), 'a site that refuses servers is never hidden' );
Harness::assert_same( 'unknown', Board::link_verdict( [ 404, 429 ] ), 'nor one that says "too many requests"' );
Harness::assert_same( 'unknown', Board::link_verdict( [ 500, 503 ] ), 'nor one with a server error' );
Harness::assert_same( 'unknown', Board::link_verdict( [ Board::NO_ANSWER, Board::NO_ANSWER ] ), 'nor one that timed out' );
Harness::assert_same( 'unknown', Board::link_verdict( [] ), 'a link never checked is not gone' );

$lnow = 1_800_000_000;
Harness::assert_true( Board::link_due( null, $lnow ), 'a link never checked is due' );
Harness::assert_false( Board::link_due( [ 'answers' => [ 200 ], 'at' => $lnow - DAY_IN_SECONDS ], $lnow ), 'one that answered yesterday is not' );
Harness::assert_true( Board::link_due( [ 'answers' => [ 200 ], 'at' => $lnow - 8 * DAY_IN_SECONDS ], $lnow ), 'one last checked over a week ago is' );
Harness::assert_true( Board::link_due( [ 'answers' => [ 200, 404 ], 'at' => $lnow - 60 ], $lnow ), 'one that has just said "not found" once is checked again straight away, to confirm' );
Harness::assert_false( Board::link_due( [ 'answers' => [ 404, 404 ], 'at' => $lnow - DAY_IN_SECONDS ], $lnow ), 'one already confirmed gone waits a week like the rest' );

$wg_items = [
	[ 'key' => '1', 'title' => 'Barca Leeds', 'link' => 'https://www.barca-leeds.org/men' ],
	[ 'key' => '2', 'title' => 'Calm', 'link' => 'https://www.calm.com/' ],
	[ 'key' => '3', 'title' => 'Bupa', 'link' => 'https://www.bupa.co.uk/hydration' ],
];
[ $wg_shown, $wg_gone ] = Board::without_gone(
	$wg_items,
	[
		md5( 'https://www.barca-leeds.org/men' )   => [ 'answers' => [ 404, 404 ] ],
		md5( 'https://www.bupa.co.uk/hydration' ) => [ 'answers' => [ 403, 403 ] ],
	]
);
Harness::assert_same( [ 'Calm', 'Bupa' ], array_column( $wg_shown, 'title' ), 'only the card whose page has gone is left out' );
Harness::assert_same( [ [ 'key' => '1', 'title' => 'Barca Leeds', 'link' => 'https://www.barca-leeds.org/men', 'why' => Board::LEFT_BROKEN ] ], $wg_gone, 'and it is listed with why' );

Harness::group( 'Wellbeing board: stale and fresh' );

$now = 1_800_000_000;
Harness::assert_same( 1, count( Board::fresh( [ 'items' => [ [ 'key' => '1' ] ], 'fetched' => $now - 10 * DAY_IN_SECONDS ], $now ) ), 'a board ten days old is still shown' );
Harness::assert_same( 0, count( Board::fresh( [ 'items' => [ [ 'key' => '1' ] ], 'fetched' => $now - 31 * DAY_IN_SECONDS ], $now ) ), 'a board over a month old is not' );
Harness::assert_same( Board::GOOD, Board::verdict( [ 'at' => $now, 'ok' => true, 'count' => 1, 'left_out' => 0, 'error' => '', 'source' => 'board' ], $now - 3600, $now ), 'fetched an hour ago is good' );
Harness::assert_same( Board::WARN, Board::verdict( [ 'at' => $now, 'ok' => true, 'count' => 1, 'left_out' => 0, 'error' => 'HTTP 500', 'source' => Board::FROM_FEEDS ], $now - 3600, $now ), 'read from the feeds because the board data did not answer is amber' );
Harness::assert_same( Board::WARN, Board::verdict( [ 'at' => $now, 'ok' => false, 'count' => 0, 'left_out' => 0, 'error' => 'x' ], $now - DAY_IN_SECONDS, $now ), 'a failed night with a current board is amber' );
Harness::assert_same( Board::BAD, Board::verdict( [ 'at' => $now, 'ok' => false, 'count' => 0, 'left_out' => 0, 'error' => 'x' ], $now - 40 * DAY_IN_SECONDS, $now ), 'a stale board is red' );
Harness::assert_same( Board::WARN, Board::verdict( null, 0, $now ), 'never fetched yet is amber, not red' );
