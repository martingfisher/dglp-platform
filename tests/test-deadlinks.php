<?php
/**
 * Dead links: taken out of HTML with their text kept, or pointed elsewhere.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Tools\DeadLinks;

Harness::group( 'Dead links: unlink, rewrite, leave alone' );

$dead   = [ 'https://gone.test/page', 'http://old.test/x' ];
$decide = static function ( string $href ) use ( $dead ): ?string {
	if ( 'https://moved.test/old' === $href ) {
		return 'https://moved.test/new';
	}

	return in_array( $href, $dead, true ) ? '' : null;
};

$r = DeadLinks::strip( '<p>See <a href="https://gone.test/page" target="_blank">the page</a> now.</p>', $decide );
Harness::assert_same( '<p>See the page now.</p>', $r['html'], 'a dead link is unlinked and its text kept' );
Harness::assert_same( [ [ 'href' => 'https://gone.test/page', 'action' => 'unlinked', 'to' => '' ] ], $r['changes'], 'and the change is recorded' );

$r = DeadLinks::strip( '<a href=\'https://gone.test/page\'><strong>Bold</strong> text</a>', $decide );
Harness::assert_same( '<strong>Bold</strong> text', $r['html'], 'single quotes and inner markup are handled' );

$r = DeadLinks::strip( '<a class="x" href="https://gone.test/page?a=1&amp;b=2">t</a>', $decide );
Harness::assert_same( '<a class="x" href="https://gone.test/page?a=1&amp;b=2">t</a>', $r['html'], 'an address that differs once entities are decoded is left alone' );

$r = DeadLinks::strip( '<a href="  https://gone.test/page ">t</a>', $decide );
Harness::assert_same( 't', $r['html'], 'surrounding space in the href is ignored' );

$r = DeadLinks::strip( '<p><a href="https://fine.test/">keep</a> and <a href="https://moved.test/old" rel="noopener">move</a></p>', $decide );
Harness::assert_same( '<p><a href="https://fine.test/">keep</a> and <a href="https://moved.test/new" rel="noopener">move</a></p>', $r['html'], 'a live link stays and a moved one is rewritten with its other attributes kept' );
Harness::assert_same( 'rewritten', $r['changes'][0]['action'], 'the rewrite is recorded' );

$r = DeadLinks::strip( "<a href=\"https://gone.test/page\">one</a>\n<a href=\"http://old.test/x\">two</a>", $decide );
Harness::assert_same( "one\ntwo", $r['html'], 'every dead link in the text goes' );
Harness::assert_same( 2, count( $r['changes'] ), 'two changes' );

$r = DeadLinks::strip( '<a name="anchor">no href</a> <a href="">empty</a>', static fn( string $h ): ?string => '' === $h ? '' : null );
Harness::assert_same( '<a name="anchor">no href</a> empty', $r['html'], 'an anchor without href is untouched; an empty href can be unlinked' );

$r = DeadLinks::strip( '<A HREF="https://gone.test/page">upper</A>', $decide );
Harness::assert_same( 'upper', $r['html'], 'case does not matter' );

Harness::group( 'Dead links: parts of an address' );

Harness::assert_same( 'forumcentral.wordifysites.com', DeadLinks::host_of( 'https://forumcentral.wordifysites.com/health-care/some-story/' ), 'the host' );
Harness::assert_same( 'some-story', DeadLinks::slug_of( 'https://forumcentral.wordifysites.com/health-care/some-story/' ), 'the slug is the last path segment' );
Harness::assert_same( '', DeadLinks::slug_of( 'https://forumcentral.wordifysites.com/' ), 'a bare host has no slug' );
Harness::assert_same( '', DeadLinks::host_of( '/relative/path' ), 'a relative address has no host' );
