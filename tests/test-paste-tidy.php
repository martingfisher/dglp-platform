<?php
/**
 * Paste tidy: the styling Word, Google Docs and the old theme left in stories.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Tools\PasteTidy;

Harness::group( 'Paste tidy: Google Docs, Word Online and the old theme' );

$g = PasteTidy::tidy( '<span style="font-weight: 400;">Forum Central strives for healthier lives.</span>' . "\n\n" . '<ul>' . "\n" . ' 	<li style="font-weight: 400;" aria-level="1"><span style="font-weight: 400;">Suppo</span><span style="font-weight: 400;">rt the workforce.</span></li>' . "\n" . '</ul>' . "\n" . '<i><span style="font-weight: 400;">“We have far more in common.”</span></i>' . "\n\n" . '<b>Support and resources</b>' . "\n&nbsp;" );
Harness::assert_same( 'Forum Central strives for healthier lives.' . "\n\n" . '<ul>' . "\n" . ' 	<li>Support the workforce.</li>' . "\n" . '</ul>' . "\n" . '<em>“We have far more in common.”</em>' . "\n\n" . '<strong>Support and resources</strong>', $g['html'], 'a Google Docs paste loses its spans and styles, keeps its words, list, italic and bold, and its trailing spacer' );
Harness::assert_true( $g['changes']['spans'] >= 4 && $g['changes']['bold_italic'] >= 2 && $g['changes']['spacers'] >= 1, 'and counts what it changed' );

$w = PasteTidy::tidy( '<span class="TextRun SCXW72089173 BCX8" data-contrast="auto"><span class="NormalTextRun SCXW72089173 BCX8">Leeds Citizens\' Panel is looking for members, </span><span class="NormalTextRun SCXW72089173 BCX8">do</span></span> and <a href="http://bit.ly/3ULchuz">apply</a>.<span class="EOP SCXW72089173 BCX8" data-ccp-props="{&quot;134233117&quot;:false}"> </span>' );
Harness::assert_same( 'Leeds Citizens\' Panel is looking for members, do and <a href="http://bit.ly/3ULchuz">apply</a>.', $w['html'], 'a Word Online paste is unwrapped, its link kept' );

$a = PasteTidy::tidy( '<h2><span style="font-weight: 400;">The Household Support Fund</span></h2>' . "\n" . '<span style="color: var(--awb-text-color); font-family: var(--awb-text-font-family); font-size: var(--awb-font-size);">Forum Central is administering grants.</span>' );
Harness::assert_same( '<h2>The Household Support Fund</h2>' . "\n" . 'Forum Central is administering grants.', $a['html'], 'the old theme\'s font, size and colour spans go; the heading stays a heading' );

Harness::group( 'Paste tidy: what is kept' );

$k = PasteTidy::tidy( '<p style="text-align: center; font-size: 14pt; color: #333;">Centred</p><p class="MsoNormal has-large-font-size" lang="en-GB">Text<o:p></o:p></p><span style="font-weight: bold; font-family: Calibri;">Bold</span> <span style="font-style: italic">Slanted</span>' );
Harness::assert_same( '<p style="text-align: center;">Centred</p><p class="has-large-font-size">Text</p><strong>Bold</strong> <em>Slanted</em>', $k['html'], 'alignment, the site\'s own classes, and bold and italic from a style are kept; the rest goes' );

$keep = '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="a.jpg" style="width:300px" alt="A" /></figure><!-- /wp:image --><p><i class="fa fa-phone"></i> <span id="contact">Call us</span> <a href="#contact" target="_blank" rel="noopener">here</a></p>';
Harness::assert_same( $keep, PasteTidy::tidy( $keep )['html'], 'block markup, image styles, icons, link targets and link attributes are untouched' );

$sp = PasteTidy::tidy( "<p>One</p>\n<p>&nbsp;</p>\n<p> </p>\n<p>Two</p>" );
Harness::assert_same( "<p>One</p>\n\n\n<p>Two</p>", $sp['html'], 'empty spacer paragraphs go' );
Harness::assert_same( 2, $sp['changes']['spacers'], 'and are counted' );

Harness::assert_true( PasteTidy::needs_tidy( '<span style="font-weight: 400;">x</span>' ) && ! PasteTidy::needs_tidy( '<p>Plain <strong>words</strong> and <a href="x">a link</a>.</p>' ), 'a paste is recognised and clean words are not' );
