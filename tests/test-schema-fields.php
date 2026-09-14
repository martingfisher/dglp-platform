<?php
/**
 * Field registry and validation tests.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Schema\Validator;

Harness::group( 'Every type gets the shared steps plus its own' );

foreach ( PostTypes::submittable() as $type ) {
	$fields = FieldRegistry::for_type( $type );
	Harness::assert_true( count( $fields ) > 0, $type . ' has a field set' );

	$keys = array_map( static fn( Field $f ): string => $f->key, $fields );
	Harness::assert_same( count( $keys ), count( array_unique( $keys ) ), $type . ' has no duplicate field keys' );

	foreach ( [ 'title', 'summary', 'body', 'contact_email' ] as $shared ) {
		Harness::assert_true( in_array( $shared, $keys, true ), $type . ' includes the shared field ' . $shared );
	}

	foreach ( $fields as $field ) {
		Harness::assert_true( in_array( $field->type, Field::types(), true ), $type . '.' . $field->key . ' has a known field type' );
		Harness::assert_true(
			Field::SELECT !== $field->type || count( $field->options ) > 0,
			$type . '.' . $field->key . ' is a select with options'
		);
	}
}

Harness::assert_same( [], FieldRegistry::for_type( 'not_a_type' ), 'an unknown post type has no fields' );

Harness::group( 'Type-specific fields land on step 2' );

$event_step2 = array_map( static fn( Field $f ): string => $f->key, FieldRegistry::for_step( PostTypes::EVENT, 2 ) );
Harness::assert_true( in_array( 'venue_name', $event_step2, true ), 'events ask for a venue on step 2' );
Harness::assert_true( in_array( 'accessibility', $event_step2, true ), 'events ask for accessibility notes on step 2' );
Harness::assert_false( in_array( 'venue_name', array_map( static fn( Field $f ): string => $f->key, FieldRegistry::for_type( PostTypes::NEWS ) ), true ), 'news does not ask for a venue' );

$grant_keys = array_map( static fn( Field $f ): string => $f->key, FieldRegistry::for_type( PostTypes::GRANT ) );
Harness::assert_true( in_array( 'deadline', $grant_keys, true ), 'grants ask for a deadline' );
Harness::assert_true( in_array( 'eligibility', $grant_keys, true ), 'grants ask who can apply' );

$vol_keys = array_map( static fn( Field $f ): string => $f->key, FieldRegistry::for_type( PostTypes::VOLUNTEERING ) );
Harness::assert_true( in_array( 'dbs_required', $vol_keys, true ), 'volunteering asks about DBS up front' );
Harness::assert_true( in_array( 'commitment', $vol_keys, true ), 'volunteering asks for the time commitment' );

Harness::group( 'Meta keys are namespaced' );

foreach ( FieldRegistry::for_type( PostTypes::EVENT ) as $field ) {
	Harness::assert_true( str_starts_with( $field->meta_key(), 'dgl_' ), $field->key . ' stores under a dgl_ prefixed meta key' );
}

Harness::group( 'Expiry comes from the right date per type' );

Harness::assert_same(
	'2026-04-26 15:00:00',
	FieldRegistry::expiry_for( PostTypes::EVENT, [ 'start_datetime' => '2026-04-26 10:00:00', 'end_datetime' => '2026-04-26 15:00:00' ] ),
	'an event expires at its end time'
);
Harness::assert_same(
	'2026-04-26 10:00:00',
	FieldRegistry::expiry_for( PostTypes::EVENT, [ 'start_datetime' => '2026-04-26 10:00:00', 'end_datetime' => '' ] ),
	'an event with no end time falls back to its start'
);
Harness::assert_same(
	'2026-09-30 23:59:59',
	FieldRegistry::expiry_for( PostTypes::GRANT, [ 'deadline' => '2026-09-30' ] ),
	'a grant deadline stays open until the end of the closing day'
);
Harness::assert_same(
	'2026-10-15 23:59:59',
	FieldRegistry::expiry_for( PostTypes::VOLUNTEERING, [ 'closing_date' => '2026-10-15' ] ),
	'a volunteering role closes at the end of its closing day'
);
Harness::assert_same( null, FieldRegistry::expiry_for( PostTypes::NEWS, [ 'story_date' => '2026-01-01' ] ), 'news never expires on its own' );
Harness::assert_same( null, FieldRegistry::expiry_for( PostTypes::GRANT, [] ), 'no deadline means no expiry' );

Harness::group( 'Required fields block a submission' );

$fields = FieldRegistry::for_step( PostTypes::EVENT, 2 );
$result = Validator::validate( $fields, [] );

Harness::assert_true( isset( $result['errors']['start_datetime'] ), 'a missing start time is an error' );
Harness::assert_true( isset( $result['errors']['venue_name'] ), 'a missing venue is an error' );
Harness::assert_false( isset( $result['errors']['capacity'] ), 'an optional field left blank is not an error' );
Harness::assert_same( '', $result['values']['capacity'] ?? null, 'an optional blank field stores as empty' );

Harness::group( 'Postcodes' );

$postcode = new Field( key: 'postcode', label: 'Postcode', type: Field::POSTCODE, required: true );

foreach ( [ 'LS1 4DY' => 'LS1 4DY', 'ls14dy' => 'LS1 4DY', 'bd15ld' => 'BD1 5LD', 'M1 1AE' => 'M1 1AE', 'SW1A  1AA' => 'SW1A 1AA' ] as $in => $expected ) {
	$r = Validator::validate( [ $postcode ], [ 'postcode' => $in ] );
	Harness::assert_same( $expected, $r['values']['postcode'] ?? null, '"' . $in . '" normalises to ' . $expected );
}

foreach ( [ 'Leeds', '12345', 'LS1', '' ] as $bad ) {
	$r = Validator::validate( [ $postcode ], [ 'postcode' => $bad ] );
	Harness::assert_true( isset( $r['errors']['postcode'] ), '"' . $bad . '" is rejected as a postcode' );
}

Harness::group( 'Dates and times' );

$deadline = new Field( key: 'deadline', label: 'Deadline', type: Field::DATE, required: true );
$r = Validator::validate( [ $deadline ], [ 'deadline' => '2026-09-30' ] );
Harness::assert_same( '2026-09-30', $r['values']['deadline'] ?? null, 'an ISO date passes through' );

foreach ( [ '30/09/2026', '2026-13-01', '2026-02-30', 'soon' ] as $bad ) {
	$r = Validator::validate( [ $deadline ], [ 'deadline' => $bad ] );
	Harness::assert_true( isset( $r['errors']['deadline'] ), '"' . $bad . '" is rejected as a date' );
}

$start = new Field( key: 'start', label: 'Start', type: Field::DATETIME, required: true );
foreach ( [ '2026-04-26T10:00' => '2026-04-26 10:00:00', '2026-04-26 10:00' => '2026-04-26 10:00:00', '2026-04-26 10:00:00' => '2026-04-26 10:00:00' ] as $in => $expected ) {
	$r = Validator::validate( [ $start ], [ 'start' => $in ] );
	Harness::assert_same( $expected, $r['values']['start'] ?? null, '"' . $in . '" normalises to ' . $expected );
}

Harness::group( 'URLs, email, numbers and money' );

$url = new Field( key: 'url', label: 'Link', type: Field::URL );
$r = Validator::validate( [ $url ], [ 'url' => 'doinggoodleeds.org.uk' ] );
Harness::assert_same( 'https://doinggoodleeds.org.uk', $r['values']['url'] ?? null, 'a bare domain gains https rather than being rejected' );

$r = Validator::validate( [ $url ], [ 'url' => 'javascript:alert(1)' ] );
Harness::assert_true( isset( $r['errors']['url'] ), 'a javascript: URL is refused' );

$r = Validator::validate( [ $url ], [ 'url' => 'ftp://example.com' ] );
Harness::assert_true( isset( $r['errors']['url'] ), 'a non-http scheme is refused' );

$email = new Field( key: 'email', label: 'Email', type: Field::EMAIL, required: true );
$r = Validator::validate( [ $email ], [ 'email' => 'Hello@DoingGoodLeeds.org.uk' ] );
Harness::assert_same( 'hello@doinggoodleeds.org.uk', $r['values']['email'] ?? null, 'email is lowercased' );
$r = Validator::validate( [ $email ], [ 'email' => 'not an address' ] );
Harness::assert_true( isset( $r['errors']['email'] ), 'a malformed email is refused' );

$money = new Field( key: 'amount', label: 'Amount', type: Field::MONEY );
$r = Validator::validate( [ $money ], [ 'amount' => '£1,500.50' ] );
Harness::assert_same( 1500.5, $r['values']['amount'] ?? null, 'pounds and commas are stripped from money' );
$r = Validator::validate( [ $money ], [ 'amount' => '-5' ] );
Harness::assert_true( isset( $r['errors']['amount'] ), 'a negative amount is refused' );

$number = new Field( key: 'capacity', label: 'Capacity', type: Field::NUMBER );
$r = Validator::validate( [ $number ], [ 'capacity' => '50' ] );
Harness::assert_same( 50, $r['values']['capacity'] ?? null, 'a number is cast to int' );
$r = Validator::validate( [ $number ], [ 'capacity' => '5.5' ] );
Harness::assert_true( isset( $r['errors']['capacity'] ), 'a decimal capacity is refused' );

Harness::group( 'Selects only accept their own options' );

$cost = FieldRegistry::find( PostTypes::EVENT, 'cost' );
Harness::assert_true( null !== $cost, 'the event cost field exists' );

$r = Validator::validate( [ $cost ], [ 'cost' => 'free' ] );
Harness::assert_same( 'free', $r['values']['cost'] ?? null, 'a valid option passes' );

$r = Validator::validate( [ $cost ], [ 'cost' => 'whatever_i_like' ] );
Harness::assert_true( isset( $r['errors']['cost'] ), 'an option that is not on the list is refused' );

Harness::group( 'Length caps and checkboxes' );

$summary = FieldRegistry::find( PostTypes::EVENT, 'summary' );
$r = Validator::validate( [ $summary ], [ 'summary' => str_repeat( 'a', 301 ) ] );
Harness::assert_true( isset( $r['errors']['summary'] ), 'a summary over 300 characters is refused' );
$r = Validator::validate( [ $summary ], [ 'summary' => str_repeat( 'a', 300 ) ] );
Harness::assert_false( isset( $r['errors']['summary'] ), 'a summary of exactly 300 characters passes' );

$check = new Field( key: 'dbs', label: 'DBS', type: Field::CHECKBOX );
foreach ( [ '1', 'on', 'yes', 'true', true ] as $on ) {
	$r = Validator::validate( [ $check ], [ 'dbs' => $on ] );
	Harness::assert_same( true, $r['values']['dbs'] ?? null, 'checkbox reads ' . var_export( $on, true ) . ' as ticked' );
}
foreach ( [ '0', 'off', '', 'no', false ] as $off ) {
	$r = Validator::validate( [ $check ], [ 'dbs' => $off ] );
	Harness::assert_same( false, $r['values']['dbs'] ?? null, 'checkbox reads ' . var_export( $off, true ) . ' as unticked' );
}
$r = Validator::validate( [ $check ], [] );
Harness::assert_same( false, $r['values']['dbs'] ?? null, 'an absent checkbox is unticked' );

Harness::group( 'Unknown input is discarded' );

$r = Validator::validate( [ $check ], [ 'dbs' => '1', 'post_status' => 'publish', 'ID' => 999 ] );
Harness::assert_same( [ 'dbs' ], array_keys( $r['values'] ), 'only declared fields survive validation, so extra POST keys cannot reach storage' );

Harness::group( 'URL scheme cannot be smuggled past the check' );

/*
 * Regression guard. The first implementation prepended https:// whenever the
 * value did not already start with http, which turned "javascript:alert(1)"
 * into "https://javascript:alert(1)" — a string that passes a scheme check
 * while still carrying the original payload into stored output.
 */
