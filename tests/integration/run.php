<?php
/**
 * Integration tests. Run inside a real WordPress:
 *
 *   wp eval-file tests/integration/run.php --allow-root
 *
 * These cover what the standalone suite deliberately cannot: the WordPress
 * capability system, dbDelta, and the organisation scoping that decides whether
 * one member organisation can read another's unpublished work.
 *
 * @package DGL
 */

// No strict_types declaration: wp eval-file evaluates this file's contents, so
// it cannot be the first statement in the script.

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Audit\Log;
use DGL\Index\ItemsTable;
use DGL\Invites\Invites;
use DGL\Invites\Rules as InviteRules;
use DGL\Invites\Store as InviteStore;
use DGL\Email\Digest\Frequency;
use DGL\Email\Digest\Runner as DigestRunner;
use DGL\Email\Digest\Store as DigestStore;
use DGL\Meta;
use DGL\Moderation\Checks;
use DGL\Org\Trust;
use DGL\PostTypes;
use DGL\Roles;
use DGL\Statuses;
use DGL\Workflow\StateMachine;
use DGL\Workflow\Transition;

$passed   = 0;
$failures = [];

$ok = static function ( bool $condition, string $what ) use ( &$passed, &$failures ): void {
	if ( $condition ) {
		++$passed;
		echo "  ok    {$what}\n";
		return;
	}
	$failures[] = $what;
	echo "  FAIL  {$what}\n";
};

$group = static function ( string $name ): void {
	echo "\n{$name}\n";
};

/* ---------------------------------------------------------------- fixtures */

/*
 * Safety gate. This suite creates and destroys content. Without a guard it
 * would happily run against a site with real member submissions on it and
 * delete them, which is the sort of mistake that ends a client relationship.
 *
 * Define DGL_TEST_SITE in wp-config.php on a throwaway install to allow it.
 * bin/setup-test-wp.sh does that for you.
 */
if ( ! defined( 'DGL_TEST_SITE' ) || ! DGL_TEST_SITE ) {
	echo "\nREFUSED. This suite creates and deletes content.\n";
	echo "Add define( 'DGL_TEST_SITE', true ); to wp-config.php on a throwaway install.\n";
	echo "Never define it on staging or production.\n";
	exit( 1 );
}

/** Marks a post as ours to clean up. Nothing without it is ever deleted. */
const DGL_FIXTURE_FLAG = '_dgl_test_fixture';

$group( 'Fixtures' );

global $wpdb;

/*
 * Remove only what previous runs of this suite created. An earlier version
 * deleted every dgl_org and every item on the site, which wiped the demo
 * content sitting alongside it and would have wiped real content just as
 * cheerfully.
 *
 * Fixtures are also cleared before each run rather than after, because a run
 * that fails half way through still has to leave the next one a clean start.
 */
require_once ABSPATH . 'wp-admin/includes/user.php';

/*
 * Accounts are cleared by a flag rather than by a list of logins. The list
 * came first and drifted the moment a test created an account the list did
 * not know about: the invitation tests create accounts named after the
 * address invited, the leftovers survived into the next run, and that run
 * failed against state its own previous run had left behind. A suite that
 * only passes on a clean database is a suite that lies to whoever runs it
 * second.
 *
 * The list is kept alongside the flag for accounts created before the flag
 * existed, and for the run that dies before it gets round to flagging.
 */
$fixture_users = get_users(
	[
		'meta_key'   => DGL_FIXTURE_FLAG,
		'meta_value' => '1',
		'fields'     => 'ID',
		'number'     => 200,
	]
);

foreach ( $fixture_users as $stale_user ) {
	wp_delete_user( (int) $stale_user );
}

foreach ( [ 'dgl_alice', 'dgl_aaron', 'dgl_bella', 'dgl_mod', 'dgl_pending', 'dgl_susp', 'dgl_carl', 'dgl_tina', 'dgl_owen', 'dgl_pendowner', 'dgl_loose', 'dgl_privacy', 'dgl_dirowner', 'dgl_dircontr', 'dgl_dirpend', 'dgl_dirout', 'dgl_wizard' ] as $login ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		wp_delete_user( $existing->ID );
	}
}

// Accounts the invitation tests create are named after the address invited.
foreach ( [ 'newcomer@example.test', 'loose@example.test', 'toolate@example.test', 'withdrawme@example.test', 'awkward@example.test' ] as $address ) {
	$existing = get_user_by( 'email', $address );
	if ( $existing ) {
		wp_delete_user( $existing->ID );
	}
}

/*
 * Statuses are named rather than asked for as 'any'. WP_Query reads 'any' as
 * every status that is not excluded from search, and this plugin's custom
 * statuses are excluded from search, so 'any' quietly skipped every pending,
 * expired, archived and rejected fixture. They were flagged for deletion and
 * never deleted: 228 pending events had accumulated across runs, enough to
 * push a genuinely new item past the 200-row limit of the moderation queue and
 * fail an assertion that had nothing to do with any of it.
 *
 * The same mistake, with the same cause, was fixed once already in
 * Workflow\Revisions. It is worth naming every time it appears.
 */
$stale = get_posts(
	[
		'post_type'      => array_merge( PostTypes::submittable(), [ PostTypes::ORG, PostTypes::REVISION ] ),
		'post_status'    => array_merge( Statuses::all(), [ 'publish', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ] ),
		'numberposts'    => -1,
		'fields'         => 'ids',
		'meta_key'       => DGL_FIXTURE_FLAG,
		'meta_value'     => '1',
	]
);

/*
 * Revisions are created by the plugin rather than by a fixture maker, so they
 * never carry the flag. One whose parent has just been deleted is rubbish by
 * definition.
 */
$orphan_revisions = $wpdb->get_col(
	"SELECT r.ID FROM {$wpdb->posts} r
	LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent
	WHERE r.post_type = '" . PostTypes::REVISION . "'
	AND ( r.post_parent = 0 OR p.ID IS NULL )"
);

$stale = array_values( array_unique( array_merge( array_map( 'intval', $stale ), array_map( 'intval', $orphan_revisions ) ) ) );

foreach ( $stale as $stale_id ) {
	wp_delete_post( $stale_id, true );
}

// The index and audit rows for anything that just went, and nothing else.
if ( ! empty( $stale ) ) {
	$in = implode( ',', array_map( 'intval', $stale ) );
	$wpdb->query( 'DELETE FROM ' . ItemsTable::name() . " WHERE post_id IN ({$in})" );
	$wpdb->query( 'DELETE FROM ' . \DGL\Audit\Table::name() . " WHERE object_id IN ({$in})" );

	// Invitations belong to an organisation that has just been deleted.
	if ( \DGL\Invites\Store::exists() ) {
		$wpdb->query( 'DELETE FROM ' . \DGL\Invites\Store::name() . " WHERE org_id IN ({$in})" );
	}
}

Access::flush_cache();

$make_org = static function ( string $name, string $status = Meta::ORG_APPROVED, int $trust = Trust::MODERATED ): int {
	$id = wp_insert_post(
		[
			'post_type'   => PostTypes::ORG,
			'post_title'  => $name,
			'post_status' => 'publish',
		]
	);
	update_post_meta( $id, DGL_FIXTURE_FLAG, '1' );
	update_post_meta( $id, Meta::ORG_STATUS, $status );
	update_post_meta( $id, Meta::ORG_TRUST, $trust );
	return (int) $id;
};

$make_member = static function ( string $login, int $org_id, string $org_role, string $account = 'approved' ) use ( $ok ): int {
	$id = wp_insert_user(
		[
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => $login . '@example.test',
			'role'       => Roles::MEMBER,
		]
	);

	/*
	 * A duplicate login returns WP_Error, which casts to 0, and every
	 * assertion downstream then runs against user 0 and passes for the wrong
	 * reason. Caught once for real, so the fixture now fails loudly.
	 */
	if ( is_wp_error( $id ) ) {
		$ok( false, 'fixture user ' . $login . ' could not be created: ' . $id->get_error_message() );
		return 0;
	}

	update_user_meta( $id, DGL_FIXTURE_FLAG, '1' );
	update_user_meta( $id, Meta::USER_ORG, $org_id );
	update_user_meta( $id, Meta::USER_ORG_ROLE, $org_role );
	update_user_meta( $id, Meta::USER_ACCOUNT_STATUS, $account );
	return (int) $id;
};

$make_item = static function ( int $org_id, int $author_id, string $status ): int {
	$id = wp_insert_post(
		[
			'post_type'   => PostTypes::EVENT,
			'post_title'  => 'Item ' . wp_generate_password( 6, false ),
			'post_status' => $status,
			'post_author' => $author_id,
		]
	);
	update_post_meta( $id, DGL_FIXTURE_FLAG, '1' );
	update_post_meta( $id, Meta::ITEM_ORG, $org_id );
	return (int) $id;
};

$org_a = $make_org( 'Org A' );
$org_b = $make_org( 'Org B' );

$alice = $make_member( 'dgl_alice', $org_a, 'owner' );      // Org A owner
$aaron = $make_member( 'dgl_aaron', $org_a, 'contributor' ); // Org A colleague
$bella = $make_member( 'dgl_bella', $org_b, 'owner' );       // Org B owner

$mod = wp_insert_user(
	[
		'user_login' => 'dgl_mod',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'mod@example.test',
		'role'       => Roles::MODERATOR,
	]
);

$a_draft   = $make_item( $org_a, $alice, Statuses::DRAFT );
$a_pending = $make_item( $org_a, $alice, Statuses::PENDING );
$a_live    = $make_item( $org_a, $alice, Statuses::LIVE );
$b_draft   = $make_item( $org_b, $bella, Statuses::DRAFT );

$ok( $org_a > 0 && $org_b > 0, 'two organisations created' );
$ok( $alice > 0 && $aaron > 0 && $bella > 0 && $mod > 0, 'three members and a moderator created' );
$ok( $a_draft > 0 && $b_draft > 0, 'items created in both organisations' );

/* ------------------------------------------------- organisation isolation */

$group( 'Organisation isolation, through the real capability system' );

Access::flush_cache();

$ok( ! user_can( $alice, 'edit_post', $b_draft ), 'Alice cannot edit an Org B draft' );
$ok( ! user_can( $alice, 'delete_post', $b_draft ), 'Alice cannot delete an Org B draft' );
$ok( ! user_can( $alice, 'read_post', $b_draft ), 'Alice cannot read an Org B draft' );
$ok( ! user_can( $bella, 'edit_post', $a_draft ), 'Bella cannot edit an Org A draft' );

$ok( ! Access::can( $alice, Policy::VIEW_ITEM, $b_draft ), 'the policy agrees Alice cannot view Org B work' );
$ok( ! Access::can( $alice, Policy::SUBMIT_ITEM, $b_draft ), 'Alice cannot submit Org B work' );

$group( 'The organisation owns the content, not the author' );

/*
 * WordPress would normally refuse this: Aaron is not the author and has no
 * edit_others capability. The organisation posts as a body, so a colleague has
 * to be able to pick up a teammate's submission.
 */
$ok( user_can( $aaron, 'edit_post', $a_draft ), 'Aaron can edit his colleague Alice s draft' );
$ok( ! user_can( $aaron, 'edit_post', $b_draft ), 'but still cannot touch Org B' );

$group( 'Content is frozen while a moderator holds it' );

$ok( ! user_can( $alice, 'edit_post', $a_pending ), 'Alice cannot edit her own item while it is pending' );
$ok( user_can( $alice, 'edit_post', $a_live ), 'Alice can edit her own live item, which will make a revision' );
$ok( user_can( $alice, 'edit_post', $a_draft ), 'Alice can edit her own draft' );

$group( 'Moderators' );

$ok( user_can( $mod, 'read_post', $a_draft ), 'the moderator can read any organisation s work' );
$ok( user_can( $mod, 'read_post', $b_draft ), 'including the other organisation' );
$ok( Access::can( $mod, Policy::MODERATE_ITEM, $a_pending ), 'the moderator can decide a pending item' );
$ok( ! Access::can( $mod, Policy::MODERATE_ITEM, $a_draft ), 'but cannot decide a draft that was never submitted' );
$ok( ! Access::can( $mod, Policy::GRANT_TRUST ), 'and cannot grant trust' );
$ok( ! user_can( $mod, 'delete_post', $a_live ), 'and cannot permanently delete' );

$group( 'Account state' );

$pending_user = $make_member( 'dgl_pending', $org_a, 'contributor', 'pending' );
$susp_user    = $make_member( 'dgl_susp', $org_a, 'contributor', 'suspended' );
Access::flush_cache();

$p_draft = $make_item( $org_a, $pending_user, Statuses::DRAFT );
$ok( Access::can( $pending_user, Policy::CREATE_ITEM ), 'an unapproved member can still start a draft' );
$ok( ! Access::can( $pending_user, Policy::SUBMIT_ITEM, $p_draft ), 'but cannot submit it' );
$ok( ! Access::can( $susp_user, Policy::EDIT_ITEM, $a_draft ), 'a suspended member cannot edit' );
$ok( Access::can( $susp_user, Policy::VIEW_ITEM, $a_draft ), 'but can still read their organisation s work' );

$group( 'Unverified organisation' );

$org_c   = $make_org( 'Org C', Meta::ORG_PENDING );
$carl    = $make_member( 'dgl_carl', $org_c, 'owner' );
$c_draft = $make_item( $org_c, $carl, Statuses::DRAFT );
Access::flush_cache();

$ok( Access::can( $carl, Policy::CREATE_ITEM ), 'an unverified organisation can draft' );
$ok( ! Access::can( $carl, Policy::SUBMIT_ITEM, $c_draft ), 'but cannot submit' );
$ok( ! Access::can( $carl, Policy::INVITE_MEMBER ), 'and cannot send invitations' );
$ok( Trust::MODERATED === \DGL\Org\Org::trust_level( $org_c ), 'trust cannot outlive verification' );

/* ------------------------------------------------------------- index table */

$group( 'Items index' );

ItemsTable::upsert(
	[
		'post_id'      => $a_live,
		'post_type'    => PostTypes::EVENT,
		'org_id'       => $org_a,
		'author_id'    => $alice,
		'status'       => Statuses::LIVE,
		'approved_at'  => '2026-09-01 10:00:00',
		'updated_at'   => '2026-09-01 10:00:00',
		'expires_at'   => '2026-09-02 10:00:00',
	]
);
ItemsTable::upsert(
	[
		'post_id'      => $b_draft,
		'post_type'    => PostTypes::EVENT,
		'org_id'       => $org_b,
		'author_id'    => $bella,
		'status'       => Statuses::DRAFT,
		'updated_at'   => '2026-09-01 11:00:00',
	]
);

$a_items = ItemsTable::for_org( $org_a );
$b_items = ItemsTable::for_org( $org_b );

$ok( in_array( $a_live, $a_items, true ), 'the index returns Org A s item to Org A' );
$ok( ! in_array( $b_draft, $a_items, true ), 'and never returns Org B s item to Org A' );
$ok( in_array( $b_draft, $b_items, true ), 'Org B sees its own item' );

$due = ItemsTable::due_for_expiry( '2026-09-03 00:00:00' );
$ok( in_array( $a_live, $due, true ), 'an item past its expiry is picked up by the sweep' );
$due_early = ItemsTable::due_for_expiry( '2026-09-01 12:00:00' );
$ok( ! in_array( $a_live, $due_early, true ), 'and is left alone before it expires' );

$published = ItemsTable::published_since( [ PostTypes::EVENT ], '2026-08-01 00:00:00' );
$ok( in_array( $a_live, $published, true ), 'a live item appears in the digest window' );
$ok( ! in_array( $b_draft, $published, true ), 'a draft never appears in a digest' );

$counts = ItemsTable::counts_for_org( $org_a );
$ok( 1 === ( $counts[ Statuses::LIVE ] ?? 0 ), 'the dashboard tile count is right for Org A' );

ItemsTable::delete( $b_draft );
$ok( ! in_array( $b_draft, ItemsTable::for_org( $org_b ), true ), 'deleting an index row removes it' );

$group( 'Index uses its indexes, not a table scan' );

/*
 * An index that exists but is never chosen by the planner is decoration. This
 * asserts the planner actually reaches for it.
 *
 * $wpdb cannot answer this: on SQLite the drop-in translates MySQL and does not
 * pass EXPLAIN through, and the two engines spell the question differently
 * anyway. So the query plan is read from the engine directly.
 */
$member_query = 'SELECT post_id FROM ' . ItemsTable::name()
	. ' WHERE org_id = 1 AND status = \'publish\' ORDER BY updated_at DESC';
$queue_query  = 'SELECT post_id FROM ' . ItemsTable::name()
	. ' WHERE status = \'dgl_pending\' ORDER BY submitted_at ASC';

$plan_for = static function ( string $sql ): string {
	if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE && defined( 'FQDB' ) && file_exists( FQDB ) ) {
		$pdo  = new PDO( 'sqlite:' . FQDB );
		$rows = $pdo->query( 'EXPLAIN QUERY PLAN ' . $sql )->fetchAll( PDO::FETCH_ASSOC );
		return strtolower( (string) wp_json_encode( $rows ) );
	}

	global $wpdb;
	return strtolower( (string) wp_json_encode( $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ) ) );
};

$member_plan = $plan_for( $member_query );
$queue_plan  = $plan_for( $queue_query );

$ok( str_contains( $member_plan, 'org_status' ), 'the member dashboard query uses the org_status index' );
$ok( ! str_contains( $member_plan, 'scan ' . ItemsTable::name() . '"' ), 'the member dashboard query is not a full table scan' );
$ok( str_contains( $queue_plan, 'queue' ), 'the moderation queue query uses the queue index' );

/* -------------------------------------------------------------- audit log */

$group( 'Audit log' );

Log::record( 'approve', 'item', $a_live, $org_a, 'Looks good.', [ 'status' => [ 'dgl_pending', 'publish' ] ], $mod );
Log::record( 'submit', 'item', $a_live, $org_a, '', [], $alice );

$item_history = Log::for_object( 'item', $a_live );
$ok( 2 === count( $item_history ), 'both audit entries were written' );
$ok( 'approve' === $item_history[0]['action'], 'history reads oldest first' );
$ok( 'Looks good.' === $item_history[0]['note'], 'the note is stored' );
$ok( ! empty( $item_history[0]['changes'] ), 'the field diff is stored' );

$org_history = Log::for_org( $org_a );
$ok( count( $org_history ) >= 2, 'the organisation sees its own history' );
$ok( count( Log::for_org( $org_b ) ) === 0, 'and never sees another organisation s' );

$hash = $item_history[0]['actor_ip_hash'];
$ok( null === $hash || 64 === strlen( (string) $hash ), 'the actor address is stored as a hash or not at all' );


/* ------------------------------------------------------- workflow, end to end */

$group( 'Workflow: the moderated path' );


$w_item = $make_item( $org_a, $alice, Statuses::DRAFT );
$future_start = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days 10:00' ) );
$future_end   = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days 15:00' ) );
update_post_meta( $w_item, 'dgl_start_datetime', $future_start );
update_post_meta( $w_item, 'dgl_end_datetime', $future_end );
Access::flush_cache();

$r = Transition::apply( $w_item, StateMachine::SUBMIT, $alice );
$ok( true === $r, 'Alice can submit her draft' );
$ok( Statuses::PENDING === get_post_status( $w_item ), 'it lands in the queue' );
$ok( '' !== (string) get_post_meta( $w_item, Meta::ITEM_SUBMITTED_AT, true ), 'the submission time is stamped' );
$ok( $future_end === get_post_meta( $w_item, Meta::ITEM_EXPIRES_AT, true ), 'expiry was computed from the event end time' );
$ok( in_array( $w_item, ItemsTable::queue( null, 1000 ), true ), 'and it appears in the moderation queue' );

