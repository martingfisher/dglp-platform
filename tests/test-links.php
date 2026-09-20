<?php
/**
 * One rule for typed web addresses.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Schema\Links;

Harness::group( 'Links: a bare domain gets https, a typed scheme is kept' );

Harness::assert_same( 'https://example.com', Links::normalise( 'example.com' ), 'a bare domain gets https://' );
Harness::assert_same( 'https://example.com/path?x=1', Links::normalise( '  example.com/path?x=1  ' ), 'trimmed, path and query kept' );
Harness::assert_same( 'https://example.com', Links::normalise( '//example.com' ), 'a scheme-relative address gets the scheme too' );
Harness::assert_same( 'http://example.com', Links::normalise( 'http://example.com' ), 'http typed on purpose is kept' );
Harness::assert_same( 'HTTPS://Example.com', Links::normalise( 'HTTPS://Example.com' ), 'an existing scheme is left as typed' );
Harness::assert_same( 'javascript:alert(1)', Links::normalise( 'javascript:alert(1)' ), 'a dangerous scheme is not hidden behind https' );
Harness::assert_same( 'mailto:x@y.z', Links::normalise( 'mailto:x@y.z' ), 'nor is mailto' );
Harness::assert_same( '', Links::normalise( '   ' ), 'blank stays blank' );
Harness::assert_same( 'https://localhost:8080/x', Links::normalise( 'localhost:8080/x' ), 'host:port is a host with a port, not a scheme' );
Harness::assert_same( 'https://example.com:443', Links::normalise( 'example.com:443' ), 'so is a domain with a port' );

Harness::assert_true( Links::is_web( 'https://a.b' ) && Links::is_web( 'HTTP://a.b' ) && ! Links::is_web( 'javascript:alert(1)' ) && ! Links::is_web( 'a.b' ), 'is_web knows http and https only' );
Harness::assert_same( 'https://a.b/c', Links::https_twin( 'http://a.b/c' ), 'the https twin of an http address' );
Harness::assert_same( null, Links::https_twin( 'https://a.b/c' ), 'and none for one already https' );
Harness::assert_same( [ 'https://a.b/', 'http://c.d/?x=1&y=2', '#top' ], Links::hrefs( '<p><a href="https://a.b/">a</a> <a href=\'http://c.d/?x=1&amp;y=2\'>c</a> <a href="https://a.b/">again</a> <a href="#top">t</a></p>' ), 'every href once, entities decoded' );

Harness::group( 'Links: plain http is not secure, and is found in the words' );

Harness::assert_true( Links::is_secure( 'https://a.b' ) && Links::is_secure( 'HTTPS://a.b' ) && ! Links::is_secure( 'http://a.b' ) && ! Links::is_secure( 'mailto:x@y.z' ), 'is_secure means https' );
Harness::assert_same( [ 'http://c.d/' ], Links::insecure_hrefs( '<a href="https://a.b/">a</a> <a href="http://c.d/">c</a> <a href="mailto:x@y.z">m</a>' ), 'only the http links are picked out' );
