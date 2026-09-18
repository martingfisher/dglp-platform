<?php
/**
 * Invitation rules tests.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Access\UserContext;
use DGL\Invites\Invite;
use DGL\Invites\Rules;

$at = static fn( string $s ): DateTimeImmutable => new DateTimeImmutable( $s, new DateTimeZone( 'UTC' ) );

/** An open invitation, overridable per test. */
$inv = static function ( array $o = [] ): Invite {
	return new Invite(
		id: $o['id'] ?? 1,
		email: $o['email'] ?? 'jo@charity.test',
		org_id: $o['org_id'] ?? 5,
		org_role: $o['org_role'] ?? UserContext::ORG_CONTRIBUTOR,
		invited_by: $o['invited_by'] ?? 2,
		token_hash: $o['token_hash'] ?? 'hashed',
		created_at: $o['created_at'] ?? '2026-09-01 09:00:00',
		expires_at: $o['expires_at'] ?? '2026-09-15 09:00:00',
		accepted_at: array_key_exists( 'accepted_at', $o ) ? $o['accepted_at'] : null,
		revoked_at: array_key_exists( 'revoked_at', $o ) ? $o['revoked_at'] : null,
		accepted_by: $o['accepted_by'] ?? 0,
	);
};

/** An approved owner of org 5. */
$owner = static fn( array $o = [] ): UserContext => new UserContext(
	user_id: $o['user_id'] ?? 2,
	roles: $o['roles'] ?? [ UserContext::ROLE_MEMBER ],
	org_id: array_key_exists( 'org_id', $o ) ? $o['org_id'] : 5,
	org_role: array_key_exists( 'org_role', $o ) ? $o['org_role'] : UserContext::ORG_OWNER,
	account_status: $o['account_status'] ?? UserContext::ACCOUNT_APPROVED,
	org_approved: $o['org_approved'] ?? true,
);

Harness::group( 'An invitation knows where it stands without a cron job' );

Harness::assert_same( Rules::OPEN, Rules::state( $inv(), $at( '2026-09-10 09:00:00' ) ), 'open before its expiry date' );
Harness::assert_same( Rules::EXPIRED, Rules::state( $inv(), $at( '2026-09-16 09:00:00' ) ), 'expired after it, with nothing having run' );
Harness::assert_same( Rules::EXPIRED, Rules::state( $inv(), $at( '2026-09-15 09:00:00' ) ), 'and exactly on the boundary, which is the safe way round' );
Harness::assert_same( Rules::ACCEPTED, Rules::state( $inv( [ 'accepted_at' => '2026-09-02 10:00:00' ] ), $at( '2026-09-10 09:00:00' ) ), 'accepted beats open' );
Harness::assert_same( Rules::REVOKED, Rules::state( $inv( [ 'revoked_at' => '2026-09-02 10:00:00' ] ), $at( '2026-09-10 09:00:00' ) ), 'withdrawn beats open' );
Harness::assert_same( Rules::ACCEPTED, Rules::state( $inv( [ 'accepted_at' => '2026-09-02 10:00:00', 'revoked_at' => '2026-09-03 10:00:00' ] ), $at( '2026-09-10 09:00:00' ) ), 'and an invitation taken up then withdrawn stays accepted, because the account exists' );
Harness::assert_same( Rules::EXPIRED, Rules::state( $inv( [ 'expires_at' => 'not a date' ] ), $at( '2026-09-02 09:00:00' ) ), 'an unreadable expiry date is expired, never open' );

Harness::group( 'Expiry is fourteen days' );

Harness::assert_same( '2026-09-15 09:00:00', Rules::expires_at( $at( '2026-09-01 09:00:00' ) ), 'two weeks from creation' );
Harness::assert_same( '2026-09-02 09:00:00', Rules::expires_at( $at( '2026-09-01 09:00:00' ), 1 ), 'a shorter life can be asked for' );
Harness::assert_same( '2026-09-02 09:00:00', Rules::expires_at( $at( '2026-09-01 09:00:00' ), 0 ), 'but never zero days, which would be an invitation dead on arrival' );