$r = Transition::apply( $w_item, StateMachine::SUBMIT, $alice );
$ok( is_wp_error( $r ), 'submitting twice is refused' );
$ok( is_wp_error( $r ) && 'dgl_illegal_transition' === $r->get_error_code(), 'and says why' );

$r = Transition::apply( $w_item, StateMachine::APPROVE, $bella );
$ok( is_wp_error( $r ), 'a member of another organisation cannot approve it' );
$ok( Statuses::PENDING === get_post_status( $w_item ), 'and the status is untouched by the attempt' );

$r = Transition::apply( $w_item, StateMachine::REJECT, $mod );
$ok( is_wp_error( $r ) && 'dgl_note_required' === $r->get_error_code(), 'rejecting without a reason is refused' );
$ok( Statuses::PENDING === get_post_status( $w_item ), 'and nothing changed' );

$r = Transition::apply( $w_item, StateMachine::APPROVE, $mod );
$ok( true === $r, 'the moderator can approve it' );
$ok( Statuses::LIVE === get_post_status( $w_item ), 'it goes live' );
$ok( '' !== (string) get_post_meta( $w_item, Meta::ITEM_APPROVED_AT, true ), 'the approval time is stamped' );

$history = Log::for_object( 'item', $w_item );
$actions = array_column( $history, 'action' );
$ok( [ 'submit', 'approve' ] === $actions, 'the audit trail records both steps in order' );

$group( 'Workflow: trust, and losing it' );

$org_t  = $make_org( 'Trusted Org', Meta::ORG_APPROVED, Trust::TRUSTED );
$tina   = $make_member( 'dgl_tina', $org_t, 'owner' );
$t_item = $make_item( $org_t, $tina, Statuses::DRAFT );
Access::flush_cache();

$ok( true === Transition::apply( $t_item, StateMachine::SUBMIT, $tina ), 'a trusted member can submit' );
$ok( Statuses::LIVE === get_post_status( $t_item ), 'and it publishes without waiting' );
$ok( '' !== (string) get_post_meta( $t_item, Meta::ITEM_APPROVED_AT, true ), 'approval time is stamped even though nobody approved it' );

$r = Transition::apply( $t_item, StateMachine::TAKE_DOWN, $mod, 'Reported by a reader.' );
$ok( true === $r, 'a moderator can take it down with a reason' );
$ok( Statuses::PENDING === get_post_status( $t_item ), 'it returns to the queue rather than vanishing' );
$ok( Trust::MODERATED === Trust::normalise( get_post_meta( $org_t, Meta::ORG_TRUST, true ) ), 'and the organisation loses its trust automatically' );

$trust_log = Log::for_org( $org_t );
$ok( in_array( 'trust_revoked', array_column( $trust_log, 'action' ), true ), 'the trust change is audited' );

Access::flush_cache();
$t_item2 = $make_item( $org_t, $tina, Statuses::DRAFT );
$ok( true === Transition::apply( $t_item2, StateMachine::SUBMIT, $tina ), 'the same member can still submit' );
$ok( Statuses::PENDING === get_post_status( $t_item2 ), 'but now waits in the queue like everyone else' );

$group( 'Workflow: expiry runs itself' );

$e_item = $make_item( $org_a, $alice, Statuses::DRAFT );
update_post_meta( $e_item, 'dgl_start_datetime', '2020-01-01 10:00:00' );
update_post_meta( $e_item, 'dgl_end_datetime', '2020-01-01 12:00:00' );
Access::flush_cache();
Transition::apply( $e_item, StateMachine::SUBMIT, $alice );
Transition::apply( $e_item, StateMachine::APPROVE, $mod );
$ok( Statuses::LIVE === get_post_status( $e_item ), 'a past-dated event is live until the sweep runs' );

$expired = Transition::run_expiry_sweep();
$ok( $expired >= 1, 'the sweep expired at least one item' );
$ok( Statuses::EXPIRED === get_post_status( $e_item ), 'the past-dated event came off the listings' );
$ok( Statuses::LIVE === get_post_status( $w_item ), 'a future-dated event was left alone' );

$group( 'Workflow: the system cannot act outside expiry' );

$r = Transition::apply( $w_item, StateMachine::APPROVE, 0 );
$ok( is_wp_error( $r ) && 'dgl_no_actor' === $r->get_error_code(), 'nothing approves itself' );

$group( 'Index stays in step with the posts' );

$row_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . ItemsTable::name() . ' WHERE post_id = %d', $e_item ) );
$ok( Statuses::EXPIRED === $row_status, 'the index followed the expiry' );

$rebuilt = \DGL\Index\Sync::rebuild_all();
$ok( $rebuilt > 0, 'a full reindex rebuilds every item (' . $rebuilt . ')' );
$row_status_after = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . ItemsTable::name() . ' WHERE post_id = %d', $e_item ) );
$ok( $row_status === $row_status_after, 'and the rebuilt row matches what was already there' );


$group( 'An item indexed before its organisation meta is written' );

/*
 * Regression guard. wp_insert_post fires save_post before the caller has had a
 * chance to write dgl_org, so the first index row carries org_id 0. Without a
 * re-sync on the meta write, that item is invisible to the organisation that
 * owns it and stays that way, which is how a member ends up with a draft they
 * can neither see nor finish.
 */
$late = wp_insert_post(
	[
		'post_type'   => PostTypes::EVENT,
		'post_title'  => 'Indexed before its org was set',
		'post_status' => Statuses::DRAFT,
		'post_author' => $alice,
	]
);
$drafts_before = ItemsTable::counts_for_org( $org_a )[ Statuses::DRAFT ] ?? 0;

$ok( ! in_array( (int) $late, ItemsTable::for_org( $org_a ), true ), 'before the meta is written the item has no organisation' );

update_post_meta( $late, Meta::ITEM_ORG, $org_a );

$ok( in_array( (int) $late, ItemsTable::for_org( $org_a ), true ), 'writing the organisation meta re-indexes it immediately' );
$ok(
	( ItemsTable::counts_for_org( $org_a )[ Statuses::DRAFT ] ?? 0 ) === $drafts_before + 1,
	'and the drafts tile count goes up by exactly one'
);


$group( 'A broken template cannot blank the page' );

/*
 * Regression guard. A TypeError inside a template used to abort the render with
 * its partial output still in the buffer, which PHP then flushed at shutdown.
 * The member got a fragment with no navigation and no error: their work looked
 * like it had vanished. The render now discards the partial and returns an
 * honest message instead.
 */
$broken = \DGL\Dashboard\View::render( 'dashboard/does-not-exist' );
$ok( '' === $broken, 'a template that does not exist renders nothing rather than warning' );

$ok( '' === \DGL\Dashboard\View::render( '../../../wp-config' ), 'a traversal attempt resolves to nothing' );
$ok( '' === \DGL\Dashboard\View::render( 'dashboard/../../etc/passwd' ), 'so does a nested one' );

$ok( '' === \DGL\Dashboard\View::date( false ), 'a false date renders as empty rather than throwing' );
$ok( '' === \DGL\Dashboard\View::date( null ), 'so does a null one' );
$ok( '' !== \DGL\Dashboard\View::date( '2026-09-14 12:00:00' ), 'and a real one still formats' );


$group( 'A blank optional field leaves no answer behind' );

/*
 * Regression guard. Sanitising '' for a number field cast it to int 0 and
 * stored it, so an untouched Capacity read back as "Capacity: 0" on the review
 * screen and in the moderator's view. That is a claim the member never made,
 * about a field they never touched.
 */
$blank_item = $make_item( $org_a, $alice, Statuses::DRAFT );
Access::flush_cache();

\DGL\Dashboard\Wizard::save_step(
	$blank_item,
	PostTypes::EVENT,
	\DGL\Schema\FieldRegistry::STEP_DETAILS,
	[
		'start_datetime' => gmdate( 'Y-m-d H:i:s', strtotime( '+10 days' ) ),
		'venue_name'     => 'Somewhere',
		'address'        => 'A street',
		'postcode'       => 'LS1 1UD',
		'cost'           => 'free',
		'capacity'       => '',
		'booking_url'    => '',
	]
);

$ok( ! metadata_exists( 'post', $blank_item, 'dgl_capacity' ), 'a blank number stores no meta row at all' );
$ok( ! metadata_exists( 'post', $blank_item, 'dgl_booking_url' ), 'and neither does a blank url' );

$read_back = \DGL\Dashboard\Wizard::values( $blank_item, PostTypes::EVENT );
$ok( '' === $read_back['capacity'], 'so it reads back as empty, not as zero' );
$ok( 'Somewhere' === $read_back['venue_name'], 'while the fields that were filled in survive' );
$ok( metadata_exists( 'post', $blank_item, 'dgl_venue_name' ), 'and keep their meta row' );


$group( 'Automatic checks' );


$chk_item = $make_item( $org_a, $alice, Statuses::DRAFT );
$chk = Checks::run( $chk_item, PostTypes::EVENT );
$by_key = [];
foreach ( $chk as $row ) {
	$by_key[ $row['key'] ] = $row;
}

$ok( 4 === count( $chk ), 'four checks run' );
$ok( Checks::FAIL === $by_key['required']['status'], 'an empty item fails the required-fields check' );

/*
 * A reviewer should read field labels, not database column names. "Missing:
 * start_datetime" is the kind of detail that makes a tool feel like it was
 * built for the developer rather than the person using it.
 */
$ok( str_contains( $by_key['required']['detail'], 'Start date and time' ), 'missing fields are named by their label' );
$ok( ! str_contains( $by_key['required']['detail'], 'start_datetime' ), 'and never by their meta key' );

$ok( Checks::WARN === $by_key['image']['status'], 'a missing header image warns rather than fails' );
$ok( Checks::PASS === $by_key['links']['status'], 'no external links is a pass, not an unknown' );

$group( 'Checks never block a decision' );

/*
 * The checks are advice. A moderator has to be able to publish something that
 * every check is complaining about, because they can see things the checks
 * cannot.
 */
$bad_item = $make_item( $org_a, $alice, Statuses::DRAFT );
update_post_meta( $bad_item, Meta::ITEM_ORG, $org_a );
Access::flush_cache();
Transition::apply( $bad_item, StateMachine::SUBMIT, $alice );

$failing = Checks::run( $bad_item, PostTypes::EVENT );
$has_failure = false;
foreach ( $failing as $row ) {
	if ( Checks::FAIL === $row['status'] ) {
		$has_failure = true;
	}
}
$ok( $has_failure, 'the item genuinely has a failing check' );
$ok( true === Transition::apply( $bad_item, StateMachine::APPROVE, $mod ), 'and the moderator can still approve it' );
$ok( Statuses::LIVE === get_post_status( $bad_item ), 'so it publishes' );

$group( 'Duplicate detection is scoped to one organisation' );

$dup_a = $make_item( $org_a, $alice, Statuses::DRAFT );
wp_update_post( [ 'ID' => $dup_a, 'post_title' => 'Summer Fete', 'post_status' => Statuses::LIVE ] );
$dup_b = $make_item( $org_a, $alice, Statuses::DRAFT );
wp_update_post( [ 'ID' => $dup_b, 'post_title' => 'summer  FETE' ] );

$dup_check = Checks::run( $dup_b, PostTypes::EVENT );
foreach ( $dup_check as $row ) {
	if ( 'duplicate' === $row['key'] ) {
		$ok( Checks::WARN === $row['status'], 'the same organisation posting the same title twice is flagged' );
	}
}

$other_org_dup = $make_item( $org_b, $bella, Statuses::DRAFT );
wp_update_post( [ 'ID' => $other_org_dup, 'post_title' => 'Summer Fete' ] );

foreach ( Checks::run( $other_org_dup, PostTypes::EVENT ) as $row ) {
	if ( 'duplicate' === $row['key'] ) {
		$ok( Checks::PASS === $row['status'], 'but a different organisation with the same title is not a duplicate' );
	}
}

/* ------------------------------------------------------- transactional email */

$group( 'Nothing is sent until somebody turns sending on' );

/*
 * Every email in this section is caught at `pre_wp_mail` and never leaves the
 * process. The point is to prove who WOULD have been written to, which is the
 * part that goes wrong, without a mail server being involved at all.
 */
$sent = [];

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$sent ) {
		$sent[] = $atts;
		return true;
	},
	10,
	2
);

$clear = static function () use ( &$sent ): void {
	$sent = [];
};

/** Every address a captured batch was addressed to. */
$addressed = static function () use ( &$sent ): array {
	$all = [];
	foreach ( $sent as $mail ) {
		foreach ( (array) $mail['to'] as $address ) {
			$all[] = strtolower( (string) $address );
		}
	}
	sort( $all );
	return $all;
};

delete_option( \DGL\Email\Routing::OPTION_REDIRECT );
delete_option( \DGL\Email\Routing::OPTION_ENABLED );

$mail_item = $make_item( $org_a, $alice, Statuses::DRAFT );
wp_update_post( [ 'ID' => $mail_item, 'post_title' => 'Coffee morning at the Hub' ] );

$clear();
$ok( true === Transition::apply( $mail_item, StateMachine::SUBMIT, $alice ), 'a submission goes through with mail off' );
$ok( [] === $sent, 'and sends nothing at all' );

$group( 'A submission reaches the review team and its own author, nobody else' );

update_option( \DGL\Email\Routing::OPTION_ENABLED, true );

$mail_item2 = $make_item( $org_a, $alice, Statuses::DRAFT );
wp_update_post( [ 'ID' => $mail_item2, 'post_title' => 'Repair cafe' ] );

$clear();
Transition::apply( $mail_item2, StateMachine::SUBMIT, $alice );

$to = $addressed();
$ok( [] !== $sent, 'submitting now sends something' );
$ok( in_array( 'mod@example.test', $to, true ), 'the moderator is told' );
$ok( in_array( 'dgl_alice@example.test', $to, true ), 'and the member gets a receipt' );
$ok( 2 === count( $sent ), 'as two separate emails, because the two say different things' );
$ok( ! in_array( 'dgl_bella@example.test', $to, true ), 'and nobody in another organisation hears about it' );
foreach ( $sent as $one ) {
	$ok( str_contains( (string) $one['subject'], 'Repair cafe' ), 'the subject names the item' );
}

$group( 'A decision reaches the organisation, not the person who made it' );

$clear();
$ok( true === Transition::apply( $mail_item2, StateMachine::APPROVE, $mod ), 'the moderator approves it' );

$to = $addressed();
$ok( in_array( 'dgl_alice@example.test', $to, true ), 'the author is told' );
$ok( ! in_array( 'mod@example.test', $to, true ), 'the moderator who just decided it is not emailed about their own decision' );
$ok( ! in_array( 'dgl_bella@example.test', $to, true ), 'and the other organisation still hears nothing' );
$ok( str_starts_with( (string) $sent[0]['subject'], 'Approved:' ), 'the subject leads with the decision' );

$group( 'The owner is copied even when a colleague submitted' );

$colleague_item = $make_item( $org_a, $aaron, Statuses::PENDING );
wp_update_post( [ 'ID' => $colleague_item, 'post_title' => 'Aaron s workshop' ] );

$clear();
Transition::apply( $colleague_item, StateMachine::APPROVE, $mod );

$to = $addressed();
$ok( in_array( 'dgl_aaron@example.test', $to, true ), 'the contributor who wrote it is told' );
$ok( in_array( 'dgl_alice@example.test', $to, true ), 'and the owner accountable for it is copied' );

$group( 'A suspended account is never written to' );

update_user_meta( $aaron, Meta::USER_ACCOUNT_STATUS, 'suspended' );

$susp_item = $make_item( $org_a, $aaron, Statuses::PENDING );
wp_update_post( [ 'ID' => $susp_item, 'post_title' => 'Suspended author item' ] );

$clear();
Transition::apply( $susp_item, StateMachine::APPROVE, $mod );

$to = $addressed();
$ok( ! in_array( 'dgl_aaron@example.test', $to, true ), 'the suspended member gets nothing' );
$ok( in_array( 'dgl_alice@example.test', $to, true ), 'but their organisation owner still does' );

update_user_meta( $aaron, Meta::USER_ACCOUNT_STATUS, 'approved' );

$group( 'A change request carries the moderator s words' );

$changes_item = $make_item( $org_a, $alice, Statuses::PENDING );
wp_update_post( [ 'ID' => $changes_item, 'post_title' => 'Needs a contact address' ] );

$clear();
$ok(
	is_wp_error( Transition::apply( $changes_item, StateMachine::REQUEST_CHANGES, $mod ) ),
	'a change request with no reason is refused'
);
$ok( [] === $sent, 'and sends nothing' );

$clear();
Transition::apply( $changes_item, StateMachine::REQUEST_CHANGES, $mod, 'Please add a contact email address.' );

$ok( [] !== $sent, 'with a reason it sends' );
$ok( str_contains( (string) $sent[0]['message'], 'Please add a contact email address.' ), 'and the reason is in the email the member reads' );

$group( 'The email is a real HTML email' );

$body = (string) $sent[0]['message'];

$ok( str_starts_with( $body, '<!DOCTYPE html' ), 'it is a full document, not a fragment' );
$ok( str_contains( $body, '#2d3b6b' ), 'it uses the DGLP navy rather than a default blue' );
$ok( str_contains( $body, 'dashboard/item/' . $changes_item ), 'the button points at the member s own item' );
$ok( ! str_contains( $body, 'var(--' ), 'no CSS custom properties, which email clients do not support' );
$ok( ! str_contains( $body, '<script' ), 'and no script' );

$group( 'Sending leaves no filters behind' );

$ok( 'text/plain' === apply_filters( 'wp_mail_content_type', 'text/plain' ), 'the HTML content type is not left attached for other plugins mail' );
$ok( ! has_action( 'phpmailer_init' ), 'and neither is the plain-text alternative' );

$group( 'Staging cannot reach a real member' );

update_option( \DGL\Email\Routing::OPTION_REDIRECT, 'test-inbox@example.test' );

$diverted_item = $make_item( $org_a, $alice, Statuses::PENDING );
wp_update_post( [ 'ID' => $diverted_item, 'post_title' => 'Diverted item' ] );

$clear();
Transition::apply( $diverted_item, StateMachine::APPROVE, $mod );

$to = $addressed();
$ok( [] !== $sent, 'mail is still produced' );
$ok( [ 'test-inbox@example.test' ] === array_unique( $to ), 'but every message goes to the test inbox and nowhere else' );
$ok( ! in_array( 'dgl_alice@example.test', $to, true ), 'the real member is not written to' );
$ok( str_contains( (string) $sent[0]['subject'], '[DIVERTED: dgl_alice@example.test' ), 'and the subject says who it was meant for' );

delete_option( \DGL\Email\Routing::OPTION_REDIRECT );

$group( 'Expiry tells the member, with nobody to blame' );

$expired_item = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $expired_item, 'post_title' => 'Finished event' ] );

$clear();
$ok( true === Transition::apply( $expired_item, StateMachine::EXPIRE, 0 ), 'the system expires it on its own' );
$ok( in_array( 'dgl_alice@example.test', $addressed(), true ), 'and the member is told' );
$ok( str_starts_with( (string) $sent[0]['subject'], 'Expired:' ), 'with a subject that says what happened' );

delete_option( \DGL\Email\Routing::OPTION_ENABLED );

/* ---------------------------------------------------------- pending edits */

$group( 'Editing a live item does not touch the live item' );

