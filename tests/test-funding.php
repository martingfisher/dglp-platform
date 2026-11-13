<?php
/**
 * The weekly funding import and the funding filters: the pure parts.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
use DGL\Funding\Endpoint;
use DGL\Funding\Facets;
use DGL\Funding\Import;
use DGL\Funding\Vocab;

Harness::group( 'Funding: vocabulary' );

Harness::assert_true( isset( Vocab::options( 'applicants' )['cic'] ), 'the vocabulary file loads' );
Harness::assert_same( [ 'cic', 'registered_charity' ], Vocab::keep( 'applicants', [ 'cic', 'nonsense', 'registered_charity', 'cic' ] ), 'unknown and repeated values are dropped, order kept' );
Harness::assert_same( [], Vocab::keep( 'area', 'leeds' ) === [ 'leeds' ] ? [] : [ 'x' ], 'a single value is accepted as a list' );
Harness::assert_same( '£1,000 to £10,000', Vocab::amounts()['1k_10k']['label'], 'amount bands carry labels' );
Harness::assert_same( null, Vocab::amounts()['50k_plus']['max'], 'the top band is open-ended' );

$fit = Vocab::fit_from_org( [ 'cio_charitable_incorporated_organisation' ], [ 'access_a_learning_disability', 'access_a_visual_impairment', 'cdc_irish', 'circ_veterans', 'not_a_key' ] );
Harness::assert_same( [ 'cio', 'registered_charity' ], $fit['applicants'], 'a CIO can apply where CIOs or charities can' );
Harness::assert_same( [ 'learning_disability', 'disabled_people', 'culturally_diverse', 'veterans' ], $fit['beneficiaries'], 'the longest matching prefix wins, unknown keys are ignored' );
Harness::assert_same( [ 'applicants' => [], 'beneficiaries' => [] ], Vocab::fit_from_org( [], [] ), 'an empty profile fits nothing in particular' );

Harness::group( 'Funding: cleaning a fund from the import' );

$today  = '2026-11-16';
$topics = [ 'grants-and-funding', 'wellbeing', 'featured', 'older-people' ];
$base   = [
	'key'             => 'tnlcf:Awards For All!!',
	'title'           => '<b>Awards for All</b>',
	'funder'          => 'The National Lottery Community Fund',
	'summary'         => 'Grants for community projects.',
	'body'            => "First paragraph.\n\nSecond paragraph.",
	'amount_min'      => '£20,000',
	'amount_max'      => 300,
	'deadline'        => '2026-12-01',
	'rolling'         => false,
	'application_url' => 'https://example.org/apply',
	'fund_type'       => [ 'grant', 'bogus' ],
	'applicants'      => [ 'cic' ],
	'beneficiaries'   => [],
	'area'            => 'england',
	'topics'          => [ 'wellbeing', 'featured', 'made-up' ],
];

$f = Import::normalise( $base, $today, $topics );
Harness::assert_true( null !== $f, 'a good fund is kept' );
Harness::assert_same( 'tnlcf:awards-for-all', $f['key'], 'the key is reduced to a safe slug' );
Harness::assert_same( 'Awards for All', $f['words']['title'], 'tags are stripped from the title' );
Harness::assert_same( 300.0, $f['facts']['amount_min'], 'a range given the wrong way round is swapped' );
Harness::assert_same( 20000.0, $f['facts']['amount_max'], 'a pound sign and commas are read' );
Harness::assert_same( [ 'grant' ], $f['words']['fund_type'], 'unknown categories are dropped' );
Harness::assert_same( [ 'grants-and-funding', 'wellbeing' ], $f['topics'], 'topics: always funding, never a site topic, never an unknown one' );
Harness::assert_same( '2026-12-01', $f['facts']['deadline'], 'a future deadline is kept' );

Harness::assert_same( null, Import::normalise( array_merge( $base, [ 'deadline' => '2026-11-15' ] ), $today, $topics ), 'a fund that closed yesterday is skipped' );
Harness::assert_same( '2026-12-28', Import::normalise( array_merge( $base, [ 'deadline' => null, 'rolling' => true ] ), $today, $topics )['facts']['deadline'], 'a rolling fund gets six weeks' );
Harness::assert_same( '2026-12-28', Import::normalise( array_merge( $base, [ 'deadline' => '2026-02-30' ] ), $today, $topics )['facts']['deadline'], 'a date that does not exist counts as none' );
Harness::assert_true( Import::normalise( array_merge( $base, [ 'deadline' => null, 'rolling' => false ] ), $today, $topics )['facts']['rolling_deadline'], 'no closing date on the page means open all year, never a made-up deadline' );
Harness::assert_same( null, Import::normalise( array_merge( $base, [ 'title' => '' ] ), $today, $topics ), 'no title, no fund' );
Harness::assert_same( '', Import::normalise( array_merge( $base, [ 'application_url' => 'http://example.org' ] ), $today, $topics )['facts']['application_url'], 'a link that is not https is dropped' );
Harness::assert_same( '', Import::normalise( array_merge( $base, [ 'application_url' => 'javascript:alert(1)' ] ), $today, $topics )['facts']['application_url'], 'a script link is dropped' );
Harness::assert_same( [ 'grant' ], Import::normalise( array_merge( $base, [ 'fund_type' => [] ] ), $today, $topics )['words']['fund_type'], 'a fund with no kind is a grant' );
Harness::assert_same( '', Import::normalise( array_merge( $base, [ 'area' => 'mars' ] ), $today, $topics )['words']['area'], 'an unknown area is dropped' );
Harness::assert_same( 'Awards for All', Import::normalise( array_merge( $base, [ 'summary' => '' ] ), $today, $topics )['words']['summary'], 'no summary falls back to the title' );

$long = str_repeat( 'Word ', 400 ) . 'end.';
Harness::assert_true( str_word_count( Import::normalise( array_merge( $base, [ 'body' => $long ] ), $today, $topics )['words']['body'] ) <= 261, 'the body is capped in words' );
Harness::assert_same( "<p>One &amp; two</p>\n<p>&lt;b&gt;three</p>", Import::body_html( "One & two\n\n<b>three" ), 'the body becomes escaped paragraphs' );

Harness::assert_same( null, Import::money( 0 ), 'zero is no amount' );
Harness::assert_same( null, Import::money( 'about ten grand' ), 'words are no amount' );
Harness::assert_same( 1500.0, Import::money( '1,500' ), 'commas are read' );

Harness::group( 'Funding: the sync token' );

$hash = hash( 'sha256', 'secret-token' );
Harness::assert_true( Endpoint::matches( 'secret-token', $hash ), 'the right token passes' );
Harness::assert_false( Endpoint::matches( 'secret-tokem', $hash ), 'a wrong token fails' );
Harness::assert_false( Endpoint::matches( '', $hash ), 'no token fails' );
Harness::assert_false( Endpoint::matches( 'secret-token', '' ), 'no saved hash fails everything' );

Harness::group( 'Funding: filters' );

$req = Facets::from_request( [ 'kind' => 'GRANT', 'who' => 'cic', 'area' => 'nowhere', 'amount' => '1k_10k', 'closing' => 'rolling', 'fit' => 'mine', 'for' => [ 'x' ] ] );
Harness::assert_same( 'grant', $req['kind'], 'a known value is kept, case aside' );
Harness::assert_same( '', $req['area'], 'an unknown value is dropped' );
Harness::assert_same( '', $req['for'], 'an array where a value belongs is dropped' );
Harness::assert_same( 'mine', $req['fit'], 'fit=mine is kept' );

Harness::assert_same( [], Facets::meta_query( Facets::from_request( [] ), $today ), 'no filters, no clauses' );

$mq = Facets::meta_query( [ 'who' => 'cic' ], $today );
Harness::assert_same( [ 'key' => 'dgl_applicants', 'value' => '"cic"', 'compare' => 'LIKE' ], $mq[0], 'a choice matches its quoted key' );

$mq = Facets::meta_query( [ 'for' => 'carers' ], $today );
Harness::assert_same( 'NOT EXISTS', $mq[0][0]['compare'], 'a fund open to anyone matches any "work with"' );

$mq = Facets::meta_query( [ 'amount' => '1k_10k' ], $today );
Harness::assert_same( 1000.0, $mq[0][0]['value'], 'the fund must go at least as high as the band floor' );
Harness::assert_same( 10000.0, $mq[0][1][1]['value'], 'and start no higher than the band top' );
Harness::assert_same( 2, count( Facets::meta_query( [ 'amount' => '50k_plus' ], $today )[0] ), 'the top band has no ceiling' );

$mq = Facets::meta_query( [ 'closing' => '2_weeks' ], $today );
Harness::assert_same( [ '2026-11-16', '2026-11-30' ], $mq[0][0]['value'], 'closing in two weeks is today to fourteen days on' );

$mq = Facets::meta_query( [ 'fit' => 'mine' ], $today, [ 'applicants' => [ 'cio', 'registered_charity' ], 'beneficiaries' => [] ] );
Harness::assert_same( 3, count( $mq[0] ) - 1, 'fit: no named applicants, or one of ours' );
Harness::assert_same( 2, count( $mq ), 'fit with no people adds no people clause' );
Harness::assert_same( [], Facets::meta_query( [ 'fit' => 'mine' ], $today, null ), 'fit with no organisation narrows nothing' );

Harness::group( 'Funding: the card line' );

Harness::assert_same( [ 'Funder', '£300 to £20,000', 'Closes 1 Dec 2026' ], Cards::fund_meta( 'Funder', 300.0, 20000.0, '2026-12-01', false ), 'funder, range and closing date' );
Harness::assert_same( [ 'Up to £500', 'Open all year' ], Cards::fund_meta( '', '', '500', '2026-12-28', true ), 'a rolling fund says so, not its moving date' );
Harness::assert_same( [ 'From £1,000' ], Cards::fund_meta( '', 1000, '', '', false ), 'a floor alone' );
Harness::assert_same( [ '£5,000' === '' ? '' : 'Up to £5,000' ], Cards::fund_meta( '', 5000, 5000, '', false ), 'the same floor and top is one amount' );

Harness::group( 'Funding: the closing leaf' );

defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
$leaf = Cards::fund_date_parts( '2026-12-15', false, '2026-11-13' );
Harness::assert_same( [ 'rolling' => false, 'month' => 'Dec', 'day' => '15', 'iso' => '2026-12-15', 'soon' => false ], $leaf, 'a closing date gives month, day and its date' );
Harness::assert_true( Cards::fund_date_parts( '2026-11-27', false, '2026-11-13' )['soon'], 'fourteen days out is soon' );
Harness::assert_false( Cards::fund_date_parts( '2026-11-28', false, '2026-11-13' )['soon'], 'fifteen is not' );
Harness::assert_true( Cards::fund_date_parts( '2026-12-28', true, '2026-11-13' )['rolling'], 'a rolling fund never shows its refresh date' );
Harness::assert_same( '', Cards::fund_date_parts( '2026-12-28', true, '2026-11-13' )['day'], 'and has no day' );
Harness::assert_same( null, Cards::fund_date_parts( '', false ), 'no date and not rolling, no leaf' );

Harness::group( 'Funding: the list of funders\' pages' );

use DGL\Funding\Sources;

Harness::assert_same( 'https://example.org/grants', Sources::clean_url( 'example.org/grants' ), 'a bare address gets https' );
Harness::assert_same( 'https://example.org/grants', Sources::clean_url( 'http://example.org/grants' ), 'http becomes https' );
Harness::assert_same( '', Sources::clean_url( 'not a web address' ), 'nonsense is refused' );
Harness::assert_same( '', Sources::clean_url( 'javascript:alert(1)' ), 'a script link is refused' );
Harness::assert_same( 'the-key-fund-2', Sources::make_id( 'The Key Fund!', [ 'the-key-fund' ] ), 'an id is made from the name and never repeats' );
Harness::assert_true( Sources::same_page( 'https://www.example.org/apply/', 'http://example.org/apply' ), 'the same page with or without www, https or a trailing slash' );
Harness::assert_false( Sources::same_page( 'https://example.org/apply', 'https://example.org/about' ), 'different pages differ' );

$existing = [ [ 'id' => 'key-fund', 'name' => 'Key Fund', 'url' => 'https://thekeyfund.co.uk/apply' ] ];
Harness::assert_true( is_string( Sources::prepare( '', 'https://a.org', '', $existing ) ), 'no name is refused' );
Harness::assert_true( is_string( Sources::prepare( 'A', 'nope', '', $existing ) ), 'no usable address is refused' );
Harness::assert_true( str_contains( (string) Sources::prepare( 'Again', 'https://www.thekeyfund.co.uk/apply/', '', $existing ), 'Key Fund' ), 'a page already on the list is refused, naming it' );
$fresh = Sources::prepare( '  Leeds <b>Fund</b> ', 'leedsfund.org/grants', 'Small grants', $existing );
Harness::assert_same( [ 'id' => 'leeds-fund', 'name' => 'Leeds Fund', 'url' => 'https://leedsfund.org/grants', 'notes' => 'Small grants' ], $fresh, 'a good one is cleaned and given an id' );

$req = Facets::from_request( [ 'source' => 'key-fund' ] );
Harness::assert_same( 'key-fund', $req['source'], 'the source filter takes an id' );
Harness::assert_same( '', Facets::from_request( [ 'source' => "x' OR 1=1" ] )['source'], 'and nothing else' );
Harness::assert_same( [ 'key' => 'dgl_funding_source', 'value' => 'key-fund', 'compare' => '=' ], Facets::meta_query( [ 'source' => 'key-fund' ], '2026-11-16' )[0], 'and narrows to that source\'s funds' );
