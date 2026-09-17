<?php
/**
 * Organisation directory: the several-of-a-list field, the option lists,
 * the Forum Central mapping and who may flip the directory switch.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Access\Policy;
use DGL\Access\UserContext;
use DGL\Org\Import;
use DGL\Org\Options;
use DGL\Org\Schema as OrgSchema;
use DGL\Schema\Field;
use DGL\Schema\Validator;

Harness::group( 'Several-of-a-list fields validate to option keys' );

$services = new Field( key: 'org_services', label: 'Services', type: Field::CHOICES, options: [ 'a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma' ] );

$r = Validator::validate( [ $services ], [ 'org_services' => [ 'c', 'zzz', 'a', '' ] ] );
Harness::assert_same( [ 'a', 'c' ], $r['values']['org_services'], 'known keys kept in option order, unknown and blank dropped' );
Harness::assert_same( [], $r['errors'], 'no error for an unknown key' );

$r = Validator::validate( [ $services ], [] );
Harness::assert_same( [], $r['values']['org_services'], 'absent posts as an empty list' );

$r = Validator::validate( [ $services ], [ 'org_services' => 'b' ] );
Harness::assert_same( [ 'b' ], $r['values']['org_services'], 'a lone scalar is accepted as a one-item list' );

$must = new Field( key: 'must', label: 'Must', type: Field::CHOICES, required: true, options: [ 'a' => 'Alpha' ] );
$r    = Validator::validate( [ $must ], [ 'must' => [ '' ] ] );
Harness::assert_true( isset( $r['errors']['must'] ), 'a required list with nothing ticked is an error' );

Harness::assert_true( in_array( Field::CHOICES, Field::types(), true ), 'the type is registered' );

Harness::group( 'Option lists are sound' );

foreach ( Options::all() as $key => $options ) {
	Harness::assert_true( count( $options ) >= 4, $key . ' has a real list' );
	Harness::assert_same( count( $options ), count( array_unique( array_map( 'strval', $options ) ) ), $key . ' has no duplicate labels' );

	foreach ( array_keys( $options ) as $option_key ) {
		Harness::assert_true( 1 === preg_match( '/^[a-z0-9_]+$/', (string) $option_key ), $key . ' key "' . $option_key . '" is a stable slug' );
	}
}

Harness::assert_same( 34, count( Options::wards() ), '33 wards plus Leeds-wide' );
Harness::assert_same( 'armley', Options::key_for( Options::wards(), '  ARMLEY ' ), 'key_for ignores case and space' );
Harness::assert_same( null, Options::key_for( Options::wards(), 'City' ), '"City" is not a ward' );

foreach ( OrgSchema::open_fields() as $field ) {
	if ( in_array( $field->type, [ Field::SELECT, Field::CHOICES ], true ) ) {
		Harness::assert_true( count( $field->options ) > 0, $field->key . ' has options' );
	}
	Harness::assert_true( isset( OrgSchema::sections()[ $field->step ] ), $field->key . ' sits under a section heading' );
}

$keys = array_map( static fn( Field $f ): string => $f->key, OrgSchema::fields() );
Harness::assert_same( count( $keys ), count( array_unique( $keys ) ), 'profile field keys are unique' );

Harness::group( 'Forum Central rows map onto the profile' );

$row = [
	Import::NAME       => ' Caring Together ',
	Import::EMAIL      => 'Info@CaringTogether.org.uk',
	Import::PHONE      => '0113 243 0298',
	Import::WEBSITE    => 'www.caringtogether.org.uk',
	Import::STREET     => '127 Woodhouse St',
	Import::SUPP_1     => 'Woodhouse',
	Import::SUPP_2     => '',
	Import::POSTCODE   => 'ls6 2py',
	Import::LAT        => '53.8125508',
	Import::LNG        => '-1.5509646',
	Import::DESC       => str_repeat( 'x', 450 ),
	Import::ORG_TYPE   => 'Neighbourhood Network',
	Import::ACCESS     => 'Step Free Access, Disabled Parking',
	Import::ACCRED     => 'Mindful Employer, Living Wage Employer',
	Import::WARD       => 'Little London and Woodhouse',
	Import::SPECIALISM => 'Older People, Mental Health',
	Import::USERS      => "Age Groups: Older People, People's circumstances: Gypsy, Roma and Traveller Communities, People from Culturally Diverse Communities: Indian, Pakistani, South Asian",
	Import::SERVICES   => 'Social groups/Activities: Art, Social groups/Activities, Befriending Nonsense',
	Import::DELIVERY   => 'In person/Face to face, Online',
	Import::LEGAL      => 'Registered Charity',
	Import::NUMBER     => '1234567',
	Import::STAFF      => '11 - 50',
	Import::VOLUNTEERS => '100+',
	Import::PERMISSION => 'I am happy for the information I have provided above about this organisation to be made available online and shared where appropriate by Forum Central',
	Import::VOLITION   => 'Current Member',
	Import::LOPF       => '',
	Import::FC_ID      => '42',
	Import::CITY       => 'Leeds',
	Import::SUBTYPE    => '',
];

$m = Import::map( $row );
Harness::assert_same( 'Caring Together', $m['name'], 'name trimmed' );
Harness::assert_same( '42', $m['fc_id'], 'contact id carried' );
Harness::assert_same( 'info@caringtogether.org.uk', $m['fields']['org_email'], 'email lower-cased' );
Harness::assert_same( [ 'caringtogether.org.uk' ], $m['domains'], 'domain taken from the email' );
Harness::assert_same( 'https://www.caringtogether.org.uk', $m['fields']['org_website'], 'website given a scheme' );
Harness::assert_same( 'LS6 2PY', $m['fields']['org_postcode'], 'postcode upper-cased' );
Harness::assert_same( 'Woodhouse', $m['fields']['org_address_2'], 'supplemental lines become line 2' );
Harness::assert_same( 450, mb_strlen( $m['fields']['org_description'] ), 'a description under the 650 limit is kept whole' );
$long = str_repeat( 'word ', 140 ) . 'tail';
$cut  = Import::map( [ Import::NAME => 'X', Import::FC_ID => '1', Import::DESC => $long ] )['fields']['org_description'];
Harness::assert_true( mb_strlen( $cut ) <= 650 && mb_strlen( $cut ) > 600 && str_ends_with( $cut, 'word' ), 'a long description is cut at a word boundary under 650: ' . mb_strlen( $cut ) . ' chars' );
Harness::assert_same( 'little_london_and_woodhouse', $m['fields']['org_ward'], 'ward by key' );
Harness::assert_same( 'registered_charity', $m['fields']['org_legal_status'], 'legal status by key' );
Harness::assert_same( '11_50', $m['fields']['org_staff'], 'staff band by key' );
Harness::assert_same( '100_plus', $m['fields']['org_volunteers'], 'volunteer band by key' );
Harness::assert_same( [ 'neighbourhood_network' ], $m['fields']['org_type'], 'one type' );
Harness::assert_same( [ 'disabled_parking', 'step_free_access' ], $m['fields']['org_accessibility'], 'accessibility in option order' );
Harness::assert_same( [ 'mental_health', 'older_people' ], $m['fields']['org_specialism'], 'specialism in option order' );
Harness::assert_same( [ 'in_person_face_to_face', 'online' ], $m['fields']['org_delivery'], 'delivery types' );
Harness::assert_true( in_array( 'circ_gypsy_roma_and_traveller_communities', $m['fields']['org_service_users'], true ), 'a label containing commas is matched whole' );
Harness::assert_true( in_array( 'cdc_indian_pakistani_south_asian', $m['fields']['org_service_users'], true ), 'and so is the three-part one' );
Harness::assert_same( 3, count( $m['fields']['org_service_users'] ), 'three service-user groups, not seven fragments' );
Harness::assert_same( [ 'social_groups_activities', 'social_art' ], $m['fields']['org_services'], '"Art" and its parent are both kept, the nonsense is not' );
Harness::assert_same( [ 'Befriending Nonsense' ], $m['unmatched'][ Import::SERVICES ], 'the unmatched value is reported' );
Harness::assert_same( '1', $m['facts']['permission'], 'permission recognised' );
Harness::assert_same( '', $m['facts']['age_friendly'], 'not age friendly' );
Harness::assert_same( '53.812551', $m['facts']['lat'], 'latitude rounded' );
Harness::assert_same( [], $m['problems'], 'no problems' );

$m = Import::map( [ Import::NAME => 'Gmail Group', Import::EMAIL => 'x@gmail.com', Import::WARD => 'City', Import::FC_ID => '7' ] );
Harness::assert_same( [], $m['domains'], 'a public provider records no domain' );
Harness::assert_same( 'x@gmail.com', $m['fields']['org_email'], 'but the address is still the public contact' );
Harness::assert_same( [ 'City' ], $m['unmatched'][ Import::WARD ], '"City" is reported, not guessed' );
Harness::assert_same( '', $m['fields']['org_ward'], 'and the ward is left blank' );

$m = Import::map( [ Import::NAME => '', Import::EMAIL => 'not an email', Import::FC_ID => '8', Import::LAT => '999' ] );
Harness::assert_same( 2, count( $m['problems'] ), 'a nameless row with a bad email has two problems' );
Harness::assert_same( '', $m['facts']['lat'], 'an impossible latitude is dropped' );

[ $keys, $left ] = Import::pick_many( [ 'a' => 'Art', 'b' => 'Art and Craft' ], 'Art and Craft, Art, Dance' );
Harness::assert_same( [ 'a', 'b' ], $keys, 'longest label matched first, then the shorter one' );
Harness::assert_same( [ 'Dance' ], $left, 'leftover reported' );

Harness::group( 'Who can flip the directory switch' );

$org_a  = new UserContext( 1, [ UserContext::ROLE_MEMBER ], 10, UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_APPROVED, true );
$owner  = new UserContext( 2, [ UserContext::ROLE_MEMBER ], 10, UserContext::ORG_OWNER, UserContext::ACCOUNT_APPROVED, true );
$pend   = new UserContext( 3, [ UserContext::ROLE_MEMBER ], 10, UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_PENDING, true );
$susp   = new UserContext( 4, [ UserContext::ROLE_MEMBER ], 10, UserContext::ORG_OWNER, UserContext::ACCOUNT_SUSPENDED, true );
$org_b  = new UserContext( 5, [ UserContext::ROLE_MEMBER ], 11, UserContext::ORG_OWNER, UserContext::ACCOUNT_APPROVED, true );
$admin  = new UserContext( 6, [ UserContext::ROLE_ADMIN ], null, null, UserContext::ACCOUNT_APPROVED, true );
$mod    = new UserContext( 7, [ UserContext::ROLE_MODERATOR ], null, null, UserContext::ACCOUNT_APPROVED, true );

Harness::assert_true( Policy::can_toggle_directory( $org_a, 10 ), 'an approved contributor can' );
Harness::assert_true( Policy::can_toggle_directory( $owner, 10 ), 'so can the owner' );
Harness::assert_false( Policy::can_toggle_directory( $pend, 10 ), 'a pending member cannot' );
Harness::assert_false( Policy::can_toggle_directory( $susp, 10 ), 'nor a suspended one' );
Harness::assert_false( Policy::can_toggle_directory( $org_b, 10 ), 'nor another organisation' );
Harness::assert_true( Policy::can_toggle_directory( $admin, 10 ), 'an administrator can' );
Harness::assert_false( Policy::can_toggle_directory( $mod, 10 ), 'a moderator with no organisation cannot' );
Harness::assert_false( Policy::can_toggle_directory( $owner, 0 ), 'no organisation, no switch' );
