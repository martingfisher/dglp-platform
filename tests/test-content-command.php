<?php
/**
 * The exact-text replace behind `wp dgl content replace`.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Tools\ContentCommand;

Harness::group( 'Content replace: exact text, exact count, or nothing' );

$body = '<a href="#">one</a> "content":"Jobs","link":"" <b>two</b> "content":"Jobs","link":""';

$one = ContentCommand::plan( $body, '<b>two</b>', '<b>2</b>' );
Harness::assert_same( 1, $one['count'], 'one match is counted' );
Harness::assert_same( '', $one['error'], 'and accepted at the default of one' );
Harness::assert_same( '<a href="#">one</a> "content":"Jobs","link":"" <b>2</b> "content":"Jobs","link":""', $one['result'], 'only that text changes' );

$two = ContentCommand::plan( $body, '"content":"Jobs","link":""', 'X' );
Harness::assert_same( 2, $two['count'], 'two matches are counted' );
Harness::assert_true( '' !== $two['error'] && $two['result'] === $body, 'two when one is expected is refused and nothing changes' );

$both = ContentCommand::plan( $body, '"content":"Jobs","link":""', 'X', 2 );
Harness::assert_same( '<a href="#">one</a> X <b>two</b> X', $both['result'], 'expecting two replaces both' );

$none = ContentCommand::plan( $body, '<b>TWO</b>', 'x' );
Harness::assert_true( 0 === $none['count'] && '' !== $none['error'] && $none['result'] === $body, 'matching is case-sensitive, and no match is refused' );

$cut = ContentCommand::plan( $body, ' <b>two</b>', '' );
Harness::assert_same( '<a href="#">one</a> "content":"Jobs","link":"" "content":"Jobs","link":""', $cut['result'], 'an empty replacement deletes the text' );

$blank = ContentCommand::plan( $body, '', 'x' );
Harness::assert_true( '' !== $blank['error'] && $blank['result'] === $body, 'an empty search is refused' );

$slashes = ContentCommand::plan( 'a - b', '-', '--' );
Harness::assert_same( 'a -- b', $slashes['result'], 'backslashes in block JSON are matched and kept literally' );

Harness::assert_true( str_contains( $one['context'][0], '[[<b>two</b>]]' ), 'the dry run shows the match in context' );

Harness::group( 'Content replace: a span from one marker to the next' );

$page = 'A <!-- wp:b {"id":"x1"} --><div>one</div><!-- /wp:b --> B <!-- wp:b {"id":"x2"} --><div>two</div><!-- /wp:b --> C';

$span = ContentCommand::plan_span( $page, '<!-- wp:b {"id":"x1"}', '<!-- /wp:b -->', '' );
Harness::assert_same( 'A  B <!-- wp:b {"id":"x2"} --><div>two</div><!-- /wp:b --> C', $span['result'], 'only the block that starts with the marker goes, up to its own closer' );
Harness::assert_same( 1, $span['count'], 'and it is counted once' );

$both = ContentCommand::plan_span( $page, '<!-- wp:b ', '<!-- /wp:b -->', '[b]', 2 );
Harness::assert_same( 'A [b] B [b] C', $both['result'], 'two spans are replaced when two are expected' );

$one = ContentCommand::plan_span( $page, '<!-- wp:b ', '<!-- /wp:b -->', '[b]' );
Harness::assert_true( '' !== $one['error'] && $one['result'] === $page, 'two spans when one is expected is refused' );

$open = ContentCommand::plan_span( $page, '<!-- wp:b {"id":"x2"}', '<!-- /wp:zzz -->', '' );
Harness::assert_true( '' !== $open['error'] && $open['result'] === $page, 'an unclosed span is refused' );

$none = ContentCommand::plan_span( $page, '<!-- wp:b {"id":"x2"}', '', '' );
Harness::assert_true( '' !== $none['error'], 'a missing --to is refused' );
