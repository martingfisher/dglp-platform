# Brokerage tool API: draft for discussion

**Status:** draft, 25 November 2026. Nothing here is built yet. It is written so Hippo Digital can
build against the example responses straight away while Results You Can Measure (RYCM) builds the
real endpoints to match.

**Owner on the platform side:** Martin Fisher, RYCM.

## The idea in one paragraph

Doing Good Leeds (doinggoodleeds.org.uk) already holds the directory of Leeds charities and
community groups. Organisations sign up, are checked by VAL's review team, and keep their own
profiles up to date. The brokerage tool reads that directory through a small JSON API instead of
keeping its own copy. Hippo builds the front end and the matching to the Figma designs; RYCM keeps
the data, accounts and approvals in one place.

## Phases

| Phase | What the tool does | What the platform provides |
|---|---|---|
| 1 | Shows and matches organisations. Businesses tell VAL they want to help through a form. | Read-only endpoints below. The form can be the tool's own, emailing VAL, or a link to a Doing Good Leeds page. |
| 2, only if needed | Businesses register, and their offers go into VAL's review queue. | One authenticated endpoint that writes into the existing review queue. |

Phase 1 needs no accounts, no secrets and no database on Hippo's side.

## Base address and versioning

```
https://doinggoodleeds.org.uk/wp-json/dgl/v1/broker/
```

- `v1` stays stable. Fields can be added; nothing is renamed or removed without a `v2` and notice.
- Field names here are the API's own, kept separate from the platform's internal names, so changes
  inside the plugin do not reach the tool.
- Unknown fields must be ignored by the client, so additions never break it.

## Endpoints (phase 1)

All are `GET`, public, JSON, UTF-8. They only return what the public directory already shows.

### `GET /organisations`

Approved organisations that are listed in the public directory. About 380 today.

| Parameter | Type | Meaning |
|---|---|---|
| `q` | text | Words in the name or description. |
| `ward` | key | One ward key from `/vocab`, or `leeds_wide`. |
| `serves` | keys, comma separated | Organisations that work with any of these groups (see `/vocab`). |
| `legal_status` | keys, comma separated | Any of these legal statuses. |
| `updated_since` | ISO 8601 date-time | Only organisations changed since then, for syncing. |
| `page` | integer, from 1 | Page number. |
| `per_page` | integer, 1 to 100 | Default 20. |

Response `200`:

```json
{
  "data": [
    {
      "id": 12345,
      "name": "Example Community Group",
      "slug": "example-community-group",
      "url": "https://doinggoodleeds.org.uk/directory/example-community-group/",
      "description": "We run lunch clubs and befriending for older people in west Leeds ...",
      "logo": {
        "url": "https://doinggoodleeds.org.uk/wp-content/uploads/2026/11/example-logo.png",
        "width": 600,
        "height": 300,
        "alt": "Example Community Group logo"
      },
      "charity_or_company_number": "1234567",
      "legal_status": { "key": "registered_charity", "label": "Registered Charity" },
      "website": "https://example.org.uk/",
      "email": "hello@example.org.uk",
      "phone": "0113 000 0000",
      "address": {
        "line_1": "1 Example Street",
        "line_2": null,
        "city": "Leeds",
        "postcode": "LS12 1AA"
      },
      "location": { "lat": 53.7977, "lng": -1.5958 },
      "ward": { "key": "armley", "label": "Armley" },
      "serves": [
        { "key": "age_adults", "label": "Age Groups: Adults" },
        { "key": "age_older_people", "label": "Age Groups: Older People" }
      ],
      "accessibility": [ { "key": "step_free_access", "label": "Step Free Access" } ],
      "accreditations": [ { "key": "living_wage_employer", "label": "Living Wage Employer" } ],
      "leeds_schemes": [],
      "paid_staff": { "key": "11_50", "label": "11 - 50" },
      "volunteers": { "key": "51_100", "label": "51 - 100" },
      "updated_at": "2026-11-24T10:27:29Z"
    }
  ],
  "meta": { "page": 1, "per_page": 20, "total": 381, "pages": 20 }
}
```

The organisation above is made up; keys and labels are the ones the site uses today.

Rules the client can rely on:

- Every field is always present. A field the organisation has not filled in is `null`, or `[]` for
  lists. Only `id`, `name`, `slug`, `url`, `email` and `updated_at` are guaranteed to have a value.
