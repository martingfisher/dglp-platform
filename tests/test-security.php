<?php
/**
 * Fixes from the October 2026 security review that can be checked without
 * WordPress. Each assertion failed, or could not run, before its fix.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Access\UserContext;
use DGL\Events\Ics;
use DGL\Invites\Rules as InviteRules;
use DGL\Joining\Guard;
use DGL\Reports\Csv;

Harness::group( 'Rate limits count the visitor, not Cloudflare' );

Harness::assert_same( '203.0.113.9', Guard::client_ip( [ 'REMOTE_ADDR' => '203.0.113.9' ] ), 'a direct request is counted by its own address' );
Harness::assert_same( '203.0.113.9', Guard::client_ip( [ 'REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ] ), 'through Cloudflare, by the visitor Cloudflare names' );
Harness::assert_same( '2001:db8::5', Guard::client_ip( [ 'REMOTE_ADDR' => '2606:4700:10::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::5' ] ), 'over IPv6 too' );
Harness::assert_same( '198.51.100.7', Guard::client_ip( [ 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ] ), 'a header from anybody else is ignored, so it cannot be used to dodge the limit' );
Harness::assert_same( '172.70.1.2', Guard::client_ip( [ 'REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'not an address' ] ), 'a header that is not an address is ignored' );
Harness::assert_same( '', Guard::client_ip( [ 'REMOTE_ADDR' => '<script>' ] ), 'and so is a remote address that is not one' );
Harness::assert_true( Guard::is_cloudflare( '104.16.0.1' ) && Guard::is_cloudflare( '131.0.75.255' ) && ! Guard::is_cloudflare( '131.0.76.0' ), 'range edges are right' );
Harness::assert_false( Guard::is_cloudflare( '2001:db8::1' ), 'an IPv6 address outside the ranges is not Cloudflare' );

Harness::group( 'A calendar file cannot be given extra properties' );

Harness::assert_same( 'one\ntwo\nthree', Ics::escape( "one\rtwo\r\nthree" ), 'a lone CR is a line break, escaped like any other' );
Harness::assert_false( str_contains( Ics::escape( "x\rATTACH:http://evil.example" ), "\r" ), 'so no raw CR reaches the file' );

Harness::group( 'Spreadsheet cells cannot start a formula' );

foreach ( [ '=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\n=1", '  =1', "\u{3000}=1", "\u{FF1D}1+1", "\u{FF0B}1" ] as $cell ) {
	Harness::assert_same( "'" . $cell, Csv::safe( $cell ), 'prefixed: ' . json_encode( $cell ) );
}

foreach ( [ 'Leeds', 'Leeds - East', '12', ' hello' ] as $cell ) {
	Harness::assert_same( $cell, Csv::safe( $cell ), 'left alone: ' . json_encode( $cell ) );
}

Harness::group( 'Only an approved owner of an approved organisation invites' );

$inviter = static fn( string $status ): UserContext => new UserContext(
	user_id: 2,
	roles: [ UserContext::ROLE_MEMBER ],
	org_id: 5,
	org_role: UserContext::ORG_OWNER,
	account_status: $status,
	org_approved: true,
);

Harness::assert_true( [] !== InviteRules::grantable_roles( $inviter( UserContext::ACCOUNT_APPROVED ), 5 ), 'an approved owner can invite' );
Harness::assert_same( [], InviteRules::grantable_roles( $inviter( UserContext::ACCOUNT_PENDING ), 5 ), 'an owner whose account is still pending cannot' );
