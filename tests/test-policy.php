<?php
/**
 * Access policy tests, weighted towards the cases that would leak data.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Access\ItemContext;
use DGL\Access\Policy;
use DGL\Access\UserContext;
use DGL\PostTypes;
use DGL\Statuses;

const ORG_A = 11;
const ORG_B = 22;

/** An approved member of organisation A. */
function member_a( string $org_role = UserContext::ORG_CONTRIBUTOR, string $account = UserContext::ACCOUNT_APPROVED ): UserContext {
	return new UserContext( 101, [ UserContext::ROLE_MEMBER ], ORG_A, $org_role, $account, true );
}

/** An approved member of organisation B. */
function member_b(): UserContext {
	return new UserContext( 202, [ UserContext::ROLE_MEMBER ], ORG_B, UserContext::ORG_OWNER, UserContext::ACCOUNT_APPROVED, true );
}

function moderator( int $id = 303 ): UserContext {
	return new UserContext( $id, [ UserContext::ROLE_MODERATOR ], null, null, UserContext::ACCOUNT_APPROVED, true );
}

function administrator(): UserContext {
	return new UserContext( 404, [ UserContext::ROLE_ADMIN ], null, null, UserContext::ACCOUNT_APPROVED, true );
}

/** An item belonging to organisation A, authored by member A. */
function item_a( string $status, int $org = ORG_A, int $author = 101 ): ItemContext {
	return new ItemContext( 9001, PostTypes::EVENT, $org, $author, $status );
}

Harness::group( 'Cross-organisation isolation' );

$b_item = item_a( Statuses::DRAFT, ORG_B, 202 );
Harness::assert_false( Policy::decide( member_a(), Policy::VIEW_ITEM, $b_item ), 'member of A cannot view B draft' );
Harness::assert_false( Policy::decide( member_a(), Policy::EDIT_ITEM, $b_item ), 'member of A cannot edit B draft' );
Harness::assert_false( Policy::decide( member_a(), Policy::SUBMIT_ITEM, $b_item ), 'member of A cannot submit B draft' );
Harness::assert_false( Policy::decide( member_a(), Policy::DELETE_ITEM, $b_item ), 'member of A cannot delete B draft' );
Harness::assert_false( Policy::decide( member_a(), Policy::ARCHIVE_ITEM, item_a( Statuses::LIVE, ORG_B, 202 ) ), 'member of A cannot archive B live item' );
Harness::assert_true( Policy::decide( member_b(), Policy::VIEW_ITEM, $b_item ), 'member of B can view own B draft' );

Harness::group( 'Orphaned items' );

$orphan = item_a( Statuses::DRAFT, 0, 101 );
Harness::assert_false( Policy::decide( member_a(), Policy::VIEW_ITEM, $orphan ), 'member cannot view an item with no organisation' );
Harness::assert_false( Policy::decide( member_a(), Policy::EDIT_ITEM, $orphan ), 'member cannot edit an orphaned item' );
Harness::assert_true( Policy::decide( administrator(), Policy::EDIT_ITEM, $orphan ), 'administrator can edit an orphaned item to reassign it' );

Harness::group( 'Content is frozen while under review' );

Harness::assert_false( Policy::decide( member_a(), Policy::EDIT_ITEM, item_a( Statuses::PENDING ) ), 'member cannot edit their own item while pending' );
Harness::assert_false( Policy::decide( member_a(), Policy::ARCHIVE_ITEM, item_a( Statuses::PENDING ) ), 'member cannot archive their own item while pending' );
Harness::assert_true( Policy::decide( member_a(), Policy::EDIT_ITEM, item_a( Statuses::DRAFT ) ), 'member can edit a draft' );
Harness::assert_true( Policy::decide( member_a(), Policy::EDIT_ITEM, item_a( Statuses::CHANGES ) ), 'member can edit after changes requested' );
Harness::assert_true( Policy::decide( member_a(), Policy::EDIT_ITEM, item_a( Statuses::LIVE ) ), 'member can edit a live item, producing a revision' );
Harness::assert_true( Policy::decide( member_a(), Policy::EDIT_ITEM, item_a( Statuses::EXPIRED ) ), 'member can edit an expired item' );
Harness::assert_false( Policy::decide( member_a(), Policy::EDIT_ITEM, item_a( Statuses::ARCHIVED ) ), 'member cannot edit an archived item without restoring it' );
Harness::assert_false( Policy::decide( member_a(), Policy::EDIT_ITEM, item_a( Statuses::REJECTED ) ), 'member cannot edit a rejected item' );