$link = new Field( key: 'link', label: 'Link', type: Field::URL );

foreach ( [
	'javascript:alert(1)',
	'JaVaScRiPt:alert(1)',
	'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
	'ftp://example.com',
	'mailto:someone@example.com',
	'file:///etc/passwd',
] as $hostile ) {
	$r = Validator::validate( [ $link ], [ 'link' => $hostile ] );
	Harness::assert_true( isset( $r['errors']['link'] ), 'refused: ' . $hostile );
	Harness::assert_false( isset( $r['values']['link'] ), 'nothing stored for: ' . $hostile );
}

foreach ( [
	'https://doinggoodleeds.org.uk'        => 'https://doinggoodleeds.org.uk',
	'http://doinggoodleeds.org.uk'         => 'http://doinggoodleeds.org.uk',
	'doinggoodleeds.org.uk'                => 'https://doinggoodleeds.org.uk',
	'doinggoodleeds.org.uk/events'         => 'https://doinggoodleeds.org.uk/events',
	'//doinggoodleeds.org.uk'              => 'https://doinggoodleeds.org.uk',
	'HTTPS://DoingGoodLeeds.org.uk'        => 'HTTPS://DoingGoodLeeds.org.uk',
] as $in => $expected ) {
	$r = Validator::validate( [ $link ], [ 'link' => $in ] );
	Harness::assert_same( $expected, $r['values']['link'] ?? null, '"' . $in . '" accepted as ' . $expected );
}
