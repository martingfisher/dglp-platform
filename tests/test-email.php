<?php
/**
 * Transactional email: copy, routing and the plain-text alternative.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Email\Context;
use DGL\Email\Copy;
use DGL\Email\Message;
use DGL\Email\Routing;
use DGL\Statuses;
use DGL\Workflow\Plan;
use DGL\Workflow\Planner;
use DGL\Workflow\StateMachine;

$ctx = static fn( array $o = [] ): Context => new Context(
	title: $o['title'] ?? 'Coffee morning at the Hub',
	type_label: $o['type_label'] ?? 'Event',
	org_name: $o['org_name'] ?? 'Leeds Community Trust',
	actor_name: $o['actor_name'] ?? 'Jo Bloggs',
	site_name: $o['site_name'] ?? 'Doing Good Leeds Partnership',
	item_url: $o['item_url'] ?? 'https://example.test/dashboard/item/12',
	review_url: $o['review_url'] ?? 'https://example.test/dashboard/review/12',
	public_url: $o['public_url'] ?? '',
	queue_url: $o['queue_url'] ?? 'https://example.test/dashboard/review',
	expires_on: $o['expires_on'] ?? '',
	note: $o['note'] ?? '',
	trusted_for: $o['trusted_for'] ?? '',
);

/*
 * The test that matters most. Every plan the workflow can produce names an
 * audience and a message key. If copy is missing for one of those pairs, that
 * notification silently never sends, and nobody finds out until a member asks
 * why they were not told their content was rejected.
 */
Harness::group( 'Every notification the workflow plans has words written for it' );

$pairs = [];

foreach ( array_keys( StateMachine::table() ) as $action ) {
	foreach ( Statuses::all() as $from ) {
		foreach ( [ false, true ] as $trust ) {
			foreach ( [ true, false ] as $staff ) {
				$plan = Planner::plan( $action, $from, $trust, $staff );

				if ( null === $plan || '' === $plan->message_key ) {
					continue;
				}

				foreach ( $plan->notify as $audience ) {
					$pairs[ $plan->message_key . '/' . $audience ] = [ $plan->message_key, $audience ];
				}
			}
		}
	}
}

Harness::assert_true( count( $pairs ) > 0, 'the planner produces notifications to check' );

foreach ( $pairs as $label => [ $key, $audience ] ) {
	$message = Copy::compose( $key, $audience, $ctx() );

	Harness::assert_true( $message instanceof Message, $label . ' has copy' );

	if ( ! $message instanceof Message ) {
		continue;
	}

	Harness::assert_true( '' !== trim( $message->subject ), $label . ' has a subject' );
	Harness::assert_true( [] !== $message->paragraphs, $label . ' says something' );
	Harness::assert_true( $message->has_cta(), $label . ' gives one thing to click' );
}

Harness::group( 'Copy fails loudly rather than inventing a fallback' );

Harness::assert_same( null, Copy::compose( 'no_such_message', Plan::NOTIFY_MEMBER, $ctx() ), 'an unknown key returns null' );
Harness::assert_same( null, Copy::compose( 'restored', Plan::NOTIFY_MEMBER, $ctx() ), 'restore tells the team, not the member' );
Harness::assert_true( Copy::compose( 'restored', Plan::NOTIFY_MODERATORS, $ctx() ) instanceof Message, 'and the team version exists' );

Harness::group( 'The same event reads differently to each audience' );

$member = Copy::compose( 'taken_down', Plan::NOTIFY_MEMBER, $ctx() );
$team   = Copy::compose( 'taken_down', Plan::NOTIFY_MODERATORS, $ctx() );

Harness::assert_true( $member->subject !== '' && $team->subject !== '', 'both have subjects' );
Harness::assert_true( $member->paragraphs !== $team->paragraphs, 'a take-down does not say the same thing to both' );
Harness::assert_same( 'https://example.test/dashboard/item/12', $member->cta_url, 'the member is sent to their own item' );
Harness::assert_same( 'https://example.test/dashboard/review/12', $team->cta_url, 'the team are sent to the review screen' );

Harness::group( 'Links point somewhere that exists' );