Harness::group( 'Account state gates' );

$pending_account = member_a( UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_PENDING );
Harness::assert_true( Policy::decide( $pending_account, Policy::CREATE_ITEM ), 'pending member can start a draft' );
Harness::assert_true( Policy::decide( $pending_account, Policy::EDIT_ITEM, item_a( Statuses::DRAFT ) ), 'pending member can edit their draft' );
Harness::assert_false( Policy::decide( $pending_account, Policy::SUBMIT_ITEM, item_a( Statuses::DRAFT ) ), 'pending member cannot submit' );

$suspended = member_a( UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_SUSPENDED );
Harness::assert_true( Policy::decide( $suspended, Policy::VIEW_ITEM, item_a( Statuses::LIVE ) ), 'suspended member can still read their own content' );
Harness::assert_false( Policy::decide( $suspended, Policy::EDIT_ITEM, item_a( Statuses::DRAFT ) ), 'suspended member cannot edit' );
Harness::assert_false( Policy::decide( $suspended, Policy::CREATE_ITEM ), 'suspended member cannot create' );

$closed = member_a( UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_CLOSED );
Harness::assert_false( Policy::decide( $closed, Policy::VIEW_ITEM, item_a( Statuses::LIVE ) ), 'closed account cannot view' );
Harness::assert_false( Policy::decide( $closed, Policy::CREATE_ITEM ), 'closed account cannot create' );

$anon = UserContext::anonymous();
Harness::assert_false( Policy::decide( $anon, Policy::VIEW_ITEM, item_a( Statuses::LIVE ) ), 'anonymous visitor is denied by the policy' );
Harness::assert_false( Policy::decide( $anon, Policy::MODERATE_ITEM, item_a( Statuses::PENDING ) ), 'anonymous visitor cannot moderate' );

Harness::group( 'Organisation verification gates submission' );

$unverified_org = new UserContext( 105, [ UserContext::ROLE_MEMBER ], ORG_A, UserContext::ORG_OWNER, UserContext::ACCOUNT_APPROVED, false );
Harness::assert_false( Policy::decide( $unverified_org, Policy::SUBMIT_ITEM, item_a( Statuses::DRAFT, ORG_A, 105 ) ), 'unverified organisation cannot submit' );
Harness::assert_true( Policy::decide( $unverified_org, Policy::CREATE_ITEM ), 'unverified organisation can still draft' );
Harness::assert_false( Policy::decide( $unverified_org, Policy::INVITE_MEMBER ), 'unverified organisation cannot send invites' );

Harness::group( 'Organisation roles' );

Harness::assert_false( Policy::decide( member_a( UserContext::ORG_CONTRIBUTOR ), Policy::MANAGE_ORG ), 'contributor cannot edit the organisation profile' );
Harness::assert_true( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::MANAGE_ORG ), 'owner can edit the organisation profile' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_CONTRIBUTOR ), Policy::INVITE_MEMBER ), 'contributor cannot invite colleagues' );
Harness::assert_true( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::INVITE_MEMBER ), 'owner of a verified organisation can invite' );

Harness::group( 'Moderation' );

Harness::assert_true( Policy::decide( moderator(), Policy::VIEW_ITEM, $b_item ), 'moderator can view any organisation item' );
Harness::assert_true( Policy::decide( moderator(), Policy::MODERATE_ITEM, item_a( Statuses::PENDING ) ), 'moderator can decide a pending item' );
Harness::assert_false( Policy::decide( moderator(), Policy::MODERATE_ITEM, item_a( Statuses::LIVE ) ), 'moderator cannot re-decide a live item' );
Harness::assert_false( Policy::decide( moderator(), Policy::MODERATE_ITEM, item_a( Statuses::DRAFT ) ), 'moderator cannot decide a draft' );
Harness::assert_false( Policy::decide( moderator( 101 ), Policy::MODERATE_ITEM, item_a( Statuses::PENDING, ORG_A, 101 ) ), 'moderator cannot approve their own submission' );
Harness::assert_true( Policy::decide( administrator(), Policy::MODERATE_ITEM, item_a( Statuses::PENDING, ORG_A, 404 ) ), 'administrator can unstick their own submission' );
Harness::assert_false( Policy::decide( member_a(), Policy::MODERATE_ITEM, item_a( Statuses::PENDING ) ), 'member cannot moderate' );

