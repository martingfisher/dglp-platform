# Funding opportunities

Funding is the Grants post type (`dgl_grant`, at `/grants/`), filled every week
by an automated import instead of by hand.

Before this, VAL put together a "Sources of Grant Funding" Word document by hand,
around 15 pages and 140 funder links, refreshed every month or two and linked
from `/support/funding/`. It took one person days each time. The import does the
same reading every week and files each fund under categories people can search
and subscribe to.

## How it fits together

```
Cloudflare cron (Monday 04:00 UTC)
  -> Worker "dgl-funding" (workers/funding), a Cloudflare Workflow
       for each batch of up to 10 sources:
         TinyFish Fetch: page as markdown, plus its links
         unchanged since last week? reuse last week's result (no model call)
         changed? Claude Haiku reads it into the shape below
                  (Claude Sonnet only when Haiku's answer fails the checks)
  -> POST https://doinggoodleeds.org.uk/wp-json/dgl/v1/funding/sync
       the plugin adds, refreshes and withdraws Grant items
  -> new items wait in the review queue; one email lists them
```

Nothing is ever read on a page view. A week the Worker fails changes nothing:
the items already on the site stay until their own deadlines pass.

## The sync request

`POST /wp-json/dgl/v1/funding/sync`, header `Authorization: Bearer <token>`.
The token is made with `wp dgl funding token` (printed once, stored hashed) and
lives in the Cloudflare Secrets Store as `dgl_funding_token`.

```json
{
  "run_id": "2026-11-16T04:00:00Z",
  "sources": [
    {
      "id": "tnlcf-awards-for-all",
      "name": "National Lottery Community Fund: Awards for All",
      "url": "https://www.tnlcommunityfund.org.uk/funding/programmes/national-lottery-awards-for-all-england",
      "ok": true,
      "error": "",
      "grants": [
        {
          "key": "tnlcf-awards-for-all:awards-for-all-england",
          "title": "National Lottery Awards for All England",
          "funder": "The National Lottery Community Fund",
          "summary": "Grants of £300 to £20,000 for community-led projects ...",
          "body": "Two or three short paragraphs, plain text, in our own words.",
          "amount_min": 300,
          "amount_max": 20000,
          "deadline": null,
          "rolling": true,
          "eligibility": "Who can apply, plain text.",
          "how_to_apply": "How to apply, plain text.",
          "application_url": "https://www.tnlcommunityfund.org.uk/...",
          "fund_type": ["grant"],
          "applicants": ["registered_charity", "cio", "constituted_group"],
          "costs": ["project", "equipment", "staff"],
          "beneficiaries": [],
          "area": "england",
          "topics": ["grants-and-funding", "wellbeing"],
          "confidence": 0.9
        }
      ]
    }
  ]
}
```

Rules the plugin applies, whatever the Worker sends:

- Every category value must be a key in [`src/Funding/vocab.json`](../src/Funding/vocab.json);
  unknown ones are dropped. `topics` must be existing topic slugs. An empty
  `beneficiaries` list means "anyone".
- `deadline` is `YYYY-MM-DD` or null. A fund whose deadline has passed is skipped.
  A rolling fund (or one with no date) gets a deadline six weeks after the run;
  each week it is still on the funder's page moves that on, so if the import
  ever stops, rolling funds come down by themselves instead of lingering.
- `key` identifies a fund across weeks. The Worker reuses last week's key for the
  same programme.
- A source with `ok: false` changes nothing. A fund missing from a source that
  was read successfully and still lists at least one fund is withdrawn (its
  expiry is set to now).
- The words (title, summary, body, eligibility, how to apply, categories) are
  written once, when the item is made, so the review team's edits are never
  overwritten. The facts (amounts, deadline, rolling, link) are refreshed every
  week.
- New items go to the review queue (`dgl_pending`). `wp option update
  dgl_funding_autopublish 1` publishes them straight away instead.
- Text is plain text only, stripped and capped. Links must be https.

The answer: `{"ok":true,"added":n,"updated":n,"revived":n,"withdrawn":n,"skipped":n,"errors":[...]}`.

## Holding it back until it is signed off

One switch decides whether the public sees funding. In wp-admin it is
**Settings > Grants and Funding**: one button, "Show funding to the public" (or
"Hide funding from the public"), beside how the weekly import last went. Each
press is recorded in the audit log. The same switch from the command line:

```
wp dgl funding public off     # hidden: only signed-in administrators see it
wp dgl funding public on      # live
wp dgl funding public status
```

(`wp option update dgl_funding_public 0|1` does the same.) It was switched off on
13 November 2026 to wait for DGLP's approval.

While it is off, `/grants/` and every fund's page answer 404 to the public and are
noindex; the funding blocks and feeds draw nothing; funds are left out of topic
pages, search, organisation pages, the sitemaps, the digest and the digest
choices. Administrators see everything with a "Preview for administrators" line.
The weekly import keeps running, so the list is current when it goes on.

Any other block that should go live with funding gets the extra CSS class
`dgl-funding-launch` (Advanced > Additional CSS class(es) in the block editor):
it draws nothing until funding is switched on. The news, events and training
feeds on `/support/funding/` carry it.

On the day: `wp dgl funding public on`, then clear the page cache (SmartCache).
Nothing else changes. To be told who subscribes to funding in the digest, see
"Digest subscribers" below.

### Digest subscribers

A member's digest lists the types they ticked. Subscriptions saved before
funding existed do not include it, so existing subscribers get funding only if
they tick it. While funding is held back, new members are not signed up for it
either (0.60.6); once it is on, new members get it by default like every other
type. Adding it to existing subscribers waits for DGLP's decision after approval.

## The team's Funding screen

Review team > **Grants and Funding** in the dashboard (`/dashboard/review/funding/`):

- every funder's page the weekly run reads, with its link, any note, how many of
  its funds are on the site now (the number opens `/grants/?source=<id>`, the list
  filtered to that funder), and what the last run found there: "Read: 3 open",
  "Read: nothing open", "Could not be read" or "Read at the next run";
- **Add a funder**: a name and the address of its funding page (https, a real
  host, not already on the list). It is read at the next Monday run;
- **Delete**: the page is not read again and its funds come off the site at once
  (live ones expire, ones waiting for review leave the queue). Asks first;
- **Also on the site**: funds from elsewhere, such as the Arts Council write-ups in
  `workers/funding/manual.json`;
- the Show / Hide switch for the public, administrators only.

The list itself is the option `dgl_funding_sources`. The Worker reads it from
`GET /wp-json/dgl/v1/funding/sources` (same token as the sync) at the start of
every run. The first time it was empty, so the Worker seeded it from its own
`sources.json` (`POST` to the same route, accepted only while the list is empty).
From then on the site's list is the list; `sources.json` is only the fallback if
the site cannot be reached. Every add and delete is in the audit log.