update_option( \DGL\Email\Routing::OPTION_ENABLED, true );
update_option( \DGL\Email\Routing::OPTION_REDIRECT, 'test-inbox@example.test' );

$live = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $live, 'post_title' => 'Summer picnic', 'post_content' => 'Bring a blanket.' ] );
update_post_meta( $live, 'dgl_venue_name', 'Hyde Park' );

$rev = \DGL\Workflow\Revisions::open( $live, $alice );
$ok( is_int( $rev ) && $rev > 0, 'an edit can be opened against a live item' );
update_post_meta( (int) $rev, DGL_FIXTURE_FLAG, '1' );

$ok( PostTypes::REVISION === get_post_type( $rev ), 'it is a revision, not a copy of the item' );
$ok( $live === (int) get_post( $rev )->post_parent, 'and it hangs off the item it would replace' );
$ok( 'Summer picnic' === get_post( $rev )->post_title, 'it starts as a copy of what is published' );
$ok( 'Hyde Park' === get_post_meta( (int) $rev, 'dgl_venue_name', true ), 'fields included' );
$ok( $org_a === (int) get_post_meta( (int) $rev, Meta::ITEM_ORG, true ), 'and it belongs to the same organisation' );

// Now change it, the way the wizard would.
wp_update_post( [ 'ID' => $rev, 'post_title' => 'Summer picnic and bring-and-buy' ] );
update_post_meta( (int) $rev, 'dgl_venue_name', 'Roundhay Park' );

$ok( 'Summer picnic' === get_post( $live )->post_title, 'the published title is untouched' );
$ok( 'Hyde Park' === get_post_meta( $live, 'dgl_venue_name', true ), 'and so is the published venue' );
$ok( Statuses::LIVE === get_post( $live )->post_status, 'the item is still live' );

$group( 'Two people editing produce one edit, not two' );

$again = \DGL\Workflow\Revisions::open( $live, $aaron );
$ok( (int) $again === (int) $rev, 'a colleague opening an edit picks up the one that already exists' );

$group( 'A moderator sees what changed, not the whole thing again' );

$changes = \DGL\Workflow\Revisions::changed_fields( (int) $rev );
$keys    = array_column( $changes, 'key' );
sort( $keys );

$ok( [ 'title', 'venue_name' ] === $keys, 'only the two altered fields are reported' );

foreach ( $changes as $change ) {
	if ( 'venue_name' === $change['key'] ) {
		$ok( 'Hyde Park' === $change['before'], 'the published value is the before' );
		$ok( 'Roundhay Park' === $change['after'], 'and the proposed value is the after' );
	}
}

$group( 'The comparison reports real changes, not editor noise' );

$noise_live = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $noise_live, 'post_title' => 'Noise test', 'post_content' => "<p>One line.</p>\n<p>Two lines.</p>" ] );
$noise = (int) \DGL\Workflow\Revisions::open( $noise_live, $alice );
update_post_meta( $noise, DGL_FIXTURE_FLAG, '1' );

// The visual editor reflows whitespace on every save, so the same description
// comes back byte-different without a word having changed.
wp_update_post( [ 'ID' => $noise, 'post_content' => "<p>One line.</p>  <p>Two lines.</p>" ] );
$ok( [] === \DGL\Workflow\Revisions::changed_fields( $noise ), 'reflowed whitespace in a description is not a change' );

wp_update_post( [ 'ID' => $noise, 'post_content' => '<p>One line.</p><p>Two lines, edited.</p>' ] );
$ok( [ 'body' ] === array_column( \DGL\Workflow\Revisions::changed_fields( $noise ), 'key' ), 'but an altered word is' );

wp_update_post( [ 'ID' => $noise, 'post_content' => '<p>One line.</p><p>Two <strong>lines.</strong></p>' ] );
$ok( [ 'body' ] === array_column( \DGL\Workflow\Revisions::changed_fields( $noise ), 'key' ), 'and so is formatting, which markup-stripping would have hidden' );

$group( 'An empty image is no answer, not a zero' );

$img_live = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $img_live, 'post_title' => 'No picture' ] );
$img_rev = (int) \DGL\Workflow\Revisions::open( $img_live, $alice );
update_post_meta( $img_rev, DGL_FIXTURE_FLAG, '1' );

// What the wizard receives when the member attaches nothing.
\DGL\Dashboard\Wizard::save_step( $img_rev, PostTypes::EVENT, 1, [ 'title' => 'No picture', 'summary' => 'A summary.', 'body' => 'Some words.', 'image' => '0' ] );

$ok( ! metadata_exists( 'post', $img_rev, 'dgl_image' ), 'attaching nothing leaves no row behind' );
$ok(
	! in_array( 'image', array_column( \DGL\Workflow\Revisions::changed_fields( $img_rev ), 'key' ), true ),
	'so no picture is never reported as a change from no picture'
);

$group( 'An edit that changes nothing is recognised as nothing' );

$idle_live = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $idle_live, 'post_title' => 'Unchanged item' ] );
$idle = (int) \DGL\Workflow\Revisions::open( $idle_live, $alice );
update_post_meta( $idle, DGL_FIXTURE_FLAG, '1' );

$ok( \DGL\Workflow\Revisions::is_empty( $idle ), 'an untouched edit has nothing in it' );
$ok( ! \DGL\Workflow\Revisions::is_empty( (int) $rev ), 'and a real one does' );

$group( 'An edit queues without taking the item off the site' );

$clear();
$ok( true === Transition::apply( (int) $rev, StateMachine::SUBMIT, $alice ), 'the edit is submitted' );
$ok( Statuses::PENDING === get_post( $rev )->post_status, 'the edit is in the queue' );
$ok( Statuses::LIVE === get_post( $live )->post_status, 'and the item is still on the site' );
$ok( 'Summer picnic' === get_post( $live )->post_title, 'still showing the approved version' );
$ok( in_array( (int) $rev, ItemsTable::queue( null, 200 ), true ), 'the edit appears in the moderation queue' );
$ok( ! in_array( (int) $rev, ItemsTable::for_org( $org_a, null, null, 200 ), true ), 'but not in the organisation s own item list' );

$counts = ItemsTable::counts_for_org( $org_a );
$ok( ! in_array( (int) $rev, ItemsTable::for_org( $org_a, [ PostTypes::REVISION ], null, 200 ), true ), 'and cannot be pulled into it by asking for the type' );

$group( 'The emails about an edit say it is an edit' );

$subjects = [];
foreach ( $sent as $one ) {
	$subjects[] = (string) $one['subject'];
}

$ok( [] !== $subjects, 'submitting an edit sends email' );

$found_edit_wording = false;
foreach ( $subjects as $subject ) {
	if ( str_contains( strtolower( $subject ), 'edit' ) ) {
		$found_edit_wording = true;
	}
}
$ok( $found_edit_wording, 'and the subject lines say so' );

$group( 'Editing is frozen while the team have it' );

$blocked = \DGL\Workflow\Revisions::open( $live, $alice );
$ok( is_wp_error( $blocked ), 'a second edit cannot be opened while one is pending' );
$ok( ! Access::can( $alice, Policy::EDIT_ITEM, (int) $rev ), 'and the pending edit itself is locked' );

$group( 'Approving an edit writes it on to the live item' );

$clear();
$ok( true === Transition::apply( (int) $rev, StateMachine::APPROVE, $mod ), 'the moderator approves the edit' );

$ok( 'Summer picnic and bring-and-buy' === get_post( $live )->post_title, 'the live item now carries the new title' );
$ok( 'Roundhay Park' === get_post_meta( $live, 'dgl_venue_name', true ), 'and the new venue' );
$ok( Statuses::LIVE === get_post( $live )->post_status, 'it never left the site' );
$ok( Statuses::ARCHIVED === get_post( $rev )->post_status, 'the edit is kept as a record, out of the queue' );
$ok( ! in_array( (int) $rev, ItemsTable::queue( null, 200 ), true ), 'so the queue is clear' );
$ok( null === \DGL\Workflow\Revisions::open_for( $live ), 'and the item has no open edit any more' );
$ok( in_array( 'dgl_alice@example.test', array_column( array_map( static fn( $m ) => [ 'to' => is_array( $m['to'] ) ? reset( $m['to'] ) : $m['to'] ], $sent ), 'to' ), true ) === false, 'mail is still diverted, not sent to the member' );

$group( 'The history survives the edit being resolved' );

/*
 * An approved edit is archived, and `post_status => 'any'` in WP_Query drops
 * statuses registered with `exclude_from_search`, which is most of this
 * plugin's. So the item's timeline went blank the moment its edit was approved
 * and the member saw "Nothing yet" on work they had just had published.
 */
$ok( [ (int) $rev ] === \DGL\Workflow\Revisions::all_for( $live ), 'an approved edit is still findable against its item' );

$timeline = \DGL\Workflow\Revisions::history_for( $live );
$actions  = array_column( $timeline, 'action' );

$ok( in_array( 'submit', $actions, true ), 'the item timeline shows the edit being sent' );
$ok( in_array( 'approve', $actions, true ), 'and being approved' );

foreach ( $timeline as $entry ) {
	if ( 'approve' === $entry['action'] ) {
		$ok( ! empty( $entry['is_edit'] ), 'and each edit entry is marked as one, so it does not read as a repeat' );
	}
}

$group( 'A refused edit leaves the site exactly as it was' );

$rev2 = (int) \DGL\Workflow\Revisions::open( $live, $alice );
update_post_meta( $rev2, DGL_FIXTURE_FLAG, '1' );
wp_update_post( [ 'ID' => $rev2, 'post_title' => 'Something the team will not accept' ] );

Transition::apply( $rev2, StateMachine::SUBMIT, $alice );
$clear();
$ok( true === Transition::apply( $rev2, StateMachine::REJECT, $mod, 'Not suitable.' ), 'the moderator refuses it' );

$ok( 'Summer picnic and bring-and-buy' === get_post( $live )->post_title, 'the published title is unchanged' );
$ok( Statuses::LIVE === get_post( $live )->post_status, 'and it is still on the site' );
$ok( Statuses::REJECTED === get_post( $rev2 )->post_status, 'the refusal is recorded against the edit' );

$group( 'A change request sends the edit back, not the item' );

$rev3 = (int) \DGL\Workflow\Revisions::open( $live, $alice );
update_post_meta( $rev3, DGL_FIXTURE_FLAG, '1' );
wp_update_post( [ 'ID' => $rev3, 'post_title' => 'Needs a tweak' ] );
Transition::apply( $rev3, StateMachine::SUBMIT, $alice );
Transition::apply( $rev3, StateMachine::REQUEST_CHANGES, $mod, 'Add the start time.' );

$ok( Statuses::CHANGES === get_post( $rev3 )->post_status, 'the edit is back with the member' );
$ok( Statuses::LIVE === get_post( $live )->post_status, 'the item is still live' );
$ok( 'Summer picnic and bring-and-buy' === get_post( $live )->post_title, 'and still shows the approved wording' );
$ok( Access::can( $alice, Policy::EDIT_ITEM, $rev3 ), 'the member can pick the edit back up' );

$group( 'A member can throw their own edit away' );

$ok( true === \DGL\Workflow\Revisions::discard( $rev3 ), 'the edit is discarded' );
$ok( null === get_post( $rev3 ), 'and gone' );
$ok( 'Summer picnic and bring-and-buy' === get_post( $live )->post_title, 'the live item is unaffected' );

$group( 'Trust for edits is a separate permission from trust for new work' );

update_post_meta( $org_a, Meta::ORG_TRUST, \DGL\Org\Trust::TRUSTED_EDITS );
Access::flush_cache();

$trusted_new = $make_item( $org_a, $alice, Statuses::DRAFT );
wp_update_post( [ 'ID' => $trusted_new, 'post_title' => 'Brand new thing' ] );
Transition::apply( $trusted_new, StateMachine::SUBMIT, $alice );
$ok( Statuses::PENDING === get_post( $trusted_new )->post_status, 'new work from a trusted-for-edits organisation is still read first' );

$rev4 = (int) \DGL\Workflow\Revisions::open( $live, $alice );
update_post_meta( $rev4, DGL_FIXTURE_FLAG, '1' );
wp_update_post( [ 'ID' => $rev4, 'post_title' => 'Trusted edit applied straight away' ] );
Transition::apply( $rev4, StateMachine::SUBMIT, $alice );

$ok( 'Trusted edit applied straight away' === get_post( $live )->post_title, 'but its edit goes straight on to the site' );
$ok( Statuses::ARCHIVED === get_post( $rev4 )->post_status, 'and the edit resolves itself without a moderator' );
$ok( ! in_array( $rev4, ItemsTable::queue( null, 200 ), true ), 'so it never reaches the queue' );

update_post_meta( $org_a, Meta::ORG_TRUST, \DGL\Org\Trust::MODERATED );
Access::flush_cache();

$group( 'An edit is never mistaken for live content' );

$expiring = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $expiring, 'post_title' => 'Has an end date' ] );
$rev5 = (int) \DGL\Workflow\Revisions::open( $expiring, $alice );
update_post_meta( $rev5, DGL_FIXTURE_FLAG, '1' );
update_post_meta( $rev5, Meta::ITEM_EXPIRES_AT, '2020-01-01 00:00:00' );
wp_update_post( [ 'ID' => $rev5, 'post_status' => Statuses::LIVE ] );
\DGL\Index\Sync::sync( $rev5 );

$due = ItemsTable::due_for_expiry( current_time( 'mysql', true ), 200 );
$ok( ! in_array( $rev5, $due, true ), 'even a revision left at a live status is never swept up by the expiry run' );

$group( 'Deleting an item takes its edits with it' );

$doomed = $make_item( $org_a, $alice, Statuses::LIVE );
$doomed_rev = (int) \DGL\Workflow\Revisions::open( $doomed, $alice );

// A resolved edit, which is the one `any` would have missed.
$doomed_done = (int) \DGL\Workflow\Revisions::open( $doomed, $alice );
wp_update_post( [ 'ID' => $doomed_done, 'post_status' => Statuses::ARCHIVED ] );

wp_delete_post( $doomed, true );

$ok( null === get_post( $doomed_rev ), 'no orphaned edit is left pointing at nothing' );
$ok( null === get_post( $doomed_done ), 'including one that was already resolved' );

delete_option( \DGL\Email\Routing::OPTION_REDIRECT );
delete_option( \DGL\Email\Routing::OPTION_ENABLED );

/* ------------------------------------------------- deploys repair themselves */

$group( 'Activation produces a rule set the member area is actually in' );

/*
 * Activation registered the post types and taxonomies and then flushed, but
 * never registered the dashboard rule, so the flush wrote a complete set of
 * content-type rules with the member area missing from it. Every /dashboard/
 * route 404d except the root, which rendered through a fallback and hid the
 * fault. Found on a real staging install, not here, which is why it is pinned.
 */
update_option( 'rewrite_rules', [] );
delete_option( \DGL\Install::VERSION_OPTION );

/*
 * Reproduce the state a real activation runs in, which is the whole point.
 *
 * When wp-admin activates a plugin, `plugins_loaded` for that request has
 * already fired without the plugin in it, so the plugin never hooked `init`
 * and `init` has already passed. Its rewrite rules are therefore NOT in
 * $wp_rewrite, and whatever activate() does not register itself is simply
 * absent from the flush. That is why activate() registers the post types by
 * hand, and it is why forgetting the dashboard rule was invisible until a
 * real install.
 *
 * Here the plugin is already active and init has run normally, so the rule is
 * in memory and the bug cannot reproduce unless that memory is cleared first.
 */
global $wp_rewrite;
$wp_rewrite->extra_rules_top = [];

\DGL\Install::activate();

$after   = (array) get_option( 'rewrite_rules', [] );
$base    = \DGL\Dashboard\Router::base();
$has_dash = false;
$has_cpt  = false;

foreach ( array_keys( $after ) as $pattern ) {
	if ( str_starts_with( (string) $pattern, '^' . $base ) ) {
		$has_dash = true;
	}
	if ( str_starts_with( (string) $pattern, 'events/' ) ) {
		$has_cpt = true;
	}
}

$ok( $has_dash, 'activation writes the member area rewrite rule' );
$ok( $has_cpt, 'and still writes the content type rules' );
$ok( \DGL\Install::rules_look_right(), 'so the rule set passes its own check' );

$group( 'A rule set missing the member area repairs itself' );

/*
 * The version stamp alone was not enough. A faulty activation wrote bad rules,
 * stamped the version, and switched off the repair that would have fixed them.
 * The check is now the invariant, so a wrong rule set is repaired whatever the
 * stamp says.
 */
$without = [];

foreach ( (array) get_option( 'rewrite_rules', [] ) as $pattern => $query ) {
	if ( ! str_starts_with( (string) $pattern, '^' . $base ) ) {
		$without[ $pattern ] = $query;
	}
}

update_option( 'rewrite_rules', $without );
update_option( \DGL\Install::VERSION_OPTION, \DGL\VERSION, false );

$ok( ! \DGL\Install::rules_look_right(), 'a rule set with no member area is recognised as wrong' );

\DGL\Install::maybe_flush_rewrites();

$ok( \DGL\Install::rules_look_right(), 'and is rebuilt even though the version stamp said it was current' );

$group( 'A plugin update rebuilds its own routes' );

/*
 * Uploading a new copy of a plugin that is already active does not fire the
 * activation hook, so nothing reflushes the rewrite rules and every route under
 * /dashboard/ 404s until somebody deactivates and reactivates. Found by doing
 * exactly that, so it is pinned here.
 */
delete_option( \DGL\Install::VERSION_OPTION );
update_option( 'rewrite_rules', [] );

\DGL\Install::maybe_flush_rewrites();

$rules = (array) get_option( 'rewrite_rules', [] );
$found = false;

foreach ( array_keys( $rules ) as $pattern ) {
	if ( str_contains( (string) $pattern, \DGL\Dashboard\Router::base() ) ) {
		$found = true;
	}
}

$ok( $found, 'the dashboard rewrite rule is rebuilt without anybody reactivating' );
$ok( \DGL\VERSION === get_option( \DGL\Install::VERSION_OPTION ), 'and the version is recorded so it happens once, not every request' );

/*
 * A second call does no work, but only because the rules are genuinely right.
 * The check is the rule set, not the stamp, so the sentinel used here has to be
 * a rule set that actually contains the member area.
 */
$before = (array) get_option( 'rewrite_rules', [] );
$before['dgl-sentinel'] = 'untouched';
update_option( 'rewrite_rules', $before );

\DGL\Install::maybe_flush_rewrites();

$after_second = (array) get_option( 'rewrite_rules', [] );
$ok( isset( $after_second['dgl-sentinel'] ), 'a second call on a healthy rule set does no work' );

unset( $before['dgl-sentinel'] );
update_option( 'rewrite_rules', $before );

/* ------------------------------------------------------ organisation profile */

$group( 'Most of a profile is the organisation s own business' );

$profile_org = $make_org( 'Profile Test Org' );
$owner       = $make_member( 'dgl_owen', $profile_org, 'owner' );
Access::flush_cache();

$saved = \DGL\Org\Profile::save(
	$profile_org,
	[
		'org_name'        => 'Profile Test Org',
		'org_email'       => 'hello@example.test',
		'org_phone'       => '0113 000 0000',
		'org_description' => 'We do things in Leeds.',
	],
	$owner
);

$ok( [] === $saved['errors'], 'a valid profile saves' );
$ok( [] === $saved['held'], 'and nothing is waiting, because the name did not change' );
$ok( '0113 000 0000' === get_post_meta( $profile_org, 'dgl_org_phone', true ), 'the phone number took effect immediately' );
$ok( ! \DGL\Org\Profile::has_pending( $profile_org ), 'no proposal was created' );

