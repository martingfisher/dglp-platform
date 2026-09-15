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

/*
 * Remove only what previous runs of this suite created. An earlier version
 * deleted every dgl_org and every item on the site, which wiped the demo
 * content sitting alongside it and would have wiped real content just as
 * cheerfully.
 *
 * Fixtures are also cleared before each run rather than after, because a run
 * that fails half way through still has to leave the next one a clean start.
 */
foreach ( [ 'dgl_alice', 'dgl_aaron', 'dgl_bella', 'dgl_mod', 'dgl_pending', 'dgl_susp', 'dgl_carl', 'dgl_tina' ] as $login ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
}

$stale = get_posts(
	[
		'post_type'      => array_merge( PostTypes::submittable(), [ PostTypes::ORG, PostTypes::REVISION ] ),
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'meta_key'       => DGL_FIXTURE_FLAG,
		'meta_value'     => '1',
	]
);

foreach ( $stale as $stale_id ) {
	wp_delete_post( $stale_id, true );
}

global $wpdb;

// The index and audit rows for anything that just went, and nothing else.
if ( ! empty( $stale ) ) {
	$in = implode( ',', array_map( 'intval', $stale ) );
	$wpdb->query( 'DELETE FROM ' . ItemsTable::name() . " WHERE post_id IN ({$in})" );
	$wpdb->query( 'DELETE FROM ' . \DGL\Audit\Table::name() . " WHERE object_id IN ({$in})" );
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

$make_member = static function ( string $login, int $org_id, string $org_role, string $account = 'approved' ): int {
	$id = wp_insert_user(
		[
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => $login . '@example.test',
			'role'       => Roles::MEMBER,
		]
	);
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
$ok( in_array( $w_item, ItemsTable::queue(), true ), 'and it appears in the moderation queue' );

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