$approved = Copy::compose( 'approved', Plan::NOTIFY_MEMBER, $ctx( [ 'public_url' => '' ] ) );
Harness::assert_same(
	'https://example.test/dashboard/item/12',
	$approved->cta_url,
	'with no public page yet, the member goes to the dashboard rather than a 404'
);

$approved = Copy::compose( 'approved', Plan::NOTIFY_MEMBER, $ctx( [ 'public_url' => 'https://example.test/events/coffee/' ] ) );
Harness::assert_same( 'https://example.test/events/coffee/', $approved->cta_url, 'and to the live page once there is one' );

$team = Copy::compose( 'submitted', Plan::NOTIFY_MODERATORS, $ctx( [ 'review_url' => '' ] ) );
Harness::assert_same( 'https://example.test/dashboard/review', $team->cta_url, 'a missing review link falls back to the queue' );

Harness::group( 'Facts are left out rather than printed empty' );

$full = Copy::compose( 'expired', Plan::NOTIFY_MEMBER, $ctx( [ 'expires_on' => '30 September 2026' ] ) );
Harness::assert_true( in_array( '30 September 2026', array_values( $full->facts ), true ), 'an expiry date is shown' );

$bare = Copy::compose( 'expired', Plan::NOTIFY_MEMBER, $ctx( [ 'expires_on' => '', 'org_name' => '' ] ) );
Harness::assert_false( in_array( '', array_values( $bare->facts ), true ), 'nothing blank is listed' );

Harness::group( 'Missing detail degrades to something readable' );

$empty = $ctx( [ 'title' => '', 'type_label' => '', 'org_name' => '', 'site_name' => '' ] );

Harness::assert_same( 'Untitled submission', $empty->title(), 'a missing title has a stand-in' );
Harness::assert_same( 'submission', $empty->type(), 'so does a missing type' );
Harness::assert_same( 'submission', $empty->type_lower(), 'lower case too' );
Harness::assert_true( str_contains( Copy::compose( 'approved', Plan::NOTIFY_MEMBER, $empty )->subject, 'Untitled submission' ), 'and the email still sends with sense in it' );

Harness::group( 'The note is carried, not summarised' );

$note = "Please add a contact email.\n\nThe venue address is also missing.";
$m    = Copy::compose( 'changes_requested', Plan::NOTIFY_MEMBER, $ctx( [ 'note' => $note ] ) );

Harness::assert_true( $m->has_note(), 'a change request carries the moderator note' );
Harness::assert_same( $note, $m->note, 'word for word' );
Harness::assert_true( str_contains( $m->to_text(), 'Please add a contact email.' ), 'and it reaches the plain-text version' );
Harness::assert_false( Copy::compose( 'expired', Plan::NOTIFY_MEMBER, $ctx() )->has_note(), 'expiry has nobody to quote' );

Harness::group( 'The plain-text alternative is the whole message' );

$m    = Copy::compose( 'changes_requested', Plan::NOTIFY_MEMBER, $ctx( [ 'note' => 'Add a contact email.' ] ) );
$text = $m->to_text();

Harness::assert_true( str_contains( $text, $m->heading ), 'the heading is there' );
Harness::assert_true( str_contains( $text, 'Add a contact email.' ), 'the note is there' );
Harness::assert_true( str_contains( $text, $m->cta_url ), 'the link is there as a URL, not a button' );
Harness::assert_true( str_contains( $text, 'Leeds Community Trust' ), 'the facts are there' );
Harness::assert_false( str_contains( $text, '<' ), 'and no markup leaked into it' );

Harness::group( 'Staging cannot reach a real member' );

$to = [ 'member@charity.test', 'owner@charity.test' ];

Harness::assert_same( $to, Routing::deliver_to( $to, '' ), 'with no redirect, mail goes where it was addressed' );
Harness::assert_same( [ 'martin@example.test' ], Routing::deliver_to( $to, 'martin@example.test' ), 'with one, it goes nowhere else' );
Harness::assert_same( [ 'martin@example.test' ], Routing::deliver_to( [], 'martin@example.test' ), 'even when there were no recipients' );
Harness::assert_true( Routing::is_diverted( 'martin@example.test' ), 'a redirect is a diversion' );
Harness::assert_false( Routing::is_diverted( '   ' ), 'whitespace is not' );
Harness::assert_same( [ 'a@b.test' ], Routing::deliver_to( [ 'a@b.test', 'A@b.test ', 'a@b.test' ], '' ), 'duplicates and padding are cleaned up' );
Harness::assert_same( [ 'a@b.test' ], Routing::deliver_to( [ 'a@b.test', '', '  ' ], '' ), 'and blanks dropped' );

