<?php
/**
 * What a listing body may contain.
 *
 * The filtering itself is WordPress's kses and is exercised in the integration
 * suite. What is pinned here is the list it is given, because the bug this
 * replaces was a list that was too generous: `wp_kses_post` let a pasted
 * document keep its styling. Anybody widening the list has to change a test
 * that says why it is narrow.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Content;

Harness::group( 'Content: the allowlist is what the toolbar can make' );

$allowed = Content::allowed_html();

Harness::assert_same( [ 'p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a' ], array_keys( $allowed ), 'exactly the toolbar elements, and a paragraph to put them in' );

foreach ( [ 'span', 'div', 'img', 'table', 'h1', 'h2', 'h3', 'iframe', 'script', 'style', 'font' ] as $tag ) {
	Harness::assert_false( isset( $allowed[ $tag ] ), $tag . ' is not allowed' );
}

foreach ( $allowed as $tag => $attrs ) {
	Harness::assert_false( isset( $attrs['style'] ), $tag . ' cannot carry a style attribute' );
	Harness::assert_false( isset( $attrs['class'] ), $tag . ' cannot carry a class' );
	Harness::assert_false( isset( $attrs['id'] ), $tag . ' cannot carry an id' );
}

Harness::assert_same( [ 'href', 'title', 'rel', 'target' ], array_keys( $allowed['a'] ), 'a link keeps its address and how it opens, nothing else' );

Harness::group( 'Content: the editor is told the same list' );

Harness::assert_same(
	'p,br,strong,b,em,i,ul,ol,li,a[href|title|rel|target]',
	Content::editor_valid_elements(),
	'valid_elements is generated from the allowlist, so the two cannot drift'
);
