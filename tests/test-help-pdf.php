<?php
/**
 * The help guide PDF writer: wrapping, encoding and a file a reader opens.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Help\Pdf;

Harness::group( 'Help PDF: lines wrap by measured width, not by character count' );

Harness::assert_same( [ 'iiii iiii', 'WWWW' ], Pdf::wrap( 'iiii iiii WWWW', 10.0, false, 60.0 ), 'narrow letters fit where wide ones do not' );
Harness::assert_same( [ 'one', 'two' ], Pdf::wrap( "one \n two", 10.0, false, 20.0 ), 'whitespace including newlines splits words' );
Harness::assert_same( [ 'averyveryverylongword', 'next' ], Pdf::wrap( 'averyveryverylongword next', 10.0, false, 30.0 ), 'a word wider than the line gets a line of its own rather than vanishing' );
Harness::assert_same( [ '' ], Pdf::wrap( '   ', 10.0, false, 100.0 ), 'blank text is one empty line' );
Harness::assert_true( Pdf::width( 'Hello', 10.0, true ) > Pdf::width( 'Hello', 10.0, false ), 'bold measures wider than regular' );

Harness::group( 'Help PDF: the file' );

$doc = [
	'title'    => 'Member guide',
	'lede'     => 'How the member area works.',
	'sections' => [
		[ 'heading' => 'Signing in', 'blocks' => [ [ 'p', 'Go to the sign-in page. Use the email address you joined with.' ], [ 'ul', [ 'One thing', 'Another thing with a £5 fee and a café' ] ], [ 'ol', [ 'First', 'Second' ] ] ] ],
		[ 'heading' => 'A long section', 'blocks' => array_fill( 0, 40, [ 'p', str_repeat( 'Words that go on for a while so the page fills up and breaks. ', 4 ) ] ) ],
	],
	'faqs'     => [ [ 'q' => 'Can I (really) do this?', 'a' => 'Yes, with a backslash \\ and brackets ().' ] ],
];
$pdf = Pdf::render( $doc, 'Doing Good Leeds Partnership, 19 September 2026' );

Harness::assert_true( str_starts_with( $pdf, '%PDF-1.4' ) && str_ends_with( $pdf, "%%EOF\n" ), 'a PDF header and trailer' );
Harness::assert_true( 1 === preg_match( '/\/Count (\d+)/', $pdf, $m ) && (int) $m[1] >= 3, 'the long section runs to at least three pages (' . ( $m[1] ?? '?' ) . ')' );
Harness::assert_true( str_contains( $pdf, '(Member guide) Tj' ) && str_contains( $pdf, '/F2 20 Tf' ), 'the title is set in bold at 20pt' );
Harness::assert_true( str_contains( $pdf, "(Can I \\(really\\) do this?) Tj" ) && str_contains( $pdf, 'backslash \\\\ and brackets \\(\\)' ), 'brackets and backslashes are escaped so the file parses' );
Harness::assert_true( str_contains( $pdf, "\xA35 fee and a caf\xE9" ), 'pound and accented letters go out in WinAnsi' );
Harness::assert_true( str_contains( $pdf, "(\x95) Tj" ) && str_contains( $pdf, '(1.) Tj' ), 'bullets and numbered steps are drawn' );
Harness::assert_true( str_contains( $pdf, '(Doing Good Leeds Partnership, 19 September 2026) Tj' ) && str_contains( $pdf, '(2) Tj' ), 'the footer and page numbers are on the pages' );

// Every xref offset must point at "N 0 obj", or a reader refuses the file.
preg_match( '/xref\n0 (\d+)\n(.*?)trailer/s', $pdf, $x );
$rows = array_values( array_filter( explode( "\n", trim( $x[2] ?? '' ) ) ) );
$bad  = 0;
foreach ( $rows as $i => $row ) {
	if ( 0 === $i ) {
		continue;
	}
	$offset = (int) substr( $row, 0, 10 );
	if ( ! preg_match( '/^' . $i . ' 0 obj/', substr( $pdf, $offset, 20 ) ) ) {
		++$bad;
	}
}
Harness::assert_same( 0, $bad, 'every cross-reference offset lands on its object (' . count( $rows ) . ' entries)' );
