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

Harness::group( 'Trust is a review team decision' );

Harness::assert_true( Policy::decide( moderator(), Policy::GRANT_TRUST ), 'moderator can set trust' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::GRANT_TRUST ), 'org owner cannot grant themselves trust' );
Harness::assert_true( Policy::decide( administrator(), Policy::GRANT_TRUST ), 'administrator can set trust' );
Harness::assert_true( Policy::decide( moderator(), Policy::MANAGE_ORGS ), 'moderator can open the Organisations section' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::MANAGE_ORGS ), 'a member cannot' );

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

Harness::group( 'Removing a member: an owner, in their own organisation, never themselves' );

$owner_a       = member_a( UserContext::ORG_OWNER );
$contrib_a     = member_a( UserContext::ORG_CONTRIBUTOR );
$pending_owner = member_a( UserContext::ORG_OWNER, UserContext::ACCOUNT_PENDING );

Harness::assert_true( Policy::can_remove_member( $owner_a, 999, ORG_A ), 'an owner can remove a colleague in their organisation' );
Harness::assert_false( Policy::can_remove_member( $contrib_a, 999, ORG_A ), 'a contributor cannot remove anybody' );
Harness::assert_false( Policy::can_remove_member( $owner_a, $owner_a->user_id, ORG_A ), 'an owner cannot remove themselves' );
Harness::assert_false( Policy::can_remove_member( $owner_a, 999, ORG_B ), 'an owner cannot remove somebody from another organisation' );
Harness::assert_false( Policy::can_remove_member( $owner_a, 999, null ), 'nobody can remove a person with no organisation' );
Harness::assert_false( Policy::can_remove_member( $owner_a, 0, ORG_A ), 'a missing target is refused' );
Harness::assert_false( Policy::can_remove_member( $pending_owner, 999, ORG_A ), 'an owner whose own account is still pending cannot remove anybody' );
Harness::assert_true( Policy::can_remove_member( administrator(), 999, ORG_B ), 'an administrator can remove anybody from any organisation' );
Harness::assert_false( Policy::can_remove_member( administrator(), administrator()->user_id, ORG_B ), 'even an administrator cannot remove themselves this way' );
Harness::assert_false( Policy::can_remove_member( moderator(), 999, ORG_A ), 'a moderator is not an owner and cannot remove members' );

Harness::group( 'Changing a live event\'s dates without review' );

$news_live = new ItemContext( 9002, PostTypes::NEWS, ORG_A, 101, Statuses::LIVE );

Harness::assert_true( Policy::decide( member_a(), Policy::CHANGE_SCHEDULE, item_a( Statuses::LIVE ) ), 'a member of the owning organisation can change a live event\'s schedule' );
Harness::assert_true( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::CHANGE_SCHEDULE, item_a( Statuses::LIVE ) ), 'so can its owner' );
Harness::assert_false( Policy::decide( member_b(), Policy::CHANGE_SCHEDULE, item_a( Statuses::LIVE ) ), 'another organisation cannot' );
Harness::assert_false( Policy::decide( member_a(), Policy::CHANGE_SCHEDULE, item_a( Statuses::DRAFT ) ), 'a draft carries its dates through the wizard, not here' );
Harness::assert_false( Policy::decide( member_a(), Policy::CHANGE_SCHEDULE, item_a( Statuses::PENDING ) ), 'nor a pending one' );
Harness::assert_false( Policy::decide( member_a(), Policy::CHANGE_SCHEDULE, item_a( Statuses::EXPIRED ) ), 'nor an expired one, which goes back through review' );
Harness::assert_false( Policy::decide( member_a(), Policy::CHANGE_SCHEDULE, $news_live ), 'news has no schedule' );
Harness::assert_false( Policy::decide( moderator(), Policy::CHANGE_SCHEDULE, item_a( Statuses::LIVE ) ), 'a moderator does not change members\' dates for them' );
Harness::assert_true( Policy::decide( administrator(), Policy::CHANGE_SCHEDULE, item_a( Statuses::LIVE ) ), 'an administrator can' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_PENDING ), Policy::CHANGE_SCHEDULE, item_a( Statuses::LIVE ) ), 'an unapproved account cannot' );