Harness::group( 'A diverted email still says who it was for' );

Harness::assert_same( 'Approved: X', Routing::subject( 'Approved: X', [ 'a@b.test' ], '' ), 'a live site leaves the subject alone' );
Harness::assert_same(
	'[DIVERTED: a@b.test] Approved: X',
	Routing::subject( 'Approved: X', [ 'a@b.test' ], 'me@test.test' ),
	'a diverted one names the intended recipient'
);
Harness::assert_same(
	'[DIVERTED: a@b.test, c@d.test, e@f.test +2 more] Approved: X',
	Routing::subject( 'Approved: X', [ 'a@b.test', 'c@d.test', 'e@f.test', 'g@h.test', 'i@j.test' ], 'me@test.test' ),
	'a long list is truncated rather than burying the subject'
);
Harness::assert_same(
	'[DIVERTED: no recipients] Approved: X',
	Routing::subject( 'Approved: X', [], 'me@test.test' ),
	'and an empty list is stated rather than hidden'
);

Harness::group( 'Addressing a message does not change it' );

$m = Copy::compose( 'approved', Plan::NOTIFY_MEMBER, $ctx() );
$a = $m->for_recipients( [ 'x@y.test' ] );

Harness::assert_same( [], $m->to, 'the original is untouched' );
Harness::assert_same( [ 'x@y.test' ], $a->to, 'the copy is addressed' );
Harness::assert_same( $m->subject, $a->subject, 'and says exactly the same thing' );
Harness::assert_same( $m->note, $a->note, 'note included' );
Harness::assert_same( [ 'x@y.test' ], $a->with_subject( 'New' )->to, 'a subject change keeps the recipients' );
Harness::assert_same( 'New', $a->with_subject( 'New' )->subject, 'and changes the subject' );

Harness::group( 'The article matches the type' );

Harness::assert_same( 'an event', $ctx( [ 'type_label' => 'Event' ] )->type_with_article(), 'an event' );
Harness::assert_same( 'a news item', $ctx( [ 'type_label' => 'News item' ] )->type_with_article(), 'a news item' );
Harness::assert_same( 'a grant', $ctx( [ 'type_label' => 'Grant' ] )->type_with_article(), 'a grant' );
Harness::assert_same( 'a training opportunity', $ctx( [ 'type_label' => 'Training opportunity' ] )->type_with_article(), 'a training opportunity' );
Harness::assert_same( 'a volunteering opportunity', $ctx( [ 'type_label' => 'Volunteering opportunity' ] )->type_with_article(), 'a volunteering opportunity' );
Harness::assert_same( 'a submission', $ctx( [ 'type_label' => '' ] )->type_with_article(), 'and the stand-in reads properly too' );

Harness::assert_true(
	str_contains( Copy::compose( 'submitted', Plan::NOTIFY_MODERATORS, $ctx( [ 'type_label' => 'Event' ] ) )->paragraphs[0], 'has submitted an event' ),
	'so the review team are never told about "a event"'
);

Harness::group( 'Submitting produces a receipt' );

$receipt = Copy::compose( 'submitted', Plan::NOTIFY_MEMBER, $ctx() );
$queue   = Copy::compose( 'submitted', Plan::NOTIFY_MODERATORS, $ctx() );

Harness::assert_true( $receipt instanceof Message, 'the member is told their work arrived' );
Harness::assert_true( $receipt->paragraphs !== $queue->paragraphs, 'and not in the words written for the review team' );
Harness::assert_same( 'https://example.test/dashboard/item/12', $receipt->cta_url, 'it links to their own submission' );
Harness::assert_true( str_contains( $receipt->paragraphs[0], 'not on the site yet' ), 'it says where the work is not' );
Harness::assert_false( str_contains( strtolower( $receipt->to_text() ), 'within' ), 'and promises no turnaround this plugin cannot know' );

