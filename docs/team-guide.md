# Review team guide

For the DGLP staff who check submissions, verify organisations and look after
members. Two places matter: the review queue at `/dashboard/review`, which you
reach through the user area, and the Organisations screen in wp-admin.

## Help inside the user area

The review team see the same screens under the name "Admin area"; members
see "User area". It is one place with two labels, not two places.

Members have a "Help" link in the sidebar: a guide with a contents list,
questions people ask, and "Download as PDF". You have a "Team guide" link
under Review team with the same for this document, kept in step with the
code (`src/Help/Content.php`), also as a PDF. Point people at those rather
than at this file.

## The review queue

The "Review" link in the user area sidebar carries a count. It is the number
of things waiting for a decision, not unread items. The queue has three parts,
oldest first.

**Approved.** The second entry under Review team opens what is on the site; its filters show refusals, expired and archived items too. It lists everything already
decided, newest first, filtered by outcome. Open one to check it again. What
you can change depends on where it is: a live item can be taken off the site
(it goes back in the queue), a refusal can be reopened (back in the queue,
the member is told), an archived item can be restored (back in the queue).
Nothing goes straight back on the site from here; it is decided again.

**Submissions.** News, events and training that members have sent in, and
edits to items already on the site. Open one to read it as the public would
see it, then choose:

- **Approve and publish.** It goes live and the member is emailed. For an
  edit, the button reads "Approve this edit" and the new version replaces the
  live one.
- **Send back.** Write what needs changing. The member is emailed your note
  and the item returns to them as a draft.
- **Refuse.** Write why. The member is emailed your reason. A refused item
  stays in their archive and cannot be resubmitted without an edit.

Nothing reaches the public site without one of these decisions, and the
member is told each time.

**Organisation changes waiting.** A member has asked to change their
organisation's name or logo. The listings keep the old details until you
accept. Accepting updates every listing at once. Refusing needs a note, which
the member reads by email.

**New organisations to verify.** Somebody registered with an email address
that matched no organisation on the list, proved the address by clicking the
link, and told us about their organisation. They can draft but not submit
until you decide.

- **Verify the organisation.** It becomes a member organisation, the person
  becomes its owner, and they can submit and invite colleagues. They are
  emailed.
- **Refuse.** The organisation is removed and the account closed. Your note
  goes to them by email, so say why.

## Repeating events

A weekly or monthly event is one listing, not one a week. The member says how
it repeats and until when (at most six months). It shows once on the events
list, sorted by its next date, and on every day it runs on the calendar at
`/events/calendar/`. Two weeks before its end date the organisation's owners
get one email asking whether it is still running, with a one-click button
that keeps it listed for six more months. If nobody clicks, it comes off the
site on its last date. Owners can also change a live event's dates and times
from its screen without review: the words and pictures still come to you, the
dates do not. Every event page has "Add to your calendar", which downloads
the event (a series as one entry on every date it runs). The calendar page
has "Subscribe in your own calendar": the address at `/events/calendar.ics`
pasted into Google Calendar, Outlook or Apple Calendar keeps itself up to
date. See `docs/events-repeat.md`.

If an event is off, the organisation marks it cancelled from its item
screen: the whole thing, or one date of a repeating one. A cancelled event
stays on the site for a week with a Cancelled stamp so people who saw it
know, then comes off on its own. A cancelled date shows struck through on
the calendar. Both are undoable by the organisation and show in their
notifications and the audit trail. You do not need to do anything; take it
down yourself only if it should go at once.

## The public news and events pages

Both open with one item large on the left and three beside it, then the
rest as rows that keep loading as the visitor scrolls. The large one is the
featured item if you have featured one (see below), otherwise the first in
the list: the newest story, or the soonest event. Every card shows the
item's first topic as a chip, its title, and one line: for news the
organisation, date and reading time; for events when, where and the
organisation. An item with no picture gets a plain navy tile, so ask for a
picture when one is missing.

## Site search

The search box in the site header looks through organisations, news, events
and training, and the results page shows each kind in its own section, in
that order, with a count and a "See all" link when there are more than six.
A word is matched in the title, the body and the summary of an item, and in
the name and description of an organisation. Only live items and listed,
approved organisations appear. Nothing found offers the four lists to browse.

## Filters on the public lists

Every public list has a Topic filter, and the events list has a When filter
too (today, the next seven days, this weekend, this month, next month). A
repeating event counts when its next date falls in the window. The filters
fold behind one button on a phone. Topics come from the ones you manage
under Organisations > Topics in wp-admin; only topics with something
published under them are offered. Both lists also have an Organisation
filter: every approved organisation with something live on that list, so a
member of the public can see everything one organisation has posted. The
address carries it (`/news/?org=123`), so it can be linked to.

## How long news stays up

Events and training come off the site on their dates. A news story has no
date and stays on the site until its organisation archives it or you take it
down from the review screen. Nothing comes off on its own. (Until 0.22.0 a
story was listed for three months and then asked about; Martin withdrew that
on 22 September 2026 so stories keep their long-tail search value.)

