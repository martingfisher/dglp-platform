<?php
/**
 * Trust settings: what an organisation may publish without review.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Org\TrustSettings;

const T_NEWS  = 'dgl_news';
const T_EVENT = 'dgl_event';
const T_TRAIN = 'dgl_training';
$known  = [ T_NEWS, T_EVENT, T_TRAIN ];
$labels = [ T_NEWS => 'News', T_EVENT => 'Events', T_TRAIN => 'Training' ];

Harness::group( 'Trust settings: reading what is stored' );

$s = TrustSettings::from_meta( [ 'on' => true, 'types' => [ T_NEWS => true, T_EVENT => false ], 'edits' => true ], $known );
Harness::assert_true( $s->is_on() && $s->trusts( T_NEWS ) && ! $s->trusts( T_EVENT ) && ! $s->trusts( T_TRAIN ) && $s->trusts_edits(), 'the full shape reads back' );
Harness::assert_same( [ 'on' => true, 'types' => [ T_EVENT => false, T_NEWS => true, T_TRAIN => false ], 'edits' => true ], $s->to_meta(), 'and writes back with every known type present, keys sorted' );

$s = TrustSettings::from_meta( [ 'on' => false, 'types' => [ T_NEWS => true ], 'edits' => true ], $known );
Harness::assert_false( $s->trusts( T_NEWS ) || $s->trusts_edits(), 'master off clears what is hidden under it' );
Harness::assert_same( 0, $s->legacy_level(), 'and amounts to level 0' );

Harness::assert_true( TrustSettings::from_meta( 2, $known )->trusts( T_TRAIN ) && TrustSettings::from_meta( 2, $known )->trusts_edits(), 'legacy 2 is everything' );
$s = TrustSettings::from_meta( '1', $known );
Harness::assert_true( $s->is_on() && $s->trusts_edits() && ! $s->trusts( T_NEWS ), 'legacy "1" is edits only' );
Harness::assert_false( TrustSettings::from_meta( 0, $known )->is_on(), 'legacy 0 is off' );

foreach ( [ '', null, 'nonsense', [ 'on' => 'yes' ], [ 'on' => 'true', 'edits' => 'true' ], 3.5 ] as $rubbish ) {
	Harness::assert_false( TrustSettings::from_meta( $rubbish, $known )->is_on(), 'rubbish fails closed: ' . var_export( $rubbish, true ) );
}

$s = TrustSettings::from_meta( [ 'on' => true, 'types' => [ 'dgl_grant' => true ] ], $known );
Harness::assert_true( $s->trusts( 'dgl_grant' ), 'a type the site does not offer now is kept' );
Harness::assert_same( 'Trusted: nothing yet', $s->summary( $labels ), 'but is not named in the summary' );

Harness::group( 'Trust settings: which submissions skip review' );

$off = TrustSettings::off();
Harness::assert_false( $off->skips_review( T_NEWS, false ) || $off->skips_review( T_NEWS, true ), 'off never skips' );

$news = new TrustSettings( true, [ T_NEWS => true ], false );
Harness::assert_true( $news->skips_review( T_NEWS, false ), 'trusted for News: a new story skips' );
Harness::assert_true( $news->skips_review( T_NEWS, true ), 'and an edit to a story skips' );
Harness::assert_false( $news->skips_review( T_EVENT, false ), 'a new event does not' );
Harness::assert_false( $news->skips_review( T_EVENT, true ), 'nor an edit to an event' );

$edits = new TrustSettings( true, [], true );
Harness::assert_true( $edits->skips_review( T_EVENT, true ), 'trusted for edits: an edit to anything skips' );
Harness::assert_false( $edits->skips_review( T_EVENT, false ), 'a new item does not' );

$nothing = new TrustSettings( true, [], false );
Harness::assert_false( $nothing->skips_review( T_NEWS, false ) || $nothing->skips_review( T_NEWS, true ), 'master on with nothing under it skips nothing' );

$both = new TrustSettings( true, [ T_NEWS => true ], true );
Harness::assert_same( 'type', $both->why( T_NEWS, true ), 'the type switch is named when both apply' );
Harness::assert_same( 'edits', $both->why( T_EVENT, true ), 'the edits switch when only it applies' );
Harness::assert_same( '', $both->why( T_EVENT, false ), 'nothing when neither' );

Harness::group( 'Trust settings: in words' );

Harness::assert_same( 'Not trusted', $off->summary( $labels ), 'off' );
Harness::assert_same( 'Trusted: nothing yet', $nothing->summary( $labels ), 'on with nothing' );
Harness::assert_same( 'Trusted: everything', ( new TrustSettings( true, [ T_NEWS => true, T_EVENT => true, T_TRAIN => true ], true ) )->summary( $labels ), 'everything' );
Harness::assert_same( 'Trusted: edits only', $edits->summary( $labels ), 'edits only' );
Harness::assert_same( 'Trusted: News, Events', ( new TrustSettings( true, [ T_NEWS => true, T_EVENT => true ], false ) )->summary( $labels ), 'some types' );
Harness::assert_same( 'Trusted: News, Events, and edits', ( new TrustSettings( true, [ T_NEWS => true, T_EVENT => true ], true ) )->summary( $labels ), 'some types and edits' );
Harness::assert_same( 'Trusted: News, Events, Training', ( new TrustSettings( true, [ T_NEWS => true, T_EVENT => true, T_TRAIN => true ], false ) )->summary( $labels ), 'every type but not edits is not "everything"' );

Harness::group( 'Trust settings: the old level, for the index' );

Harness::assert_same( 0, $off->legacy_level(), 'off is 0' );
Harness::assert_same( 1, $edits->legacy_level(), 'edits only is 1' );
Harness::assert_same( 2, $news->legacy_level(), 'any type is 2' );
Harness::assert_same( 0, $nothing->legacy_level(), 'on with nothing is 0' );
Harness::assert_same( 0, $both->with_master( false )->legacy_level(), 'switching the master off is 0' );
Harness::assert_true( $both->with_master( true )->equals( $both ), 'switching the master on keeps what was there' );
Harness::assert_true( TrustSettings::from_legacy( 2, $known )->equals( new TrustSettings( true, [ T_NEWS => true, T_EVENT => true, T_TRAIN => true ], true ) ), 'legacy 2 round-trips' );
Harness::assert_false( $news->equals( $edits ), 'different settings are not equal' );
