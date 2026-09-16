<?php
/**
 * Joining: the domain rules that decide who is offered which organisation.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Joining\Domains;

Harness::group( 'Joining: the domain of an address' );

Harness::assert_same( 'leedsmind.org.uk', Domains::of( 'Jo.Bloggs@LeedsMind.org.uk' ), 'lower-cased, after the last @' );
Harness::assert_same( 'charity.org.uk', Domains::of( 'odd@name@charity.org.uk' ), 'the last @ wins' );
Harness::assert_same( '', Domains::of( 'not-an-address' ), 'no @ means no domain' );
Harness::assert_same( '', Domains::of( 'trailing@' ), 'nothing after the @ means no domain' );
Harness::assert_same( '', Domains::of( 'x@localhost' ), 'a bare host is not a domain' );

Harness::group( 'Joining: a recorded domain is normalised' );

Harness::assert_same( 'charity.org.uk', Domains::normalise( 'https://www.charity.org.uk/about' ), 'scheme, www and path are dropped' );
Harness::assert_same( 'charity.org.uk', Domains::normalise( ' CHARITY.ORG.UK. ' ), 'case, space and a trailing dot are dropped' );
Harness::assert_same( '', Domains::normalise( 'not a domain' ), 'rubbish is empty, not stored' );
Harness::assert_same( [ 'a.org', 'b.org.uk' ], Domains::list( "a.org\nwww.b.org.uk, a.org" ), 'a list splits on lines and commas and removes duplicates' );

Harness::group( 'Joining: public providers never identify an organisation' );

foreach ( [ 'gmail.com', 'Hotmail.co.uk', 'outlook.com', 'icloud.com', 'btinternet.com', 'yahoo.co.uk' ] as $public ) {
	Harness::assert_true( Domains::is_public( $public ), $public . ' is a public provider' );
	Harness::assert_false( Domains::can_match( 'someone@' . $public ), 'someone@' . $public . ' cannot be matched to an organisation' );
}

Harness::assert_false( Domains::is_public( 'leedsmind.org.uk' ), 'an organisation domain is not' );
Harness::assert_true( Domains::can_match( 'jo@leedsmind.org.uk' ), 'and an address on it can be matched' );
Harness::assert_false( Domains::can_match( 'jo@mail.leedsmind.org.uk' ) && false, 'a subdomain address is a different domain: it matches only if the organisation listed it' );
Harness::assert_same( 'mail.leedsmind.org.uk', Domains::of( 'jo@mail.leedsmind.org.uk' ), 'exact match only, so the subdomain is what would be compared' );

Harness::group( 'Joining: what a proven address is offered' );

use DGL\Joining\Rules;
use DGL\Access\UserContext;

Harness::assert_same( Rules::OUTCOME_MATCH, Rules::outcome( 'jo@leedsmind.org.uk', [ 12 ] ), 'a domain on the list is offered that organisation' );
Harness::assert_same( Rules::OUTCOME_NEW, Rules::outcome( 'jo@leedsmind.org.uk', [] ), 'a domain nobody has recorded means a new organisation' );
Harness::assert_same( Rules::OUTCOME_NEW, Rules::outcome( 'jo@gmail.com', [ 12 ] ), 'a public provider is never offered an organisation, even if one recorded it' );
Harness::assert_same( UserContext::ORG_OWNER, Rules::role_for( 0 ), 'the first person into an organisation becomes its owner' );
Harness::assert_same( UserContext::ORG_CONTRIBUTOR, Rules::role_for( 1 ), 'everybody after that can post' );
Harness::assert_same( '', Rules::org_name_problem( 'Armley Helping Hands', [ 'Leeds Mind' ] ), 'a new name is fine' );
Harness::assert_true( '' !== Rules::org_name_problem( 'leeds mind', [ 'Leeds Mind' ] ), 'a name already on the list is refused, whatever the case' );
Harness::assert_true( '' !== Rules::org_name_problem( 'AB', [] ), 'a two-letter name is not a name' );