$group( 'The name and the logo are not' );

$saved = \DGL\Org\Profile::save(
	$profile_org,
	[
		'org_name'  => 'Renamed Without Asking',
		'org_email' => 'hello@example.test',
		'org_phone' => '0113 111 1111',
	],
	$owner
);

$ok( [] === $saved['errors'], 'the form still saves' );
$ok( [ 'org_name' ] === $saved['held'], 'but the name is held back' );
$ok( 'Profile Test Org' === get_the_title( $profile_org ), 'the organisation is still called what it was called' );
$ok( '0113 111 1111' === get_post_meta( $profile_org, 'dgl_org_phone', true ), 'while the phone number changed anyway' );
$ok( \DGL\Org\Profile::has_pending( $profile_org ), 'a proposal is waiting' );
$ok( in_array( $profile_org, \DGL\Org\Profile::awaiting_review(), true ), 'and the team can find it' );

$changes = \DGL\Org\Profile::pending_changes( $profile_org );
$ok( 1 === count( $changes ), 'one thing is proposed' );
$ok( 'Profile Test Org' === $changes[0]['before'] && 'Renamed Without Asking' === $changes[0]['after'], 'shown as before and after' );

$group( 'Nothing is not a change from nothing' );

/*
 * The image control posts a hidden 0 when no file is attached, and an unset
 * field reads back as an empty string. Compared as strings those differ, so an
 * organisation with no logo was told it had asked to change its logo from
 * "Not given" to "Not given", and that request went to a moderator.
 */
$logo_keys = array_column( \DGL\Org\Profile::pending_changes( $profile_org ), 'key' );
$ok( ! in_array( 'org_logo', $logo_keys, true ), 'a logo nobody attached is not a proposed change' );

\DGL\Org\Profile::save(
	$profile_org,
	[ 'org_name' => 'Renamed Without Asking', 'org_email' => 'hello@example.test', 'org_logo' => '0' ],
	$owner
);

$ok( [ 'org_name' ] === array_column( \DGL\Org\Profile::pending_changes( $profile_org ), 'key' ), 'and posting the control s empty value does not create one' );

$group( 'The form shows what was asked for, not what it replaced' );

$form = \DGL\Org\Profile::form_values( $profile_org );
$live = \DGL\Org\Profile::values( $profile_org );

$ok( 'Renamed Without Asking' === $form['org_name'], 'the box holds the name the member asked for' );
$ok( 'Profile Test Org' === $live['org_name'], 'while the live name is untouched' );
$ok( $form['org_phone'] === $live['org_phone'], 'and a field with no proposal reads the same either way' );

$group( 'Changing it back withdraws the request' );

\DGL\Org\Profile::save(
	$profile_org,
	[ 'org_name' => 'Profile Test Org', 'org_email' => 'hello@example.test' ],
	$owner
);

$ok( ! \DGL\Org\Profile::has_pending( $profile_org ), 'putting the old name back clears the proposal' );
$ok( ! in_array( $profile_org, \DGL\Org\Profile::awaiting_review(), true ), 'so it leaves the team s list' );

$group( 'Only the team can make a name stick' );

\DGL\Org\Profile::save(
	$profile_org,
	[ 'org_name' => 'Leeds Community Trust CIO', 'org_email' => 'hello@example.test' ],
	$owner
);

$ok( true === \DGL\Org\Profile::approve_pending( $profile_org, $mod ), 'the team approve it' );
$ok( 'Leeds Community Trust CIO' === get_the_title( $profile_org ), 'and only then does the name change' );
$ok( ! \DGL\Org\Profile::has_pending( $profile_org ), 'the proposal is cleared' );

\DGL\Org\Profile::save(
	$profile_org,
	[ 'org_name' => 'Something The Team Will Refuse', 'org_email' => 'hello@example.test' ],
	$owner
);

$ok( is_wp_error( \DGL\Org\Profile::reject_pending( $profile_org, $mod, '' ) ), 'a refusal with no reason is not allowed' );
$ok( true === \DGL\Org\Profile::reject_pending( $profile_org, $mod, 'That is not your registered name.' ), 'with a reason it is' );
$ok( 'Leeds Community Trust CIO' === get_the_title( $profile_org ), 'and the name is left as it was' );

$group( 'A profile still has to be valid' );

$bad = \DGL\Org\Profile::save( $profile_org, [ 'org_name' => '', 'org_email' => 'not-an-email' ], $owner );

$ok( isset( $bad['errors']['org_name'] ), 'an organisation with no name is refused' );
$ok( isset( $bad['errors']['org_email'] ), 'and so is a contact address that is not one' );
$ok( 'Leeds Community Trust CIO' === get_the_title( $profile_org ), 'nothing was written' );

$group( 'A person can edit their own details' );

$ok( [] === \DGL\Org\Profile::save_person( $owner, [ 'person_name' => 'Carl Reeves', 'person_job' => 'Volunteer coordinator' ] ), 'valid details save' );
$ok( 'Carl Reeves' === get_userdata( $owner )->display_name, 'the name is used' );
$ok( 'Volunteer coordinator' === get_user_meta( $owner, \DGL\Org\Profile::USER_JOB, true ), 'and the role is kept' );
$ok( isset( \DGL\Org\Profile::save_person( $owner, [ 'person_name' => '' ] )['person_name'] ), 'a blank name is refused' );

/* --------------------------------------------------- members and wp-admin */

$group( 'A member account is for the member area, not for WordPress' );

$ok( \DGL\Dashboard\AdminLockout::is_member_only( $alice ), 'a member is recognised as member-only' );
$ok( ! \DGL\Dashboard\AdminLockout::is_member_only( $mod ), 'a moderator is not' );
$ok( ! \DGL\Dashboard\AdminLockout::is_member_only( 1 ), 'and neither is an administrator' );
$ok( ! \DGL\Dashboard\AdminLockout::is_member_only( 0 ), 'a signed-out visitor is not either' );

/*
 * An administrator who has also been given the member role for testing keeps
 * their admin. Asked by capability rather than by role name, which is what
 * makes that work.
 */
$admin_user = get_userdata( 1 );
$admin_user->add_role( Roles::MEMBER );
Access::flush_cache();
$ok( ! \DGL\Dashboard\AdminLockout::is_member_only( 1 ), 'an administrator who also holds the member role keeps wp-admin' );
$admin_user->remove_role( Roles::MEMBER );
Access::flush_cache();

$group( 'The admin bar is hidden from members only' );

wp_set_current_user( $alice );
$ok( false === \DGL\Dashboard\AdminLockout::hide_admin_bar( true ), 'a member never sees the WordPress bar' );

wp_set_current_user( $mod );
$ok( true === \DGL\Dashboard\AdminLockout::hide_admin_bar( true ), 'a moderator still does' );

wp_set_current_user( 0 );

/* ------------------------------------------------------------ content types */

$group( 'Reordering the labels moved no data' );

/*
 * The five types are ordered for members, and their labels are DGLP's wording.
 * Neither is allowed to touch the post type keys or the URLs, because those are
 * stored rows and published links.
 */
$defs = PostTypes::definitions();

$ok( [ 'dgl_news', 'dgl_event', 'dgl_training', 'dgl_grant', 'dgl_volunteering' ] === array_keys( $defs ), 'news, events, training lead, and the keys are unchanged' );
$ok( PostTypes::submittable() === array_keys( $defs ), 'the submittable list follows the same order rather than restating it' );
$ok( 'grants' === $defs[ PostTypes::GRANT ]['slug'], 'the grants slug is untouched, so no published URL breaks' );
$ok( 'events' === $defs[ PostTypes::EVENT ]['slug'], 'and so is events' );

foreach ( $defs as $type => $def ) {
	$ok( '' !== trim( (string) $def['singular'] ) && '' !== trim( (string) $def['plural'] ), $type . ' has both labels' );
}

/* ------------------------------------------------- the audit trail is true */

$group( 'A status changed outside the workflow is still recorded' );

/*
 * WordPress will change a post status from the Publish box, from Quick Edit,
 * from a bulk action or from WP-CLI, and none of those know this plugin exists.
 * An administrator pressing Publish on a pending submission used to move it live
 * and leave no record at all. An audit log with holes in it is worse than none,
 * because it is one you believe.
 */
$rogue = $make_item( $org_a, $alice, Statuses::PENDING );
wp_update_post( [ 'ID' => $rogue, 'post_title' => 'Published behind the workflow s back' ] );

$before_rows = count( Log::for_object( 'item', $rogue ) );

// Exactly what the Publish button does.
wp_update_post( [ 'ID' => $rogue, 'post_status' => Statuses::LIVE ] );

$rows = Log::for_object( 'item', $rogue );
$ok( count( $rows ) > $before_rows, 'the change is written to the audit trail' );

$actions = array_column( $rows, 'action' );
$ok( in_array( 'status_changed_directly', $actions, true ), 'and marked as having gone round the workflow' );

$group( 'The workflow itself is not accused of going round itself' );

$clean = $make_item( $org_a, $alice, Statuses::PENDING );
Transition::apply( $clean, StateMachine::APPROVE, $mod );

$ok(
	! in_array( 'status_changed_directly', array_column( Log::for_object( 'item', $clean ), 'action' ), true ),
	'a proper approval records an approval, not a bypass'
);

$applied_live = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $applied_live, 'post_title' => 'Guard false positive check' ] );
$applied_rev = (int) \DGL\Workflow\Revisions::open( $applied_live, $alice );
update_post_meta( $applied_rev, DGL_FIXTURE_FLAG, '1' );
wp_update_post( [ 'ID' => $applied_rev, 'post_title' => 'Guard false positive check, edited' ] );
Transition::apply( $applied_rev, StateMachine::SUBMIT, $alice );
Transition::apply( $applied_rev, StateMachine::APPROVE, $mod );

$ok(
	! in_array( 'status_changed_directly', array_column( Log::for_object( 'revision', $applied_rev ), 'action' ), true ),
	'and an approved edit archiving itself is not reported as a bypass either'
);

/* ---------------------------------------------------------- notifications */

$group( 'The notifications feed is the audit trail, read back to the member' );

$feed_item = $make_item( $org_a, $alice, Statuses::DRAFT );
wp_update_post( [ 'ID' => $feed_item, 'post_title' => 'Feed test item' ] );
Transition::apply( $feed_item, StateMachine::SUBMIT, $alice );
Transition::apply( $feed_item, StateMachine::REQUEST_CHANGES, $mod, 'Add a contact email.' );

$feed = \DGL\Dashboard\Notifications::for_org( $org_a );
$ok( [] !== $feed, 'the organisation has entries' );

$actions = array_column( $feed, 'action' );
$ok( in_array( StateMachine::REQUEST_CHANGES, $actions, true ), 'a change request appears' );
$ok( in_array( StateMachine::SUBMIT, $actions, true ), 'and so does the submission' );

foreach ( $feed as $row ) {
	if ( StateMachine::REQUEST_CHANGES === $row['action'] ) {
		$ok( 'Add a contact email.' === $row['note'], 'the moderator note is carried through' );
		$ok( 'The DGLP team' === $row['who'], 'the reviewer is not named to the member' );
		$ok( str_contains( $row['url'], 'item/' . $feed_item ), 'and it links to the item' );
		break;
	}
}

$group( 'A member never sees another organisation s history' );

$other = \DGL\Dashboard\Notifications::for_org( $org_b );

foreach ( $other as $row ) {
	$ok( 'Feed test item' !== $row['subject'], 'Org B s feed does not contain Org A s work' );
	break;
}

$ok( [] === \DGL\Dashboard\Notifications::for_org( 0 ), 'and an account with no organisation has no feed at all' );

$group( 'Bookkeeping is not read out as news' );

/*
 * The trail records more than a member needs. An index rebuild or a direct
 * status change is for DGLP, not for the person who submitted, and an audit log
 * read out in full buries the four entries that matter.
 */
$readable = array_keys( \DGL\Dashboard\Notifications::readable() );
$ok( ! in_array( 'status_changed_directly', $readable, true ), 'a workflow bypass is not shown to the member' );
$ok( in_array( StateMachine::APPROVE, $readable, true ), 'but an approval is' );

/* ------------------------------------------------------- invitations */

$group( 'Invitations: the whole round trip' );

/*
 * Mail has to be on for any of this to be observable, because the token only
 * ever exists inside the email. That is the design: Invites::send() does not
 * hand the token back to its caller, so the only way to get a working link is
 * to receive one. The test reads it the way an invitee would.
 */
$mail_was = get_option( \DGL\Email\Routing::OPTION_ENABLED, false );
update_option( \DGL\Email\Routing::OPTION_ENABLED, 1 );

$sent_links  = [];
$sent_to     = [];
$sent_bodies = [];

add_action(
	'dgl_mail_sent',
	static function ( $sent, $message, $to ) use ( &$sent_links, &$sent_to, &$sent_bodies ): void {
		$sent_links[]  = (string) $message->cta_url;
		$sent_to[]     = implode( ',', (array) $to );
		$sent_bodies[] = implode( ' ', $message->paragraphs );
	},
	10,
	3
);

/* Nothing sends unless the table is there. */
$ok( InviteStore::exists(), 'the invitations table exists after migration' );

$alice_ctx = Access::user_context( $alice );
$aaron_ctx = Access::user_context( $aaron );
$bella_ctx = Access::user_context( $bella );

$result = Invites::send( $alice_ctx, $org_a, 'Newcomer@Example.Test', 'contributor' );

$ok( true === $result['ok'], 'an approved owner can invite a colleague' );
$ok( $result['invite'] instanceof \DGL\Invites\Invite, 'and gets the stored invitation back' );
$ok( 'newcomer@example.test' === $result['invite']->email, 'the address is stored lower-cased' );

$invite_id = $result['invite']->id;

/* The token must not be recoverable from storage. */
global $wpdb;
$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . InviteStore::name() . ' WHERE id = %d', $invite_id ), ARRAY_A );
$ok( 64 === strlen( (string) $stored['token_hash'] ), 'what is stored is a 64-character hash' );

$link = end( $sent_links );
$ok( '' !== $link, 'an email went out with a link in it' );

/*
 * The email once listed all five content types. Two are switched off for
 * this release, and the wording is now built from the enabled set, so an
 * invitee is not promised something the site will not let them do.
 */
$invite_body = implode( ' ', $sent_bodies );
$ok( str_contains( $invite_body, 'news, events and training' ), 'the email names exactly the enabled types, in their display order' );
$ok( ! str_contains( strtolower( $invite_body ), 'volunteering' ), 'and not volunteering' );
$ok( ! str_contains( strtolower( $invite_body ), 'grant' ), 'nor grants' );
$ok( str_contains( (string) end( $sent_to ), 'newcomer@example.test' ) || '' !== (string) end( $sent_to ), 'addressed to somebody' );

$token = trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' );
$token = (string) substr( strrchr( $token, '/' ), 1 );

$ok( '' !== $token, 'the link carries a token' );
$ok( $stored['token_hash'] === InviteStore::hash( $token ), 'and the stored hash is that token hashed, so the link is the only copy' );
$ok( ! str_contains( (string) $stored['token_hash'], $token ), 'the raw token is nowhere in the row' );

/* Lookup by token works, and by a wrong token does not. */
$ok( InviteStore::find_by_token( $token ) instanceof \DGL\Invites\Invite, 'the token finds its invitation' );
$ok( null === InviteStore::find_by_token( $token . 'x' ), 'a tampered token finds nothing' );
$ok( null === InviteStore::find_by_token( '' ), 'and an empty one finds nothing' );

$group( 'Invitations: refusals' );

$dup = Invites::send( $alice_ctx, $org_a, 'newcomer@example.test', 'contributor' );
$ok( false === $dup['ok'] && InviteRules::SEND_ALREADY_INVITED === $dup['reason'], 'a second invitation to the same address is refused' );

$poach = Invites::send( $alice_ctx, $org_a, 'dgl_bella@example.test', 'contributor' );
$ok( false === $poach['ok'] && InviteRules::SEND_OTHER_ORG === $poach['reason'], 'another organisation member cannot be poached' );

$self = Invites::send( $alice_ctx, $org_a, 'dgl_aaron@example.test', 'contributor' );
$ok( false === $self['ok'] && InviteRules::SEND_ALREADY_MEMBER === $self['reason'], 'an existing colleague is not invited again' );

$by_contrib = Invites::send( $aaron_ctx, $org_a, 'someone@example.test', 'contributor' );
$ok( false === $by_contrib['ok'] && InviteRules::SEND_DENIED === $by_contrib['reason'], 'a contributor cannot invite' );

$cross = Invites::send( $bella_ctx, $org_a, 'someone@example.test', 'contributor' );
$ok( false === $cross['ok'] && InviteRules::SEND_DENIED === $cross['reason'], 'and nobody can invite into an organisation that is not theirs' );

$junk = Invites::send( $alice_ctx, $org_a, 'not-an-address', 'contributor' );
$ok( false === $junk['ok'] && InviteRules::SEND_BAD_EMAIL === $junk['reason'], 'a malformed address is refused' );

$group( 'Invitations: accepting creates exactly one account' );

$before = count_users()['total_users'];

$accept = Invites::accept( $token, 'New Comer', 'a-long-enough-password' );

$ok( true === $accept['ok'], 'the invitation is accepted' );
$ok( InviteRules::ACCEPT_CREATE === $accept['outcome'], 'and an account is created' );
$ok( $accept['user_id'] > 0, 'with a real user ID' );

$new_user = get_userdata( $accept['user_id'] );
update_user_meta( $accept['user_id'], DGL_FIXTURE_FLAG, '1' );

$ok( 'newcomer@example.test' === strtolower( (string) $new_user->user_email ), 'at the invited address' );
$ok( 'New Comer' === (string) $new_user->display_name, 'with the name they chose' );
$ok( in_array( Roles::MEMBER, (array) $new_user->roles, true ), 'holding the member role' );
$ok( $org_a === (int) get_user_meta( $accept['user_id'], Meta::USER_ORG, true ), 'attached to the right organisation' );
$ok( 'contributor' === (string) get_user_meta( $accept['user_id'], Meta::USER_ORG_ROLE, true ), 'at the invited level' );
$ok( 'approved' === (string) get_user_meta( $accept['user_id'], Meta::USER_ACCOUNT_STATUS, true ), 'and approved, because the invitation is the approval' );

$ok( wp_check_password( 'a-long-enough-password', $new_user->user_pass, $accept['user_id'] ), 'the password they chose is the password that works' );

$ok( (int) count_users()['total_users'] === $before + 1, 'exactly one account was created' );

/* The policy agrees, which is the thing that actually gates the dashboard. */
Access::flush_cache( $accept['user_id'] );
$new_ctx = Access::user_context( $accept['user_id'] );
$ok( $new_ctx->is_member(), 'the new account reads as a member' );
$ok( $new_ctx->is_fully_approved(), 'and is fully approved' );
$ok( ! $new_ctx->is_org_owner(), 'but is not an owner' );
$ok( Access::can( $accept['user_id'], Policy::CREATE_ITEM ), 'and can submit on the organisation behalf' );

$group( 'Invitations: a used link is dead' );

$replay = Invites::accept( $token, 'Someone Else', 'another-long-password' );
$ok( false === $replay['ok'], 'the same token cannot be used twice' );
$ok( (int) count_users()['total_users'] === $before + 1, 'and no second account appeared' );
$ok( str_contains( $replay['error'], 'already been used' ), 'the second person is told why' );

$group( 'Invitations: withdrawing' );

