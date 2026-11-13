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
