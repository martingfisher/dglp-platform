<?php
/**
 * The two help guides, as data.
 *
 * One structure feeds both the page and the PDF, so they cannot drift. Each
 * guide is a title, a lede, sections of blocks (paragraphs, bullet lists,
 * numbered steps, small headings) and questions people ask. Everything is
 * translatable and written for the person doing the job, not the developer.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Help;

use DGL\Dashboard\Router;
use DGL\Events\Cancel;
use DGL\Events\Rule;
use DGL\Events\Series;
use DGL\Workflow\Lifetime;
use DGL\Workflow\Pins;

defined( 'ABSPATH' ) || exit;

final class Content {

	public const MEMBER = 'member';
	public const TEAM   = 'team';

	/**
	 * @return array{title: string, lede: string, sections: array<int, array{id: string, heading: string, blocks: array<int, array{0: string, 1: mixed}>}>, faqs: array<int, array{q: string, a: string}>, faq_heading: string}
	 */
	public static function guide( string $which ): array {
		return self::TEAM === $which ? self::team() : self::member();
	}

	/** The slug in the address and the file name. */
	public static function slug( string $which ): string {
		return self::TEAM === $which ? 'team' : 'members';
	}

	/* ---- Members ---------------------------------------------------------- */

	private static function member(): array {
		$news_months = max( 1, (int) round( ( Lifetime::days_for( \DGL\PostTypes::NEWS ) ?? 90 ) / 30 ) );

		return [
			'title'       => __( 'Member guide', 'dgl-platform' ),
			'lede'        => __( 'How to post news, events and training for your organisation, what happens after you send something, and how to keep it right. Everything here is for people at member organisations of the Doing Good Leeds Partnership.', 'dgl-platform' ),
			'faq_heading' => __( 'Questions people ask', 'dgl-platform' ),
			'sections'    => [
				[
					'id'      => 'getting-in',
					'heading' => __( 'Getting in', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'The member area is at the "Sign in" link in the site header. Sign in with the email address and password you set when you joined.', 'dgl-platform' ) ],
						[ 'p', __( 'Not a member yet? Press "Join the member area" on the sign-in page and give your work email address. We send a link that works once, for 48 hours. When you click it:', 'dgl-platform' ) ],
						[ 'ul', [
							__( 'If your address is at an organisation already on the list, you are offered that organisation and join it with one click. Its owner is told.', 'dgl-platform' ),
							__( 'If it matches nothing, describe your organisation. The DGLP team check every new organisation before it can post. You can draft in the meantime.', 'dgl-platform' ),
							__( 'If a colleague already uses the member area, the quickest way in is to ask them to invite you from their Members page.', 'dgl-platform' ),
						] ],
						[ 'p', __( 'Forgotten your password? The link under the sign-in form sends a reset email.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'dashboard',
					'heading' => __( 'Your dashboard', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'The dashboard shows your organisation\'s listings by type, with a count of each, and the latest activity. The menu on the left (behind "Menu" on a phone) has one line per type, your archive, notifications, and your organisation and profile.', 'dgl-platform' ) ],
						[ 'p', __( 'Listings belong to the organisation, not to the person who typed them. Anyone at your organisation can see, edit and archive anything your organisation has posted.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'posting',
					'heading' => __( 'Posting something', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Press "Start a submission" and pick the type: news, an event, training, or whatever else the site offers. Each type has a short form in four steps:', 'dgl-platform' ) ],
						[ 'ol', [
							__( 'Basics: the headline, a one-line summary, the description, a picture, and the topics it fits. If you add a picture you must describe it in a few words; that is what screen readers say and what shows if the picture fails to load.', 'dgl-platform' ),
							__( 'Details: for an event, the start and end, where it happens (in person, online or both), the venue or the joining link, whether it repeats, cost and booking. Other types ask for what they need.', 'dgl-platform' ),
							__( 'Contact and links: the name, email, phone and website people should use. These start filled in with what your organisation used last time.', 'dgl-platform' ),
							__( 'Review and submit: read it back, then "Send for review".', 'dgl-platform' ),
						] ],
						[ 'p', __( 'You can stop at any step with "Save and close". It is kept as a draft and you carry on from your dashboard. A draft is never on the site.', 'dgl-platform' ) ],
						[ 'p', __( 'Photos are shrunk and stripped of camera data in your browser before they are sent. Anything over 20MB is refused with a message.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'after',
					'heading' => __( 'What happens after you send it', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Somebody on the review team reads it and does one of three things. You get an email and a notification each time.', 'dgl-platform' ) ],
						[ 'ul', [
							__( 'Approved: it is on the site.', 'dgl-platform' ),
							__( 'Sent back: it returns to you with a note saying what to change. Edit it and send it again.', 'dgl-platform' ),
							__( 'Refused: it stays in your archive with the reason. You can copy it to a new draft and start again.', 'dgl-platform' ),
						] ],
						[ 'p', __( 'Each listing shows its state on your dashboard: Draft, With the team, Needs changes, On the site, Came off on its date, Archived or Refused. Nothing reaches the public site without a decision.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'editing',
					'heading' => __( 'Changing something that is already on the site', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Open the listing and press "Edit". You are editing a copy. The version on the site stays up, unchanged, until the review team approve your edit; then the new version replaces it. While an edit is waiting you can "Discard the edit" to keep what is live.', 'dgl-platform' ) ],
						[ 'p', __( 'Two things on a live event do not go through review, because a wrong date for a week is worse than an unread one: the "Dates and times" card and cancelling. See the next section.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'events',
					'heading' => __( 'Events: repeating, changing dates, cancelling', 'dgl-platform' ),
					'blocks'  => [
						[ 'h3', __( 'Repeating events', 'dgl-platform' ) ],
						[ 'p', sprintf(
							/* translators: %d: months. */
							__( 'A weekly or monthly event is one listing, not one a week. On the Details step tick "This event repeats" and say how: every week or every two weeks on chosen days, or monthly on the same date or the same weekday. Give the date it runs until, at most %d months ahead, and any dates it does not run. It shows once on the events list, sorted by its next date, and on every day it runs on the public calendar.', 'dgl-platform' ),
							Rule::MAX_MONTHS_AHEAD
						) ],
						[ 'p', sprintf(
							/* translators: 1: days, 2: months. */
							__( 'Two weeks before the "until" date the owners of your organisation get one email asking whether it is still running, with one button that keeps it listed for another %2$d months. The same button is on the event\'s screen once the end is within %1$d weeks. If nobody presses it, the event comes off the site on its last date.', 'dgl-platform' ),
							Series::EXTEND_WINDOW_WEEKS,
							Series::EXTEND_MONTHS
						) ],
						[ 'h3', __( 'Changing the dates', 'dgl-platform' ) ],
						[ 'p', __( 'On a live event\'s screen the "Dates and times" card saves at once, without review. It is locked while you have an edit waiting for review, because the edit carries the dates too: finish or discard the edit first.', 'dgl-platform' ) ],
						[ 'h3', __( 'Cancelling', 'dgl-platform' ) ],
						[ 'p', sprintf(
							/* translators: %d: days. */
							__( 'The "Cancelled?" card on a live event\'s screen does two things. "Cancel one date" takes one date of a repeating event off: it shows as cancelled on the calendar and on the event page, and the other dates carry on. "Mark it cancelled" cancels the whole event: it stays on the site for %d days with a Cancelled stamp so people who saw it know, then comes off on its own. Both can be undone from the same card.', 'dgl-platform' ),
							Cancel::LISTED_DAYS
						) ],
						[ 'h3', __( 'Calendars', 'dgl-platform' ) ],
						[ 'p', __( 'Every event page has "Add to your calendar", which downloads the event for Google, Outlook or Apple Calendar; a repeating event goes in as one entry on every date it runs. The public calendar page has "Subscribe in your own calendar" for everything at once.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'news',
					'heading' => __( 'How long news stays up', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', sprintf(
							/* translators: %d: months. */
							__( 'Events and training come off the site on their dates. A news story has no date, so it is listed for %d months from the day it is approved. Two weeks before the end your organisation\'s owners get one email with a button that keeps it up for the same again; the same button is on the story\'s screen once the end is near. Nobody presses it, it comes off on its last day and stays in your archive.', 'dgl-platform' ),
							$news_months
						) ],
					],
				],
				[
					'id'      => 'taking-down',
					'heading' => __( 'Taking things down, archiving and copying', 'dgl-platform' ),
					'blocks'  => [
						[ 'ul', [
							__( '"Take off the site" on a live listing removes it from the public site at once and keeps it in your archive.', 'dgl-platform' ),
							__( '"Archive" on anything not with the team moves it out of the way. "Restore" brings an archived listing back, through review.', 'dgl-platform' ),
							__( '"Copy to a new draft" makes a fresh draft from any listing: the words, picture, venue, contact details and topics come across, the dates do not. Use it for the next run of something.', 'dgl-platform' ),
						] ],
					],
				],
				[
					'id'      => 'organisation',
					'heading' => __( 'Your organisation and colleagues', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( '"Organisation and profile" has five tabs: Organisation, Your details, Members, Sign-in and security, and Email preferences.', 'dgl-platform' ) ],
						[ 'ul', [
							__( 'Owners can do everything, including editing the organisation and inviting people. Contributors submit and edit listings. An owner can make a colleague an owner or a contributor, or remove them; they are told by email. You cannot change or remove yourself.', 'dgl-platform' ),
							__( 'To invite a colleague, use the Members tab. They get an email with a link that sets up their account.', 'dgl-platform' ),
							__( 'Changes to your organisation\'s name or logo are checked by the team before they show on listings. Everything else on the Organisation tab takes effect straight away.', 'dgl-platform' ),
							__( '"Show us in the directory" puts your organisation in the public directory. Any member can switch it, and it takes effect at once.', 'dgl-platform' ),
						] ],
					],
				],
				[
					'id'      => 'email',
					'heading' => __( 'Email and notifications', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Every decision about your work comes as one email, and everything that happens to your organisation\'s listings is on the Notifications screen. Under Email preferences you can also ask for a daily, weekly or monthly round-up of what other member organisations have posted, by topic. Every email has an unsubscribe link that works without signing in.', 'dgl-platform' ) ],
					],
				],
			],
			'faqs'        => [
				[ 'q' => __( 'I sent something and nothing has happened.', 'dgl-platform' ), 'a' => __( 'The review team read submissions during working hours. Check the listing\'s state on your dashboard: "With the team" means it is in their queue. You get an email the moment they decide.', 'dgl-platform' ) ],
				[ 'q' => __( 'I cannot press "Send for review".', 'dgl-platform' ), 'a' => __( 'Either something on the form is missing (the button says what) or your organisation has not been verified yet. You can draft in the meantime; the button turns on once the team verify you.', 'dgl-platform' ) ],
				[ 'q' => __( 'A colleague posted it. Can I edit it?', 'dgl-platform' ), 'a' => __( 'Yes. Listings belong to the organisation. Anyone at your organisation can edit, archive or copy anything it has posted.', 'dgl-platform' ) ],
				[ 'q' => __( 'I edited a live listing. Why is the old version still showing?', 'dgl-platform' ), 'a' => __( 'Edits go through review. The version on the site stays up until the team approve the new one. You will get an email.', 'dgl-platform' ) ],
				[ 'q' => __( 'Our event has moved. Do I resubmit it?', 'dgl-platform' ), 'a' => __( 'No. Open it and use the "Dates and times" card. It saves at once. If an edit of the words is waiting for review, finish or discard that first.', 'dgl-platform' ) ],
				[ 'q' => __( 'Our weekly group is not running next Tuesday.', 'dgl-platform' ), 'a' => __( 'Open the event and use "Cancel one date". That date shows as cancelled; the rest carry on. If you knew in advance, "Dates it does not run" on the Details step does the same when you set the event up.', 'dgl-platform' ) ],
				[ 'q' => __( 'Why did our event come off the site?', 'dgl-platform' ), 'a' => __( 'A one-off event comes off after it has happened. A repeating event comes off on its "until" date unless somebody at your organisation pressed the button in the reminder email or on the event\'s screen. It is in your archive; copy it to a new draft to list it again.', 'dgl-platform' ) ],
				[ 'q' => __( 'Why did our news story come off the site?', 'dgl-platform' ), 'a' => __( 'News is listed for a set spell and then comes off, unless somebody at your organisation pressed the "keep it listed" button in the reminder email or on the story\'s screen. It is in your archive.', 'dgl-platform' ) ],
				[ 'q' => __( 'My link was refused because it starts with http://.', 'dgl-platform' ), 'a' => __( 'The site only links to pages served over https, the padlock in the browser. Type the address as https://... instead; most sites work that way already. If yours does not, leave the link out for now and ask the DGLP team, who can help you secure your hosting.', 'dgl-platform' ) ],
				[ 'q' => __( 'What size and shape should the picture be?', 'dgl-platform' ), 'a' => __( 'Any photo from a phone is fine. It is shrunk in your browser before it is sent. Landscape works best on the list pages. Describe it in a few words when asked.', 'dgl-platform' ) ],
				[ 'q' => __( 'How do I change our organisation\'s name or logo?', 'dgl-platform' ), 'a' => __( 'On the Organisation tab, if you are an owner. The change is checked by the team before it shows, so the listings keep the old details until then.', 'dgl-platform' ) ],
				[ 'q' => __( 'How do I close my account?', 'dgl-platform' ), 'a' => __( 'Email the DGLP team. They close it and tell you what happens to anything you have published, which stays with your organisation.', 'dgl-platform' ) ],
			],
		];
	}

	/* ---- The review team --------------------------------------------------- */

	private static function team(): array {
		return [
			'title'       => __( 'Review team guide', 'dgl-platform' ),
			'lede'        => __( 'For the DGLP, Voluntary Action Leeds and Forum Central staff who check submissions, verify organisations and look after members. Two places matter: the review queue in the member area, and the Organisations screen in wp-admin.', 'dgl-platform' ),
			'faq_heading' => __( 'Questions the team ask', 'dgl-platform' ),
			'sections'    => [
				[
					'id'      => 'queue',
					'heading' => __( 'The review queue', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'The "Review queue" link in the sidebar carries a count: the number of things waiting for a decision. The queue is oldest first and has three parts.', 'dgl-platform' ) ],
						[ 'h3', __( 'Submissions', 'dgl-platform' ) ],
						[ 'p', __( 'News, events and training that members have sent in, and edits to items already on the site. Press "Review" on a row to read it as the public would see it, with the checks the system ran (missing fields, broken links, duplicates), then choose:', 'dgl-platform' ) ],
						[ 'ul', [
							__( 'Approve and publish: it goes live and the member is emailed. For an edit the button reads "Approve this edit" and the new version replaces the live one.', 'dgl-platform' ),
							__( 'Send back: write what needs changing. The member is emailed your note and the item returns to them as a draft.', 'dgl-platform' ),
							__( 'Refuse: write why. The member is emailed your reason. A refused item stays in their archive.', 'dgl-platform' ),
						] ],
						[ 'h3', __( 'Organisation changes waiting', 'dgl-platform' ) ],
						[ 'p', __( 'A member has asked to change their organisation\'s name or logo. Listings keep the old details until you accept; accepting updates every listing at once. Refusing needs a note, which the member reads by email.', 'dgl-platform' ) ],
						[ 'h3', __( 'New organisations to verify', 'dgl-platform' ) ],
						[ 'p', __( 'Somebody joined with an email address that matched no organisation on the list, proved the address, and described their organisation. They can draft but not submit until you decide. Verifying makes it a member organisation with that person as its owner; refusing removes the organisation and closes the account, with your note going to them by email.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'decided',
					'heading' => __( 'Decided: changing a decision', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( '"Decided" in the sidebar lists everything already decided, newest first, filtered by outcome. Open one to check it again. A live item can be taken off the site (it goes back in the queue), a refusal can be reopened (back in the queue, the member is told), an archived item can be restored (back in the queue). Nothing goes straight back on the site from here; it is decided again.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'featuring',
					'heading' => __( 'Featuring an item', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', sprintf(
							/* translators: 1: days, 2: days. */
							__( 'Open any live event or news story. The "Feature it" card holds it at the top of its public list for %1$d or %2$d days with a Featured stamp. It drops back on its own when the time is up and the organisation is told either way. "Stop featuring it" ends it early. Nothing longer than a fortnight, so nothing is featured by neglect.', 'dgl-platform' ),
							Pins::choices()[0],
							Pins::choices()[1]
						) ],
					],
				],
				[
					'id'      => 'taking-down',
					'heading' => __( 'Taking something off the site', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Open any live item. The last card is "Take it off the site". A note is required. The item comes off the public site, goes back into the queue as needing changes, and the member is emailed your note.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'events',
					'heading' => __( 'Events: what members do without you', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'A repeating event is one listing. Two weeks before its end date the organisation\'s owners are emailed a one-click button that keeps it listed for six more months; if nobody clicks, it comes off on its last date. Owners change a live event\'s dates and times from its screen without review, and mark it cancelled (it stays a week with a Cancelled stamp, then comes off) or cancel one date of a series. The words and pictures still come to you; the dates and cancellations do not. Take something down yourself only if it should go at once.', 'dgl-platform' ) ],
						[ 'p', __( 'News is listed for three months and then comes off unless the organisation extends it from the reminder email or the story\'s screen. Nothing about this comes to you.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'organisations',
					'heading' => __( 'The Organisations screen in wp-admin', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Each organisation has a box with three settings.', 'dgl-platform' ) ],
						[ 'ul', [
							__( 'Verification. Pending means nobody at the organisation can submit yet. Approved means they can. Suspended stops submitting and editing but leaves their live listings up. Verifying from the review queue sets this to Approved for you.', 'dgl-platform' ),
							__( 'Trust level. Moderated is the default: every submission and every edit is reviewed. "Trusted for edits" reviews new items but lets edits to approved items go live straight away. "Trusted" lets everything go live and lists it for a spot check. Move an organisation up only when its record earns it. Trust never outlives verification.', 'dgl-platform' ),
							__( 'Email domains, one per line. Anyone who joins with an address at that domain is offered this organisation and, if it is Approved, joins straight away as a colleague. The first person in becomes its owner. Public providers such as gmail.com are never matched, whatever you type.', 'dgl-platform' ),
						] ],
						[ 'p', __( 'Topics for listings and the public filters are managed under Organisations > Topics. Only topics with something published under them are offered to visitors.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'directory',
					'heading' => __( 'The directory', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Every organisation\'s profile carries the details from Forum Central\'s list: address, ward, type, services, who they work with, size, accreditations. Owners edit them. The public directory shows an organisation when it is verified and its switch is on. Any approved member can switch it; pending or suspended organisations never show whatever the switch says.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'joining',
					'heading' => __( 'How people join', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'The sign-in page links to "Join the member area". The person gives an email address and we send a link that works once, for 48 hours. A domain match joins them to that organisation and emails its owner; no match lands in your queue under "New organisations to verify". Owners invite and remove colleagues themselves from the Members tab. A removed colleague is signed out everywhere and emailed.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'email',
					'heading' => __( 'What members are emailed', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'Every decision sends one email to the member: approved, sent back with your note, refused with your reason, organisation change accepted or refused, organisation verified or refused, removed from an organisation, role changed. The review team is emailed when an organisation change is waiting. Digests go out daily, weekly or monthly to members who asked for them, and never when there is nothing new.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'health',
					'heading' => __( 'System health', 'dgl-platform' ),
					'blocks'  => [
						[ 'p', __( 'In wp-admin, under Organisations, "System health" is one screen that says whether the site is doing its job: cron running, email on, the listings index in step, what is waiting for a decision, digest subscribers, the directory. Every row is Fine, Look or Broken and says what to do. It changes nothing.', 'dgl-platform' ) ],
					],
				],
				[
					'id'      => 'wrong',
					'heading' => __( 'If something looks wrong', 'dgl-platform' ),
					'blocks'  => [
						[ 'ul', [
							__( 'A member says they cannot submit: check the organisation\'s Verification is Approved and the member\'s account is not pending or suspended.', 'dgl-platform' ),
							__( 'A member cannot see a colleague\'s listing: they are in different organisations. Listings belong to the organisation, not the person.', 'dgl-platform' ),
							__( 'An email did not arrive: check System health first, then ask the site administrator to run the mail status check.', 'dgl-platform' ),
							__( 'A picture has no description: the form requires one, so an old listing is the likely cause. Send it back and ask for one.', 'dgl-platform' ),
							__( 'A link starts with http://: the site does not link to pages served over plain http, and the form refuses new ones. An older listing can still carry one; the External links check marks it as a fail. Send it back to be changed to https, and offer the organisation help securing their site.', 'dgl-platform' ),
						] ],
					],
				],
			],
			'faqs'        => [
				[ 'q' => __( 'I approved something by mistake.', 'dgl-platform' ), 'a' => __( 'Open it from Decided (or from the queue) and use "Take it off the site" with a note. It comes off at once and goes back in the queue to be decided again.', 'dgl-platform' ) ],
				[ 'q' => __( 'I refused something that should have gone through.', 'dgl-platform' ), 'a' => __( 'Open it from Decided and press "Look at it again". It goes back in the queue and the member is told. Then approve it.', 'dgl-platform' ) ],
				[ 'q' => __( 'A member wants to change the date of a live event. Do they resubmit?', 'dgl-platform' ), 'a' => __( 'No. They change it from the "Dates and times" card on the event\'s screen and it applies at once. Nothing comes to you.', 'dgl-platform' ) ],
				[ 'q' => __( 'Where do new organisations appear?', 'dgl-platform' ), 'a' => __( 'In the review queue under "New organisations to verify", and on the Organisations screen in wp-admin with Verification set to Pending.', 'dgl-platform' ) ],
				[ 'q' => __( 'Can two organisations share one person?', 'dgl-platform' ), 'a' => __( 'No. An account belongs to one organisation. Somebody who works for two needs two accounts with two email addresses.', 'dgl-platform' ) ],
				[ 'q' => __( 'How do I feature something on the home page?', 'dgl-platform' ), 'a' => __( '"Feature it" puts it first on its own public list (events or news) with a stamp, for 7 or 14 days. The home page is the theme\'s; the platform does not place things on it.', 'dgl-platform' ) ],
				[ 'q' => __( 'Somebody has left an organisation.', 'dgl-platform' ), 'a' => __( 'Their owner removes them from the Members tab. If the owner has left, a site administrator changes the account in wp-admin under Users. What they posted stays with the organisation.', 'dgl-platform' ) ],
				[ 'q' => __( 'The counts on the dashboard look wrong.', 'dgl-platform' ), 'a' => __( 'Open System health. If the listings index is out of step it says so and names the command to run.', 'dgl-platform' ) ],
			],
		];
	}
}