Harness::group( 'Reopening a refusal is a moderator\'s move' );

Harness::assert_true( Policy::decide( moderator(), Policy::REOPEN_ITEM, item_a( Statuses::REJECTED ) ), 'a moderator can reopen a refusal' );
Harness::assert_true( Policy::decide( moderator( 101 ), Policy::REOPEN_ITEM, item_a( Statuses::REJECTED ) ), 'including the author-moderator: undoing a mistake needs no second person' );
Harness::assert_false( Policy::decide( moderator(), Policy::REOPEN_ITEM, item_a( Statuses::LIVE ) ), 'only a refusal reopens' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::REOPEN_ITEM, item_a( Statuses::REJECTED ) ), 'a member cannot reopen their own refusal' );
Harness::assert_true( Policy::decide( administrator(), Policy::REOPEN_ITEM, item_a( Statuses::REJECTED ) ), 'an administrator can' );

Harness::group( 'Keeping a listing up longer is the owning organisation\'s move' );

$news_live = new ItemContext( 9003, PostTypes::NEWS, ORG_A, 101, Statuses::LIVE );
$training_live = new ItemContext( 9004, PostTypes::TRAINING, ORG_A, 101, Statuses::LIVE );

Harness::assert_true( Policy::decide( member_a(), Policy::EXTEND_ITEM, item_a( Statuses::LIVE ) ), 'an approved member can extend a live event' );
Harness::assert_true( Policy::decide( member_a(), Policy::EXTEND_ITEM, $news_live ), 'and a live news item' );
Harness::assert_false( Policy::decide( member_a(), Policy::EXTEND_ITEM, $training_live ), 'training comes off on its own date' );
Harness::assert_false( Policy::decide( member_b(), Policy::EXTEND_ITEM, $news_live ), 'another organisation cannot' );
Harness::assert_false( Policy::decide( member_a(), Policy::EXTEND_ITEM, item_a( Statuses::EXPIRED ) ), 'nor once it has expired' );
Harness::assert_false( Policy::decide( moderator(), Policy::EXTEND_ITEM, $news_live ), 'a moderator does not extend members\' listings for them' );

Harness::group( 'Changing a colleague\'s role follows the removal rules' );

Harness::assert_true( Policy::can_change_role( member_a( UserContext::ORG_OWNER ), 999, ORG_A ), 'an owner can change a colleague\'s role' );
Harness::assert_false( Policy::can_change_role( member_a( UserContext::ORG_CONTRIBUTOR ), 999, ORG_A ), 'a contributor cannot' );
Harness::assert_false( Policy::can_change_role( member_a( UserContext::ORG_OWNER ), member_a( UserContext::ORG_OWNER )->user_id, ORG_A ), 'nobody changes their own role, so the last owner cannot demote themselves' );
Harness::assert_false( Policy::can_change_role( member_a( UserContext::ORG_OWNER ), 999, ORG_B ), 'nor a person in another organisation' );

Harness::group( 'Featuring is the review team\'s move on live items' );

Harness::assert_true( Policy::decide( moderator(), Policy::PIN_ITEM, item_a( Statuses::LIVE ) ), 'a moderator can feature a live item' );
Harness::assert_false( Policy::decide( moderator(), Policy::PIN_ITEM, item_a( Statuses::PENDING ) ), 'not a pending one' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::PIN_ITEM, item_a( Statuses::LIVE ) ), 'and a member cannot feature their own' );

Harness::group( 'Cancelling is the owning organisation\'s move on a live event, the same gate as its dates' );

Harness::assert_true( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::CANCEL_ITEM, item_a( Statuses::LIVE ) ), 'an owner can cancel their live event' );
Harness::assert_false( Policy::decide( member_a( UserContext::ORG_OWNER ), Policy::CANCEL_ITEM, item_a( Statuses::PENDING ) ), 'not one that is not on the site' );
Harness::assert_false( Policy::decide( member_b( UserContext::ORG_OWNER ), Policy::CANCEL_ITEM, item_a( Statuses::LIVE ) ), 'another organisation cannot' );
Harness::assert_false( Policy::decide( moderator(), Policy::CANCEL_ITEM, item_a( Statuses::LIVE ) ), 'a plain moderator cannot: they take things down instead' );