Harness::group( 'Who may invite' );

Harness::assert_true( Rules::may_invite( $owner(), 5, UserContext::ORG_CONTRIBUTOR ), 'an owner may invite a contributor' );
Harness::assert_true( Rules::may_invite( $owner(), 5, UserContext::ORG_OWNER ), 'and may make another owner, so a charity is never locked out by one person leaving' );
Harness::assert_false( Rules::may_invite( $owner( [ 'org_role' => UserContext::ORG_CONTRIBUTOR ] ), 5, UserContext::ORG_CONTRIBUTOR ), 'a contributor may invite nobody' );
Harness::assert_false( Rules::may_invite( $owner(), 9, UserContext::ORG_CONTRIBUTOR ), 'an owner may not invite into somebody else organisation' );
Harness::assert_false( Rules::may_invite( $owner( [ 'org_approved' => false ] ), 5, UserContext::ORG_CONTRIBUTOR ), 'an unverified organisation cannot recruit in DGLP name' );
Harness::assert_false( Rules::may_invite( $owner( [ 'account_status' => UserContext::ACCOUNT_SUSPENDED ] ), 5, UserContext::ORG_CONTRIBUTOR ), 'a suspended owner cannot invite' );
Harness::assert_false( Rules::may_invite( $owner( [ 'account_status' => UserContext::ACCOUNT_CLOSED ] ), 5, UserContext::ORG_CONTRIBUTOR ), 'nor a closed one' );
Harness::assert_false( Rules::may_invite( UserContext::anonymous(), 5, UserContext::ORG_CONTRIBUTOR ), 'nor a stranger' );

$mod = new UserContext( user_id: 7, roles: [ UserContext::ROLE_MODERATOR ], org_id: null, org_role: null, account_status: UserContext::ACCOUNT_APPROVED );
Harness::assert_true( Rules::may_invite( $mod, 5, UserContext::ORG_OWNER ), 'the DGLP team may invite the first owner of any organisation' );
Harness::assert_true( Rules::may_invite( $mod, 99, UserContext::ORG_OWNER ), 'including one they have no link to, which is how a new partner starts' );

Harness::assert_false( Rules::may_invite( $owner(), 5, 'administrator' ), 'no role outside the two organisation roles can be handed out' );
Harness::assert_false( Rules::may_invite( $owner(), 5, '' ), 'nor an empty one' );

Harness::group( 'Withdrawing one' );

Harness::assert_true( Rules::may_revoke( $owner(), $inv() ), 'an owner can withdraw an invitation' );
Harness::assert_true( Rules::may_revoke( $owner( [ 'user_id' => 88 ] ), $inv() ), 'including one a colleague sent, so a holiday cannot strand a mistake' );
Harness::assert_false( Rules::may_revoke( $owner( [ 'org_role' => UserContext::ORG_CONTRIBUTOR ] ), $inv() ), 'a contributor cannot' );
Harness::assert_true( Rules::may_revoke( $mod, $inv() ), 'the DGLP team can' );

Harness::group( 'Refusing to send, with a reason worth reading' );

$send = static fn( array $o = [] ): string => Rules::can_send(
	$o['actor'] ?? new UserContext( 2, [ UserContext::ROLE_MEMBER ], 5, UserContext::ORG_OWNER, UserContext::ACCOUNT_APPROVED, true ),
	$o['org_id'] ?? 5,
	$o['org_role'] ?? UserContext::ORG_CONTRIBUTOR,
	$o['email'] ?? 'new@charity.test',
	$o['is_valid_email'] ?? true,
	array_key_exists( 'existing_org', $o ) ? $o['existing_org'] : null,
	$o['has_open'] ?? false,
	$o['open_count'] ?? 0
);