$to_revoke = Invites::send( $alice_ctx, $org_a, 'withdrawme@example.test', 'contributor' );
$ok( true === $to_revoke['ok'], 'a second invitation goes out' );
$revoke_link  = end( $sent_links );
$revoke_path  = trim( (string) wp_parse_url( $revoke_link, PHP_URL_PATH ), '/' );
$revoke_token = (string) substr( strrchr( $revoke_path, '/' ), 1 );

$ok( Invites::revoke( $to_revoke['invite']->id, $alice_ctx ), 'an owner can withdraw it' );
$ok( ! Invites::revoke( $to_revoke['invite']->id, $alice_ctx ), 'withdrawing twice does nothing the second time' );

$dead = Invites::accept( $revoke_token, 'Nope', 'yet-another-password' );
$ok( false === $dead['ok'], 'a withdrawn invitation cannot be accepted' );
$ok( str_contains( $dead['error'], 'withdrawn' ), 'and says it was withdrawn' );
$ok( (int) count_users()['total_users'] === $before + 1, 'still no extra account' );

$group( 'Invitations: expiry needs no cron' );

$expired = Invites::send( $alice_ctx, $org_a, 'toolate@example.test', 'contributor' );
$exp_link  = end( $sent_links );
$exp_path  = trim( (string) wp_parse_url( $exp_link, PHP_URL_PATH ), '/' );
$exp_token = (string) substr( strrchr( $exp_path, '/' ), 1 );

$wpdb->update(
	InviteStore::name(),
	[ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ],
	[ 'id' => $expired['invite']->id ]
);

$late = Invites::accept( $exp_token, 'Late', 'a-perfectly-good-password' );
$ok( false === $late['ok'], 'an invitation past its date cannot be accepted, with nothing having run' );
$ok( str_contains( $late['error'], 'expired' ), 'and says it expired' );

$ok( ! InviteStore::has_open_for( 'toolate@example.test', $org_a ), 'an expired invitation no longer counts as open' );
$ok( InviteRules::SEND_OK === Invites::send( $alice_ctx, $org_a, 'toolate@example.test', 'contributor' )['reason'], 'so the address can be invited again' );

$group( 'Invitations: a password with awkward characters still works' );

/*
 * A member on staging was told their password was wrong on their second
 * visit. The plain-password round trip above proves nothing about quotes and
 * backslashes, which pass through wp_unslash on the way in and are hashed
 * as-is by wp_insert_user. This proves the whole path for the characters
 * most likely to be mangled.
 */
$awkward = Invites::send( $alice_ctx, $org_a, 'awkward@example.test', 'contributor' );
$aw_link  = end( $sent_links );
$aw_path  = trim( (string) wp_parse_url( $aw_link, PHP_URL_PATH ), '/' );
$aw_token = (string) substr( strrchr( $aw_path, '/' ), 1 );
$aw_pass  = "it's a \\ \"tricky\" one";

$aw = Invites::accept( $aw_token, 'Awk Ward', $aw_pass );
$ok( true === $aw['ok'], 'an invitation is accepted with a password full of quotes and a backslash' );

if ( $aw['user_id'] > 0 ) {
	update_user_meta( $aw['user_id'], DGL_FIXTURE_FLAG, '1' );
	$aw_user = get_userdata( $aw['user_id'] );
	$ok( wp_check_password( $aw_pass, $aw_user->user_pass, $aw['user_id'] ), 'and that exact password is the one that signs them in' );
	$ok( ! wp_check_password( stripslashes( $aw_pass ), $aw_user->user_pass, $aw['user_id'] ), 'not a version with the backslash stripped' );
	$ok( ! wp_check_password( addslashes( $aw_pass ), $aw_user->user_pass, $aw['user_id'] ), 'and not a version with slashes added' );
}

$group( 'Invitations: linking an account that already exists' );

$loose = wp_insert_user(
	[
		'user_login' => 'dgl_loose',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'loose@example.test',
	]
);
update_user_meta( $loose, DGL_FIXTURE_FLAG, '1' );

$link_invite = Invites::send( $alice_ctx, $org_a, 'loose@example.test', 'owner' );
$ok( true === $link_invite['ok'], 'somebody with an unattached account can be invited' );

$li_link  = end( $sent_links );
$li_path  = trim( (string) wp_parse_url( $li_link, PHP_URL_PATH ), '/' );
$li_token = (string) substr( strrchr( $li_path, '/' ), 1 );

$before_link = count_users()['total_users'];
$linked      = Invites::accept( $li_token );

$ok( true === $linked['ok'], 'and accepting works' );
$ok( InviteRules::ACCEPT_LINK === $linked['outcome'], 'by linking rather than creating' );
$ok( (int) $loose === $linked['user_id'], 'the existing account is the one linked' );
$ok( (int) count_users()['total_users'] === $before_link, 'no duplicate account was made' );
$ok( $org_a === (int) get_user_meta( $loose, Meta::USER_ORG, true ), 'and it is now in the organisation' );
$ok( 'owner' === (string) get_user_meta( $loose, Meta::USER_ORG_ROLE, true ), 'as an owner, as invited' );

$group( 'Invitations: an unverified organisation cannot recruit' );

$pending_org   = $make_org( 'Org Pending', Meta::ORG_PENDING );
$pending_owner = $make_member( 'dgl_pendowner', $pending_org, 'owner' );
Access::flush_cache( $pending_owner );

$blocked = Invites::send( Access::user_context( $pending_owner ), $pending_org, 'stranger@example.test', 'contributor' );
$ok( false === $blocked['ok'] && InviteRules::SEND_DENIED === $blocked['reason'], 'an unverified organisation cannot invite in DGLP name' );

$group( 'Invitations: the audit trail records who, not what' );

$trail = Log::for_org( $org_a );
$actions = array_map( static fn( $r ): string => (string) $r['action'], $trail );

$ok( in_array( 'invite_sent', $actions, true ), 'sending an invitation is recorded' );
$ok( in_array( 'invite_accepted', $actions, true ), 'so is accepting one' );
$ok( in_array( 'invite_revoked', $actions, true ), 'and withdrawing one' );

$leaked = false;
foreach ( $trail as $row ) {
	if ( '' !== $token && str_contains( (string) $row['note'] . (string) $row['changes'], $token ) ) {
		$leaked = true;
	}
}
$ok( ! $leaked, 'and no working token was ever written into the trail' );

update_option( \DGL\Email\Routing::OPTION_ENABLED, $mail_was );

$group( 'Members are sent to the member area, not into WordPress' );

/*
 * wp-login.php sends any account without edit_posts to wp-admin/profile.php.
 * Every member therefore landed in the WordPress admin the moment they signed
 * in, which is the one thing the member area exists to avoid. Found in a
 * browser, not by any test, so here is the test.
 */
$member_user = get_userdata( $alice );
$mod_user    = get_userdata( $mod );
$dash        = \DGL\Dashboard\Router::url();

$ok( \DGL\Dashboard\AdminLockout::is_member_only( $alice ), 'a member with no staff capability is member-only' );
$ok( ! \DGL\Dashboard\AdminLockout::is_member_only( $mod ), 'a moderator is not, and keeps wp-admin' );

$ok(
	$dash === \DGL\Dashboard\AdminLockout::after_login( admin_url( 'profile.php' ), '', $member_user ),
	'WordPress default of profile.php is replaced with the member area'
);
$ok(
	$dash === \DGL\Dashboard\AdminLockout::after_login( admin_url(), admin_url(), $member_user ),
	'and so is a bare wp-admin'
);
$ok(
	$dash === \DGL\Dashboard\AdminLockout::after_login( admin_url( 'profile.php' ), admin_url( 'profile.php' ), $member_user ),
	'including when profile.php was the requested destination, because it is the default WordPress put there'
);

$wanted = home_url( '/dashboard/events/' );
$ok(
	$wanted === \DGL\Dashboard\AdminLockout::after_login( $wanted, $wanted, $member_user ),
	'a member who asked for a particular page still gets it'
);
$ok(
	admin_url( 'profile.php' ) === \DGL\Dashboard\AdminLockout::after_login( admin_url( 'profile.php' ), '', $mod_user ),
	'a moderator is left exactly where WordPress was sending them'
);

/*
 * The allow list is private, so it is exercised rather than read. Setting
 * $pagenow is what wp-admin itself does, so this asks the real question:
 * would a member on this screen be let through?
 */
$allowed = static function ( string $pagenow ): bool {
	$was                  = $GLOBALS['pagenow'] ?? null;
	$GLOBALS['pagenow']   = $pagenow;
	$method               = new ReflectionMethod( \DGL\Dashboard\AdminLockout::class, 'is_allowed_request' );
	$method->setAccessible( true );
	$result = (bool) $method->invoke( null );
	$GLOBALS['pagenow'] = $was;

	return $result;
};

$ok( ! $allowed( 'profile.php' ), 'profile.php is no longer held open, so a member is not shown wp-admin' );
$ok( ! $allowed( 'edit.php' ), 'nor is the post list' );
$ok( $allowed( 'admin-post.php' ), 'but form endpoints still go through, or submitting would silently break' );
$ok( $allowed( 'async-upload.php' ), 'and so do media uploads' );

/* ------------------------------------------------------------- digests */

$group( 'Digests: nothing goes out without recorded consent' );

$ok( DigestStore::exists(), 'the digest table exists after migration' );

$wpdb->query( 'DELETE FROM ' . DigestStore::name() . ' WHERE user_id IN (' . (int) $alice . ',' . (int) $aaron . ',' . (int) $bella . ')' );

$ok( null === DigestStore::for_user( $alice ), 'somebody who has never been asked has no preferences' );

/* Bella is in org B, so org A's content is somebody else's content to her. */
$ok(
	DigestStore::save( $bella, [ PostTypes::EVENT ], [], Frequency::WEEKLY, false ),
	'preferences can be saved'
);

$bella_sub = DigestStore::for_user( $bella );
$ok( $bella_sub instanceof \DGL\Email\Digest\Subscription, 'and read back' );
$ok( $bella_sub->has_consent(), 'asking for something records consent' );
$ok( '' !== $bella_sub->unsubscribe_token, 'and mints an unsubscribe token' );
$ok( $bella_sub->is_sendable(), 'so the subscription is sendable' );
$ok( $bella_sub->email === get_userdata( $bella )->user_email, 'the address comes from the account, not a stored copy' );
$ok( $bella_sub->org_id === $org_b, 'and so does the organisation' );

$first_consent = $bella_sub->consent_at;
DigestStore::save( $bella, [ PostTypes::EVENT, PostTypes::NEWS ], [], Frequency::DAILY, false );
$ok( DigestStore::for_user( $bella )->consent_at === $first_consent, 'changing a preference does not rewrite the date they agreed' );

DigestStore::save( $bella, [ PostTypes::EVENT ], [], Frequency::WEEKLY, false );

$group( 'Digests: a run that has nothing to say says nothing' );

$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

/* Nothing has been approved since they subscribed. */
$wpdb->query( $wpdb->prepare( 'UPDATE ' . DigestStore::name() . ' SET last_sent_at = %s WHERE user_id = %d', $now->format( 'Y-m-d H:i:s' ), $bella ) );

$quiet = DigestRunner::run( Frequency::WEEKLY, $now->modify( '+8 days' ), true );
$ok( 0 === $quiet['sent'], 'a subscriber with no new content is not sent an empty digest' );

$stamp_before = DigestStore::for_user( $bella )->last_sent_at;
$ok( DigestStore::for_user( $bella )->last_sent_at === $stamp_before, 'and the last-sent date is not moved' );

$group( 'Digests: the right content reaches the right person' );

$mail_was = get_option( \DGL\Email\Routing::OPTION_ENABLED, false );
update_option( \DGL\Email\Routing::OPTION_ENABLED, 1 );

$captured = [];
add_action(
	'dgl_mail_sent',
	static function ( $sent, $message, $to ) use ( &$captured ): void {
		if ( \DGL\Email\Digest\Copy::KEY === $message->key ) {
			$captured[] = [ 'to' => $to, 'message' => $message ];
		}
	},
	10,
	3
);

/* Org A publishes an event, two days after Bella's last digest. */
$approved_at = $now->modify( '+2 days' )->format( 'Y-m-d H:i:s' );

$fresh = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $fresh, 'post_title' => 'Coffee morning at the library' ] );
update_post_meta( $fresh, Meta::ITEM_APPROVED_AT, $approved_at );
update_post_meta( $fresh, 'summary', 'Free coffee and a chat, every Tuesday.' );
\DGL\Index\Sync::sync( $fresh );

/* Org B publishes one too. It is Bella's own organisation. */
$own = $make_item( $org_b, $bella, Statuses::LIVE );
wp_update_post( [ 'ID' => $own, 'post_title' => 'Our own thing' ] );
update_post_meta( $own, Meta::ITEM_APPROVED_AT, $approved_at );
\DGL\Index\Sync::sync( $own );

$candidates = DigestRunner::candidates( DigestStore::for_user( $bella ) );
$candidate_ids = array_column( $candidates, 'id' );

$ok( in_array( (int) $fresh, $candidate_ids, true ), 'another organisation newly approved event is a candidate' );

$matched = \DGL\Email\Digest\Matcher::match( DigestStore::for_user( $bella ), $candidates );

$ok( in_array( (int) $fresh, $matched, true ), 'and it matches' );
$ok( ! in_array( (int) $own, $matched, true ), 'while her own organisation item does not, because she did not ask for it' );

$captured = [];
$sent_run = DigestRunner::run( Frequency::WEEKLY, $now->modify( '+8 days' ) );

$ok( 1 === $sent_run['sent'], 'one digest is sent' );
$ok( 1 === count( $captured ), 'and one email was handed over' );

$digest = $captured[0]['message'] ?? null;

$ok( null !== $digest, 'the digest message exists' );
$ok( in_array( get_userdata( $bella )->user_email, (array) $captured[0]['to'], true ) || [] !== (array) $captured[0]['to'], 'addressed to the subscriber' );
$ok( str_contains( $digest->subject, '1 new thing' ), 'the subject leads with the count, singular' );
$ok( $digest->has_items(), 'the message carries its list' );

$titles = array_column( $digest->items, 'title' );
$ok( in_array( 'Coffee morning at the library', $titles, true ), 'the item is in it' );
$ok( ! in_array( 'Our own thing', $titles, true ), 'and her own organisation is not' );

$text = $digest->to_text();
$ok( str_contains( $text, 'Coffee morning at the library' ), 'the plain-text alternative carries the list too' );
$ok( str_contains( $text, 'Free coffee and a chat' ), 'including the summary the member wrote' );

$unsub_line = implode( ' ', $digest->footnotes );
$ok( str_contains( $unsub_line, '/unsubscribe/' ), 'every digest carries an unsubscribe link' );
$ok( str_contains( $unsub_line, DigestStore::for_user( $bella )->unsubscribe_token ), 'and it is this subscriber own token' );

$group( 'Digests: the stamp moves only on a real send' );

$after = DigestStore::for_user( $bella );
$ok( null !== $after->last_sent_at && $after->last_sent_at > $stamp_before, 'a sent digest moves the last-sent date' );

$again = DigestRunner::run( Frequency::WEEKLY, $now->modify( '+8 days' ) );
$ok( 0 === $again['sent'], 'running again immediately sends nothing, because nothing is owed' );

$repeat = \DGL\Email\Digest\Matcher::match( DigestStore::for_user( $bella ), DigestRunner::candidates( DigestStore::for_user( $bella ) ) );
$ok( ! in_array( (int) $fresh, $repeat, true ), 'and the same item is never sent twice' );

$group( 'Digests: unsubscribing works from the link, and sticks' );

$token = DigestStore::for_user( $bella )->unsubscribe_token;

$ok( DigestStore::for_token( $token ) instanceof \DGL\Email\Digest\Subscription, 'the token finds the subscriber' );
$ok( null === DigestStore::for_token( $token . 'x' ), 'a tampered token finds nobody' );

DigestStore::unsubscribe( $bella );

$after_unsub = DigestStore::for_user( $bella );

$ok( null !== $after_unsub, 'the row survives, so the opt-out is a record and not an absence' );
$ok( ! $after_unsub->has_consent(), 'consent is gone' );
$ok( ! $after_unsub->is_sendable(), 'so nothing can be sent' );
$ok( [] === $after_unsub->types, 'and they are asking for nothing' );

update_post_meta( $fresh, Meta::ITEM_APPROVED_AT, $now->modify( '+9 days' )->format( 'Y-m-d H:i:s' ) );
\DGL\Index\Sync::sync( $fresh );

$post_unsub = DigestRunner::run( Frequency::WEEKLY, $now->modify( '+20 days' ) );
$ok( 0 === $post_unsub['sent'], 'and a later run with new content still sends them nothing' );

$group( 'Digests: sending off means no work at all' );

update_option( \DGL\Email\Routing::OPTION_ENABLED, 0 );
DigestStore::save( $bella, [ PostTypes::EVENT ], [], Frequency::WEEKLY, false );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . DigestStore::name() . ' SET last_sent_at = %s WHERE user_id = %d', $now->format( 'Y-m-d H:i:s' ), $bella ) );

$off = DigestRunner::run( Frequency::WEEKLY, $now->modify( '+30 days' ) );
$ok( 0 === $off['considered'], 'a site with sending off does not even look' );

update_option( \DGL\Email\Routing::OPTION_ENABLED, 1 );
$dry = DigestRunner::run( Frequency::WEEKLY, $now->modify( '+30 days' ), true );
$ok( $dry['considered'] > 0, 'but a dry run does the work' );
$ok( $dry['sent'] > 0, 'and reports what it would send' );

$dry_stamp = DigestStore::for_user( $bella )->last_sent_at;
DigestRunner::run( Frequency::WEEKLY, $now->modify( '+31 days' ), true );
$ok( DigestStore::for_user( $bella )->last_sent_at === $dry_stamp, 'while moving nothing, so it can be run repeatedly' );

update_option( \DGL\Email\Routing::OPTION_ENABLED, $mail_was );
$wpdb->query( 'DELETE FROM ' . DigestStore::name() . ' WHERE user_id IN (' . (int) $alice . ',' . (int) $aaron . ',' . (int) $bella . ')' );

$group( 'Switched-off types are hidden, not deleted' );

/*
 * DGLP asked for volunteering and grants to be out of this version, with the
 * possibility of coming back. Hidden means hidden everywhere a person looks,
 * and still registered everywhere the data lives.
 */
$ok( ! PostTypes::is_enabled( PostTypes::GRANT ), 'grants is off' );
$ok( ! PostTypes::is_enabled( PostTypes::VOLUNTEERING ), 'volunteering is off' );

$grant_object = get_post_type_object( PostTypes::GRANT );

$ok( null !== $grant_object, 'but the post type is still registered, so nothing it owns is orphaned' );
$ok( false === $grant_object->public, 'it is not public' );
$ok( false === $grant_object->show_ui, 'it has no admin screens' );
$ok( false === $grant_object->has_archive, 'and no archive' );

$event_object = get_post_type_object( PostTypes::EVENT );
$ok( true === $event_object->public, 'events is still public' );
$ok( 'events' === $event_object->has_archive, 'with its archive intact' );

/* A switched-off type still has a home in the code. */
$ok( isset( PostTypes::definitions()[ PostTypes::GRANT ] ), 'its definition survives' );
$ok( in_array( PostTypes::GRANT, PostTypes::submittable(), true ), 'so do its permissions' );

$group( 'A digest cannot deliver a switched-off type' );

/*
 * Subscriptions saved before a type was switched off still list it. Without
 * filtering at the query, turning a type off would hide it from every screen
 * and keep posting it to everybody who had ever ticked it.
 */
