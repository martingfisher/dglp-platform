<?php
/**
 * Schema formatting tests.
 *
 * dbDelta() parses CREATE TABLE with a regex, not a SQL parser. Get the
 * whitespace wrong and it silently skips an index instead of erroring, which is
 * exactly the sort of bug that only shows up as "the queue got slow" months
 * later. These assertions pin the formatting it requires.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Audit\Table as AuditTable;
use DGL\Index\ItemsTable;

Harness::group( 'Items index schema' );

$items = ItemsTable::schema();

Harness::assert_same( 'wptest_dgl_items', ItemsTable::name(), 'items table is prefixed' );
Harness::assert_true( str_contains( $items, 'PRIMARY KEY  (post_id)' ), 'PRIMARY KEY has the two spaces dbDelta requires' );
Harness::assert_true( str_contains( $items, 'CREATE TABLE wptest_dgl_items (' ), 'CREATE TABLE names the prefixed table' );
Harness::assert_true( str_ends_with( trim( $items ), 'DEFAULT CHARSET=utf8mb4;' ), 'schema ends with the charset collate' );

foreach ( [ 'org_status', 'queue', 'digest', 'expiry', 'author' ] as $index ) {
	Harness::assert_true(
		(bool) preg_match( '/\n\tKEY ' . $index . ' \([a-z_,]+\)/', $items ),
		'index ' . $index . ' is declared in the dbDelta format'
	);
}

// The indexes have to match the queries, or they are decoration.
Harness::assert_true( str_contains( $items, 'KEY org_status (org_id,status,updated_at)' ), 'org_status index matches the member dashboard query' );
Harness::assert_true( str_contains( $items, 'KEY queue (status,submitted_at)' ), 'queue index matches the moderation queue query' );
Harness::assert_true( str_contains( $items, 'KEY digest (post_type,status,approved_at)' ), 'digest index matches the digest query' );
Harness::assert_true( str_contains( $items, 'KEY expiry (expires_at,status)' ), 'expiry index matches the expiry sweep' );

// Zero dates are rejected under MySQL strict mode, so no column may default to one.
Harness::assert_false( str_contains( $items, "'0000-00-00" ), 'no column defaults to a zero date' );

Harness::group( 'Audit schema' );

$audit = AuditTable::schema();

Harness::assert_same( 'wptest_dgl_audit', AuditTable::name(), 'audit table is prefixed' );
Harness::assert_true( str_contains( $audit, 'PRIMARY KEY  (id)' ), 'audit PRIMARY KEY has the two spaces dbDelta requires' );
Harness::assert_true( str_contains( $audit, 'id bigint(20) unsigned NOT NULL auto_increment' ), 'audit id auto increments' );
Harness::assert_true( str_contains( $audit, 'KEY org_time (org_id,logged_at)' ), 'audit is indexed for per-organisation history' );
Harness::assert_true( str_contains( $audit, 'KEY object (object_type,object_id,logged_at)' ), 'audit is indexed for per-item history' );
Harness::assert_false( str_contains( $audit, "'0000-00-00" ), 'no audit column defaults to a zero date' );

// The IP is pseudonymised, so the column must be a hash width rather than an address.
Harness::assert_true( str_contains( $audit, 'actor_ip_hash char(64)' ), 'the actor address is stored as a sha256 hash, not in the clear' );

Harness::group( 'Volunteering and grants are switched off for this release' );

use DGL\PostTypes;

Harness::assert_same( 5, count( PostTypes::definitions() ), 'all five types still exist in the code' );
Harness::assert_same( 3, count( PostTypes::enabled() ), 'three are enabled' );

Harness::assert_false( PostTypes::is_enabled( PostTypes::VOLUNTEERING ), 'volunteering is off' );
Harness::assert_false( PostTypes::is_enabled( PostTypes::GRANT ), 'grants is off' );
Harness::assert_true( PostTypes::is_enabled( PostTypes::EVENT ), 'events is on' );
Harness::assert_true( PostTypes::is_enabled( PostTypes::NEWS ), 'news is on' );
Harness::assert_true( PostTypes::is_enabled( PostTypes::TRAINING ), 'training is on' );

/*
 * The labels and slugs of a switched-off type have to survive, or any content
 * of that type becomes unlabelled the moment somebody looks at it, and turning
 * the type back on becomes a rebuild rather than a one-line change.
 */
Harness::assert_same( 'Volunteering', PostTypes::definitions()[ PostTypes::VOLUNTEERING ]['plural'], 'a switched-off type keeps its label' );
Harness::assert_same( 'grants', PostTypes::definitions()[ PostTypes::GRANT ]['slug'], 'and its slug' );

Harness::assert_true( in_array( PostTypes::GRANT, PostTypes::submittable(), true ), 'and stays submittable, so permissions and the index still work for anything already stored' );

Harness::assert_same( [ PostTypes::NEWS, PostTypes::EVENT, PostTypes::TRAINING ], PostTypes::enabled_keys(), 'enabled types keep their display order: news, events, training' );

Harness::group( 'Switching one back on is deleting a string' );

add_filter_stub( 'dgl_disabled_types', static fn(): array => [ PostTypes::VOLUNTEERING ] );

Harness::assert_true( PostTypes::is_enabled( PostTypes::GRANT ), 'grants comes back when it is off the list' );
Harness::assert_same( 4, count( PostTypes::enabled() ), 'and the enabled set grows' );
Harness::assert_false( PostTypes::is_enabled( PostTypes::VOLUNTEERING ), 'while volunteering stays off' );

clear_filter_stubs();

Harness::assert_same( 3, count( PostTypes::enabled() ), 'and the default is restored for anything that runs after this' );
