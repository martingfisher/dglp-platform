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
use DGL\Org\Trust;
use DGL\PostTypes;
use DGL\Roles;
use DGL\Statuses;

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

$group( 'Fixtures' );

/*
 * Wipe anything left by a previous run first. Without this the second run
 * silently diverges: wp_insert_user() refuses a duplicate login, returns a
 * WP_Error, and every assertion downstream is measuring nonsense. A test suite
 * that only works on a clean database is a test suite you stop trusting.
 */
foreach ( [ 'dgl_alice', 'dgl_aaron', 'dgl_bella', 'dgl_mod', 'dgl_pending', 'dgl_susp', 'dgl_carl' ] as $login ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
}

foreach ( array_merge( PostTypes::submittable(), [ PostTypes::ORG, PostTypes::REVISION ] ) as $type ) {
	foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $stale ) {
		wp_delete_post( $stale, true );
	}
}

global $wpdb;
$wpdb->query( 'DELETE FROM ' . ItemsTable::name() );
$wpdb->query( 'DELETE FROM ' . \DGL\Audit\Table::name() );
Access::flush_cache();

$make_org = static function ( string $name, string $status = Meta::ORG_APPROVED, int $trust = Trust::MODERATED ): int {
	$id = wp_insert_post(
		[
			'post_type'   => PostTypes::ORG,
			'post_title'  => $name,
			'post_status' => 'publish',
		]
	);
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
