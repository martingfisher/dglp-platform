# Sources

`sources.json` holds 118 sources, built on 13 November 2026 from VAL's October 2026 "Sources of Grant Funding"
document (its funder links) plus major funders the document relies on but does not link.
Every URL was fetched through TinyFish (`POST /check`) and read into a funding result in a dry run
(`samples/dry-run.json`), 127 of 128 returned readable pages.

## Changes from the document's links

Left out: pages that are not funders (doinggoodleeds.org.uk, Facebook, Instagram, Crowdfunder, easyfundraising,
procurement portals, newsletter and tracking links, generic help and directory pages, Gov.uk Contracts Finder,
Leeds business rates, Charity Digital, CIOF, Good Finance, myfundingcentral, findingfinance).
Tracking parameters were stripped from URLs that kept them.

Added: Two Ridings Community Foundation, Comic Relief, Lloyds Bank Foundation, Tudor Trust, Postcode Community,
Local and Earth Trusts, Leeds City Council funds, Foyle, Co-op, Power to Change, Rank, Clothworkers,
Barrow Cadbury, JRCT, Nationwide, Youth Endowment Fund, Spirit of 2012, Persimmon, Sainsbury's,
Yorkshire Cricket Foundation, Localgiving, Sir Halley Stewart, Souter, Coalfields Regeneration Trust,
Childwick, Leeds Hospitals Charity, Community First Yorkshire, Energy Redress.

## URLs that were wrong and are fixed

- National Lottery Community Fund: the document's `/funding/programmes/...` links 404 now; the live path is
  `/funding/funding-programmes/...`.
- Allen Lane, Benefact Trust, Barrow Cadbury, Energy Redress, Leeds City Council (community committees):
  old links 404, replaced by pages found through TinyFish search.
- Artemis: the old page 404s; the live page says it is closed to new applications.

## Dropped because they cannot be read

| Source | Why |
|---|---|
| Arts Council England (open funds, project grants, music creators) | TinyFish answers `bot_blocked` |
| Womble Bond Dickinson Foundation | `bot_blocked` |
| Bernard Sunley Foundation | `empty_content` |
| Yorkshire Sport Foundation (return to play) | 404, no replacement found |
| Wates Foundation, Backstage Trust, Hospital Saturday Fund, Kirby Laing, Santander Foundation, NHS Charities Together, Garfield Weston (apply page), Big Give (page has no text), Wolfson | 404, unreachable or no readable text |

Arts Council England is the main gap: add it back if TinyFish can read it later, or add a manual entry.

## Still readable but thin

Co-op local community fund and Souter Charitable Trust return `unreadable` (bot wall or no text) and show as
`ok: false` each week without changing anything on the site. Many home pages (Esmee Fairbairn, Tudor, Lloyds,
Foyle, Co-op Foundation, Nationwide, Rank) read as `not_funding`; point them at the funder's "apply" page when
someone has time to look.

## Source review, 13 November 2026

Sources that returned no grants in the first dry run were re-pointed from funder home or "about" pages to the
page that describes the open programme, using TinyFish fetch and search. `notes` on each entry carries the
amounts and timing found.

Removed (cannot apply, or duplicate):

| Source | Why |
|---|---|
| elephant-trust | Grants to visual artists only |
| postcode-community-trust | Wales only |
| foyle-foundation | Permanently closed, all schemes ended 2025 |
| spirit-of-2012 | Spend-out trust, closed January 2026 |
| two-ridings | East Yorkshire, Hull, North Yorkshire and York only (not Leeds) |
| community-first-yorkshire | North Yorkshire and York only |
| souter-charitable-trust | Domain is a hosting placeholder, no site |
| leeds-neighbourhood-fund | Describes CIL allocation by community committees, no grant listing |
| sport-england-green | Duplicate of `sport-england-funding` (the Movement Fund) |
| henry-smith-charity | Old domain now redirects to `henry-smith-grants`; its current programmes are closed |
| sainsburys-communities | No direct application (store nominations through Neighbourly) |

Kept, with the reason in `notes` (expect little or nothing until they reopen): invitation only (Tudor Trust,
Postcode Earth Trust, Rank Foundation, Nationwide Foundation), closed now (Albert Hunt to new applicants,
Artemis, Co-op Foundation until 2027/2028, Comic Relief, Youth Endowment Fund open call paused, Lloyds Bank
Foundation round closed, Postcode Local Trust until the 2027 rounds, Charles Hayward Older People and Heritage),
limited fit for Leeds groups (Strategic Legal Fund, Theatres Trust, Forces in Mind Trust, UnLtd, Barrow Cadbury,
Childwick, Coalfields Regeneration Trust, Yorkshire Cricket Foundation, Alec Dickson Trust).

Added: `digital-inclusion-leeds-health-hub` (Digital Health Hub Fund, Leeds).

Re-pointed to the programme page: Charles Hayward, Alec Dickson, Albert Hunt, Hedley, Gosling, Masonic Charitable
Foundation (now freemasonscharity.org.uk), The Fore, Esmee Fairbairn, Strategic Legal Fund, Theatres Trust,
Forces in Mind Trust, UnLtd, Digital Inclusion Leeds, Comic Relief, Lloyds Bank Foundation, Tudor, Postcode Local
Trust, Postcode Earth Trust, Co-op Foundation, Clothworkers, JRCT, Nationwide, Youth Endowment Fund, Yorkshire
Cricket Foundation, Sir Halley Stewart, Leeds Hospitals Charity, Energy Redress (apply), Leeds Phil and Lit.

## Adding a source

Append `{id, name, url, funder?, notes?}` to `sources.json` (id short, kebab-case, never changed after
first use because it prefixes every key) and redeploy. `notes` starting with `Listing` marks a page that lists
many programmes: if a programme also has its own source the own-page copy wins.
