# Sources

`sources.json` holds 128 sources, built on 13 November 2026 from VAL's October 2026 "Sources of Grant Funding"
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

## Adding a source

Append `{id, name, url, funder?, notes?}` to `sources.json` (id short, kebab-case, never changed after
first use because it prefixes every key) and redeploy. `notes` starting with `Listing` marks a page that lists
many programmes: if a programme also has its own source the own-page copy wins.
