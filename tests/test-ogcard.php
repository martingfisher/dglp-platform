<?php
/**
 * Social card text wrapping: pure, measured by a stand-in.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\OgCard;

Harness::group( 'Social card: wrapping words to a width' );

// Ten pixels a character, so a width of 100 is ten characters.
$w = static fn( string $s ): int => 10 * mb_strlen( $s );

Harness::assert_same( [ 'Coffee', 'morning at', 'the Hub' ], OgCard::wrap( 'Coffee morning at the Hub', 100, $w, 4 ), 'words wrap at the width, never mid-word' );
Harness::assert_same( [ 'Short' ], OgCard::wrap( 'Short', 100, $w, 3 ), 'one line when it fits' );
Harness::assert_same( [], OgCard::wrap( '   ', 100, $w, 3 ), 'nothing from nothing' );
Harness::assert_same( [ 'Supercali…' ], OgCard::wrap( 'Supercalifragilistic', 100, $w, 3 ), 'a word wider than the line is cut with an ellipsis' );
Harness::assert_same( [ 'One two', 'Supercali…', 'three' ], OgCard::wrap( 'One two Supercalifragilistic three', 100, $w, 4 ), 'and sits on its own line' );

$lines = OgCard::wrap( 'one two three four five six seven eight nine ten', 100, $w, 2 );
Harness::assert_same( 2, count( $lines ), 'no more lines than allowed' );
Harness::assert_true( str_ends_with( $lines[1], '…' ), 'and the last one says there is more' );
Harness::assert_true( $w( $lines[1] ) <= 100, 'while still fitting the width' );
Harness::assert_same( [ 'a b c d e' ], OgCard::wrap( "a  b\n c\td   e", 100, $w, 3 ), 'runs of whitespace are one space' );
