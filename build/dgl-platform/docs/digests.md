# Digests

A round-up of what member organisations have posted, to members who asked for
one. Separate from transactional email in every way that matters: it needs
recorded consent, it carries an unsubscribe link, and it is never sent empty.

## What decides what

| Question | Answered by | Needs a database? |
|---|---|---|
| Is one owed yet? | `Digest\Frequency` | No |
| What goes in it? | `Digest\Matcher` | No |
| May it be sent at all? | `Digest\Subscription` | No |
| Who is owed one? | `Digest\Store` | Yes |
| Building and sending | `Digest\Runner` | Yes |
| What it says | `Digest\Copy` | No |

The first three are pure and exercised without WordPress, which is why the
rules can be asserted exhaustively rather than sampled.

## Consent

A row in the table is not consent. `consent_at` is, and `Subscription::is_sendable()`
refuses without it. Under PECR the record has to say when somebody agreed, so
the timestamp is written once, on the first save that asks for something, and
is **not** rewritten when they later change a checkbox. Rewriting it would
erase the only date that matters.

Asking for nothing is a valid answer. It clears consent and keeps the row,
because deleting the row would lose the fact that this person opted out and the
next bulk change would start writing to them again.

## Cadence

Daily, weekly or monthly, worked out from the subscriber's own last send rather
than from a calendar rule. A run the server missed catches up on the next pass
instead of skipping a period.

The cron checks hourly for all three. An hourly run where nothing is due reads
one index per cadence and stops.

**This needs a real system cron.** On WordPress's pseudo-cron a quiet site will
not fire it, and a digest nobody receives looks exactly like a digest nobody
wanted.

## An empty digest is never sent

If nothing matches, no email goes out **and the last-sent stamp is not moved**.
A monthly subscriber with a quiet quarter gets one digest covering the quarter,
rather than three empty emails or a silently skipped window.

The stamp is also not moved when a send fails, so a subscriber never loses the
window a failed send would have covered.

## Unsubscribing

Every digest carries the link, in the footer, always. One click, no sign-in and
no confirmation step: somebody who has clicked "stop emailing me" has already
decided, and asking again is how a message gets marked as spam.

Footer URLs are turned into real links by the template. They used to go out as
escaped text, and a plain URL is not clickable in every client.

## Checking it

```
wp dgl digest status                  # who is subscribed, who is due, is cron scheduled
wp dgl digest run --dry-run           # build everything, send nothing, move nothing
wp dgl digest preview <user>          # what one subscriber's next digest would contain
wp dgl digest run --frequency=weekly  # send, for real
```

`--dry-run` first on any site with real member addresses in its user table.
Staging is a clone of production, so that is every staging site.

## What is not built

- No per-organisation digest, and no "someone posted in your topic" alert.
- Topics filter, they do not opt in: choosing no topic means every topic, which
  is what somebody who ignores the control expects.
- A digest carries at most 25 items. A quiet subscriber returning after a year
  gets the most recent 25, not a year of listings.