Harness::group( 'An edit is never described as a new submission' );

$edit = static fn( array $o = [] ): Context => new Context(
	title: $o['title'] ?? 'Coffee morning at the Hub',
	type_label: $o['type_label'] ?? 'Event',
	org_name: 'Leeds Community Trust',
	actor_name: 'Jo Bloggs',
	site_name: 'Doing Good Leeds Partnership',
	item_url: 'https://example.test/dashboard/item/12',
	review_url: 'https://example.test/dashboard/review/99',
	public_url: $o['public_url'] ?? '',
	queue_url: 'https://example.test/dashboard/review',
	expires_on: '',
	note: $o['note'] ?? '',
	is_edit: true,
	trusted_for: $o['trusted_for'] ?? '',
);

/*
 * The five messages an edit can produce. Each has to say something different
 * from its new-submission twin, because the two are not the same event and a
 * member reading "your event is live" about an edit that was refused would be
 * badly misled.
 */
$edit_pairs = [
	[ 'submitted', Plan::NOTIFY_MEMBER ],
	[ 'submitted', Plan::NOTIFY_MODERATORS ],
	[ 'published_on_trust', Plan::NOTIFY_MEMBER ],
	[ 'published_on_trust', Plan::NOTIFY_MODERATORS ],
	[ 'approved', Plan::NOTIFY_MEMBER ],
	[ 'changes_requested', Plan::NOTIFY_MEMBER ],
	[ 'rejected', Plan::NOTIFY_MEMBER ],
];

foreach ( $edit_pairs as [ $key, $audience ] ) {
	$new  = Copy::compose( $key, $audience, $ctx() );
	$made = Copy::compose( $key, $audience, $edit() );
	$name = $key . '/' . $audience;

	Harness::assert_true( $made instanceof Message, $name . ' has edit copy' );
	Harness::assert_true( $made->subject !== $new->subject, $name . ' has its own subject' );
	Harness::assert_true( $made->paragraphs !== $new->paragraphs, $name . ' has its own words' );
	Harness::assert_true( str_contains( strtolower( $made->subject . ' ' . implode( ' ', $made->paragraphs ) ), 'edit' ), $name . ' says it is an edit' );
}

Harness::group( 'An edit in trouble says the site is still fine' );

foreach ( [ 'changes_requested', 'rejected' ] as $key ) {
	$m = Copy::compose( $key, Plan::NOTIFY_MEMBER, $edit( [ 'note' => 'Wrong date.' ] ) );

	Harness::assert_true(
		str_contains( implode( ' ', $m->paragraphs ), 'published version is unchanged' ),
		$key . ' reassures the member their live listing has not come down'
	);
}

Harness::assert_true(
	str_contains( implode( ' ', Copy::compose( 'submitted', Plan::NOTIFY_MEMBER, $edit() )->paragraphs ), 'has not changed' ),
	'and so does the receipt, which is when they will be most worried'
);

Harness::group( 'The review team are told what they are opening' );

$m = Copy::compose( 'submitted', Plan::NOTIFY_MODERATORS, $edit() );

Harness::assert_true( str_contains( $m->subject, 'Edit to a published event' ), 'the subject says it is an edit before they click' );
Harness::assert_same( 'https://example.test/dashboard/review/99', $m->cta_url, 'and the link goes to the edit, not the item' );
Harness::assert_same(
	'https://example.test/dashboard/item/12',
	Copy::compose( 'approved', Plan::NOTIFY_MEMBER, $edit() )->cta_url,
	'while the member is sent to the item, which is the thing that still exists afterwards'
);

Harness::group( 'Copying a message never loses a property' );

/*
 * for_recipients() and with_subject() used to list every property by hand, in
 * two places. Adding one meant remembering both or watching it vanish the
 * moment the mailer addressed the message - which is after every test that
 * builds one, and before it goes on the wire. This asserts the invariant
 * rather than the list, so it holds for properties nobody has thought of yet.
 */