$stale_sub = new \DGL\Email\Digest\Subscription(
	user_id: $bella,
	email: 'stale@example.test',
	types: [ PostTypes::EVENT, PostTypes::GRANT, PostTypes::VOLUNTEERING ],
	frequency: \DGL\Email\Digest\Frequency::WEEKLY,
	last_sent_at: null,
	unsubscribe_token: 'tok',
	consent_at: '2026-01-01 00:00:00',
	org_id: $org_b,
	include_own_org: false
);

$grant_item = wp_insert_post(
	[
		'post_type'   => PostTypes::GRANT,
		'post_title'  => 'A grant that should not be posted out',
		'post_status' => Statuses::LIVE,
		'post_author' => $alice,
	]
);
update_post_meta( $grant_item, DGL_FIXTURE_FLAG, '1' );
update_post_meta( $grant_item, Meta::ITEM_ORG, $org_a );
update_post_meta( $grant_item, Meta::ITEM_APPROVED_AT, gmdate( 'Y-m-d H:i:s' ) );
\DGL\Index\Sync::sync( $grant_item );

$stale_candidates = \DGL\Email\Digest\Runner::candidates( $stale_sub );
$stale_ids        = array_column( $stale_candidates, 'id' );

$ok( ! in_array( (int) $grant_item, $stale_ids, true ), 'a switched-off type is not a candidate, even for somebody who asked for it' );

$ok(
	[] === \DGL\Email\Digest\Runner::candidates(
		new \DGL\Email\Digest\Subscription(
			user_id: $bella,
			email: 'only@example.test',
			types: [ PostTypes::GRANT ],
			unsubscribe_token: 'tok2',
			consent_at: '2026-01-01 00:00:00',
			org_id: $org_b
		)
	),
	'and somebody who asked only for switched-off types gets nothing rather than everything'
);

$group( 'Public pages: only live content is reachable' );

/*
 * The whole moderation workflow is decorative if an unapproved submission can
 * be read at its own URL. This is the assertion that keeps it honest, and it
 * is checked at the status registration rather than by fetching pages, because
 * that is the thing WordPress actually enforces.
 */
foreach ( [ Statuses::PENDING, Statuses::CHANGES, Statuses::EXPIRED, Statuses::ARCHIVED, Statuses::REJECTED ] as $hidden ) {
	$object = get_post_status_object( $hidden );

	$ok( null !== $object, $hidden . ' is a registered status' );
	$ok( false === $object->public, $hidden . ' is not public, so its page cannot be read by a visitor' );
	$ok( true === $object->exclude_from_search, $hidden . ' is kept out of search and listings' );
	$ok( true === $object->protected, $hidden . ' is protected, so its content needs a capability to read' );
}

$live_status = get_post_status_object( Statuses::LIVE );
$ok( true === $live_status->public, 'and the live status is public, or nothing would ever appear' );

$group( 'Public pages: what gets published' );

$pub_item = $make_item( $org_a, $alice, Statuses::LIVE );
wp_update_post( [ 'ID' => $pub_item, 'post_title' => 'A public event' ] );
// Stored the way the wizard stores them, under the meta key. The fixtures
// used the bare field key, which is how the public pages passed every test
// while showing a real event with no date, venue or summary.
foreach ( [ 'summary' => 'The standfirst.', 'venue_name' => 'Armley Library', 'start_datetime' => '2026-11-04 18:30:00', 'capacity' => '40', 'booking_url' => 'https://example.test/book' ] as $k => $v ) {
	update_post_meta( $pub_item, \DGL\Schema\FieldRegistry::find( PostTypes::EVENT, $k )->meta_key(), $v );
}
$ok( 'dgl_summary' === \DGL\Schema\FieldRegistry::find( PostTypes::EVENT, 'summary' )->meta_key(), 'the stored key is the prefixed one' );
$ok( 'The standfirst.' === (string) \DGL\Frontend\Frontend::value( get_post( $pub_item ), 'summary' ), 'and the public side reads it' );

$pub_post = get_post( $pub_item );
$facts    = \DGL\Frontend\Frontend::facts( $pub_post );
$keys     = array_column( $facts, 'key' );

$ok( in_array( 'venue_name', $keys, true ), 'the venue is published' );
$ok( in_array( 'start_datetime', $keys, true ), 'so is the date' );
$ok( in_array( 'booking_url', $keys, true ), 'and the booking link' );

/*
 * Capacity is a planning note a member records so DGLP know the scale of the
 * thing. Printed on a public listing it becomes a scarcity claim the organiser
 * never made.
 */
$ok( ! in_array( 'capacity', $keys, true ), 'capacity is not published, because it was never a public fact' );

// The title, summary and body are the page itself, not rows in a fact table.
$ok( ! in_array( 'title', $keys, true ), 'the headline is not repeated as a fact' );
$ok( ! in_array( 'summary', $keys, true ), 'nor the summary' );
$ok( ! in_array( 'body', $keys, true ), 'nor the description' );

$empty_fields = array_filter( $facts, static fn( array $f ): bool => '' === trim( wp_strip_all_tags( $f['value'] ) ) );
$ok( [] === $empty_fields, 'nothing empty is printed' );

$ok(
	! str_contains( implode( ' ', array_column( $facts, 'value' ) ), 'Not given' ),
	'and "Not given" never reaches a public page, because it is a prompt for a member, not a message to a visitor'
);

$ok( 'https://example.test/book' === \DGL\Frontend\Frontend::booking_url( $pub_post ), 'the booking link is found' );
$ok( str_contains( \DGL\Frontend\Frontend::meta_line( $pub_post ), 'Armley Library' ), 'the listing line names the venue' );
$ok( str_contains( \DGL\Frontend\Frontend::meta_line( $pub_post ), '2026' ), 'and when it is' );

$group( 'Public pages: switched-off types have none' );

$ok( null === \DGL\Frontend\Frontend::archive_url( PostTypes::GRANT ) || '' === \DGL\Frontend\Frontend::archive_url( PostTypes::GRANT ), 'a switched-off type has no archive URL' );
$ok( '' !== \DGL\Frontend\Frontend::archive_url( PostTypes::EVENT ), 'an enabled one does' );

$group( 'Public listings are ordered by when the thing happens' );

/*
 * Reverse-chronological post order puts next March above this Saturday, which
 * is the single most common way a community listing becomes useless.
 */
$ok( 'start_datetime' === \DGL\Frontend\Frontend::sort_key( PostTypes::EVENT ), 'events sort by their start date' );
$ok( null === \DGL\Frontend\Frontend::sort_key( PostTypes::NEWS ), 'news has no date of its own, so it keeps newest first' );

/*
 * The real query, as the front page runs it: the main query, with the
 * archive's pre_get_posts applied. The listing had been sorting on a key no
 * row carries ('start_datetime', not 'dgl_start_datetime') and setting
 * `meta_key`, which inner-joins postmeta and drops every row without it. On
 * staging that read "There are no events listed" above a live event.
 */
$ev_late  = $make_item( $org_a, $alice, Statuses::LIVE );
$ev_soon  = $make_item( $org_a, $alice, Statuses::LIVE );
$ev_never = $make_item( $org_a, $alice, Statuses::LIVE );
update_post_meta( $ev_late, 'dgl_start_datetime', '2031-12-01 10:00:00' );
update_post_meta( $ev_soon, 'dgl_start_datetime', '2031-01-01 10:00:00' );

$archive_query = new WP_Query();
$GLOBALS['wp_the_query'] = $archive_query;
$archive_ids = array_map( 'intval', (array) $archive_query->query( [ 'post_type' => PostTypes::EVENT, 'post_status' => Statuses::LIVE, 'posts_per_page' => 500, 'fields' => 'ids' ] ) );

$ok( in_array( $ev_never, $archive_ids, true ), 'a live event with no start date is still listed' );
$ok( in_array( $ev_soon, $archive_ids, true ) && in_array( $ev_late, $archive_ids, true ), 'dated events are listed' );
$ok( array_search( $ev_soon, $archive_ids, true ) < array_search( $ev_late, $archive_ids, true ), 'and the sooner one comes first' );

$group( 'Rich text: a pasted document loses its formatting and keeps its words' );

$pasted = '<style>.MsoNormal{mso-style:1}</style>'
	. '<p class="MsoNormal" style="margin:0;text-align:justify"><span style="font-family:Calibri;color:#1F497D">Hello <b>world</b></span></p>'
	. '<h2>A heading</h2><table><tr><td>cell</td></tr></table>'
	. '<script>alert(1)</script>'
	. '<ul><li>one</li></ul>'
	. '<a href="https://example.test/" style="color:red" target="_blank" rel="noopener" onclick="x()">link</a>'
	. '<img src="x.jpg"><iframe src="https://evil.test/"></iframe>'
	. '<p>&nbsp;</p><p> </p>';

$cleaned = \DGL\Content::clean( $pasted );

$ok( str_contains( $cleaned, '<p>Hello <b>world</b></p>' ), 'the paragraph and its bold survive without class or style' );
$ok( ! str_contains( $cleaned, 'style=' ), 'no inline style anywhere' );
$ok( ! str_contains( $cleaned, '<span' ) && ! str_contains( $cleaned, '<h2' ) && ! str_contains( $cleaned, '<table' ) && ! str_contains( $cleaned, '<td' ), 'span, heading and table tags are gone' );
$ok( str_contains( $cleaned, 'A heading' ) && str_contains( $cleaned, 'cell' ), 'the words inside them are not' );
$ok( ! str_contains( $cleaned, 'alert' ) && ! str_contains( $cleaned, 'mso-style' ), 'script and style go with their contents' );
$ok( str_contains( $cleaned, '<ul><li>one</li></ul>' ), 'a list survives' );
$ok( str_contains( $cleaned, '<a href="https://example.test/" target="_blank" rel="noopener">link</a>' ), 'a link keeps href, target and rel and loses style and onclick' );
$ok( ! str_contains( $cleaned, '<img' ) && ! str_contains( $cleaned, '<iframe' ) && ! str_contains( $cleaned, 'evil.test' ), 'images and frames are gone' );
$ok( ! str_contains( $cleaned, '&nbsp;' ) && ! str_contains( $cleaned, '<p></p>' ) && ! str_contains( $cleaned, '<p> </p>' ), 'empty paragraphs are dropped' );

$body_field = \DGL\Schema\FieldRegistry::find( PostTypes::EVENT, 'body' );
$ok( null !== $body_field && \DGL\Schema\Field::RICHTEXT === $body_field->type, 'the event body is the rich text field' );
$ok( null !== $body_field && \DGL\Schema\Store::sanitise( $body_field, '<p style="x">a</p><span>b</span>' ) === '<p>a</p>b', 'storage runs rich text through the same cleaner' );

$group( 'Uploads: every image is cut to 1600px and stripped of metadata' );

/*
 * Built here rather than committed: a JPEG with a real EXIF block (an APP1
 * segment carrying an Artist tag) and a PNG with transparency, so the test can
 * see the metadata go and the alpha stay.
 */
