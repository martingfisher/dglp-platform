<?php
/**
 * The topic list DGLP decided on: well formed, and the legacy mapping points at it.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Topics\Topics;

Harness::group( 'Topics: the list is the one DGLP signed off' );

$topics = Topics::all();

Harness::assert_same( 28, count( $topics ), 'twenty-eight topics, as on the spreadsheet' );
Harness::assert_same( 'Arts, Culture and Heritage', array_values( $topics )[0], 'in DGLP\'s order, Arts first' );
Harness::assert_same( 'Workforce', array_values( $topics )[27], 'and Workforce last' );
Harness::assert_same( count( $topics ), count( array_unique( array_values( $topics ) ) ), 'no two topics share a name' );

foreach ( $topics as $slug => $name ) {
	Harness::assert_true( 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ), "slug is a public address: {$slug}" );
	Harness::assert_true( '' !== trim( $name ) && trim( $name ) === $name, "name is trimmed and non-empty: {$slug}" );
}

Harness::assert_same( 'mens-health', array_search( "Men's Health", $topics, true ), 'the apostrophe does not reach the slug' );
Harness::assert_same( 'have-your-say', array_search( 'Have your Say (Surveys and consultations)', $topics, true ), 'brackets do not reach the slug' );

Harness::group( 'Topics: every legacy category maps to a topic on the list, or is retired' );

foreach ( Topics::legacy() as $old => $new ) {
	Harness::assert_true( isset( $topics[ $new ] ), "legacy {$old} maps to a listed topic ({$new})" );
}

Harness::assert_same( 9, count( Topics::retired() ), 'nine old categories go' );
Harness::assert_same( [], array_intersect( Topics::retired(), array_keys( Topics::legacy() ) ), 'nothing is both merged and removed' );
Harness::assert_same( [], array_intersect( Topics::retired(), array_keys( $topics ) ), 'nothing removed is also a topic slug' );
Harness::assert_same( 'featured', Topics::legacy()['featured-2'], 'the duplicate Featured folds into the one Featured' );
Harness::assert_same( 'health-and-social-care', Topics::legacy()['forumcentral'], 'Forum Central and Health & Care both become Health and Social Care' );
Harness::assert_same( 'health-and-social-care', Topics::legacy()['health-care'], 'Forum Central and Health & Care both become Health and Social Care' );
Harness::assert_same( 1, Topics::LIST_VERSION, 'list version 1: bump it when the list changes' );