$full = new DGL\Email\Message(
	key: 'k',
	audience: 'a',
	subject: 'Subject',
	preheader: 'Pre',
	heading: 'Heading',
	paragraphs: [ 'One', 'Two' ],
	facts: [ 'Label' => 'Value' ],
	note: 'A note',
	note_label: 'Note label',
	cta_label: 'Do it',
	cta_url: 'https://example.test/do',
	footnotes: [ 'Small print' ],
	items: [ [ 'title' => 'An item', 'meta' => 'Event', 'url' => 'https://example.test/i', 'summary' => 'About it' ] ],
	to: [ 'first@example.test' ]
);

$addressed = $full->for_recipients( [ 'second@example.test' ] );
$retitled  = $full->with_subject( 'Diverted' );

$properties = array_map(
	static fn( ReflectionProperty $p ): string => $p->getName(),
	( new ReflectionClass( DGL\Email\Message::class ) )->getProperties()
);

Harness::assert_true( count( $properties ) > 10, 'the message has the properties this test thinks it has' );

foreach ( $properties as $name ) {
	if ( 'to' !== $name ) {
		Harness::assert_same( $full->{$name}, $addressed->{$name}, "addressing a message keeps {$name}" );
	}

	if ( 'subject' !== $name ) {
		Harness::assert_same( $full->{$name}, $retitled->{$name}, "changing the subject keeps {$name}" );
	}
}

Harness::assert_same( [ 'second@example.test' ], $addressed->to, 'and the new recipients are used' );
Harness::assert_same( 'Diverted', $retitled->subject, 'and the new subject is used' );
Harness::assert_same( [ 'first@example.test' ], $retitled->to, 'while the recipients survive a subject change' );

Harness::group( 'A digest list survives into the plain-text alternative' );

$text = $full->to_text();

Harness::assert_true( str_contains( $text, 'An item' ), 'the item title is there' );
Harness::assert_true( str_contains( $text, 'https://example.test/i' ), 'so is its link, because a text reader has no button to press' );
Harness::assert_true( str_contains( $text, 'About it' ), 'and its summary' );
Harness::assert_true( $full->has_items(), 'a message with a list says it has one' );
Harness::assert_false( $full->for_recipients( [] )->with_subject( 'x' )->has_items() === false, 'and still says so after being copied twice' );

Harness::group( 'The unsubscribe link in the footer is clickable' );

/*
 * It went out as escaped text. Some clients auto-link a bare URL and some do
 * not, so for some readers the only way to stop the emails was to copy the
 * address into a browser. Almost nobody does that; they press the spam button,
 * and that costs the sending domain far more than an unsubscribe.
 */
$footed = new DGL\Email\Message(
	key: 'digest',
	audience: 'subscriber',
	subject: 'S',
	footnotes: [
		'Change what you get: https://example.test/dashboard/profile/email/ - Stop these emails: https://example.test/dashboard/unsubscribe/abc123/',
		'A line with a sentence-ending URL https://example.test/x.',
		'A line with <script>alert(1)</script> and no link at all.',
	]
);

$html = DGL\Email\Template::render( $footed );

Harness::assert_true( str_contains( $html, 'href="https://example.test/dashboard/unsubscribe/abc123/"' ), 'the unsubscribe URL becomes a real link' );
Harness::assert_true( str_contains( $html, 'href="https://example.test/dashboard/profile/email/"' ), 'and so does the preferences URL' );
Harness::assert_true( str_contains( $html, 'href="https://example.test/x"' ), 'a trailing full stop is not swallowed into the URL' );
Harness::assert_true( str_contains( $html, 'x</a>.' ), 'and stays in the sentence where it belongs' );
Harness::assert_false( str_contains( $html, '<script>' ), 'markup in a footnote is still escaped' );
Harness::assert_true( str_contains( $html, '&lt;script&gt;' ), 'and shown as text' );

Harness::group( 'Series: the "still running?" reminder' );

$remind = \DGL\Email\SeriesCopy::ending_soon( 'Coffee morning', '31 March 2027', 'https://example.test/dashboard/extend/9/abc/', 'https://example.test/dashboard/item/9/', 'DGLP' );