Harness::assert_same( Rules::SEND_OK, $send(), 'a clean invitation goes' );
Harness::assert_same( Rules::SEND_BAD_EMAIL, $send( [ 'is_valid_email' => false ] ), 'a malformed address is refused' );
Harness::assert_same( Rules::SEND_BAD_EMAIL, $send( [ 'email' => '' ] ), 'so is an empty one' );
Harness::assert_same( Rules::SEND_ALREADY_MEMBER, $send( [ 'existing_org' => 5 ] ), 'somebody already in the organisation is not invited twice' );
Harness::assert_same( Rules::SEND_OTHER_ORG, $send( [ 'existing_org' => 9 ] ), 'and somebody in another organisation cannot be poached by typing their address' );
Harness::assert_same( Rules::SEND_ALREADY_INVITED, $send( [ 'has_open' => true ] ), 'a second invitation to a waiting address is refused' );
Harness::assert_same( Rules::SEND_TOO_MANY, $send( [ 'open_count' => Rules::MAX_OPEN_PER_ORG ] ), 'and there is a ceiling on outstanding invitations' );
Harness::assert_same( Rules::SEND_OK, $send( [ 'open_count' => Rules::MAX_OPEN_PER_ORG - 1 ] ), 'one below the ceiling is still fine' );
Harness::assert_same( Rules::SEND_DENIED, $send( [ 'org_role' => UserContext::ORG_OWNER, 'actor' => new UserContext( 2, [ UserContext::ROLE_MEMBER ], 5, UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_APPROVED, true ) ] ), 'permission is checked before anything else' );

Harness::group( 'Every refusal says what to do next' );

foreach ( [ Rules::SEND_DENIED, Rules::SEND_BAD_EMAIL, Rules::SEND_ALREADY_MEMBER, Rules::SEND_ALREADY_INVITED, Rules::SEND_OTHER_ORG, Rules::SEND_TOO_MANY ] as $reason ) {
	Harness::assert_true( '' !== Rules::send_error( $reason ), $reason . ' has wording of its own' );
}

Harness::group( 'Taking one up' );

$accept = static fn( array $o = [] ): string => Rules::accept_outcome(
	$o['invite'] ?? new Invite( 1, 'jo@charity.test', 5, UserContext::ORG_CONTRIBUTOR, 2, 'h', '2026-09-01 09:00:00', '2026-09-15 09:00:00' ),
	$o['now'] ?? new DateTimeImmutable( '2026-09-10 09:00:00', new DateTimeZone( 'UTC' ) ),
	array_key_exists( 'existing_user', $o ) ? $o['existing_user'] : null,
	array_key_exists( 'existing_org', $o ) ? $o['existing_org'] : null
);

Harness::assert_same( Rules::ACCEPT_CREATE, $accept(), 'a stranger gets an account made for them' );
Harness::assert_same( Rules::ACCEPT_LINK, $accept( [ 'existing_user' => 12, 'existing_org' => null ] ), 'an existing unattached account is linked rather than duplicated' );
Harness::assert_same( Rules::ACCEPT_LINK, $accept( [ 'existing_user' => 12, 'existing_org' => 0 ] ), 'and zero means unattached too' );
Harness::assert_same( Rules::ACCEPT_ALREADY_MEMBER, $accept( [ 'existing_user' => 12, 'existing_org' => 5 ] ), 'somebody already in is not added twice' );
Harness::assert_same( Rules::ACCEPT_OTHER_ORG, $accept( [ 'existing_user' => 12, 'existing_org' => 9 ] ), 'an account in another organisation is never moved by a link' );

$closed = static fn( array $o ): string => $accept( [ 'invite' => new Invite( 1, 'jo@charity.test', 5, UserContext::ORG_CONTRIBUTOR, 2, 'h', '2026-09-01 09:00:00', $o['expires_at'] ?? '2026-09-15 09:00:00', $o['accepted_at'] ?? null, $o['revoked_at'] ?? null ) ] );

