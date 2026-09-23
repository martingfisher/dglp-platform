<?php
/**
 * Duplicates: whether a described organisation is one already on the list.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Org\Duplicates;

Harness::group( 'Duplicates: a name as it is compared' );

Harness::assert_same( 'leeds mind', Duplicates::normalise_name( 'The Leeds Mind Ltd.' ), 'the, punctuation and Ltd go' );
Harness::assert_same( 'leeds mind', Duplicates::normalise_name( 'Leeds  Mind CIC' ), 'double spaces and CIC go' );
Harness::assert_same( 'leeds mind', Duplicates::normalise_name( 'LEEDS MIND C.I.C.' ), 'a dotted C.I.C. goes too' );
Harness::assert_same( 'leeds mind', Duplicates::normalise_name( 'Leeds Mind (Registered Charity)' ), 'a bracketed registered charity goes' );
Harness::assert_same( 'leeds mind', Duplicates::normalise_name( 'Leeds Mind Limited UK' ), 'two suffixes in a row both go' );
Harness::assert_same( 'leeds mind trust', Duplicates::normalise_name( 'Leeds Mind Trust' ), 'Trust stays: it tells organisations apart' );
Harness::assert_same( 'cafe leeds', Duplicates::normalise_name( 'Café Léeds' ), 'accents are folded' );
Harness::assert_same( 'arts and minds', Duplicates::normalise_name( 'Arts & Minds' ), 'an ampersand is "and"' );
Harness::assert_same( 'theatre company', Duplicates::normalise_name( 'Theatre Company' ), 'a name starting with "the..." keeps its word' );
Harness::assert_same( 'ltd', Duplicates::normalise_name( 'Ltd' ), 'a name that is only a suffix is left alone rather than emptied' );
Harness::assert_same( '', Duplicates::normalise_name( '  ' ), 'nothing is nothing' );

Harness::group( 'Duplicates: words, numbers, postcodes and websites' );

Harness::assert_same( [ 'leeds', 'mind' ], Duplicates::tokens( 'leeds mind and mind' ), 'words once each, joining words dropped' );
Harness::assert_same( '1234567', Duplicates::normalise_number( 'Charity no. 1 234 567' ), 'a charity number is its digits' );
Harness::assert_same( 'SC012345', Duplicates::normalise_number( 'sc012345' ), 'a Scottish number keeps its letters, upper-cased' );
Harness::assert_same( '09876543', Duplicates::normalise_number( 'Company number: 09876543' ), 'the label is taken off the front' );
Harness::assert_same( '', Duplicates::normalise_number( 'none' ), '"none" is not a number' );
Harness::assert_same( '', Duplicates::normalise_number( 'n/a' ), 'nor is n/a' );
Harness::assert_same( '', Duplicates::normalise_number( '123' ), 'nor three digits' );
Harness::assert_same( 'LS14AP', Duplicates::normalise_postcode( 'ls1 4ap' ), 'a postcode is upper-case with no space' );
Harness::assert_same( 'LS274PQ', Duplicates::normalise_postcode( ' LS27 4PQ ' ), 'with a two-digit district' );
Harness::assert_same( '', Duplicates::normalise_postcode( 'not one' ), 'rubbish is empty' );
Harness::assert_same( 'leedsmind.org.uk', Duplicates::website_domain( 'https://www.leedsmind.org.uk/about' ), 'a website is its domain' );
Harness::assert_same( 'leedsmind.wordpress.com', Duplicates::website_domain( 'https://leedsmind.wordpress.com' ), 'a subdomain on a builder identifies one organisation' );
Harness::assert_same( '', Duplicates::website_domain( 'https://www.facebook.com/leedsmind' ), 'a Facebook page identifies Facebook' );
Harness::assert_same( '', Duplicates::website_domain( 'gmail.com' ), 'a public provider identifies nobody' );

Harness::group( 'Duplicates: similar names' );

Harness::assert_true( Duplicates::jaccard( [ 'leeds', 'mind' ], [ 'leeds', 'mind', 'wellbeing' ] ) >= Duplicates::JACCARD_MIN, 'two of three words shared is similar' );
Harness::assert_false( Duplicates::jaccard( [ 'leeds', 'community', 'trust' ], [ 'leeds', 'community', 'foundation' ] ) >= Duplicates::JACCARD_MIN, 'two of four is not' );
Harness::assert_true( Duplicates::name_similarity( 'armley helping hands', 'armley helping hand' ) >= Duplicates::SIMILAR_MIN, 'a missing letter is similar' );
Harness::assert_false( Duplicates::name_similarity( 'leeds mind', 'leeds mencap' ) >= Duplicates::SIMILAR_MIN, 'a different word is not' );
Harness::assert_same( 0.0, Duplicates::name_similarity( '', 'leeds mind' ), 'nothing is similar to nothing' );

$list = [
	[ 'id' => 1, 'name' => 'Leeds Mind', 'domains' => [ 'leedsmind.org.uk' ], 'website' => 'https://www.leedsmind.org.uk', 'number' => '1007625', 'postcode' => 'LS6 2AB', 'status' => 'approved' ],
	[ 'id' => 2, 'name' => 'Hollybush Conservation Volunteers', 'domains' => [], 'website' => '', 'number' => '', 'postcode' => 'LS5 3BP', 'status' => 'approved' ],
	[ 'id' => 3, 'name' => 'Armley Helping Hands', 'domains' => [], 'website' => 'https://armleyhelpinghands.org', 'number' => '', 'postcode' => 'LS12 1AA', 'status' => 'pending' ],
	[ 'id' => 4, 'name' => 'Nowhere Collective', 'domains' => [ 'nowhere.test' ], 'website' => '', 'number' => '', 'postcode' => '', 'status' => 'pending', 'trashed' => true ],
	[ 'id' => 5, 'name' => 'St Luke\'s Cares', 'domains' => [], 'website' => 'https://www.facebook.com/stlukescares', 'number' => '', 'postcode' => 'LS11 7DF', 'status' => 'approved' ],
	[ 'id' => 6, 'name' => 'Beeston Hub', 'domains' => [], 'website' => '', 'number' => '', 'postcode' => 'LS11 7DF', 'status' => 'approved' ],
];

$find = static fn( array $probe, int $exclude = 0 ): array => Duplicates::find( array_merge( [ 'name' => '', 'website' => '', 'email_domain' => '', 'number' => '', 'postcode' => '' ], $probe ), $list, $exclude );
$ids  = static fn( array $matches ): array => array_map( static fn( array $m ): int => $m['id'], $matches );

Harness::group( 'Duplicates: hard matches block' );

$m = $find( [ 'name' => 'The Leeds Mind Ltd' ] );
Harness::assert_same( [ 1 ], $ids( $m ), 'the same name with a suffix finds the organisation' );
Harness::assert_same( Duplicates::HARD, $m[0]['strength'], 'and it is hard' );
Harness::assert_same( [ 'name_exact' ], $m[0]['reasons'], 'for the name' );

$m = $find( [ 'name' => 'Mind in Leeds', 'website' => 'http://leedsmind.org.uk/' ] );
Harness::assert_same( Duplicates::HARD, $m[0]['strength'], 'the same website as a recorded domain is hard' );
Harness::assert_true( in_array( 'website', $m[0]['reasons'], true ), 'for the website' );

$m = $find( [ 'name' => 'Helping Hands of Armley', 'website' => 'https://armleyhelpinghands.org/contact' ] );
Harness::assert_same( [ 3 ], $ids( $m ), 'the same website as a recorded website is found even with no recorded domain' );
Harness::assert_same( Duplicates::HARD, $m[0]['strength'], 'and is hard' );
Harness::assert_same( 'pending', $m[0]['status'], 'a pending organisation is offered like any other' );

$m = $find( [ 'name' => 'Something Else', 'email_domain' => 'leedsmind.org.uk' ] );
Harness::assert_same( Duplicates::HARD, $m[0]['strength'], 'an email domain an organisation records is hard' );
Harness::assert_same( [ 'email_domain' ], $m[0]['reasons'], 'for the domain' );

$m = $find( [ 'name' => 'Something Else', 'email_domain' => 'armleyhelpinghands.org' ] );
Harness::assert_same( [ 3 ], $ids( $m ), 'an email domain matching an organisation website is hard too' );

$m = $find( [ 'name' => 'Something Else', 'number' => 'Charity 1007625' ] );
Harness::assert_same( [ 'number' ], $m[0]['reasons'], 'the same charity number is hard' );
Harness::assert_same( Duplicates::HARD, $m[0]['strength'], 'whatever the name' );

$m = $find( [ 'name' => 'Leeds Mind Wellbeing', 'postcode' => 'ls6 2ab' ] );
Harness::assert_same( Duplicates::HARD, $m[0]['strength'], 'a similar name at the same postcode is hard' );
Harness::assert_same( [ 'name_similar', 'postcode_and_name' ], $m[0]['reasons'], 'with both reasons' );

Harness::group( 'Duplicates: soft matches ask' );

$m = $find( [ 'name' => 'Leeds Mind Wellbeing' ] );
Harness::assert_same( Duplicates::SOFT, $m[0]['strength'], 'a similar name alone is soft' );
Harness::assert_same( [ 'name_similar' ], $m[0]['reasons'], 'for the name' );

$m = $find( [ 'name' => 'Hollybush' ] );
Harness::assert_same( [ 2 ], $ids( $m ), 'a name that starts another finds it' );
Harness::assert_same( Duplicates::SOFT, $m[0]['strength'], 'softly' );

$m = $find( [ 'name' => 'Beeston' ] );
Harness::assert_same( [], $ids( $m ), 'but not when the shorter name is under eight letters' );

$m = $find( [ 'name' => 'Cottingley Cornerstone', 'postcode' => 'LS11 7DF' ] );
Harness::assert_same( [ 6, 5 ], $ids( $m ), 'the same postcode alone finds everybody in the building, by name' );
Harness::assert_same( Duplicates::SOFT, $m[0]['strength'], 'and is soft' );
Harness::assert_same( [ 'postcode' ], $m[0]['reasons'], 'for the postcode' );

$m = $find( [ 'name' => 'Some Group', 'website' => 'https://www.facebook.com/somegroup' ] );
Harness::assert_same( [], $ids( $m ), 'a Facebook page never matches another Facebook page' );

Harness::group( 'Duplicates: the bin, exclusions and order' );

$m = $find( [ 'name' => 'Nowhere Collective', 'email_domain' => 'nowhere.test' ] );
Harness::assert_same( [ 4 ], $ids( $m ), 'a refused registration is still found' );
Harness::assert_same( Duplicates::SOFT, $m[0]['strength'], 'but never hard' );
Harness::assert_true( $m[0]['trashed'], 'and flagged as in the bin' );
Harness::assert_same( [ 'name_exact', 'email_domain' ], $m[0]['reasons'], 'with its reasons kept for the team' );
Harness::assert_same( [], Duplicates::hard( $m ), 'hard() never returns it' );
Harness::assert_same( [], Duplicates::soft( $m ), 'soft() leaves it out for the person' );
Harness::assert_same( [ 4 ], $ids( Duplicates::soft( $m, true ) ), 'and includes it for the team' );

Harness::assert_same( [], $ids( $find( [ 'name' => 'Leeds Mind' ], 1 ) ), 'the record being checked is not its own duplicate' );

$m = $find( [ 'name' => 'Leeds Mind Wellbeing', 'number' => '1007625', 'postcode' => 'LS11 7DF' ] );
Harness::assert_same( [ 1, 6, 5 ], $ids( $m ), 'hard first, then by name' );
Harness::assert_same( [ Duplicates::HARD, Duplicates::SOFT, Duplicates::SOFT ], array_map( static fn( array $x ): string => $x['strength'], $m ), 'in that order' );

Harness::assert_same( [ 1 ], $ids( Duplicates::hard( $m ) ), 'hard() keeps the blocker' );
Harness::assert_same( [ 6, 5 ], $ids( Duplicates::soft( $m ) ), 'soft() keeps the questions' );

Harness::assert_same( [], $find( [] ), 'nothing typed matches nothing' );

Harness::group( 'Duplicates: how close an address is to an organisation' );

Harness::assert_same( 'same', Duplicates::domain_closeness( 'leedsmind.org.uk', [ 'leedsmind.org.uk' ], '' ), 'on the recorded domain' );
Harness::assert_same( 'subdomain', Duplicates::domain_closeness( 'mail.leedsmind.org.uk', [ 'leedsmind.org.uk' ], '' ), 'on a subdomain of it' );
Harness::assert_same( 'website', Duplicates::domain_closeness( 'leedsmind.org.uk', [], 'https://www.leedsmind.org.uk' ), 'matching the website when no domain is recorded' );
Harness::assert_same( 'public', Duplicates::domain_closeness( 'gmail.com', [ 'gmail.com' ], '' ), 'a public provider is public even if somebody recorded it' );
Harness::assert_same( 'none', Duplicates::domain_closeness( 'other.org', [ 'leedsmind.org.uk' ], 'https://leedsmind.org.uk' ), 'anything else is nothing' );
Harness::assert_same( 'none', Duplicates::domain_closeness( '', [ 'leedsmind.org.uk' ], '' ), 'no domain is nothing' );

Harness::assert_same( 'same charity or company number', Duplicates::reason_label( 'number' ), 'a reason reads as words' );