## Featuring an item

Open any live event or news story from the queue or the Approved screen. The
"Feature it" section holds it at the top of its public list for 7 or 14 days
with a Featured stamp. It drops back on its own when the time is up, hourly
by cron, and the organisation is told either way. "Stop featuring it" ends
it early. Nothing longer than a fortnight.

You can also do it from wp-admin: open the item under News or Events, find
the **Featured** box on the right, pick "Feature for 7 days" or "14 days"
(or "Stop featuring it") and press Update. Same seven or fourteen days, same
audit row, same people: moderators and administrators, on live items only.

## Reports

"Reports" under Review team shows one month in numbers, read from the audit
trail: what was sent in, approved, sent back and refused; approvals by type
and edits; the median time from submission to approval; organisations
verified and refused; people joined and removed; and the site today. Pick
any of the last twelve months. Two kinds of spreadsheet download: every
decision in the month, and every listing of a type ever sent with all its
fields. From the command line the same is `wp dgl report --month=YYYY-MM`
and `wp dgl export events|news|training|decisions --file=...`.

## Team notes

Every review screen has a "Team notes" card. Write anything the next
reviewer should know: "asked the org to confirm the venue", "second time
with no picture". Notes stay with the listing through every edit and show
as a count on the queue rows. The organisation never sees them: they are
not in the audit trail, not in notifications, and a copy of the listing
does not carry them. Any moderator can remove one.

## Moving an item to another organisation

Every item belongs to one organisation, and that name is what the public
see on it and what the organisation's members see in their dashboard. The
old site's stories came over under Forum Central or Voluntary Action Leeds
by their old category, which is not always right. On any item's review
screen, the "Who sent it" card has "Move it to another organisation": pick
the right one from the list of approved organisations and press "Move it".
The item keeps its status (a live story stays live), the new organisation's
dashboard lists it and the old one's does not, and the audit trail records
the move with both names. Moderators and administrators only.

## Taking something off the site

Open any live item from the queue or from the member's list. The last section
on the page is "Take it off the site". A note is required. The item comes off
the public site, goes back into the queue as needing changes, and the member
is emailed your note.

## The Organisations screen in wp-admin

Each organisation has a box on the right with three settings.

**Verification.** Pending means nobody at the organisation can submit yet.
Approved means they can. Suspended stops submitting and editing but leaves
their live listings up. Verifying from the review queue sets this to Approved
for you.

**Trust level.** Moderated is the default: every submission and every edit is
reviewed before it appears. "Trusted for edits" reviews new items but lets
edits to approved items go live straight away. "Trusted" lets everything go
live straight away and lists it for a spot check. Move an organisation up only
when their record earns it. Trust never outlives verification: suspend the
organisation and the trust level stops applying.

**Email domains.** One per line, for example `leedscommunitytrust.org.uk`.
Anyone who joins with an email address at that domain is offered this
organisation and, if it is Approved, joins straight away as a colleague. The
first person to join an organisation becomes its owner. Public providers such
as gmail.com and outlook.com are never matched, whatever you type here. A
person at one of those addresses picks the organisation from the list instead
and the request comes to you to check.

A pending name or logo change also shows on this screen with the same accept
and refuse choices as the queue.

## The directory

Every organisation's profile carries the details from Forum Central's list:
address, ward, type, services, who they work with, size, accreditations.
Owners edit them. The public directory at `/directory/` shows an
organisation when it is verified and its switch is on. The 146 that gave
Forum Central permission to publish were switched on at the start; the
rest appear when a member of that organisation presses "Show us in the
directory" on the Organisation tab. Any approved member can switch it on
or off, it takes effect at once, and pending or suspended organisations
never show whatever the switch says.

## How people join

The sign-in page links to "Join the user area". The person gives their email
address first and nothing else. We send a link. It works once and for 48
hours; after that they ask for another. When they click it:

- If the address matches an organisation's domain, they are offered that
  organisation and can join with one click. The owner is emailed that
  somebody has joined.
- Otherwise they pick their organisation from the list, searching by name.
  That is a request: the account is made, pending, and it lands in your
  queue as "Wants to join X".
- Only if their organisation is not on the list do they register it. What
  they type is checked against the list before anything is created: the
  same name once "The", "Ltd", "CIC" and the like are set aside, the same
  website or email domain, the same charity or company number, or the same
  postcode with a similar name is a clear match, and they are offered that
  organisation instead of creating a second one. A similar name, or the same
  postcode alone, is shown as "Is it one of these?" and they can say it is
  not. A registration lands in your queue as "Registered X".

Owners invite and remove colleagues themselves from the Members tab of their
organisation profile. An invitation skips the check. A removed colleague is
signed out everywhere and emailed.

