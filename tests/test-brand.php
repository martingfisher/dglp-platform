<?php
/**
 * Brand token tests.
 *
 * Contrast is a correctness property, not a matter of taste. Asserting it here
 * means a future palette tweak that breaks WCAG AA fails the build rather than
 * reaching a member who cannot read the result.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Brand;
use DGL\Statuses;

const AA_NORMAL = 4.5;
const AAA_NORMAL = 7.0;
const AA_LARGE  = 3.0;

Harness::group( 'Status chips clear WCAG AAA' );

$chips = Brand::status_colours();

Harness::assert_same( 7, count( $chips ), 'every status has a chip colour pair' );

foreach ( Statuses::all() as $status ) {
	Harness::assert_true( isset( $chips[ $status ] ), 'status ' . $status . ' has a chip pair' );
}

foreach ( $chips as $status => $pair ) {
	$ratio = Brand::contrast( $pair['fg'], $pair['bg'] );
	Harness::assert_true(
		$ratio >= AAA_NORMAL,
		sprintf( '%s chip is %.2f:1, needs %.1f:1', Statuses::label( $status ), $ratio, AAA_NORMAL )
	);
}

Harness::group( 'Interactive colours carry their text' );

$palette = Brand::palette();
$roles   = Brand::roles();
$white   = $palette[8];

$primary_ratio = Brand::contrast( $white, $palette[ $roles['primary'] ] );
Harness::assert_true(
	$primary_ratio >= AA_NORMAL,
	sprintf( 'white on the primary button is %.2f:1', $primary_ratio )
);

$link_ratio = Brand::contrast( $palette[ $roles['link'] ], $palette[ $roles['page'] ] );
Harness::assert_true(
	$link_ratio >= AA_NORMAL,
	sprintf( 'link colour on the page background is %.2f:1', $link_ratio )
);

$body_ratio = Brand::contrast( $palette[ $roles['ink'] ], $palette[ $roles['page'] ] );
Harness::assert_true(
	$body_ratio >= AA_NORMAL,
	sprintf( 'body copy on the page background is %.2f:1', $body_ratio )
);

$teal_solid_ratio = Brand::contrast( $white, $palette[ $roles['teal-solid'] ] );
Harness::assert_true(
	$teal_solid_ratio >= AA_NORMAL,
	sprintf( 'white on the solid teal is %.2f:1', $teal_solid_ratio )
);

Harness::group( 'The signature teal stays decorative' );

/*
 * This is a regression guard rather than a style opinion. The teal is the
 * colour DGLP think of as theirs, so the pull to use it for a button will be
 * constant. It fails AA against both white and the page background, so if it
 * ever appears in a text-bearing role this test should fail loudly.
 */
$teal = $palette[ $roles['decorative'] ];

Harness::assert_true( Brand::contrast( $white, $teal ) < AA_NORMAL, 'the teal genuinely fails white text, so the guard below is warranted' );
Harness::assert_same( 'decorative', array_search( 1, $roles, true ), 'palette slot 1 is only ever the decorative role' );

foreach ( [ 'primary', 'link', 'ink', 'danger', 'teal-solid' ] as $text_role ) {
	Harness::assert_true(
		Brand::contrast( $white, $palette[ $roles[ $text_role ] ] ) >= AA_LARGE,
		'the ' . $text_role . ' role is dark enough to carry white text'
	);
}

Harness::group( 'Contrast maths agrees with the published figures' );

// Spot checks against independently computed values, so a bug in the helper shows up.
Harness::assert_same( 21.0, round( Brand::contrast( '#ffffff', '#000000' ), 1 ), 'black on white is 21:1' );
Harness::assert_same( 1.0, round( Brand::contrast( '#57b0b6', '#57b0b6' ), 1 ), 'a colour against itself is 1:1' );
Harness::assert_same( 10.77, round( Brand::contrast( '#ffffff', '#2d3b6b' ), 2 ), 'deep navy measures 10.77:1' );
Harness::assert_same( 2.53, round( Brand::contrast( '#ffffff', '#57b0b6' ), 2 ), 'teal measures 2.53:1' );

Harness::group( 'Stylesheet matches the PHP tokens' );

$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/dashboard.css' );

Harness::assert_true( '' !== $css, 'dashboard.css is readable' );

foreach ( $chips as $status => $pair ) {
	Harness::assert_true(
		str_contains( $css, 'background: ' . $pair['bg'] . '; color: ' . $pair['fg'] . ';' ),
		'dashboard.css carries the ' . Statuses::label( $status ) . ' pair unchanged'
	);
}

/*
 * The tokens live in tokens.css and nowhere else. dashboard.css carried a
 * second copy that loaded later and won, so a token changed in tokens.css
 * changed nothing in the member area. A :root block reappearing here is
 * that bug coming back.
 */
Harness::assert_false( str_contains( $css, ':root' ), 'dashboard.css declares no tokens of its own' );

$tokens = (string) file_get_contents( dirname( __DIR__ ) . '/assets/tokens.css' );

Harness::assert_true( '' !== $tokens, 'tokens.css is readable' );

// The fallbacks in the stylesheet must be the real palette, not a stale copy.
foreach ( [ 1, 3, 4, 5, 8, 10, 14, 15, 18 ] as $slot ) {
	Harness::assert_true(
		str_contains( $tokens, 'var(--theme-palette-color-' . $slot . ', ' . $palette[ $slot ] . ')' ),
		'palette slot ' . $slot . ' reads from Blocksy with the live hex as fallback'
	);
}

Harness::assert_true( str_contains( $tokens, '--dgl-primary: var(--dgl-navy);' ), 'the primary action colour is deep navy, not teal' );
Harness::assert_false( str_contains( $tokens, '--dgl-primary: var(--dgl-teal)' ), 'the teal is never the primary action colour' );
