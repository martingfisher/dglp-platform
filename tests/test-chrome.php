<?php
/**
 * The theme furniture stripper.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Chrome;

Harness::group( 'Theme furniture is stripped whole from wp_footer output' );

$page = '<script>a()</script>'
	. '<footer id="footer" class="ct-footer"><div>site<footer class="inner">nested</footer>more</div></footer>'
	. '<script>b()</script>'
	. '<div data-block="hook:1258"><article id="post-1258"><div class="row"><div class="col"><span>Cookies Policy</span></div><div/></div></article></div>'
	. '<div class="ct-drawer-canvas"><div>keep</div></div>'
	. '<script>c()</script>';

$out = Chrome::strip( $page );
Harness::assert_same( '<script>a()</script><script>b()</script><div class="ct-drawer-canvas"><div>keep</div></div><script>c()</script>', $out, 'footer with a nested footer, and a hook block with nested divs, both gone; scripts and other divs kept' );

Harness::assert_same( '<p>x</p>', Chrome::strip( '<p>x</p>' ), 'nothing to strip leaves the markup alone' );
Harness::assert_same( 'before', Chrome::strip( 'before<div data-block="hook:9"><div>never closed' ), 'an unclosed block is cut to the end rather than half left' );
Harness::assert_same( '<div data-block="other">stay</div>', Chrome::strip( '<div data-block="other">stay</div>' ), 'only hook blocks are removed' );
Harness::assert_same( 'ab', Chrome::strip( 'a<div data-block="hook:1"><div/>x</div><div data-block="hook:2">y</div>b' ), 'two hook blocks and a self-closing div inside one' );
Harness::assert_same( '<FOOTER-note>k</FOOTER-note>', Chrome::strip( '<FOOTER-note>k</FOOTER-note>' ), 'a tag that merely starts with footer is not a footer' );