Harness::assert_true( Policy::decide( moderator(), Policy::TAKE_DOWN_ITEM, item_a( Statuses::LIVE ) ), 'moderator can take down live content' );
Harness::assert_false( Policy::decide( moderator(), Policy::TAKE_DOWN_ITEM, item_a( Statuses::PENDING ) ), 'take down only applies to live content' );
Harness::assert_false( Policy::decide( member_a(), Policy::TAKE_DOWN_ITEM, item_a( Statuses::LIVE ) ), 'member cannot take down content' );

Harness::group( 'Trust is an administrator decision' );

Harness::assert_false( Policy::decide( moderator(), Policy::GRANT_TRUST ), 'moderator cannot grant trust' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::GRANT_TRUST ), 'org owner cannot grant themselves trust' );
Harness::assert_true( Policy::decide( administrator(), Policy::GRANT_TRUST ), 'administrator can grant trust' );

Harness::group( 'Deletion is limited to untouched drafts' );

Harness::assert_true( Policy::decide( member_a(), Policy::DELETE_ITEM, item_a( Statuses::DRAFT ) ), 'member can delete their own draft' );
Harness::assert_false( Policy::decide( member_a(), Policy::DELETE_ITEM, item_a( Statuses::LIVE ) ), 'member cannot delete live content' );
Harness::assert_false( Policy::decide( member_a(), Policy::DELETE_ITEM, item_a( Statuses::REJECTED ) ), 'member cannot delete a rejected item, preserving the trail' );
Harness::assert_false( Policy::decide( moderator(), Policy::DELETE_ITEM, item_a( Statuses::LIVE ) ), 'moderator cannot permanently delete' );
Harness::assert_true( Policy::decide( administrator(), Policy::DELETE_ITEM, item_a( Statuses::LIVE ) ), 'administrator can permanently delete' );

Harness::group( 'Unknown actions fail closed' );

Harness::assert_false( Policy::decide( administrator(), 'do_anything_at_all' ), 'an unrecognised action is denied even for an administrator' );
Harness::assert_false( Policy::decide( member_a(), Policy::VIEW_ITEM, null ), 'an item action with no item is denied' );

Harness::group( 'Only a person pressing a button has their status change held' );

/*
 * Found by doing it: open a pending submission in wp-admin, fix a typo, press
 * the normal save button, and it went live. WordPress's publish box cannot
 * represent a custom status, so it posts its own and the save promotes the
 * post. The member's work reached the public with nobody deciding and nobody
 * telling them.
 *
 * Held for wp-admin's own save paths only. Pinning it for every caller also
 * pinned WP-CLI and left no way to put a broken item right.
 */
$hold = static fn( string $page, bool $admin, bool $ajax = false, bool $cron = false, string $action = '' ): bool
	=> DGL\Admin\Guard::should_hold( $page, $admin, $ajax, $cron, $action );

Harness::assert_true( $hold( 'post.php', true ), 'the edit screen is held' );
Harness::assert_true( $hold( 'edit.php', true ), 'and so is bulk edit' );
Harness::assert_true( $hold( '', true, true, false, 'inline-save' ), 'and Quick Edit, which posts through ajax' );

Harness::assert_false( $hold( 'post.php', false ), 'a front-end request is not' );
Harness::assert_false( $hold( '', false ), 'nor is WP-CLI, so a broken item can still be put right' );
Harness::assert_false( $hold( 'post.php', true, false, true ), 'nor is cron, which runs the expiry sweep' );
Harness::assert_false( $hold( '', true, true, false, 'heartbeat' ), 'and another ajax action is not Quick Edit' );
Harness::assert_false( $hold( 'upload.php', true ), 'nor is an unrelated admin screen' );