$exif_jpeg = static function ( int $w, int $h, string $out ): void {
	$artist = "Test Camera Owner\0";
	$desc   = "Taken at 53.7997,-1.5492\0";
	$n      = 2;
	$data   = 8 + 2 + 12 * $n + 4;
	$tiff   = 'II' . pack( 'v', 42 ) . pack( 'V', 8 ) . pack( 'v', $n )
		. pack( 'vvVV', 0x010E, 2, strlen( $desc ), $data )
		. pack( 'vvVV', 0x013B, 2, strlen( $artist ), $data + strlen( $desc ) )
		. pack( 'V', 0 ) . $desc . $artist;
	$app1   = "Exif\0\0" . $tiff;
	$im     = imagecreatetruecolor( $w, $h );
	for ( $i = 0; $i < 40; $i++ ) {
		imagefilledrectangle( $im, wp_rand( 0, $w ), wp_rand( 0, $h ), wp_rand( 0, $w ), wp_rand( 0, $h ), imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	}
	ob_start();
	imagejpeg( $im, null, 92 );
	$jpg = (string) ob_get_clean();
	file_put_contents( $out, substr( $jpg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 . substr( $jpg, 2 ) );
};

$fixtures = get_temp_dir() . 'dgl-fixtures-' . wp_generate_password( 6, false );
wp_mkdir_p( $fixtures );
$exif_jpeg( 3000, 2000, $fixtures . '/big-exif.jpg' );
$exif_jpeg( 900, 600, $fixtures . '/small-exif.jpg' );
$png = imagecreatetruecolor( 2400, 1200 );
imagesavealpha( $png, true );
imagefill( $png, 0, 0, imagecolorallocatealpha( $png, 0, 0, 0, 127 ) );
imagefilledellipse( $png, 1200, 600, 1000, 800, imagecolorallocate( $png, 214, 38, 42 ) );
imagepng( $png, $fixtures . '/big.png' );

$fixture_exif = @exif_read_data( $fixtures . '/big-exif.jpg' );
$ok( 'Test Camera Owner' === ( $fixture_exif['Artist'] ?? '' ), 'the fixture really carries EXIF before the test starts' );

$updir   = wp_upload_dir();
$workdir = trailingslashit( $updir['basedir'] ) . 'dgl-test-' . wp_generate_password( 6, false );
wp_mkdir_p( $workdir );

$shrink = static function ( string $name, string $type ) use ( $fixtures, $workdir ): array {
	$path = $workdir . '/' . $name;
	copy( $fixtures . '/' . $name, $path );
	$before = filesize( $path );
	$after  = \DGL\Uploads::shrink( [ 'file' => $path, 'url' => 'http://example.test/' . $name, 'type' => $type ] );
	clearstatcache();
	$size = getimagesize( (string) $after['file'] );
	$exif = 'image/jpeg' === $type ? @exif_read_data( (string) $after['file'] ) : [];
	return [ 'w' => (int) $size[0], 'h' => (int) $size[1], 'before' => $before, 'after' => filesize( (string) $after['file'] ), 'artist' => $exif['Artist'] ?? '', 'path' => (string) $after['file'] ];
};

$r = $shrink( 'big-exif.jpg', 'image/jpeg' );
$ok( 1600 === $r['w'] && 1067 === $r['h'], 'a 3000x2000 JPEG becomes 1600x1067' );
$ok( '' === $r['artist'], 'its EXIF (Artist tag in the fixture) is gone' );
$ok( $r['after'] < $r['before'], 'and the file is smaller (' . size_format( $r['before'] ) . ' to ' . size_format( $r['after'] ) . ')' );

$r = $shrink( 'small-exif.jpg', 'image/jpeg' );
$ok( 900 === $r['w'] && 600 === $r['h'], 'a 900x600 JPEG keeps its size' );
$ok( '' === $r['artist'], 'but still loses its metadata' );

$r = $shrink( 'big.png', 'image/png' );
$ok( 1600 === $r['w'] && 800 === $r['h'], 'a 2400x1200 PNG becomes 1600x800' );
$png_im = imagecreatefrompng( $r['path'] );
$ok( false !== $png_im && 127 === ( imagecolorat( $png_im, 2, 2 ) >> 24 ), 'and its transparency survives the rewrite' );

$untouched = \DGL\Uploads::shrink( [ 'file' => $workdir . '/nothing.pdf', 'url' => '', 'type' => 'application/pdf' ] );
$ok( $workdir . '/nothing.pdf' === $untouched['file'], 'a non-image type is left alone' );

foreach ( glob( $workdir . '/*' ) as $f ) {
	unlink( $f );
}
rmdir( $workdir );

$group( 'Uploads: the organisation logo goes through the same door' );

/*
 * The organisation form offered a logo control whose file was never read:
 * Profile::save() took the POST and not $_FILES. Under the CLI a file cannot
 * be moved as an upload, so the assertion is that the attempt is made and its
 * failure reported against the logo, where before it was silently ignored.
 */
$logo_key = \DGL\Dashboard\FieldRenderer::INPUT_NAME . '_file_org_logo';
$tmp_logo = wp_tempnam( 'logo.jpg' );
copy( $fixtures . '/small-exif.jpg', $tmp_logo );
$fake_file = [ 'name' => 'logo.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp_logo, 'error' => 0, 'size' => filesize( $tmp_logo ) ];
$_FILES[ $logo_key ] = $fake_file;

$org_input              = \DGL\Org\Profile::form_values( $org_a );
$org_input['org_email'] = 'orga@example.test'; // Required, and the fixture has none.
$saved     = \DGL\Org\Profile::save( $org_a, $org_input, $alice, [ $logo_key => $fake_file ] );

$ok( isset( $saved['errors']['org_logo'] ) && '' !== $saved['errors']['org_logo'], 'a logo file that cannot be stored is reported on the logo field: ' . ( $saved['errors']['org_logo'] ?? '(nothing)' ) );

unset( $_FILES[ $logo_key ] );
@unlink( $tmp_logo );

$saved = \DGL\Org\Profile::save( $org_a, $org_input, $alice, [] );
$ok( [] === $saved['errors'], 'with no file chosen the organisation saves as before' );

foreach ( glob( $fixtures . '/*' ) as $f ) {
	unlink( $f );
}
rmdir( $fixtures );

$group( 'Archive, restore and take down: the state machine finally has callers' );

$live_item = $make_item( $org_a, $alice, Statuses::LIVE );

$r = Transition::apply( $live_item, StateMachine::ARCHIVE, $aaron );
$ok( ! is_wp_error( $r ) && Statuses::ARCHIVED === get_post_status( $live_item ), 'a contributor archives a live item their organisation owns' );

$r = Transition::apply( $live_item, StateMachine::ARCHIVE, $bella );
$ok( is_wp_error( $r ), 'an owner of another organisation cannot (' . ( is_wp_error( $r ) ? $r->get_error_code() : 'no error' ) . ')' );

$r = Transition::apply( $live_item, StateMachine::RESTORE, $alice );
$ok( ! is_wp_error( $r ) && Statuses::PENDING === get_post_status( $live_item ), 'restoring sends it back through review, not straight to the site' );

$r = Transition::apply( $live_item, StateMachine::ARCHIVE, $alice );
$ok( is_wp_error( $r ), 'it cannot be archived while the team have it' );

$r = Transition::apply( $live_item, StateMachine::APPROVE, $mod );
$ok( ! is_wp_error( $r ) && Statuses::LIVE === get_post_status( $live_item ), 'the team approve it back onto the site' );

$r = Transition::apply( $live_item, StateMachine::TAKE_DOWN, $alice, 'because' );
$ok( is_wp_error( $r ), 'a member cannot take down' );

$r = Transition::apply( $live_item, StateMachine::TAKE_DOWN, $mod );
$ok( is_wp_error( $r ) && 'dgl_note_required' === $r->get_error_code(), 'the team cannot take something down without saying why' );

$r = Transition::apply( $live_item, StateMachine::TAKE_DOWN, $mod, 'Reported by a member of the public; checking.' );
$ok( ! is_wp_error( $r ) && Statuses::PENDING === get_post_status( $live_item ), 'with a reason it comes off the site and back into the queue' );

$draft_item = $make_item( $org_a, $aaron, Statuses::DRAFT );
$r = Transition::apply( $draft_item, StateMachine::ARCHIVE, $alice );
$ok( ! is_wp_error( $r ) && Statuses::ARCHIVED === get_post_status( $draft_item ), 'an owner archives a colleague\'s draft: the organisation owns it' );

$group( 'Removing a member: access goes, the work stays' );

$leaver = $make_member( 'dgl_leaver', $org_a, 'contributor' );
$leaver_item = $make_item( $org_a, $leaver, Statuses::LIVE );
\WP_Session_Tokens::get_instance( $leaver )->create( time() + 3600 );
$ok( [] !== \WP_Session_Tokens::get_instance( $leaver )->get_all(), 'the leaver has a live session before removal' );

$sent_to = [];

$r = \DGL\Org\Org::remove_member( $leaver, $aaron );
$ok( is_wp_error( $r ) && (int) \DGL\Org\Org::for_user( $leaver ) === $org_a, 'a contributor cannot remove a colleague' );

$r = \DGL\Org\Org::remove_member( $alice, $alice );
$ok( is_wp_error( $r ) && (int) \DGL\Org\Org::for_user( $alice ) === $org_a, 'an owner cannot remove themselves' );

$r = \DGL\Org\Org::remove_member( $leaver, $bella );
$ok( is_wp_error( $r ), 'an owner of another organisation cannot remove them' );

$r = \DGL\Org\Org::remove_member( $leaver, $alice );
$ok( true === $r, 'their own owner can' );
$ok( null === \DGL\Org\Org::for_user( $leaver ) || 0 === (int) \DGL\Org\Org::for_user( $leaver ), 'the organisation link is gone' );
$ok( '' === (string) get_user_meta( $leaver, Meta::USER_ORG_ROLE, true ), 'and the role with it' );
$ok( false !== get_userdata( $leaver ), 'the account itself still exists' );
$ok( [] === \WP_Session_Tokens::get_instance( $leaver )->get_all(), 'their sessions are ended, so removal is immediate' );
$ok( Statuses::LIVE === get_post_status( $leaver_item ) && (int) \DGL\Org\Org::for_item( $leaver_item ) === $org_a, 'what they posted stays live and stays the organisation\'s' );
$ok( in_array( 'leaver@example.test', array_map( 'strtolower', $sent_to ), true ) || in_array( 'dgl_leaver@example.test', array_map( 'strtolower', $sent_to ), true ) || [] !== $sent_to, 'the removed person is emailed (' . implode( ',', $sent_to ) . ')' );

$removal_log = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dgl_audit WHERE action = %s AND org_id = %d", 'member_removed', $org_a ) );
$ok( (int) $removal_log >= 1, 'the removal is in the audit trail against the organisation' );

$ok( ! Access::can( $leaver, Policy::VIEW_ITEM, $leaver_item ), 'the leaver can no longer see even their own old item' );

$group( 'Organisation changes: the team is told once, and can decide on the front end' );

$mail_was_on = get_option( \DGL\Email\Routing::OPTION_ENABLED, false );
update_option( \DGL\Email\Routing::OPTION_ENABLED, 1 );
$sent_to = []; $sent_links = []; $sent_bodies = [];

$org_input = \DGL\Org\Profile::form_values( $org_a );
$org_input['org_email'] = 'orga@example.test';
$org_input['org_name']  = 'Org A, renamed';
$saved = \DGL\Org\Profile::save( $org_a, $org_input, $alice, [] );
$ok( [] === $saved['errors'] && [ 'org_name' ] === $saved['held'], 'a name change is held for the team' );
$ok( in_array( $org_a, \DGL\Org\Profile::awaiting_review(), true ), 'and the organisation is in the waiting list' );
$ok( 1 === count( $sent_to ) && str_contains( $sent_to[0], 'mod@example.test' ), 'the review team got one email (' . implode( ' | ', $sent_to ) . ')' );
$ok( 1 === count( $sent_links ) && str_contains( $sent_links[0], '/review/org/' . $org_a ), 'linking to the front-end decision screen (' . ( $sent_links[0] ?? '' ) . ')' );
$ok( str_contains( implode( ' ', $sent_bodies ), 'organisation name' ), 'and naming what is being changed' );

$org_input['org_name'] = 'Org A, renamed again';
$saved = \DGL\Org\Profile::save( $org_a, $org_input, $alice, [] );
$ok( [] === $saved['errors'] && 1 === count( $sent_to ), 'revising the request while it waits sends no second email' );

$r = \DGL\Org\Profile::reject_pending( $org_a, $mod, '' );
$ok( is_wp_error( $r ), 'refusing needs a reason' );
$r = \DGL\Org\Profile::reject_pending( $org_a, $mod, 'Please use the registered charity name.' );
$ok( true === $r && ! in_array( $org_a, \DGL\Org\Profile::awaiting_review(), true ), 'refused with a reason, and off the waiting list' );
$ok( 'Org A' === get_the_title( $org_a ), 'the live name never changed' );

update_option( \DGL\Email\Routing::OPTION_ENABLED, $mail_was_on );

$group( 'Joining: email first, domain decides, the team verify what the list does not know' );

use DGL\Joining\Joining;
use DGL\Joining\Signup;
use DGL\Joining\Store as SignupStore;

$mail_was_on = get_option( \DGL\Email\Routing::OPTION_ENABLED, false );
update_option( \DGL\Email\Routing::OPTION_ENABLED, 1 );
add_filter( 'pre_wp_mail', '__return_true' );
$sent_to = []; $sent_links = []; $sent_bodies = [];

foreach ( [ 'newbie@orga.test', 'first@emptyorg.test', 'founder@brandnew.test', 'gmailer@gmail.com', 'refused@nowhere.test' ] as $addr ) {
	$u = get_user_by( 'email', $addr ); if ( $u ) { wp_delete_user( $u->ID ); }
}
$ok( SignupStore::exists(), 'the sign-ups table exists after migration' );

\DGL\Org\Org::set_domains( $org_a, [ 'orga.test', 'www.OrgA.test/' ] );
$ok( [ 'orga.test' ] === \DGL\Org\Org::domains( $org_a ), 'recorded domains are normalised and unique: ' . implode( ',', \DGL\Org\Org::domains( $org_a ) ) );
$ok( [ $org_a ] === \DGL\Org\Org::by_domain( 'orga.test' ), 'an address on orga.test finds Org A' );
$ok( [] === \DGL\Org\Org::by_domain( 'gmail.com' ), 'a public provider finds nothing even if somebody recorded it' );

// A listed organisation with nobody in it: the first arrival by domain runs it.
$empty_org = $make_org( 'Empty Org From The List' );
\DGL\Org\Org::set_domains( $empty_org, [ 'emptyorg.test' ] );

$r = Joining::start( 'not an address' );
$ok( ! $r['ok'], 'a non-address is refused' );
$r = Joining::start( 'dgl_alice@example.test' );
$ok( ! $r['ok'] && str_contains( $r['error'], 'already an account' ), 'an address with an account is sent to sign in' );

$r = Joining::start( 'Newbie@OrgA.test' );
$ok( $r['ok'], 'a new address starts a sign-up' );
$ok( 1 === count( $sent_links ) && [ 'newbie@orga.test' ] === array_map( 'strtolower', explode( ',', $sent_to[0] ) ), 'the verification email goes to that address alone' );
$ok( null === get_user_by( 'email', 'newbie@orga.test' ) || false === get_user_by( 'email', 'newbie@orga.test' ), 'and no user exists yet' );
$token = (string) substr( $sent_links[0], strrpos( rtrim( $sent_links[0], '/' ), '/' ) + 1 );
$token = trim( $token, '/' );

$v = Joining::verify( 'nonsense' );
$ok( null === $v['signup'] && '' !== $v['error'], 'a made-up token is refused' );
$v = Joining::verify( $token );
$ok( null !== $v['signup'] && Signup::VERIFIED === $v['signup']->state, 'the real link proves the address' );
$ok( \DGL\Joining\Rules::OUTCOME_MATCH === $v['outcome'] && [ $org_a ] === $v['orgs'], 'and Org A is offered, because the domain matches' );

$uid = Joining::join( $v['signup'], $org_b, 'Newbie', 'a-fine-password' );
$ok( is_wp_error( $uid ), 'joining an organisation the domain did not match is refused' );
$sent_to = [];
$uid = Joining::join( $v['signup'], $org_a, 'Newbie', 'a-fine-password' );
$ok( ! is_wp_error( $uid ) && (int) \DGL\Org\Org::for_user( $uid ) === $org_a, 'joining the offered one creates a linked account' );
$ok( \DGL\Access\UserContext::ORG_CONTRIBUTOR === \DGL\Org\Org::role_for_user( $uid ), 'as a contributor, because Org A already has people' );
$ok( \DGL\Access\UserContext::ACCOUNT_APPROVED === get_user_meta( $uid, Meta::USER_ACCOUNT_STATUS, true ), 'approved straight away: the domain was the check' );
$ok( [] !== $sent_to && str_contains( implode( ',', $sent_to ), 'dgl_alice@example.test' ), 'the owner is told (' . implode( ' | ', $sent_to ) . ')' );
$ok( wp_check_password( 'a-fine-password', get_userdata( $uid )->user_pass ), 'and the password they chose works' );
$v2 = Joining::verify( $token );
$ok( '' !== $v2['error'], 'the link is dead afterwards' );

$r = Joining::start( 'first@emptyorg.test' );
$ok( $r['ok'], 'a second address starts a sign-up (' . $r['error'] . ')' );
$token = trim( (string) substr( end( $sent_links ), strrpos( rtrim( end( $sent_links ), '/' ), '/' ) + 1 ), '/' );
$v = Joining::verify( $token );
$uid2 = Joining::join( $v['signup'], $empty_org, 'First Person', 'another-password' );
$ok( ! is_wp_error( $uid2 ) && \DGL\Access\UserContext::ORG_OWNER === \DGL\Org\Org::role_for_user( $uid2 ), 'the first person into a listed organisation becomes its owner' );

$sent_to = []; $sent_links = [];
$r = Joining::start( 'founder@brandnew.test' ); $token = trim( (string) substr( end( $sent_links ), strrpos( rtrim( end( $sent_links ), '/' ), '/' ) + 1 ), '/' );
$v = Joining::verify( $token );
$ok( \DGL\Joining\Rules::OUTCOME_NEW === $v['outcome'], 'an unknown domain means a new organisation' );
$uid3 = Joining::register( $v['signup'], 'Org A', [ 'org_email' => 'founder@brandnew.test' ], 'Founder', 'yet-another-pw' );
$ok( is_wp_error( $uid3 ) && 'dgl_org_name' === $uid3->get_error_code(), 'a name already on the list is refused' );
$sent_to = [];
$uid3 = Joining::register( $v['signup'], 'Brand New CIC', [ 'org_email' => 'founder@brandnew.test', 'org_website' => 'https://brandnew.test' ], 'Founder', 'yet-another-pw' );
$ok( ! is_wp_error( $uid3 ), 'a new organisation is registered' );
$new_org = (int) \DGL\Org\Org::for_user( $uid3 );
update_post_meta( $new_org, DGL_FIXTURE_FLAG, '1' );
$ok( Meta::ORG_PENDING === \DGL\Org\Org::status( $new_org ) && \DGL\Access\UserContext::ORG_OWNER === \DGL\Org\Org::role_for_user( $uid3 ) && \DGL\Access\UserContext::ACCOUNT_PENDING === get_user_meta( $uid3, Meta::USER_ACCOUNT_STATUS, true ), 'pending organisation, pending owner' );
$ok( [ 'brandnew.test' ] === \DGL\Org\Org::domains( $new_org ), 'its domain is recorded for the next colleague' );
$ok( str_contains( implode( ',', $sent_to ), 'mod@example.test' ) && str_contains( end( $sent_links ), '/review/join/' ), 'the team are emailed with a link to decide' );
$ok( 1 === count( array_filter( SignupStore::awaiting(), static fn( $s ) => $s->org_id === $new_org ) ), 'and it is in the waiting list' );
$ok( ! Access::can( $uid3, Policy::SUBMIT_ITEM, $make_item( $new_org, $uid3, Statuses::DRAFT ) ), 'the pending owner can draft but not submit' );

$signup_id = array_values( array_filter( SignupStore::awaiting(), static fn( $s ) => $s->org_id === $new_org ) )[0]->id;
$ok( is_wp_error( Joining::approve( $signup_id, $alice ) ), 'a member cannot verify an organisation' );
$sent_to = [];
$ok( true === Joining::approve( $signup_id, $mod ), 'the team can' );
$ok( Meta::ORG_APPROVED === \DGL\Org\Org::status( $new_org ) && \DGL\Access\UserContext::ACCOUNT_APPROVED === get_user_meta( $uid3, Meta::USER_ACCOUNT_STATUS, true ), 'organisation and owner both approved' );
$ok( str_contains( implode( ',', $sent_to ), 'founder@brandnew.test' ), 'and the founder is told' );

$sent_links = [];
$r = Joining::start( 'refused@nowhere.test' ); $token = trim( (string) substr( end( $sent_links ), strrpos( rtrim( end( $sent_links ), '/' ), '/' ) + 1 ), '/' );
$v = Joining::verify( $token );
$uid4 = Joining::register( $v['signup'], 'Nowhere Collective', [ 'org_email' => 'refused@nowhere.test' ], 'Nobody', 'password-eight' );
$bad_org = (int) \DGL\Org\Org::for_user( $uid4 ); update_post_meta( $bad_org, DGL_FIXTURE_FLAG, '1' );
$sid = array_values( array_filter( SignupStore::awaiting(), static fn( $s ) => $s->org_id === $bad_org ) )[0]->id;
$ok( is_wp_error( Joining::refuse( $sid, $mod, '' ) ), 'refusing needs a reason' );
$sent_to = [];
$ok( true === Joining::refuse( $sid, $mod, 'Not a Leeds organisation.' ), 'refused with one' );
$ok( 'trash' === get_post_status( $bad_org ) && \DGL\Access\UserContext::ACCOUNT_CLOSED === get_user_meta( $uid4, Meta::USER_ACCOUNT_STATUS, true ) && null === \DGL\Org\Org::for_user( $uid4 ), 'the organisation is binned, the account closed and unlinked' );
$ok( str_contains( implode( ',', $sent_to ), 'refused@nowhere.test' ), 'and the person is told why' );

$r = Joining::start( 'gmailer@gmail.com' ); $token = trim( (string) substr( end( $sent_links ), strrpos( rtrim( end( $sent_links ), '/' ), '/' ) + 1 ), '/' );
\DGL\Org\Org::set_domains( $org_b, [ 'gmail.com' ] );
$v = Joining::verify( $token );
$ok( \DGL\Joining\Rules::OUTCOME_NEW === $v['outcome'] && [] === $v['orgs'], 'a Gmail address is never offered an organisation, even one that recorded gmail.com' );
\DGL\Org\Org::set_domains( $org_b, [] );

remove_filter( 'pre_wp_mail', '__return_true' );
update_option( \DGL\Email\Routing::OPTION_ENABLED, $mail_was_on );

/* -------------------------------------------------------------- privacy */

$group( 'Privacy tools: export and erase' );

foreach ( [ 'subject@privacy.test', 'nobody@privacy.test' ] as $address ) {
	$existing = get_user_by( 'email', $address );
	if ( $existing ) {
		wp_delete_user( $existing->ID );
	}
}
$wpdb->delete( InviteStore::name(), [ 'email' => 'subject@privacy.test' ] );
$wpdb->delete( SignupStore::name(), [ 'email' => 'subject@privacy.test' ] );

$priv_org  = $make_org( 'Privacy Org' );
$priv_user = $make_member( 'dgl_privacy', $priv_org, 'contributor' );
wp_update_user( [ 'ID' => $priv_user, 'user_email' => 'subject@privacy.test' ] );
$priv_item = $make_item( $priv_org, $priv_user, Statuses::DRAFT );
DigestStore::save( $priv_user, [ PostTypes::EVENT ], [], Frequency::WEEKLY, false, 'test' );
Log::record( 'privacy_test', 'item', $priv_item, $priv_org, 'hello', [], $priv_user );
$priv_invite_id = InviteStore::insert( new \DGL\Invites\Invite( 0, 'subject@privacy.test', $priv_org, 'contributor', $priv_user, InviteStore::hash( InviteStore::new_token() ), InviteStore::now(), InviteStore::now() ) );
$priv_signup = SignupStore::start( 'subject@privacy.test', 'privacy.test', SignupStore::now() );

$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );
$ok( isset( $exporters['dgl-platform'] ) && is_callable( $exporters['dgl-platform']['callback'] ), 'an exporter is registered with core' );
$ok( isset( $erasers['dgl-platform'] ) && is_callable( $erasers['dgl-platform']['callback'] ), 'an eraser is registered with core' );

$export = \DGL\Privacy\Privacy::export( 'subject@privacy.test' );
$groups = array_count_values( array_column( $export['data'], 'group_id' ) );
$ok( true === $export['done'], 'export finishes in one page' );
$ok( 1 === ( $groups['dgl-membership'] ?? 0 ), 'membership exported once' );
$ok( 1 === ( $groups['dgl-digest'] ?? 0 ), 'digest preferences exported' );
$ok( 1 === ( $groups['dgl-invites'] ?? 0 ), 'the invitation addressed to them exported' );
$ok( 1 === ( $groups['dgl-signups'] ?? 0 ), 'the joining request exported' );
$ok( ( $groups['dgl-activity'] ?? 0 ) >= 1, 'their audit activity exported' );
$ok( 1 === ( $groups['dgl-listings'] ?? 0 ), 'the listing they wrote is listed' );
$flat = wp_json_encode( $export['data'] );
$ok( ! str_contains( $flat, 'token' ) && ! str_contains( $flat, 'ip_hash' ), 'no token or IP hash in the export' );
$ok( str_contains( $flat, 'Privacy Org' ), 'the organisation is named, not numbered' );

$empty = \DGL\Privacy\Privacy::export( 'nobody@privacy.test' );
$ok( [] === $empty['data'] && true === $empty['done'], 'an unknown address exports nothing and finishes' );

$result = \DGL\Privacy\Privacy::erase( 'subject@privacy.test' );
$ok( true === $result['items_removed'] && true === $result['done'], 'erase removes things and finishes' );
$ok( true === $result['items_retained'] && count( $result['messages'] ) === 2, 'and says what it kept: the audit trail and the listing' );
$ok( null === DigestStore::for_user( $priv_user ), 'digest subscription gone' );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . InviteStore::name() . ' WHERE email = %s', 'subject@privacy.test' ) ), 'invitations to the address gone' );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SignupStore::name() . ' WHERE email = %s', 'subject@privacy.test' ) ), 'joining requests gone' );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \DGL\Audit\Table::name() . ' WHERE actor_id = %d', $priv_user ) ), 'audit rows no longer name them' );
$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \DGL\Audit\Table::name() . ' WHERE object_id = %d AND action = %s', $priv_item, 'privacy_test' ) ), 'but the audit row itself is kept' );
$ok( null === \DGL\Org\Org::for_user( $priv_user ) && '' === (string) get_user_meta( $priv_user, Meta::USER_ACCOUNT_STATUS, true ), 'organisation link and account status removed' );
$ok( 'draft' === get_post_status( $priv_item ) || Statuses::DRAFT === get_post_status( $priv_item ), 'the listing is still there for the organisation' );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT author_id FROM ' . ItemsTable::name() . ' WHERE post_id = %d', $priv_item ) ), 'the index no longer names them as author' );

$again = \DGL\Privacy\Privacy::erase( 'subject@privacy.test' );
$ok( false === $again['items_removed'] && true === $again['done'], 'erasing twice removes nothing more' );

/* ---------------------------------------------------- directory and import */

$group( 'Directory toggle: any approved member, immediate, audited' );

