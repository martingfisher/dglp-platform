<?php
/**
 * Training from Voluntary Action Leeds: the pure parts.
 *
 * The fixtures follow the markup of val.org.uk/training and a course page as
 * fetched on 13 November 2026: a card per course, a row per date, and the
 * description in `pld-tr-details-body`.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Training\ValTraining;

$listing = <<<'HTML'
<html><body><div class="pld-tr-grid">
<div class="pld-tr-card" style="--pld-tr-accent:#4BA42F;">
	<a class="pld-tr-cover" href="https://val.org.uk/training/fire-warden/" aria-label="Fire Warden"></a>
	<div class="pld-tr-body">
		<h3 class="pld-tr-title">Fire Warden</h3>
		<p class="pld-tr-loc">Voluntary Action Leeds, 34 Lupton Street, Leeds, LS10 2QW</p>
		<ul class="pld-tr-dates">
			<li class="pld-tr-date">
				<span class="pld-tr-date-when">Mon 16 Nov 2026, 9:30am – 12:30pm</span>
				<span class="pld-tr-date-meta">£70.00 · 8 left</span>
				<a class="pld-tr-date-btn" href="https://val.org.uk/training-signup/?event=124220&#038;occ=occ_43d4386a55">Book</a>
			</li>
		</ul>
	</div>
</div>
<div class="pld-tr-card">
	<a class="pld-tr-cover" href="https://val.org.uk/training/meet-the-funder/" aria-label="x"></a>
	<div class="pld-tr-body">
		<h3 class="pld-tr-title">Meet the Funder: The Mayor’s Safer Communities Fund (Grant Round 11)</h3>
		<ul class="pld-tr-dates">
			<li class="pld-tr-date">
				<span class="pld-tr-date-when">Tue 17 Nov 2026, 12:00pm – 1:00pm</span>
				<span class="pld-tr-date-meta">Free</span>
			</li>
		</ul>
	</div>
</div>
<div class="pld-tr-card">
	<a class="pld-tr-cover" href="https://val.org.uk/training/food-safety-and-hygiene-level-2/"></a>
	<div class="pld-tr-body">
		<h3 class="pld-tr-title">Food Hygiene Level 2</h3>
		<p class="pld-tr-loc">Voluntary Action Leeds, 34 Lupton Street, Leeds, LS10 2QW</p>
		<ul class="pld-tr-dates">
			<li class="pld-tr-date"><span class="pld-tr-date-when">Wed 31 Mar 2027, 9:30am – 4:00pm</span><span class="pld-tr-date-meta">£100.00 · 11 left</span></li>
			<li class="pld-tr-date"><span class="pld-tr-date-when">Mon 23 Nov 2026, 9:30am – 4:00pm</span><span class="pld-tr-date-meta">£100.00 · Full</span></li>
		</ul>
	</div>
</div>
<div class="pld-tr-card">
	<a class="pld-tr-cover" href="https://example.com/training/phish/"></a>
	<div class="pld-tr-body"><h3 class="pld-tr-title">Somewhere else</h3>
		<ul class="pld-tr-dates"><li class="pld-tr-date"><span class="pld-tr-date-when">Mon 16 Nov 2026</span></li></ul></div>
</div>
<div class="pld-tr-card">
	<a class="pld-tr-cover" href="https://val.org.uk/training/no-dates/"></a>
	<div class="pld-tr-body"><h3 class="pld-tr-title">No dates</h3><ul class="pld-tr-dates"></ul></div>
</div>
<div class="pld-tr-card">
	<a class="pld-tr-cover" href="https://val.org.uk/training/fire-warden/"></a>
	<div class="pld-tr-body"><h3 class="pld-tr-title">Fire Warden again</h3>
		<ul class="pld-tr-dates"><li class="pld-tr-date"><span class="pld-tr-date-when">Mon 16 Nov 2026</span></li></ul></div>
</div>
</div></body></html>
HTML;

Harness::group( 'Training from VAL: reading the listing' );

$courses = ValTraining::parse_listing( $listing );

Harness::assert_true( is_array( $courses ), 'the listing parses' );
Harness::assert_same( 3, count( (array) $courses ), 'three courses: offsite, dateless and repeated links are dropped' );
Harness::assert_same( 'Fire Warden', $courses[0]['title'], 'a course keeps its title' );
Harness::assert_same( 'https://val.org.uk/training/fire-warden/', $courses[0]['url'], 'and its page on VAL' );
Harness::assert_same( 'Voluntary Action Leeds, 34 Lupton Street, Leeds, LS10 2QW', $courses[0]['location'], 'and its venue' );
Harness::assert_same( '2026-11-16', $courses[0]['sessions'][0]['date'], 'a session date is read as a calendar day' );
Harness::assert_same( '£70.00 · 8 left', $courses[0]['sessions'][0]['price'], 'with its price text' );
Harness::assert_same( 'Meet the Funder: The Mayor’s Safer Communities Fund (Grant Round 11)', $courses[1]['title'], 'a title keeps its punctuation' );
Harness::assert_same( '', $courses[1]['location'], 'a course with no venue has none' );
Harness::assert_same( [ '2026-11-23', '2027-03-31' ], array_column( $courses[2]['sessions'], 'date' ), 'sessions are sorted by date' );
Harness::assert_true( $courses[2]['sessions'][0]['full'], 'a full session is marked' );
Harness::assert_false( $courses[2]['sessions'][1]['full'], 'and one with places is not' );

Harness::assert_true( null === ValTraining::parse_listing( '<!DOCTYPE html><html><body><h1>Forbidden</h1></body></html>' ), 'a firewall page is a failure' );
Harness::assert_true( null === ValTraining::parse_listing( '' ), 'so is nothing' );
Harness::assert_true( null === ValTraining::parse_listing( '<html><body><div class="pld-tr-card"><h3 class="pld-tr-title">x</h3></div></body></html>' ), 'and a card with no link or dates' );

Harness::group( 'Training from VAL: dates, cost and delivery' );

Harness::assert_same( '2026-11-16', ValTraining::date_of( 'Mon 16 Nov 2026, 9:30am – 12:30pm' ), 'the day is read from the first line' );
Harness::assert_same( '2027-03-02', ValTraining::date_of( 'Tue 2 Mar 2027, 9:30am – 12:30pm' ), 'a single-digit day' );
Harness::assert_same( '2026-09-01', ValTraining::date_of( 'Tue 1 September 2026' ), 'a long month name' );
Harness::assert_true( null === ValTraining::date_of( 'Tue 31 Feb 2027' ), 'an impossible day is nothing' );
Harness::assert_true( null === ValTraining::date_of( 'Coming soon' ), 'and so is text with no date' );

$sessions = [ [ 'date' => '2026-11-10' ], [ 'date' => '2026-11-23' ], [ 'date' => '2027-03-31' ] ];
Harness::assert_same( [ '2026-11-23', '2027-03-31' ], array_column( ValTraining::upcoming( $sessions, '2026-11-13' ), 'date' ), 'past sessions are left out' );
Harness::assert_same( [ '2026-11-23', '2027-03-31' ], array_column( ValTraining::upcoming( $sessions, '2026-11-23' ), 'date' ), 'a session today still counts' );
Harness::assert_same( [], ValTraining::upcoming( $sessions, '2027-04-01' ), 'a course with nothing to come has no sessions' );

Harness::assert_same( [ 'cost' => 'paid', 'detail' => '£70.00' ], ValTraining::cost_of( '£70.00 · 8 left' ), 'a price is paid, with the figure' );
Harness::assert_same( [ 'cost' => 'paid', 'detail' => '£1,200.00' ], ValTraining::cost_of( '£1,200.00' ), 'with a thousands comma' );
Harness::assert_same( [ 'cost' => 'free', 'detail' => '' ], ValTraining::cost_of( 'Free · Full' ), 'free is free, full or not' );
Harness::assert_same( [ 'cost' => 'free', 'detail' => '' ], ValTraining::cost_of( '£0.00' ), 'a nil price is free' );
Harness::assert_same( [ 'cost' => 'free', 'detail' => '' ], ValTraining::cost_of( '' ), 'no price text is free' );
Harness::assert_same( 'donation', ValTraining::cost_of( 'By donation' )['cost'], 'a donation is a donation' );

Harness::assert_same( 'in_person', ValTraining::delivery_of( 'Voluntary Action Leeds, 34 Lupton Street, Leeds, LS10 2QW' ), 'a street address is in person' );
Harness::assert_same( 'online', ValTraining::delivery_of( 'Online' ), 'online is online' );
Harness::assert_same( 'online', ValTraining::delivery_of( '' ), 'no venue is a webinar' );
Harness::assert_same( 'online', ValTraining::delivery_of( 'Zoom' ), 'so is Zoom' );
Harness::assert_same( 'LS10 2QW', ValTraining::postcode_of( 'Voluntary Action Leeds, 34 Lupton Street, Leeds, LS10 2QW' ), 'a postcode is found' );
Harness::assert_same( 'LS10 2QW', ValTraining::postcode_of( 'Stringer House 34 Lupton Street LS102QW' ), 'even without its space' );
Harness::assert_same( '', ValTraining::postcode_of( 'Online' ), 'and none where there is none' );

Harness::group( 'Training from VAL: the fields VAL is the source for' );

$fields = ValTraining::schedule_fields( $courses[2], '2026-11-13' );
Harness::assert_same( '2026-11-23', $fields['dgl_start_date'], 'the start is the next session' );
Harness::assert_same( '', $fields['dgl_end_date'], 'with no end: it is one day, not a range' );
Harness::assert_same( 'paid', $fields['dgl_cost'], 'the price is the next session\'s' );
Harness::assert_same( '£100.00', $fields['dgl_cost_detail'], 'with its figure' );
Harness::assert_same( 'in_person', $fields['dgl_delivery'], 'the delivery follows the venue' );
Harness::assert_same( 'LS10 2QW', $fields['dgl_postcode'], 'the postcode follows the venue' );
Harness::assert_same( 'https://val.org.uk/training/food-safety-and-hygiene-level-2/', $fields['dgl_booking_url'], 'booking goes to VAL\'s page, which lists every date' );
Harness::assert_same( '2027-03-31', ValTraining::schedule_fields( $courses[2], '2026-11-24' )['dgl_start_date'], 'once the first session has gone, the next one takes over' );
Harness::assert_true( null === ValTraining::schedule_fields( $courses[2], '2027-04-01' ), 'a course with nothing to come has no fields' );
Harness::assert_same( '', ValTraining::schedule_fields( $courses[1], '2026-11-13' )['dgl_location'], 'a webinar has no location' );
Harness::assert_same( 'online', ValTraining::schedule_fields( $courses[1], '2026-11-13' )['dgl_delivery'], 'and is online' );
Harness::assert_same( 'free', ValTraining::schedule_fields( $courses[1], '2026-11-13' )['dgl_cost'], 'and free' );

Harness::group( 'Training from VAL: topics' );

Harness::assert_same( [ 'professional-development', 'workforce' ], ValTraining::topics_for( 'Fire Warden' ), 'a safety course is professional development and workforce' );
Harness::assert_same( [ 'professional-development', 'grants-and-funding' ], ValTraining::topics_for( 'Applying for Small Grants' ), 'a grants course is grants and funding' );
Harness::assert_same( [ 'professional-development', 'grants-and-funding' ], ValTraining::topics_for( 'Find a Funder: Using Funding Leeds Well' ), 'two words for the same topic count once' );
Harness::assert_same( [ 'professional-development', 'leadership' ], ValTraining::topics_for( 'Roles and Responsibilities of Trustees' ), 'trustees are leadership' );
Harness::assert_same( [ 'professional-development' ], ValTraining::topics_for( 'Something unexpected' ), 'anything else is professional development alone' );

Harness::group( 'Training from VAL: reading a course page' );

$page = <<<'HTML'
<html><body><div class="pld-tr-details"><p class="pld-tr-details-loc"><strong>Location:</strong> Voluntary Action Leeds</p><div class="pld-tr-details-body"><p>This accredited Fire Warden and Fire Marshal Training Course is designed for anyone responsible for workplace fire safety.</p>
<div>
<p>The course covers the latest <b>fire safety</b> legislation. <a href="https://val.org.uk/about/">More</a> and <a href="http://evil.example/x">this</a> and <a href="javascript:alert(1)">that</a>.</p>
<p><strong>Course Aims</strong></p>
<ul>
<li>Understand the role of a Fire Warden.</li>
<li>Learn how fires start.</li>
</ul>
<p><strong>Who Should Attend?</strong></p>
<p>Suitable for anyone appointed as a Fire Warden or Fire Marshal.</p>
<script>alert(1)</script><p onclick="x()" style="color:red">Plain.</p><img src="https://val.org.uk/x.png"><p>&nbsp;</p>
</div>
</div></div></body></html>
HTML;

$course = ValTraining::parse_course( $page );

Harness::assert_true( is_array( $course ), 'a course page parses' );
Harness::assert_same( 'This accredited Fire Warden and Fire Marshal Training Course is designed for anyone responsible for workplace fire safety.', $course['summary'], 'the summary is the opening paragraph' );
Harness::assert_same( 'Suitable for anyone appointed as a Fire Warden or Fire Marshal.', $course['who'], 'who it is for is the paragraph after "Who Should Attend?"' );
Harness::assert_true( str_contains( $course['body'], '<ul>' ) && str_contains( $course['body'], '<li>Learn how fires start.</li>' ), 'lists are kept' );
Harness::assert_true( str_contains( $course['body'], '<strong>fire safety</strong>' ), 'bold is kept, as strong' );
Harness::assert_true( str_contains( $course['body'], '<a href="https://val.org.uk/about/">More</a>' ), 'a link to VAL is kept' );
Harness::assert_false( str_contains( $course['body'], 'evil.example' ) || str_contains( $course['body'], 'javascript' ), 'a link anywhere else is not' );
Harness::assert_true( str_contains( $course['body'], 'this</p>' ) || str_contains( $course['body'], ' this ' ), 'but its words stay' );
Harness::assert_false( str_contains( $course['body'], 'script' ) || str_contains( $course['body'], 'alert' ), 'scripts go' );
Harness::assert_false( str_contains( $course['body'], 'onclick' ) || str_contains( $course['body'], 'style=' ), 'attributes go' );
Harness::assert_false( str_contains( $course['body'], '<img' ), 'pictures go' );
Harness::assert_false( str_contains( $course['body'], '<div' ), 'wrappers go' );
Harness::assert_true( null === ValTraining::parse_course( '<html><body><p>No description here</p></body></html>' ), 'a page with no description is nothing' );

Harness::assert_same( 'Voluntary Action Leeds', $course['location'], 'the venue on the course page is read, without its label' );
Harness::assert_same( '', ValTraining::parse_course( '<div class="pld-tr-details-body"><p>No venue given on this one.</p></div>' )['location'], 'a page with no venue gives none' );
Harness::assert_false( str_contains( $course['body'], "</strong>\n</p>" ), 'no stray line breaks inside a paragraph' );

$no_who = ValTraining::parse_course( '<div class="pld-tr-details-body"><p>Just a description that is quite long enough to be a summary on its own line.</p></div>' );
Harness::assert_same( '', $no_who['who'], 'a page with no "who should attend" has none' );

$long = ValTraining::parse_course( '<div class="pld-tr-details-body"><p>' . str_repeat( 'Word ', 120 ) . '</p></div>' );
Harness::assert_true( mb_strlen( $long['summary'] ) <= 300, 'a long opening is cut to fit the summary field' );
Harness::assert_true( str_ends_with( $long['summary'], '…' ), 'with an ellipsis' );

Harness::group( 'Training from VAL: choosing a summary' );

Harness::assert_same( 'A proper opening sentence that says what the course is and who runs it.', ValTraining::summary_from( [ 'Learning Outcomes', 'Stay Safe and Confident', 'A proper opening sentence that says what the course is and who runs it.' ] ), 'headings and tag-lines are passed over' );
Harness::assert_same( '', ValTraining::summary_from( [ 'Course Aims', 'Short.' ] ), 'nothing long enough is nothing' );
Harness::assert_same( 'The words that follow the venue line say what the course is about and who it suits.', ValTraining::summary_from( [ 'Location: Voluntary Action Leeds, 34 Lupton Street, Leeds, LS10 2QW', 'The words that follow the venue line say what the course is about and who it suits.' ] ), 'a venue line is not a summary' );
Harness::assert_same( 'Loose text that sits straight in the page with no paragraph tag around it at all.', ValTraining::summary_from( [ '', 'Loose text that sits straight in the page with no paragraph tag around it at all.' ] ), 'loose text lines count' );
$loose = ValTraining::parse_course( '<div class="pld-tr-details-body"><p>Short tag-line</p>Professional boundaries are not barriers: they are the foundation of trust, respect and clarity.<br><ul><li>One</li></ul></div>' );
Harness::assert_same( 'Professional boundaries are not barriers: they are the foundation of trust, respect and clarity.', $loose['summary'], 'a page with its words outside a paragraph still gets a summary' );

Harness::group( 'Training from VAL: telling somebody when it fails' );

Harness::assert_true( ValTraining::should_alert( false, 0, false ), 'a failed night is emailed' );
Harness::assert_true( ValTraining::should_alert( true, 2, false ), 'so is a night when some course pages could not be read' );
Harness::assert_false( ValTraining::should_alert( true, 0, false ), 'a good night is not' );
Harness::assert_false( ValTraining::should_alert( false, 0, true ), 'a second bad night in a row is not emailed again' );
Harness::assert_false( ValTraining::should_alert( true, 1, true ), 'nor is a second partly bad night' );

Harness::group( 'Training from VAL: links' );

Harness::assert_same( 'https://val.org.uk/training/x/', ValTraining::safe_url( 'https://val.org.uk/training/x/' ), 'VAL over https is kept' );
Harness::assert_same( 'https://www.val.org.uk/x', ValTraining::safe_url( 'https://www.val.org.uk/x' ), 'www too' );
Harness::assert_same( '', ValTraining::safe_url( 'http://val.org.uk/x' ), 'plain http is not' );
Harness::assert_same( '', ValTraining::safe_url( 'https://val.org.uk.evil.example/x' ), 'a look-alike host is not' );
Harness::assert_same( '', ValTraining::safe_url( 'https://evil.example/https://val.org.uk/' ), 'nor a link that only mentions VAL' );
Harness::assert_same( '', ValTraining::safe_url( 'javascript:alert(1)' ), 'nor a script' );