Harness::assert_same( 'Is this still running? Coffee morning', $remind->subject, 'subject asks the one question' );
Harness::assert_same( Plan::NOTIFY_MEMBER, $remind->audience, 'it goes to the member side' );
Harness::assert_true( str_contains( $remind->paragraphs[0], '31 March 2027' ), 'the last date is in the first paragraph' );
Harness::assert_true( str_contains( $remind->paragraphs[0], 'DGLP' ), 'and the site name' );
Harness::assert_same( 'https://example.test/dashboard/extend/9/abc/', $remind->cta_url, 'the button is the one-click link' );
Harness::assert_same( 'Yes, still running: keep it listed', $remind->cta_label, 'the button says what it does' );
Harness::assert_true( str_contains( implode( ' ', $remind->footnotes ), 'works once' ), 'the small print says the link is single use' );
Harness::assert_true( str_contains( implode( ' ', $remind->footnotes ), 'https://example.test/dashboard/item/9/' ), 'and points at the dashboard for other changes' );
Harness::assert_true( str_contains( $remind->paragraphs[2], 'do not need to do anything' ), 'doing nothing is spelled out as fine' );
Harness::assert_false( str_contains( strtolower( $remind->to_text() ), 'honest' ), 'never that word' );
Harness::assert_same( 'Untitled event', \DGL\Email\SeriesCopy::ending_soon( '  ', 'x', 'u', 'i', 's' )->facts['Event'], 'a blank title still reads' );

Harness::group( 'Listing: the "still current?" reminder' );

$current = \DGL\Email\SeriesCopy::listing_ending( 'Grant round opens', '17 December 2026', 'https://example.test/dashboard/extend/9/abc/', 'https://example.test/dashboard/item/9/', 'DGLP', '3 months' );
Harness::assert_same( 'Is this still current? Grant round opens', $current->subject, 'subject asks whether it is still current' );
Harness::assert_true( str_contains( $current->paragraphs[0], '17 December 2026' ) && str_contains( $current->paragraphs[1], '3 months' ), 'the date and the spell are in the body' );
Harness::assert_same( 'Yes, still current: keep it listed', $current->cta_label, 'the button says what it does' );
Harness::assert_false( str_contains( strtolower( $current->to_text() ), 'honest' ), 'never that word' );

$gone = Copy::compose( 'expired', Plan::NOTIFY_MEMBER, new Context( title: 'Grant round opens', type_label: 'News item', lifetime: '3 months' ) );
Harness::assert_true( null !== $gone && str_contains( $gone->paragraphs[0], 'listed for 3 months' ), 'an expired listing is told it ran its spell, not that it passed a date' );
$dated = Copy::compose( 'expired', Plan::NOTIFY_MEMBER, new Context( title: 'Fete', type_label: 'Event' ) );
Harness::assert_true( null !== $dated && str_contains( $dated->paragraphs[0], 'passed its date' ), 'a dated item keeps the old wording' );

Harness::group( 'Published on trust names what the organisation is trusted for' );

$m = Copy::compose( 'published_on_trust', Plan::NOTIFY_MEMBER, $ctx( [ 'trusted_for' => 'News' ] ) );
Harness::assert_true( null !== $m && str_contains( implode( ' ', $m->paragraphs ), 'trusted for News' ), 'a new story says trusted for News' );
$m = Copy::compose( 'published_on_trust', Plan::NOTIFY_MODERATORS, $ctx( [ 'trusted_for' => 'News' ] ) );
Harness::assert_true( null !== $m && str_contains( implode( ' ', $m->paragraphs ), 'trusted for News' ), 'and so does the team\'s copy' );
$m = Copy::compose( 'published_on_trust', Plan::NOTIFY_MEMBER, $edit( [ 'trusted_for' => 'edits' ] ) );
Harness::assert_true( null !== $m && str_contains( implode( ' ', $m->paragraphs ), 'trusted for edits' ), 'an edit let through on the edits switch says so' );
$m = Copy::compose( 'published_on_trust', Plan::NOTIFY_MEMBER, $ctx() );
Harness::assert_true( null !== $m && str_contains( implode( ' ', $m->paragraphs ), 'trusted for this kind of listing' ), 'with nothing known the wording still reads' );