Harness::assert_same( Rules::ACCEPT_CLOSED, $closed( [ 'accepted_at' => '2026-09-02 09:00:00' ] ), 'a used invitation cannot be used again' );
Harness::assert_same( Rules::ACCEPT_CLOSED, $closed( [ 'revoked_at' => '2026-09-02 09:00:00' ] ), 'nor a withdrawn one' );
Harness::assert_same( Rules::ACCEPT_CLOSED, $closed( [ 'expires_at' => '2026-09-05 09:00:00' ] ), 'nor an expired one' );
Harness::assert_same( Rules::ACCEPT_CLOSED, $accept( [ 'existing_user' => 12, 'existing_org' => null, 'invite' => new Invite( 1, 'jo@charity.test', 5, UserContext::ORG_CONTRIBUTOR, 2, 'h', '2026-09-01 09:00:00', '2026-09-05 09:00:00' ) ] ), 'and an expired invitation is refused before anybody is linked to anything' );

Harness::group( 'The person holding a dead link is told which kind of dead' );

Harness::assert_true( str_contains( Rules::accept_error( Rules::ACCEPT_CLOSED, Rules::ACCEPTED ), 'signing in' ), 'an already-used link points at the sign-in page' );
Harness::assert_true( str_contains( Rules::accept_error( Rules::ACCEPT_CLOSED, Rules::EXPIRED ), 'expired' ), 'an expired link says so' );
Harness::assert_true( str_contains( Rules::accept_error( Rules::ACCEPT_CLOSED, Rules::REVOKED ), 'withdrawn' ), 'a withdrawn link says so' );
Harness::assert_true( str_contains( Rules::accept_error( Rules::ACCEPT_OTHER_ORG ), 'DGLP team' ), 'and the one case a member cannot fix themselves names who can' );

Harness::group( 'Addresses are compared the way people use them' );

Harness::assert_same( 'jo@charity.test', Rules::normalise_email( '  Jo@Charity.Test ' ), 'case and whitespace are not two different people' );

Harness::group( 'Passwords: eight characters, typed twice' );

Harness::assert_same( '', Rules::password_problem( 'eightchr', 'eightchr' ), 'eight characters that match are fine' );
Harness::assert_true( '' !== Rules::password_problem( 'seven77', 'seven77' ), 'seven are not' );
Harness::assert_true( str_contains( Rules::password_problem( 'short', 'short' ), '8' ), 'and the message says how many are needed' );
Harness::assert_true( str_contains( Rules::password_problem( 'eightchr', 'eightchx' ), 'match' ), 'a mismatch is named as a mismatch' );
Harness::assert_same( '', Rules::password_problem( 'caf\u{e9}caf\u{e9}', 'caf\u{e9}caf\u{e9}' ), 'eight accented letters are eight characters, whatever strlen thinks' );
Harness::assert_same( '', Rules::password_problem( "it's \\ fine!", "it's \\ fine!" ), 'quotes and backslashes are just characters' );
Harness::assert_true( '' !== Rules::password_problem( 'short', 'different' ), 'too short is reported before a mismatch, so the first thing fixed is the one that matters' );


Harness::group( 'Role change email' );

$up = \DGL\Email\InviteCopy::role_changed( 'Leeds Trust', true, 'https://example.test/dashboard/' );
Harness::assert_same( 'You are now an owner at Leeds Trust', $up->subject, 'promotion subject' );
Harness::assert_true( str_contains( $up->paragraphs[0], 'invite and remove colleagues' ), 'says what an owner can do' );
$down = \DGL\Email\InviteCopy::role_changed( 'Leeds Trust', false, 'https://example.test/dashboard/' );
Harness::assert_true( str_contains( $down->paragraphs[0], 'still submit and edit' ), 'and what a contributor keeps' );
