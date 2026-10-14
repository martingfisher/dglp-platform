<?php
/**
 * Jobs from Voluntary Action Leeds: the pure parts.
 *
 * The fixtures follow the shape of VAL's real answers as fetched on 14
 * October 2026: WP Job Manager's RSS feed, with the vacancy's own fields in
 * a `job_listing:` namespace that is VAL's site address, and the listing
 * endpoint's JSON with the cards as HTML.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Jobs\ValJobs;

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

$feed = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:job_listing="https://val.org.uk"
	>
<channel>
	<title>Voluntary Action Leeds</title>
	<link>https://val.org.uk</link>
	<item>
		<title>Help at Home Support Worker</title>
		<link>https://val.org.uk/job/age-uk-leeds-help-at-home-support-worker/</link>
		<pubDate>Mon, 12 Oct 2026 09:39:21 +0000</pubDate>
		<guid isPermaLink="false">https://val.org.uk/?post_type=job_listing&#038;p=125979</guid>
		<description><![CDATA[<p>Closing Date: Monday 26th October 2026</p>]]></description>
		<content:encoded><![CDATA[<p>The full advert, which is never kept.</p>]]></content:encoded>
		<job_listing:location><![CDATA[Leeds]]></job_listing:location>
		<job_listing:job_type><![CDATA[Part Time]]></job_listing:job_type>
		<job_listing:company><![CDATA[Age UK Leeds]]></job_listing:company>
		<job_listing:salary><![CDATA[£13.45 per hour]]></job_listing:salary>
	</item>
	<item>
		<title>Community Cohesion Worker &#8211; Older People&#8217;s Charity</title>
		<link>https://val.org.uk/job/community-cohesion-worker/</link>
		<pubDate>Fri, 09 Oct 2026 14:00:00 +0000</pubDate>
		<job_listing:location><![CDATA[LS9, Burmantofts &amp; Harehills]]></job_listing:location>
		<job_listing:job_type><![CDATA[Part Time]]></job_listing:job_type>
		<job_listing:company><![CDATA[<b>Burmantofts</b> Community Friends]]></job_listing:company>
		<job_listing:salary><![CDATA[23953]]></job_listing:salary>
	</item>
	<item>
		<title>Somewhere else</title>
		<link>https://example.com/job/phish/</link>
		<job_listing:company><![CDATA[Not VAL]]></job_listing:company>
	</item>
	<item>
		<title>Plain http</title>
		<link>http://val.org.uk/job/plain/</link>
	</item>
	<item>
		<title>   </title>
		<link>https://val.org.uk/job/no-title/</link>
	</item>
	<item>
		<title>Help at Home Support Worker (again)</title>
		<link>https://val.org.uk/job/age-uk-leeds-help-at-home-support-worker/</link>
	</item>
	<item>
		<title>Trustee</title>
		<link>https://val.org.uk/job/trustee/</link>
		<job_listing:company><![CDATA[The Northpoint Trust]]></job_listing:company>
		<job_listing:salary><![CDATA[Voluntary- Unpaid]]></job_listing:salary>
	</item>
</channel>
</rss>
XML;

Harness::group( 'Jobs from VAL: reading the feed' );

$jobs = ValJobs::parse_feed( $feed );

Harness::assert_true( is_array( $jobs ), 'a WP Job Manager feed parses' );
Harness::assert_same( 3, count( (array) $jobs ), 'three jobs: offsite, plain http, untitled and repeated links are dropped' );
Harness::assert_same( 'Help at Home Support Worker', $jobs[0]['title'], 'the first job keeps its title' );
Harness::assert_same( 'https://val.org.uk/job/age-uk-leeds-help-at-home-support-worker/', $jobs[0]['url'], 'and its link to VAL' );
Harness::assert_same( 'Age UK Leeds', $jobs[0]['company'], 'the employer comes from the job_listing namespace' );
Harness::assert_same( 'Leeds', $jobs[0]['location'], 'so does the location' );
Harness::assert_same( 'Part Time', $jobs[0]['type'], 'and the hours' );
Harness::assert_same( '£13.45 per hour', $jobs[0]['salary'], 'a salary in words is left as written' );
Harness::assert_same( '2026-10-12', $jobs[0]['posted'], 'the posted date is kept as a date' );
Harness::assert_same( '', $jobs[0]['logo'], 'no logo until the listings are read' );
Harness::assert_false( isset( $jobs[0]['description'] ) || isset( $jobs[0]['content'] ), 'the advert itself is never kept' );
Harness::assert_same( 'Community Cohesion Worker – Older People’s Charity', $jobs[1]['title'], 'entities in a title are decoded' );
Harness::assert_same( 'LS9, Burmantofts & Harehills', $jobs[1]['location'], 'double-encoded entities too' );
Harness::assert_same( 'Burmantofts Community Friends', $jobs[1]['company'], 'tags are stripped' );
Harness::assert_same( '£23,953', $jobs[1]['salary'], 'a bare number reads as money' );
Harness::assert_same( 'Trustee', $jobs[2]['title'], 'a job with no location or hours still lists' );
Harness::assert_same( '', $jobs[2]['posted'], 'a missing date is blank, not 1970' );

Harness::assert_same( [], ValJobs::parse_feed( '<?xml version="1.0"?><rss version="2.0"><channel><title>VAL</title></channel></rss>' ), 'a feed with no items is an empty list, not a failure' );
Harness::assert_true( null === ValJobs::parse_feed( '<!DOCTYPE html><html><body><h1>Forbidden</h1></body></html>' ), 'a firewall page is a failure' );
Harness::assert_true( null === ValJobs::parse_feed( '' ), 'so is nothing' );
Harness::assert_true( null === ValJobs::parse_feed( '<rss><channel><item>' ), 'and so is half a feed' );
Harness::assert_true( null === ValJobs::parse_feed( '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>' ), 'and something that is not RSS' );

$entity = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><item><title>&x;</title><link>https://val.org.uk/job/x/</link></item></channel></rss>';
$parsed = ValJobs::parse_feed( $entity );
Harness::assert_false( str_contains( (string) ( $parsed[0]['title'] ?? '' ), 'root:' ), 'an external entity is never read' );

$many = '<?xml version="1.0"?><rss version="2.0"><channel>';
for ( $i = 0; $i < 130; $i++ ) {
	$many .= '<item><title>Job ' . $i . '</title><link>https://val.org.uk/job/' . $i . '/</link></item>';
}
$many .= '</channel></rss>';
Harness::assert_same( ValJobs::MAX_JOBS, count( (array) ValJobs::parse_feed( $many ) ), 'no more than the cap is kept' );

Harness::group( 'Jobs from VAL: logos from the listings' );

$listings = json_encode(
	[
		'found_jobs'    => true,
		'max_num_pages' => 1,
		'html'          => '<li class="post-125979 job_listing type-job_listing status-publish"><a href="https://val.org.uk/job/age-uk-leeds-help-at-home-support-worker/"><img class="company_logo" src="https://val.org.uk/wp-content/uploads/job-manager-uploads/company_logo/2026/08/age-uk-150x150.jpg" alt="Age UK Leeds" /><div class="position"><h3>Help at Home Support Worker</h3></div></a></li>'
			. '<li class="post-2 job_listing"><a href="https://val.org.uk/job/trustee/"><img class="company_logo" src="https://val.org.uk/wp-content/plugins/wp-job-manager/assets/images/company.png" alt="" /></a></li>'
			. '<li class="post-3 job_listing"><a href="https://val.org.uk/job/community-cohesion-worker/"><img class="company_logo" src="https://tracker.example.com/pixel.gif" alt="" /></a></li>'
			. '<li class="post-4 job_listing"><a href="https://val.org.uk/job/with-entity/?a=1&amp;b=2"><img class="company_logo" src="https://val.org.uk/logo.png?x=1&amp;y=2" alt="" /></a></li>',
	]
);

$logos = ValJobs::parse_logos( (string) $listings );

Harness::assert_same( 'https://val.org.uk/wp-content/uploads/job-manager-uploads/company_logo/2026/08/age-uk-150x150.jpg', $logos['https://val.org.uk/job/age-uk-leeds-help-at-home-support-worker/'] ?? '', 'a logo is matched to its job by link' );
Harness::assert_false( isset( $logos['https://val.org.uk/job/trustee/'] ), 'the plugin\'s grey placeholder is not a logo' );
Harness::assert_false( isset( $logos['https://val.org.uk/job/community-cohesion-worker/'] ), 'an image from another site is refused' );
Harness::assert_same( 'https://val.org.uk/logo.png?x=1&y=2', $logos['https://val.org.uk/job/with-entity/?a=1&b=2'] ?? '', 'an encoded ampersand in a link still matches' );
Harness::assert_same( [], ValJobs::parse_logos( 'not json' ), 'an answer that is not JSON gives no logos' );
Harness::assert_same( [], ValJobs::parse_logos( '{"html":""}' ), 'nor does an empty list' );

$with = ValJobs::with_logos( (array) $jobs, $logos );
Harness::assert_same( 'https://val.org.uk/wp-content/uploads/job-manager-uploads/company_logo/2026/08/age-uk-150x150.jpg', $with[0]['logo'], 'the job gets its logo' );
Harness::assert_same( '', $with[2]['logo'], 'a job without one stays blank' );

Harness::group( 'Jobs from VAL: what is trusted' );

Harness::assert_same( 'https://val.org.uk/job/x/', ValJobs::safe_url( 'https://val.org.uk/job/x/' ), 'VAL over https is kept' );
Harness::assert_same( 'https://www.val.org.uk/job/x/', ValJobs::safe_url( 'https://www.val.org.uk/job/x/' ), 'so is www' );
Harness::assert_same( '', ValJobs::safe_url( 'http://val.org.uk/job/x/' ), 'plain http is not' );
Harness::assert_same( '', ValJobs::safe_url( 'https://val.org.uk.example.com/job/x/' ), 'a lookalike host is not' );
Harness::assert_same( '', ValJobs::safe_url( 'https://evil@val.org.uk/' ), 'nor credentials in the link' );
Harness::assert_same( '', ValJobs::safe_url( 'https://val.org.uk:8443/' ), 'nor an odd port' );
Harness::assert_same( '', ValJobs::safe_url( 'javascript:alert(1)' ), 'nor a script' );
Harness::assert_same( '', ValJobs::safe_url( 'https://val.org.uk/"><script>' ), 'nor anything that would break out of an attribute' );

Harness::assert_same( 'Plain words', ValJobs::text( "  <em>Plain</em>\n\n words ", 50 ), 'text is one line, no tags' );
Harness::assert_same( 'abcd…', ValJobs::text( 'abcdefghij', 5 ), 'and capped' );

Harness::assert_same( '£30,812.94', ValJobs::salary( '30,812.94' ), 'pence are kept' );
Harness::assert_same( '£27,500', ValJobs::salary( '27500.00' ), 'round pounds drop the pence' );
Harness::assert_same( '£3,000', ValJobs::salary( '3000' ), 'a small sum too' );
Harness::assert_same( '£25,878 FTE', ValJobs::salary( '£25,878 FTE' ), 'anything with words is left alone' );
Harness::assert_same( '', ValJobs::salary( '' ), 'nothing stays nothing' );

Harness::group( 'Jobs from VAL: on the page' );

Harness::assert_same( 'Leeds · Part Time · £13.45 per hour', ValJobs::meta( $jobs[0] ), 'where, hours and pay on one line' );
Harness::assert_same( 'Voluntary- Unpaid', ValJobs::meta( $jobs[2] ), 'blanks are skipped, not doubled up' );
Harness::assert_same( 'A', ValJobs::initial( $jobs[0] ), 'the initial is the employer\'s' );
Harness::assert_same( 'N', ValJobs::initial( $jobs[2] ), 'without a leading "The"' );
Harness::assert_same( 'Y', ValJobs::initial( [ 'company' => '', 'title' => '"Youth" Worker' ] ), 'the title\'s when there is no employer, skipping punctuation' );
Harness::assert_same( 'É', ValJobs::initial( [ 'company' => 'école' ] ), 'and any alphabet' );

Harness::group( 'Jobs from VAL: freshness and schedule' );

$now   = 1_800_000_000;
$store = [ 'jobs' => (array) $jobs, 'fetched' => $now - DAY_IN_SECONDS ];

Harness::assert_same( 3, count( ValJobs::fresh( $store, $now ) ), 'yesterday\'s list is shown' );
Harness::assert_same( 3, count( ValJobs::fresh( $store, $now + 5 * DAY_IN_SECONDS ) ), 'so is one a few nights old, after failed fetches' );
Harness::assert_same( [], ValJobs::fresh( $store, $now + 7 * DAY_IN_SECONDS ), 'a list over a week old is withdrawn' );
Harness::assert_same( [], ValJobs::fresh( [], $now ), 'nothing fetched shows nothing' );
Harness::assert_same( [], ValJobs::fresh( [ 'fetched' => $now, 'jobs' => 'broken' ], $now ), 'a damaged store shows nothing' );

$ok   = [ 'at' => $now, 'ok' => true, 'count' => 3, 'error' => '' ];
$fail = [ 'at' => $now, 'ok' => false, 'count' => 0, 'error' => 'VAL answered with HTTP 403.' ];

Harness::assert_same( ValJobs::WARN, ValJobs::verdict( null, 0, $now ), 'never tried is amber: the first fetch is coming' );
Harness::assert_same( ValJobs::BAD, ValJobs::verdict( $fail, 0, $now ), 'tried and never fetched is red' );
Harness::assert_same( ValJobs::GOOD, ValJobs::verdict( $ok, $now - HOUR_IN_SECONDS, $now ), 'fetched last night is green' );
Harness::assert_same( ValJobs::WARN, ValJobs::verdict( $fail, $now - DAY_IN_SECONDS, $now ), 'one failed night is amber' );
Harness::assert_same( ValJobs::WARN, ValJobs::verdict( $ok, $now - 3 * DAY_IN_SECONDS, $now ), 'three nights without a run is amber' );
Harness::assert_same( ValJobs::BAD, ValJobs::verdict( $fail, $now - 8 * DAY_IN_SECONDS, $now ), 'a withdrawn list is red' );

$london = new DateTimeZone( 'Europe/London' );
$at     = static fn( string $when ): int => ( new DateTimeImmutable( $when, $london ) )->getTimestamp();

Harness::assert_same( $at( '2026-10-15 02:00' ), ValJobs::next_run( $at( '2026-10-14 15:30' ), $london ), 'in the afternoon, the next run is tomorrow at 2am' );
Harness::assert_same( $at( '2026-10-14 02:00' ), ValJobs::next_run( $at( '2026-10-14 01:10' ), $london ), 'just after midnight, it is tonight' );
Harness::assert_same( $at( '2026-10-15 02:00' ), ValJobs::next_run( $at( '2026-10-14 02:00' ), $london ), 'at 2am exactly, it is tomorrow' );
Harness::assert_same( $at( '2026-10-26 02:00' ), ValJobs::next_run( $at( '2026-10-25 12:00' ), $london ), 'and 2am local across the clocks going back' );

Harness::group( 'Jobs from VAL: what a copied logo is called' );

$logo_url = 'https://val.org.uk/wp-content/uploads/job-manager-uploads/company_logo/2026/08/age-uk-150x150.jpg';
Harness::assert_same( md5( $logo_url ) . '.jpg', ValJobs::logo_name( $logo_url ), 'a hash of the address, with its type' );
Harness::assert_same( md5( 'https://val.org.uk/a.JPEG' ) . '.jpg', ValJobs::logo_name( 'https://val.org.uk/a.JPEG' ), 'jpeg is jpg, whatever its case' );
Harness::assert_same( md5( 'https://val.org.uk/a.webp?ver=2' ) . '.webp', ValJobs::logo_name( 'https://val.org.uk/a.webp?ver=2' ), 'a query string does not hide the type' );
Harness::assert_same( '', ValJobs::logo_name( 'https://val.org.uk/logo.svg' ), 'an SVG is not kept: it can carry script' );
Harness::assert_same( '', ValJobs::logo_name( 'https://val.org.uk/logo.php' ), 'nor anything that would run' );
Harness::assert_same( '', ValJobs::logo_name( 'https://example.com/logo.png' ), 'nor a logo from another site' );
Harness::assert_same( '', ValJobs::logo_name( '' ), 'nor nothing' );

Harness::assert_same( 'https://val.org.uk/wp-content/uploads/2026/08/Age-UK-Logo-RGB-768x408-1.jpg', ValJobs::full_size( 'https://val.org.uk/wp-content/uploads/2026/08/Age-UK-Logo-RGB-768x408-1-150x150.jpg' ), 'the original behind a thumbnail, keeping sizes that are part of its own name' );
Harness::assert_same( 'https://val.org.uk/logo.png', ValJobs::full_size( 'https://val.org.uk/logo.png' ), 'an address that is not a thumbnail is left alone' );
