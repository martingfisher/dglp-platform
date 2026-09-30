<?php
/**
 * Spaces to hire: the pure parts.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\PostTypes;
use DGL\Schema\FieldRegistry;
use DGL\Schema\Types\Space;
use DGL\Schema\Types\Venue;
use DGL\Spaces\Enquiry;
use DGL\Spaces\Geocode;
use DGL\Spaces\SpacesQuery;

Harness::group( 'Spaces to hire: types, steps and fields' );

Harness::assert_same( [ PostTypes::NEWS, PostTypes::EVENT, PostTypes::TRAINING ], PostTypes::feed_keys(), 'the feed types are the three listings' );
Harness::assert_same( [ PostTypes::NEWS, PostTypes::EVENT, PostTypes::TRAINING, PostTypes::VENUE ], array_keys( PostTypes::menu() ), 'the menu stops at venues' );
Harness::assert_same( PostTypes::KIND_SPACE, PostTypes::kind_of( PostTypes::SPACE ), 'a space knows its kind' );
Harness::assert_same( PostTypes::KIND_LISTING, PostTypes::kind_of( 'dgl_nothing' ), 'an unknown type is a listing' );
Harness::assert_false( PostTypes::has_page( PostTypes::SPACE ), 'a space has no page' );
Harness::assert_true( PostTypes::has_page( PostTypes::VENUE ), 'a venue does' );
Harness::assert_same( 'spaces', PostTypes::definitions()[ PostTypes::VENUE ]['slug'], 'the venue archive is /spaces/' );

Harness::assert_same( [ 1, 2, 3, 4 ], array_keys( FieldRegistry::steps_for( PostTypes::EVENT ) ), 'an event has four steps' );
Harness::assert_same( [ 1, 2, 4 ], array_keys( FieldRegistry::steps_for( PostTypes::SPACE ) ), 'a space has three: no contact step' );
Harness::assert_same( [ 1, 2, 3, 4 ], array_keys( FieldRegistry::steps_for( PostTypes::VENUE ) ), 'a venue keeps its contact step' );
Harness::assert_true( null === FieldRegistry::find( PostTypes::SPACE, 'contact_email' ), 'a space has no contact email' );
Harness::assert_true( null !== FieldRegistry::find( PostTypes::VENUE, 'contact_email' ), 'a venue has one' );
Harness::assert_false( FieldRegistry::has_topics( PostTypes::VENUE ), 'a venue has no topics' );
Harness::assert_true( FieldRegistry::has_topics( PostTypes::NEWS ), 'news does' );
Harness::assert_true( null === Venue::expiry_field() && null === Venue::expiry_fallback() && null === Space::expiry_field() && null === Space::expiry_fallback(), 'neither expires on its own' );
Harness::assert_same( 'rate', FieldRegistry::find( PostTypes::SPACE, 'rate_unit' )->required_with, 'the rate unit is needed when there is a rate' );
Harness::assert_same( 'image_3', FieldRegistry::find( PostTypes::VENUE, 'image_3_alt' )->suggested_from, 'each extra photo has its own words' );
Harness::assert_same( 1, FieldRegistry::find( PostTypes::VENUE, 'image_3' )->step, 'and sits on the basics step with the main photo' );
Harness::assert_false( isset( Venue::wards()['leeds_wide'] ), 'a venue is in one ward, never Leeds-wide' );
Harness::assert_true( isset( Venue::wards()['armley'] ), 'and the wards are the directory\'s' );

Harness::group( 'Spaces to hire: price bands, capacity, quick facts' );

Harness::assert_same( 'on_request', SpacesQuery::price_band( null, '' ), 'no rate is price on request' );
Harness::assert_same( 'free', SpacesQuery::price_band( 0.0, 'hour' ), 'zero is free' );
Harness::assert_same( 'up_to_15', SpacesQuery::price_band( 15.0, 'hour' ), '£15 an hour is up to £15' );
Harness::assert_same( '15_30', SpacesQuery::price_band( 15.01, 'hour' ), '£15.01 is the next band' );
Harness::assert_same( '15_30', SpacesQuery::price_band( 30.0, 'hour' ), '£30 stays in it' );
Harness::assert_same( 'over_30', SpacesQuery::price_band( 30.5, 'hour' ), 'over £30' );
Harness::assert_same( 'other', SpacesQuery::price_band( 250.0, 'day' ), 'a day rate is priced but in no hourly band' );

Harness::assert_true( SpacesQuery::matches_people( [ 'cap_theatre' => 150, 'cap_boardroom' => 30 ], 100 ), 'any layout that fits counts' );
Harness::assert_false( SpacesQuery::matches_people( [ 'cap_theatre' => 50, 'cap_boardroom' => 30 ], 100 ), 'none fits, no match' );
Harness::assert_true( SpacesQuery::matches_people( [], 0 ), 'no number asked, everything matches' );
Harness::assert_false( SpacesQuery::matches_people( [], 10 ), 'a space with no capacities cannot promise ten' );

$facts = SpacesQuery::quick_facts(
	[
		[ 'cap_theatre' => '150', 'rate' => '35', 'rate_unit' => 'hour' ],
		[ 'cap_standing' => '200', 'rate' => '250', 'rate_unit' => 'day' ],
		[ 'cap_boardroom' => '12', 'rate' => '12', 'rate_unit' => 'hour' ],
		[ 'cap_boardroom' => '8' ],
	]
);
Harness::assert_same( 4, $facts['count'], 'four spaces' );
Harness::assert_same( 200, $facts['max_people'], 'the most in any layout' );
Harness::assert_same( 12.0, $facts['from'], 'the lowest hourly rate' );
Harness::assert_same( 'hour', $facts['from_unit'], 'and it is hourly' );
Harness::assert_false( $facts['on_request'], 'not on request when something is priced' );
$facts = SpacesQuery::quick_facts( [ [ 'rate' => '250', 'rate_unit' => 'day' ], [] ] );
Harness::assert_same( 250.0, $facts['from'], 'a day rate stands in when nothing is hourly' );
Harness::assert_same( 'day', $facts['from_unit'], 'and says so' );
$facts = SpacesQuery::quick_facts( [ [], [ 'cap_theatre' => '20' ] ] );
Harness::assert_true( null === $facts['from'] && $facts['on_request'], 'nothing priced: price on request' );
Harness::assert_same( '£35 an hour', SpacesQuery::rate_words( [ 'rate' => '35', 'rate_unit' => 'hour' ] ), 'a rate in words' );
Harness::assert_same( '£12.50 a session', SpacesQuery::rate_words( [ 'rate' => '12.5', 'rate_unit' => 'session' ] ), 'pence when there are some' );
Harness::assert_same( 'Free', SpacesQuery::rate_words( [ 'rate' => '0', 'rate_unit' => 'hour' ] ), 'zero is free' );
Harness::assert_same( 'Price on request', SpacesQuery::rate_words( [] ), 'no rate' );
Harness::assert_same( 150, SpacesQuery::largest( [ 'cap_theatre' => '150', 'cap_cabaret' => '80' ] ), 'the largest layout' );

$args = SpacesQuery::args_from( [ 'q' => str_repeat( 'x', 120 ), 'ward' => 'armley', 'people' => '9999999', 'type' => 'hall', 'access' => [ 'step_free', 'nope', 'step_free' ], 'price' => 'free', 'pg' => '3' ] );
Harness::assert_same( 100, mb_strlen( $args['q'] ), 'the search word is capped' );
Harness::assert_same( 'armley', $args['ward'], 'a real ward is kept' );
Harness::assert_same( SpacesQuery::MAX_PEOPLE, $args['people'], 'people is capped' );
Harness::assert_same( [ 'step_free' ], $args['access'], 'unknown and duplicate access keys go' );
Harness::assert_same( 3, $args['page'], 'the page is read' );
Harness::assert_same( [ 'q' => '', 'ward' => '', 'people' => 0, 'type' => '', 'access' => [], 'price' => '', 'page' => 1 ], SpacesQuery::args_from( [ 'ward' => 'atlantis', 'type' => 'shed', 'price' => 'cheap', 'people' => '-4', 'pg' => '-1' ] ), 'nonsense is dropped' );
Harness::assert_true( SpacesQuery::space_matches( [ 'space_type' => 'hall', 'cap_theatre' => '100', 'rate' => '20', 'rate_unit' => 'hour' ], SpacesQuery::args_from( [ 'type' => 'hall', 'people' => '80', 'price' => '15_30' ] ) ), 'a room meets every space filter' );
Harness::assert_false( SpacesQuery::space_matches( [ 'space_type' => 'hall', 'cap_theatre' => '100', 'rate' => '20', 'rate_unit' => 'hour' ], SpacesQuery::args_from( [ 'price' => 'free' ] ) ), 'or fails one' );

Harness::group( 'Spaces to hire: the geocoder reads postcodes.io' );

Harness::assert_same( [ 'lat' => 53.796543, 'lng' => -1.601234 ], Geocode::parse( '{"status":200,"result":{"postcode":"LS12 3QP","latitude":53.7965432,"longitude":-1.6012341}}' ), 'a good answer gives coordinates, to six places' );
Harness::assert_true( null === Geocode::parse( '{"status":404,"error":"Postcode not found"}' ), 'a 404 body is nothing' );
Harness::assert_true( null === Geocode::parse( '{"status":200,"result":{"latitude":null,"longitude":null}}' ), 'a postcode with no coordinates is nothing' );
Harness::assert_true( null === Geocode::parse( 'not json' ), 'garbage is nothing' );

Harness::group( 'Spaces to hire: an enquiry is checked before it is sent' );

$today   = new DateTimeImmutable( '2026-10-01', new DateTimeZone( 'Europe/London' ) );
$options = [ '12' => 'Main hall', '13' => 'Garden room', 'any' => 'Not sure yet' ];
$good    = [ 'space' => '12', 'date' => '2026-10-14', 'time_from' => '18:00', 'time_to' => '21:00', 'people' => '40', 'message' => 'A community meeting with lunch.', 'name' => 'Pat', 'email' => 'PAT@Example.test', 'phone' => '07700 900000' ];
$checked = Enquiry::validate( $good, $options, $today );
Harness::assert_same( [], $checked['errors'], 'a complete enquiry has no errors' );
Harness::assert_same( 'pat@example.test', $checked['values']['email'], 'the email is lower-cased' );
Harness::assert_same( '40', $checked['values']['people'], 'people is a plain number' );
Harness::assert_same( [], Enquiry::validate( [ 'space' => 'any', 'date' => '2026-10-01', 'message' => '1234567890', 'name' => 'A', 'email' => 'a@b.test' ], $options, $today )['errors'], 'today, "Not sure yet" and ten characters are fine' );
$bad = Enquiry::validate( [ 'space' => '99', 'date' => '2026-09-30', 'time_from' => '6pm', 'time_to' => '25:00', 'people' => '0', 'message' => 'short', 'name' => '', 'email' => 'nope' ], $options, $today );
Harness::assert_same( [ 'space', 'date', 'time_from', 'time_to', 'people', 'message', 'name', 'email' ], array_keys( $bad['errors'] ), 'every rule speaks: unknown space, yesterday, two bad times, zero people, short message, no name, bad email' );
Harness::assert_true( isset( Enquiry::validate( [ 'date' => '2026-02-30' ] + $good, $options, $today )['errors']['date'] ), '30 February is not a date' );
Harness::assert_true( isset( Enquiry::validate( [ 'message' => str_repeat( 'a', Enquiry::MESSAGE_MAX + 1 ) ] + $good, $options, $today )['errors']['message'] ), 'a message over the cap is refused' );
Harness::assert_true( isset( Enquiry::validate( [ 'people' => '5001' ] + $good, $options, $today )['errors']['people'] ), 'and more people than the site allows' );
Harness::assert_same( 'Reply-To: Pat Example <pat@example.test>', Enquiry::reply_to( 'Pat Example', 'pat@example.test' ), 'a Reply-To header' );
$evil = Enquiry::reply_to( "Pat\r\nBcc: x@y.test", "pat@example.test\r\nBcc: z@y.test" );
Harness::assert_false( (bool) preg_match( '/[\r\n]/', $evil ), 'line breaks cannot smuggle a header in' );
Harness::assert_true( str_ends_with( $evil, '<pat@example.testBcc:z@y.test>' ), 'and the address keeps no whitespace' );
