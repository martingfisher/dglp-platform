# Repeating events

One post per series. A weekly coffee morning is one event on the site, listed
once, shown on the calendar on every day it runs, and taken off the site on
its own when it stops.

## What a member sees

On step 2 of the event wizard, under the start and end, "This event repeats"
reveals:

- **How often**: every week, every two weeks, every month.
- Weekly or fortnightly: which weekdays. The start date's weekday is always
  included.
- Monthly: the same date each month (the 15th), the same weekday (the third
  Tuesday) or the last such weekday of the month. All derived from the start
  date, so nothing to type. A monthly rule on the 31st skips months without
  one.
- **Runs until**: a date, at most six months ahead.
- **Dates it does not run**: optional, one per line, up to ten.

"Anything else about the timing" is a free line under it for things the rule
cannot say.

## What the system does with it

- The rule is stored as one meta value, `dgl_repeat`, through the schema
  registry, so the wizard, the review diff, an edit and the wp-admin box all
  handle it the same way.
- `dgl_next_at` on the post (mirrored to `dgl_items.next_at`) is the next
  occurrence that has not finished. `/events/` sorts by it, so a series sits
  where its next date belongs. The hourly cron restamps every live event
  before the expiry sweep runs.
- Expiry for a series is its "runs until" date at 23:59:59. A series with
  nothing left is expired by the ordinary sweep, with the ordinary email.
- `/events/calendar/` lists the next eight weeks day by day. A series appears
  on every day it runs, minus its skipped dates. `?from=YYYY-MM-DD` moves the
  window, up to a year ahead. The page is excluded from the page cache.
- The event page shows the wording, the next five dates and a link to the
  calendar. A finished series says so.
- `/events/<slug>.ics` downloads one event for Google, Outlook or Apple
  Calendar. A series goes as one entry with an RRULE and an EXDATE per
  skipped date, so it lands on every date it runs. `/events/calendar.ics` is
  every live event as one feed people subscribe to by address; it asks
  clients to refresh twice a day. Times carry the site's timezone as a TZID
  when Settings > General names one (Europe/London); with only a UTC offset
  set they go out in UTC, which is an hour out in summer. `src/Events/Ics.php`.

## Cancelling

The owning organisation cancels from the item screen, no review, undoable.
`src/Events/Cancel.php`.

- The whole event (a one-off or a series): `dgl_cancelled_at` and an optional
  `dgl_cancelled_note`. It stays live with a Cancelled stamp on the list, a
  banner with the note on its page, struck through on the calendar,
  `STATUS:CANCELLED` in its .ics, no booking button and no download. Its
  expiry is pulled to seven days after the cancellation so the sweep takes
  it off; reinstating puts the expiry back.
- One date of a series: `dgl_cancelled_dates`, a list of Y-m-d. The date
  stays on the calendar and in the next dates, marked Cancelled, but is not
  the "next" date for sorting, the list line or the reminder, and is an
  EXDATE for subscribers. The item screen offers the next eight dates.
- Both are locked while an edit is open for review, like the dates.

## Two weeks before the end

The organisation's approved owners get one email: "Is this still running?"
with one button. The link carries a single-use token and lands on a confirm
page with its own button, so a mail scanner that follows links cannot spend
it. Confirming moves "runs until" to six months from today, at once, with no
review. Doing nothing means it comes off the site on its last date and stays
in the dashboard.

The same "Keep it listed for another 6 months" button appears on the item
screen once the end is within eight weeks.

## Changing the dates without review

On a live event's screen, "Dates and times" holds the start, end and repeat
controls and a save that applies at once. A date is a fact, not copy, and a
listing that says Tuesday for a week while an edit waits is wrong for a week.
Words and pictures still go through review as before.

The card is locked while an edit is open for review, because an approved edit
copies its fields onto the live post and would overwrite a newer schedule
with an older one. Finish or discard the edit first.

Every save and extension is logged and appears in the organisation's
notifications.

## Undated listings

The same reminder, token and confirm page can serve an undated type with a
fixed spell (`Workflow\Lifetime`): the end date is `dgl_listed_until` on the
post, the expiry stamp follows it, and extending moves it on by the spell.
No type has a spell since 0.22.0. News had one of three months from 0.10.0;
Martin withdrew it on 22 September 2026 because a story that comes off after
three months forfeits the search value the site is built for. Schema 8 clears
the end dates and puts back any story the sweep had taken off. A dated
one-off inside the reminder window is not asked anything.

## Timezone

Stored dates are the site's wall clock. Everything that prints one uses
`View::wall_date()`, so 13:00 stays 13:00 through the clock change. The
sweep compares wall clock with wall clock. The audit stamps are UTC and use
`View::date()` as before.

## Where the code is

`src/Events/`: `Rule`, `Occurrences`, `Occurrence`, `Wording` (pure PHP, unit
tested in `tests/test-recurrence.php`), `Series` (the one writer of the two
stamps, extend), `Reminder` (the email and its token), `Schedule` (the card's
save), `Calendar` (the public page). `src/Dashboard/RepeatControl.php` renders
the control. `tests/integration/run.php` has two "Repeating events" groups. `wp dgl series
status|remind|roll` reads and drives one series from the command line.