$dir_org   = $make_org( 'Directory Org' );
$dir_owner = $make_member( 'dgl_dirowner', $dir_org, 'owner' );
$dir_contr = $make_member( 'dgl_dircontr', $dir_org, 'contributor' );
$dir_pend  = $make_member( 'dgl_dirpend', $dir_org, 'contributor', 'pending' );
$other_org = $make_org( 'Other Directory Org' );
$outsider  = $make_member( 'dgl_dirout', $other_org, 'owner' );
Access::flush_cache();

$ctx = static fn( int $uid ) => Access::user_context( $uid );
$ok( false === \DGL\Org\Directory::wants_listing( $dir_org ), 'off by default' );
$ok( true === \DGL\Org\Directory::set( $dir_org, true, $ctx( $dir_contr ) ), 'a contributor can switch it on' );
$ok( true === \DGL\Org\Directory::wants_listing( $dir_org ) && true === \DGL\Org\Directory::is_listed( $dir_org ), 'and an approved organisation is then listed' );
$ok( is_wp_error( \DGL\Org\Directory::set( $dir_org, false, $ctx( $dir_pend ) ) ), 'a pending member cannot' );
$ok( is_wp_error( \DGL\Org\Directory::set( $dir_org, false, $ctx( $outsider ) ) ), 'nor can somebody from another organisation' );
$ok( true === \DGL\Org\Directory::wants_listing( $dir_org ), 'so it stayed on' );
$ok( true === \DGL\Org\Directory::set( $dir_org, false, $ctx( $dir_owner ) ), 'the owner can switch it off' );
$ok( false === \DGL\Org\Directory::wants_listing( $dir_org ), 'and it is off' );
$dir_audit = array_column( Log::for_object( 'org', $dir_org ), 'action' );
$ok( in_array( 'directory_on', $dir_audit, true ) && in_array( 'directory_off', $dir_audit, true ), 'both switches are in the audit log' );
update_post_meta( $dir_org, Meta::ORG_STATUS, Meta::ORG_PENDING );
update_post_meta( $dir_org, Meta::ORG_IN_DIRECTORY, '1' );
$ok( false === \DGL\Org\Directory::is_listed( $dir_org ), 'a pending organisation is never listed, whatever the flag says' );
update_post_meta( $dir_org, Meta::ORG_STATUS, Meta::ORG_APPROVED );

$group( 'Profile save with several-of-a-list fields' );

$saved = \DGL\Org\Profile::save(
	$dir_org,
	[
		'org_name'       => 'Directory Org',
		'org_email'      => 'hello@directory.test',
		'org_services'   => [ 'volunteering', 'not_an_option', 'advocacy_and_advice' ],
		'org_ward'       => 'armley',
		'org_service_users' => [],
	],
	$dir_owner
);
$ok( [] === $saved['errors'], 'saves without errors' );
$ok( [ 'advocacy_and_advice', 'volunteering' ] === get_post_meta( $dir_org, 'dgl_org_services', true ), 'known options kept in option order, unknown dropped' );
$ok( 'armley' === get_post_meta( $dir_org, 'dgl_org_ward', true ), 'a ward is stored by key' );
$ok( ! metadata_exists( 'post', $dir_org, 'dgl_org_service_users' ), 'an empty list leaves no row' );
$updates_before = count( array_filter( Log::for_object( 'org', $dir_org ), static fn( $r ) => 'org_updated' === $r['action'] ) );
$saved = \DGL\Org\Profile::save( $dir_org, [ 'org_name' => 'Directory Org', 'org_email' => 'hello@directory.test', 'org_services' => [ 'volunteering', 'advocacy_and_advice' ], 'org_ward' => 'armley' ], $dir_owner );
$updates_after = count( array_filter( Log::for_object( 'org', $dir_org ), static fn( $r ) => 'org_updated' === $r['action'] ) );
$ok( $updates_before === $updates_after, 'saving the same choices again is not logged as a change' );

$group( 'Import: Forum Central CSV, dry run then real, then again' );

$import_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'dgl-import-test';
wp_mkdir_p( $import_dir );
$csv_path = $import_dir . '/fc-sample.csv';
$csv_rows = [
	[ 'Organisation Name', 'Email', 'Phone', 'Website', 'Street Address', 'Supplemental Address 1', 'Supplemental Address 2', 'Postal Code', 'Latitude', 'Longitude', 'Short Description of Organisation', 'Organisation Type', 'Accessibility Provision', 'Accreditations', 'Ward Organisation is based in', 'Short description of services delivered', 'FC specialism (relevant to organisation)', 'General Service Users', 'General Service Provision', 'General Service Delivery Type', 'Legal Status', 'Charity/Company Number', 'Size - Number of Paid Staff', 'Size - Number of Volunteers (approx)', 'Permission to publish online', 'Volition Member Status', 'LOPF Membership Status', 'Contact ID', 'City', 'Contact Subtype' ],
	[ 'Import Test Trust', 'info@importtest.test', '0113 000 0000', 'www.importtest.test', '1 Test Street', 'Suite 2', '', 'ls1 1aa', '53.8', '-1.5', 'We test imports.', 'Community Anchor, Neighbourhood Network', 'Step Free Access, Induction Loop', 'Living Wage Employer', 'Armley', '', 'Older People', "Age Groups: Adults, People's circumstances: Gypsy, Roma and Traveller Communities, Made Up Group", 'Volunteering, Advocacy and advice', 'Online', 'Registered Charity', '1234567', '11 - 50', '100+', 'I am happy for the information I have provided above about this organisation to be made available online and shared where appropriate by Forum Central', 'Current Member', '', '900001', 'Leeds', 'Age_and_Dementia_Friendly_Business' ],
	[ 'Gmail Only Group', 'someone@gmail.com', '', '', '', '', '', '', '', '', '', '', '', '', 'City', '', '', '', '', '', '', '', '', '', '', 'Current Member', '', '900002', 'Leeds', '' ],
	[ '', 'blank@nowhere.test', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '900003', '', '' ],
];
$fh = fopen( $csv_path, 'w' );
fwrite( $fh, "\xEF\xBB\xBF" );
foreach ( $csv_rows as $r ) { fputcsv( $fh, $r, ',', '"', '' ); }
fclose( $fh );

foreach ( [ 'Import Test Trust', 'Gmail Only Group' ] as $stale_name ) {
	foreach ( get_posts( [ 'post_type' => PostTypes::ORG, 'post_status' => 'any', 'title' => $stale_name, 'fields' => 'ids' ] ) as $stale_org ) {
		wp_delete_post( (int) $stale_org, true );
	}
}

$run_import = static function ( string $flags ) use ( $csv_path ): string {
	$cmd = sprintf( 'php %s --path=%s --allow-root dgl org import %s %s 2>&1', escapeshellarg( '/home/claude/wp-test/wp-cli.phar' ), escapeshellarg( ABSPATH ), escapeshellarg( $csv_path ), $flags );
	$result = (string) shell_exec( $cmd );
	// The import ran in another process; this one's query cache does not know.
	wp_cache_flush();
	return $result;
};

$out = $run_import( '--dry-run' );
$ok( str_contains( $out, 'DRY RUN. 3 organisations: 2 new, 0 already known, 1 skipped.' ), 'dry run counts two new and one skipped: ' . trim( strtok( $out, "\n" ) ) );
$ok( str_contains( $out, 'Made Up Group' ) && str_contains( $out, '"City"' ), 'dry run reports the values that match nothing' );
$ok( str_contains( $out, 'no organisation name' ), 'and the row with no name' );
$ok( [] === get_posts( [ 'post_type' => PostTypes::ORG, 'post_status' => 'any', 'title' => 'Import Test Trust', 'fields' => 'ids' ] ), 'dry run wrote nothing' );

$out = $run_import( '--approve' );
$ok( str_contains( $out, '3 organisations: 2 new, 0 already known, 1 skipped.' ), 'real run creates two' );
$imported = get_posts( [ 'post_type' => PostTypes::ORG, 'post_status' => 'any', 'title' => 'Import Test Trust', 'fields' => 'ids' ] );
$ok( 1 === count( $imported ), 'the trust exists once' );
$imp = (int) ( $imported[0] ?? 0 );
update_post_meta( $imp, DGL_FIXTURE_FLAG, '1' );
$gm = (int) ( get_posts( [ 'post_type' => PostTypes::ORG, 'post_status' => 'any', 'title' => 'Gmail Only Group', 'fields' => 'ids' ] )[0] ?? 0 );
update_post_meta( $gm, DGL_FIXTURE_FLAG, '1' );
$ok( Meta::ORG_APPROVED === \DGL\Org\Org::status( $imp ), '--approve verifies it' );
$ok( [ 'importtest.test' ] === \DGL\Org\Org::domains( $imp ), 'its email domain is recorded for joining' );
$ok( [] === \DGL\Org\Org::domains( $gm ), 'a Gmail address records no domain' );
$ok( 'https://www.importtest.test' === get_post_meta( $imp, 'dgl_org_website', true ), 'a bare website gets a scheme' );
$ok( 'LS1 1AA' === get_post_meta( $imp, 'dgl_org_postcode', true ), 'postcode upper-cased' );
$ok( 'Suite 2' === get_post_meta( $imp, 'dgl_org_address_2', true ), 'supplemental address lines joined' );
$ok( [ 'community_anchor', 'neighbourhood_network' ] === get_post_meta( $imp, 'dgl_org_type', true ), 'several-of-a-list stored as keys in option order' );
$ok( in_array( 'circ_gypsy_roma_and_traveller_communities', (array) get_post_meta( $imp, 'dgl_org_service_users', true ), true ), 'a label with commas in it survives' );
$ok( 'armley' === get_post_meta( $imp, 'dgl_org_ward', true ) && '11_50' === get_post_meta( $imp, 'dgl_org_staff', true ) && '100_plus' === get_post_meta( $imp, 'dgl_org_volunteers', true ), 'one-of-a-list fields stored by key' );
$ok( '' === (string) get_post_meta( $gm, 'dgl_org_ward', true ), '"City" matches no ward and is left blank' );
$ok( '1' === get_post_meta( $imp, Meta::ORG_FC_PERMISSION, true ) && '1' === get_post_meta( $imp, Meta::ORG_AGE_FRIENDLY, true ) && '900001' === get_post_meta( $imp, Meta::ORG_FC_ID, true ), 'Forum Central facts carried over' );
$ok( '53.8' === get_post_meta( $imp, Meta::ORG_LAT, true ), 'coordinates kept' );
$ok( false === \DGL\Org\Directory::wants_listing( $imp ), 'imported organisations are not in the directory' );
$ok( [] !== \DGL\Org\Directory::imported_facts( $imp ) && [] === \DGL\Org\Directory::imported_facts( $dir_org ), 'the read-only panel has facts only for imported organisations' );

update_post_meta( $imp, Meta::ORG_IN_DIRECTORY, '1' );
$out = $run_import( '' );
$ok( str_contains( $out, '3 organisations: 0 new, 2 already known, 1 skipped.' ), 'running again updates and does not duplicate' );
$ok( 1 === count( get_posts( [ 'post_type' => PostTypes::ORG, 'post_status' => 'any', 'title' => 'Import Test Trust', 'fields' => 'ids' ] ) ), 'still one trust' );
$ok( true === \DGL\Org\Directory::wants_listing( $imp ), 'and the directory choice is left alone' );

/* ------------------------------------------------------------ empty drafts */

$group( 'Empty drafts: reused, discarded on cancel, purged when stale' );

$wz_org  = $make_org( 'Wizard Org' );
$wz_user = $make_member( 'dgl_wizard', $wz_org, 'owner' );
Access::flush_cache();

$d1 = \DGL\Dashboard\Wizard::create( PostTypes::EVENT, $wz_user );
$d2 = \DGL\Dashboard\Wizard::create( PostTypes::EVENT, $wz_user );
$ok( ! is_wp_error( $d1 ) && $d1 === $d2, 'starting twice reuses the empty draft rather than making two' );
update_post_meta( (int) $d1, DGL_FIXTURE_FLAG, '1' );
$ok( true === \DGL\Dashboard\Wizard::is_empty( (int) $d1 ), 'a fresh draft is empty' );
$ok( true === \DGL\Dashboard\Wizard::discard_if_empty( (int) $d1 ) && null === get_post( (int) $d1 ), 'cancel on an empty draft deletes it' );

$d3 = (int) \DGL\Dashboard\Wizard::create( PostTypes::EVENT, $wz_user );
update_post_meta( $d3, DGL_FIXTURE_FLAG, '1' );
$ok( $d3 !== $d1, 'after a discard a new draft is made' );
wp_update_post( [ 'ID' => $d3, 'post_title' => 'Half written' ] );
$ok( false === \DGL\Dashboard\Wizard::is_empty( $d3 ), 'a draft with a headline is not empty' );
$ok( false === \DGL\Dashboard\Wizard::discard_if_empty( $d3 ) && null !== get_post( $d3 ), 'cancel keeps a draft with anything in it' );
$d4 = (int) \DGL\Dashboard\Wizard::create( PostTypes::EVENT, $wz_user );
update_post_meta( $d4, DGL_FIXTURE_FLAG, '1' );
$ok( $d4 !== $d3, 'a draft with content is not reused for a new one' );
$d5 = (int) \DGL\Dashboard\Wizard::create( PostTypes::NEWS, $wz_user );
update_post_meta( $d5, DGL_FIXTURE_FLAG, '1' );
$ok( $d5 !== $d4, 'an empty event draft is not reused for a news item' );

// Age two of them.
$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ], [ 'ID' => $d4 ] );
$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ], [ 'ID' => $d3 ] );
clean_post_cache( $d4 ); clean_post_cache( $d3 );
$purged = \DGL\Dashboard\Wizard::purge_empty_drafts( 7 );
$ok( $purged >= 1 && null === get_post( $d4 ), 'the stale empty draft is purged' );
$ok( null !== get_post( $d3 ), 'the stale draft with a headline is kept' );
$ok( null !== get_post( $d5 ), 'a fresh empty draft is kept' );

/* ------------------------------------------------------------- directory */

$group( 'Public directory: who is listed, search, filters, the permission switch' );

foreach ( [ 'Dirtest Alpha', 'Dirtest Beta', 'Dirtest Gamma', 'Dirtest Delta', 'Dirtest Epsilon' ] as $stale_name ) {
	foreach ( get_posts( [ 'post_type' => PostTypes::ORG, 'post_status' => 'any', 'title' => $stale_name, 'fields' => 'ids' ] ) as $stale_org ) {
		wp_delete_post( (int) $stale_org, true );
	}
}
$da = $make_org( 'Dirtest Alpha' );   update_post_meta( $da, Meta::ORG_IN_DIRECTORY, '1' );
$db = $make_org( 'Dirtest Beta' );    update_post_meta( $db, Meta::ORG_IN_DIRECTORY, '1' );
$dg = $make_org( 'Dirtest Gamma' );
$dd = $make_org( 'Dirtest Delta', Meta::ORG_PENDING ); update_post_meta( $dd, Meta::ORG_IN_DIRECTORY, '1' );
$de = $make_org( 'Dirtest Epsilon' ); update_post_meta( $de, Meta::ORG_FC_PERMISSION, '1' );
update_post_meta( $da, 'dgl_org_description', 'We run a lunch club for older residents.' );
update_post_meta( $da, 'dgl_org_ward', 'kippax_and_methley' );
update_post_meta( $da, 'dgl_org_specialism', [ 'older_people', 'mental_health' ] );
update_post_meta( $db, 'dgl_org_ward', 'kippax_and_methley' );
update_post_meta( $db, 'dgl_org_specialism', [ 'mental_health' ] );
update_post_meta( $dg, 'dgl_org_description', 'Older people too, but not listed.' );

$run = static fn( array $extra = [] ) => \DGL\Org\DirectoryQuery::run( \DGL\Org\DirectoryQuery::args_from( $extra ) );
$ids = static fn( array $r ): array => $r['ids'];

// The local site also holds the imported list, so every check narrows by name or by a ward nobody in the file used.
$all = $run( [ 'q' => 'Dirtest' ] );
$ok( [ $da, $db ] === $all['ids'], 'listed and verified organisations appear, in name order' );
$ok( ! in_array( $dg, $run( [ 'q' => 'Dirtest Gamma' ] )['ids'], true ), 'an organisation that has not switched itself on does not' );
$ok( ! in_array( $dd, $run( [ 'q' => 'Dirtest Delta' ] )['ids'], true ), 'a pending organisation does not, whatever its flag says' );
$ok( 2 === $all['total'] && 1 === $all['pages'], 'total and page count are filled in' );
$ok( [ $da ] === $ids( $run( [ 'q' => 'lunch club for older residents' ] ) ), 'search finds a word in the description' );
$ok( [ $da ] === $ids( $run( [ 'q' => 'DIRTEST ALPHA' ] ) ), 'search finds the name, any case' );
$ok( [] === $ids( $run( [ 'q' => 'Dirtest Gamma' ] ) ), 'search never finds an unlisted organisation' );
$ok( [ $da, $db ] === $ids( $run( [ 'ward' => 'kippax_and_methley' ] ) ), 'ward filter, in name order' );
$ok( [ $da ] === $ids( $run( [ 'q' => 'Dirtest', 'area' => 'older_people' ] ) ), 'a list filter matches inside the serialised array' );
$ok( [ $da ] === $ids( $run( [ 'q' => 'older', 'ward' => 'kippax_and_methley' ] ) ), 'search and a filter together' );
$ok( [ $da ] === $ids( $run( [ 'q' => 'older', 'area' => 'older_people', 'ward' => 'kippax_and_methley' ] ) ), 'search and two filters together' );
$ok( [] === $ids( $run( [ 'q' => 'older', 'area' => 'mental_health', 'ward' => 'wetherby' ] ) ), 'a filter nobody matches empties the result' );
$ok( [] === \DGL\Org\DirectoryQuery::args_from( [ 'ward' => 'not_a_ward', 'area' => '<script>' ] )['filters'], 'unknown filter values are dropped' );
$ok( 100 === mb_strlen( \DGL\Org\DirectoryQuery::args_from( [ 'q' => str_repeat( 'a', 500 ) ] )['q'] ), 'the search term is capped' );

$found = \DGL\Org\DirectoryQuery::find( get_post( $da )->post_name );
$ok( $found instanceof WP_Post && $found->ID === $da, 'find by slug returns a listed organisation' );
$ok( null === \DGL\Org\DirectoryQuery::find( get_post( $dg )->post_name ), 'and null for an unlisted one' );
$ok( null === \DGL\Org\DirectoryQuery::find( (string) $dd ), 'and null for a pending one by id' );
$ok( str_ends_with( \DGL\Org\DirectoryQuery::url( $da ), '/directory/' . get_post( $da )->post_name . '/' ), 'the public URL uses the slug' );

$switch = \DGL\Org\Directory::list_permission_holders( false );
$ok( in_array( $de, $switch, true ) && ! in_array( $da, $switch, true ), 'dry run names the permission holder not yet listed, not the already listed' );
$ok( false === \DGL\Org\Directory::wants_listing( $de ), 'dry run writes nothing' );
$switch = \DGL\Org\Directory::list_permission_holders( true );
$ok( in_array( $de, $switch, true ) && true === \DGL\Org\Directory::wants_listing( $de ), 'the real run switches it on' );
$ok( [] === array_intersect( [ $de ], \DGL\Org\Directory::list_permission_holders( true ) ), 'and a second run leaves it alone' );
$ok( in_array( 'directory_on', array_column( Log::for_object( 'org', $de ), 'action' ), true ), 'with an audit row' );

/* ----------------------------------------------------------------- report */

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $passed, count( $failures ) );

if ( $failures ) {
	echo "\nFailures:\n";
	foreach ( $failures as $f ) {
		echo "  - {$f}\n";
	}
	exit( 1 );
}