- Every chosen option is sent as `{ "key", "label" }`. Match on `key`; show `label`.
- `location` is `null` when the address has not been placed on the map yet.
- Text is plain text, never HTML.

### `GET /organisations/{id}`

One organisation, same shape as an item in `data`. `404` if it does not exist, is not approved, or
has asked to be left out of the directory. The client should drop it from any cache on `404`.

### `GET /vocab`

Every option list with keys and labels, so the tool can build filters without hard-coding them.

```json
{
  "ward":          [ { "key": "leeds_wide", "label": "Leeds-wide" }, { "key": "armley", "label": "Armley" } ],
  "serves":        [ { "key": "age_adults", "label": "Age Groups: Adults" } ],
  "legal_status":  [ { "key": "registered_charity", "label": "Registered Charity" } ],
  "accessibility": [ { "key": "step_free_access", "label": "Step Free Access" } ],
  "accreditations":[ { "key": "living_wage_employer", "label": "Living Wage Employer" } ],
  "leeds_schemes": [ { "key": "neighbourhood_network", "label": "Neighbourhood Network" } ],
  "size":          [ { "key": "1_10", "label": "1 - 10" } ]
}
```

Today's lists: 34 wards (including Leeds-wide), 46 groups served, 9 legal statuses, 6 accessibility
features, 7 accreditations, 5 Leeds schemes and 4 size bands.

### `GET /health`

```json
{ "ok": true, "version": "0.62.0", "organisations": 381, "time": "2026-11-25T15:00:00Z" }
```

For monitoring on both sides.

## Errors

```json
{ "code": "dgl_bad_parameter", "message": "ward must be a key from /vocab.", "data": { "status": 400 } }
```

`400` bad parameter, `404` not found, `429` too many requests, `5xx` our problem. The client should
show cached results or a polite message on `5xx` and `429`, and never retry in a tight loop.

## Caching and limits

- Responses carry `Cache-Control: public, max-age=300`, and Cloudflare caches them at the edge. A
  profile change can take up to five minutes to show in the tool.
- Rate limit: 60 requests a minute per IP address. The tool should call the API from its own server
  and cache, not from every visitor's browser.
- For a full sync, page through `/organisations?per_page=100` (four requests today), then use
  `updated_since` for changes.

## Phase 2 (only if needed): a business offers help

`POST /broker/offers`, server to server only, with `Authorization: Bearer <token>`. The token is
issued by RYCM, stored as a secret on Hippo's server, and never sent to a browser. The platform
keeps only a hash of it.

```json
{
  "business": { "name": "Example Ltd", "contact_name": "Sam Smith", "email": "sam@example.com", "phone": "", "website": "" },
  "offer": { "type": "volunteering", "summary": "Ten staff for a day of practical help", "wards": ["armley"], "serves": ["age_older_people"] },
  "organisation_ids": [12345],
  "consent": { "privacy_notice_version": "2026-11", "agreed_at": "2026-11-25T15:00:00Z" }
}
```

Response `202 { "id": 12345, "status": "pending" }`. The offer joins VAL's existing review queue,
with the same notifications and audit log as everything else on the platform. VAL decides what
happens next; the tool never publishes anything itself. The offer fields are placeholders until the
Figma screens are mapped.

## Testing and environments

- **Before the API exists:** build against the JSON in this document. RYCM will add a set of example
  files (a page of organisations, one organisation, the vocab) matching it exactly.
- **After:** the endpoints are public, so local development can read production directly. There is
  no staging copy of Doing Good Leeds.
- RYCM adds tests to the platform's test suites that check these response shapes, so no release of
  the plugin can change them by accident.

## To agree before building

1. **Field mapping.** Which fields the Figma screens use for matching and display, against the list
   above. Anything the designs need that organisations do not record today has to be added to the
   profile form first, and then filled in by organisations, so it should be found now.
2. Whether phase 1's business form emails VAL, or phase 2 is needed from the start.
3. Where the tool lives: a path on doinggoodleeds.org.uk or its own subdomain.
4. Privacy: phase 1 only shows what is already public. Phase 2 collects business contact details,
   which need VAL's privacy notice and a retention period.
5. Who to contact when something breaks, on each side.
