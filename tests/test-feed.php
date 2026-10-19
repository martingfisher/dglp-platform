<?php
/**
 * The topic feed: its attributes are cleaned, its list is stated as data,
 * and every page on DGLP's sheet points at a topic that exists.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Feed;
use DGL\Topics\Topics;

Harness::group( 'Feed: the attributes are cleaned' );

$a = Feed::normalise( [] );
Harness::assert_same( 'news', $a['type'], 'no type means news' );
Harness::assert_same( '', $a['topic'], 'no topic means any topic' );
Harness::assert_same( 4, $a['count'], 'four by default, one row' );
Harness::assert_same( null, $a['heading'], 'no heading given means the type\'s own' );
Harness::assert_true( $a['link'], 'the All link is on by default' );

Harness::assert_same( 'event', Feed::normalise( [ 'type' => 'events' ] )['type'], 'events (plural) is read as event' );
Harness::assert_same( 'event', Feed::normalise( [ 'TYPE' => ' Event ' ] )['type'], 'case and spaces do not matter' );
Harness::assert_same( 'training', Feed::normalise( [ 'type' => 'training' ] )['type'], 'training is a type' );
Harness::assert_same( 'news', Feed::normalise( [ 'type' => 'grants' ] )['type'], 'a type the block does not offer falls back to news' );
Harness::assert_same( 'news', Feed::normalise( [ 'type' => '"><script>' ] )['type'], 'junk falls back to news' );

Harness::assert_same( 'older-people', Feed::normalise( [ 'topic' => ' Older-People ' ] )['topic'], 'a topic slug is lowered and trimmed' );
Harness::assert_same( '', Feed::normalise( [ 'topic' => 'Older People!' ] )['topic'], 'something that is not a slug is no topic' );
Harness::assert_same( '', Feed::normalise( [ 'topic' => "a' OR 1=1" ] )['topic'], 'an injection attempt is no topic' );

Harness::assert_same( 1, Feed::normalise( [ 'count' => '0' ] )['count'], 'count has a floor of one' );
Harness::assert_same( 12, Feed::normalise( [ 'count' => '500' ] )['count'], 'count has a ceiling of twelve' );
Harness::assert_same( 4, Feed::normalise( [ 'count' => 'lots' ] )['count'], 'a count that is not a number is the default' );
Harness::assert_same( 8, Feed::normalise( [ 'count' => 8 ] )['count'], 'a good count is kept' );

Harness::assert_same( '', Feed::normalise( [ 'heading' => '' ] )['heading'], 'an empty heading is no heading, not the default' );
Harness::assert_same( 'Coming up', Feed::normalise( [ 'heading' => '<b>Coming up</b>' ] )['heading'], 'tags are stripped from a heading' );
Harness::assert_false( Feed::normalise( [ 'link' => 'no' ] )['link'], 'link="no" turns the link off' );
Harness::assert_false( Feed::normalise( [ 'link' => 'false' ] )['link'], 'link="false" turns the link off' );

Harness::group( 'Feed: the list is stated as data' );

$list = Feed::item_list( 'dgl-feed-2', 'Latest news', 'https://example.org/page/', [ [ 'https://example.org/n/1/', 'One' ], [ 'https://example.org/n/2/', 'Two' ] ] );
Harness::assert_same( 'ItemList', $list['@type'], 'an ItemList' );
Harness::assert_same( 'https://example.org/page/#dgl-feed-2', $list['@id'], 'its id is the page plus the block\'s own id, so three blocks do not collide' );
Harness::assert_same( 2, count( $list['itemListElement'] ), 'one entry per item' );
Harness::assert_same( 2, $list['itemListElement'][1]['position'], 'positions run from one' );
Harness::assert_same( 'Two', $list['itemListElement'][1]['name'], 'each entry names its item' );
Harness::assert_same( 'https://example.org/n/1/', $list['itemListElement'][0]['url'], 'and links to it' );

$a = Feed::item_list( 'dgl-feed-1', 'x', 'https://example.org/p/', [] )['@id'];
$b = Feed::item_list( 'dgl-feed-2', 'x', 'https://example.org/p/', [] )['@id'];
Harness::assert_true( $a !== $b, 'two blocks on one page get two ids' );

Harness::group( 'Feed: every page on the sheet maps to a topic that exists' );

$topics = Topics::all();

foreach ( Feed::page_topics() as $page => $slug ) {
	Harness::assert_true( isset( $topics[ $slug ] ), "{$page} uses a listed topic ({$slug})" );
}

Harness::assert_same( 'children-and-young-people', Feed::page_topics()['Young Lives Leeds'], 'Young Lives Leeds, the demo page, is Children and Young People' );
Harness::assert_same( 12, count( Feed::page_topics() ), 'twelve pages are mapped; Workforce Wellbeing is left for the team' );