The email form has three quiet defences: a hidden field robots fill and
people never see, a signed clock that drops a submit made within three
seconds of the form being drawn, and a limit of three links per address and
ten per connection an hour. A robot is shown "sent" and nothing is sent
(the audit trail records `join_blocked`); a person over the limit is told
to wait an hour.

## Checking a joining request

Both kinds sit in the review queue under "Joining requests to check", oldest
first, and open to one screen with a decision at the bottom.

**Somebody wants to join an organisation on the list.** The screen shows
what they said about how they are connected, and one line about their email
address: on a subdomain of the organisation's domain (probably genuine, and
worth adding that domain in wp-admin), matching the organisation's website,
a public address such as Gmail (nothing to go on but what they said), or
nothing like anything recorded. It lists who is already in the organisation
with their emails, so you can ask. Approve adds them as a contributor, or as
owner if nobody is in it yet; an owner can promote them later. Refuse closes
the account and emails them your note; the organisation is untouched. The
organisation's owners are emailed when you approve.

**Somebody registered an organisation.** The screen shows what they typed,
and "Likely matches on the list": every organisation this could be, with the
reason (same website, similar name, same charity number, same postcode) and
whether it is verified, awaiting verification, or a previously refused
registration in the bin. If they were shown near matches before registering
and said none was theirs, it says so.

- If it is one of the listed organisations, press "It is this one" (or pick
  it in the attach box) and "Attach and remove the duplicate". The person
  joins the existing organisation with the usual rules, their typed details
  fill any gaps in its record without overwriting anything, their email
  domain is recorded on it if you leave the box ticked, the duplicate record
  is deleted, and the person and the organisation's owners are emailed. If
  the duplicate already has a listing or another person in it, attach
  refuses; verify or refuse it instead.
- If it matches a registration in the bin, that was refused once. Restore it
  from wp-admin if the refusal was a mistake, then attach the person to it.
- Otherwise verify it: the organisation becomes a member with this person as
  its owner. Or refuse it with a note, which removes the organisation and
  closes the account.

The attach box also works on a request to join, for the case where the
person picked the wrong organisation. Then nothing is deleted.

A name change an owner asks for is refused automatically if it would make
two organisations the same once suffixes are set aside; the same check runs
on `wp dgl invite org` and warns on the import. The one place it does not
run is the title box on an organisation in wp-admin, so leave that alone
unless you have checked the list.

## What members are emailed

Every decision above sends one email to the member: approved, sent back with
your note, refused with your reason, organisation change accepted or refused,
organisation verified or refused, and removed from an organisation. The
review team is emailed when an organisation change is waiting. Digests go out
daily, weekly or monthly to members who asked for them, and never when there
is nothing new.

## System health

In wp-admin, under Organisations, "System health" is one screen that says
whether the site is doing its job: cron running, email on, the listings index
in step, what is waiting for a decision, digest subscribers, the directory.
Every row is Fine, Look or Broken and says what to do. It changes nothing.

## If something looks wrong

- A member says they cannot submit: check the organisation's Verification is
  Approved and the member's account is not pending or suspended.
- A member cannot see a colleague's listing: they are in different
  organisations. Listings belong to the organisation, not the person.
- An email did not arrive: ask the site administrator to run the mail status
  check. It reports whether sending is on, where mail is being redirected on
  staging, and whether the logo in the email header can be fetched.
- Somebody says the join email never came: `wp dgl join status <email>`
  says whether an account already exists, where mail is going (on staging
  every email is redirected to one test inbox), how many links were sent
  this hour, each signup and whether its link was used, and the audit rows.
- Somebody who already has an account tries to join: the join page tells
  them so and sends them to sign in with the address filled in, or to set a
  new password. Nothing is sent and no signup is created.

## Web addresses

Every box that takes a web address accepts "example.org.uk" and stores it
as https://example.org.uk. Plain http:// is refused everywhere, in the
address boxes, in the words and on the join form: DGLP decided on 20
September 2026 that the site links to nothing served without a
certificate, and offers to help a group secure its hosting instead. The
editor's link button turns a typed http:// into https://. The External
links check reads the links inside the words too and marks any http link
on an older listing as a fail. `wp dgl links audit [--live]` lists every
stored http link so they can be chased before go-live.

## Images and contact details on submissions

A photo is shrunk to 1600px and stripped of its camera data in the
member's browser before it is sent, and anything over 20MB is refused
there with a message. A new submission starts with the contact name,
email, phone and website the organisation used last time; every one can
be changed on the Contact and links step.

**The picture's description.** A picture goes up the moment a member
chooses it, before they save the step. The AltText.ai plugin describes it
on the way in, and that description appears in "What the picture shows"
within a few seconds, for them to keep or change. A description they typed
is never replaced. The box is not compulsory: a member can leave it and the
picture keeps AltText.ai's words. The review screen's checks still flag a
picture with no words at all. Without JavaScript the file goes up with the
step as before and the box is filled when the step reloads.
