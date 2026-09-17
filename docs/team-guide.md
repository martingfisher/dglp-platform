# Review team guide

For the DGLP staff who check submissions, verify organisations and look after
members. Two places matter: the review queue at `/dashboard/review`, which you
reach through the member area, and the Organisations screen in wp-admin.

## The review queue

The "Review" link in the member area sidebar carries a count. It is the number
of things waiting for a decision, not unread items. The queue has three parts,
oldest first.

**Decided.** The second entry under Review team lists everything already
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
dates do not. See `docs/events-repeat.md`.

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
as gmail.com and outlook.com are never matched, whatever you type here, so a
group that only has a Gmail address gets its colleagues in by invitation from
the owner instead.

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

The sign-in page links to "Join the member area". The person gives their email
address first and nothing else. We send a link. It works once and for 48
hours; after that they ask for another. When they click it:

- If the address matches an organisation's domain, they are offered that
  organisation and can join with one click. The owner is emailed that
  somebody has joined.
- If it matches nothing, they describe their organisation and it lands in
  your queue under "New organisations to verify".

Owners invite and remove colleagues themselves from the Members tab of their
organisation profile. A removed colleague is signed out everywhere and emailed.

## What members are emailed

Every decision above sends one email to the member: approved, sent back with
your note, refused with your reason, organisation change accepted or refused,
organisation verified or refused, and removed from an organisation. The
review team is emailed when an organisation change is waiting. Digests go out
daily, weekly or monthly to members who asked for them, and never when there
is nothing new.

## If something looks wrong

- A member says they cannot submit: check the organisation's Verification is
  Approved and the member's account is not pending or suspended.
- A member cannot see a colleague's listing: they are in different
  organisations. Listings belong to the organisation, not the person.
- An email did not arrive: ask the site administrator to run the mail status
  check. It reports whether sending is on, where mail is being redirected on
  staging, and whether the logo in the email header can be fetched.

## Images and contact details on submissions

A photo is shrunk to 1600px and stripped of its camera data in the
member's browser before it is sent, and anything over 20MB is refused
there with a message. A new submission starts with the contact name,
email, phone and website the organisation used last time; every one can
be changed on the Contact and links step.
