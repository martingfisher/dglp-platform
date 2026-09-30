# Spaces to hire

Built 30 September 2026, shipped in 0.41.0. The proposal it answers is
`docs/wireframes/spaces-to-hire-proposal.html` (25 September 2026), and
Martin's answers to its six questions, given on 30 September, are the
decisions below.

## What it is

A section of the public site, `/spaces/`, where member organisations list
rooms, halls, kitchens, gardens and whole buildings for hire, and anyone
planning a meeting, class or event in Leeds can find one by size, area,
price and access needs, then send an enquiry. Enquiry only: no booking,
no payment, no calendar.

## Decisions

- **Who can list.** Member organisations only, through the dashboard.
- **Enquiry, not booking.** The form on a venue page emails the venue.
- **Prices are optional.** A space with no rate shows "Price on request".
  A venue with at least one priced space ranks above one with none.
- **No member rate.** One rate per space. Listing is free while the
  organisation is a member.
- **Map in version one.** Leaflet with OpenStreetMap tiles, postcodes
  placed once through postcodes.io. List and ward filters as well.
- **Review.** Every new venue and space is reviewed. An organisation with
  the trust switches on updates prices, photos and availability without
  waiting, the same way its other listings work.

## The model

Organisation > Venue > Space.

- A **venue** (`dgl_venue`) is a building at one address: name, up to four
  photos, type, address, postcode, ward, access features, facilities,
  getting there, availability, good to know, how quickly they reply, and
  the contact details enquiries go to. It has a public page at
  `/spaces/<slug>/`.
- A **space** (`dgl_space`) is something you can hire inside it: a hall,
  meeting room, studio, kitchen, outdoor space, or the whole building. It
  carries a photo, capacities by layout (theatre, cabaret, boardroom,
  standing), floor area, what is in the room, an optional rate with its
  unit (an hour, a session, a day) and a note. It has no page of its own:
  its public address is the venue page plus `#space-<id>`.
- A space belongs to one venue from the moment it is created and cannot
  be moved. To list it under another venue, archive it and make it again.
- Neither expires. Both stay up until archived or taken down.

Both are ordinary submittable items, so the wizard, revisions, the review
queue, trust switches, audit, checks, reports and CSV export all apply
without special cases. A space has three wizard steps, not four: it has no
contact step, because enquiries go to the venue's contact. Neither has
topics.

## Listing one

Dashboard > Post something > Venue. Four steps as usual. When the venue is
saved (a draft, or live), its screen has "Add a space", which starts the
space wizard under that venue. "Post something > Space" asks which venue
first when the organisation has more than one. A venue's screen lists its
spaces with their states; a space's screen names its venue.

## What happens to spaces when a venue changes

A venue's status carries its spaces with it, as the same actor, in one
audit trail, with one email:

| Venue action | Its spaces |
|---|---|
| Archive | Draft, live, expired, rejected and needs-changes spaces are archived |
| Take off the site | Live spaces come off, with the same note |
| Reject | Pending spaces are rejected |
| Restore | Archived spaces are restored (through review) |
| Move to another organisation | Spaces move with it; a space cannot be moved alone |

A pending space under a venue that is not live stays pending. The review
screen's checks warn "the venue is not live yet" so the team approve the
venue first, or know why the space will not show.

## The public pages

**Find a space, `/spaces/`.** One row per venue with a photo, name, type,
ward, its spaces as a compact list (the whole-building option first, then
by size), the lowest hourly rate, and "N spaces, reply in two days". The
filters: search words, how many people, ward, kind of space, price, and
access needs (all chosen must be present). List and Map are a toggle; the
map is drawn only when asked for, from pins the page already carries, so
the list view loads nothing extra. Paging is `?pg=N`. A filtered or paged
list is `noindex`; the plain list is indexed.

**A venue page, `/spaces/<slug>/`.** Gallery of up to three photos (the
venue's, then its spaces'), a quick-facts strip (spaces, most people,
from £X an hour or Price on request, step-free), the description, one
card per space with capacities, tags, rate and its own Enquire button,
the "About the venue" list, the enquiry form, a "Where and who" card with
the address, contact name, phone, website and ward, and the map with one
pin and a "Bigger map" button that opens it in a dialog about 60% of the
screen wide, draggable and zoomable. The summary sits at the top of the
main column so the enquiry card starts level with it, and the card stays
in place as the rooms scroll past when it fits in the window; taller than
the window it sits in the flow, never clipped, and the page is at least as
deep as the card. On a phone a bar at the foot of the screen keeps the rate and Enquire
in view while the cards scroll.

**The organisation's page** in the directory has a "Spaces to hire at N
venues" block listing its live venues. Site search has a "Spaces to hire"
group. A venue's schema is `EventVenue` with address, geo, telephone,
photos and `maximumAttendeeCapacity`.

### Price bands

Hourly rates only: Free (rate 0), Up to £15, £15 to £30, Over £30, and
Price on request (no rate). A session or day rate counts as priced for
ranking and matches "Any price" but has no band of its own. "How many
people" matches a space when any of its four layouts takes that many.

### Ranking

Venues with at least one priced live space come first, then by name.
Within a venue the whole-building option is listed first, then spaces by
largest capacity.

## Enquiries

The form asks which space (pre-selected from the card's Enquire button,
with "Not sure yet"), date, times, how many people, what it is for, name,
email and phone. It emails the venue's contact address, or every owner of
the organisation when the venue has none, with Reply-To set to the
enquirer. Nothing is stored: the audit trail records that an enquiry was
sent for the venue, without the enquirer's details. No confirmation email
to the enquirer in version one.

Public pages are cached, so the form carries no WordPress nonce. It is
protected by a honeypot, a signed time stamp, an Origin or Referer check
against the site's own address, and rate limits of 10 an hour per email
address and 30 an hour per IP address. A venue with no contact address and
an organisation with no owners shows no form and says to contact the
organisation directly.

## The map

Leaflet 1.9.4 ships inside the plugin (`assets/vendor/leaflet/`, BSD
licence alongside), so nothing loads from a third-party script host. The
tiles come from `tile.openstreetmap.org` with the attribution their policy
asks for. If a content security policy is ever added to the site it needs
that host.

A venue is placed when it goes live and whenever an approved edit lands:
its postcode is looked up on postcodes.io (free, no key, Open Government
Licence) with a four-second timeout and the latitude and longitude are
stored on the venue (`dgl_lat`, `dgl_lng`). Never on a page view. Each
postcode's answer is cached for 30 days; a postcode the service does not
know is remembered for 30 days too, and a network or server failure for
an hour. `wp dgl spaces geocode` places every live venue without a pin;
`--all` refreshes every venue; `--dry-run` lists them; `wp dgl spaces
pins` shows what is stored.

## Commands

```
wp dgl spaces geocode [--all] [--dry-run]
wp dgl spaces pins
wp dgl demo spaces --org=<id> [--images] [--skip-geocode] [--dry-run]
wp dgl demo spaces --remove
```

The demo makes five venues and fourteen spaces: a community centre with a
whole-building day rate and a kitchen at Price on request, a church hall
with a session rate, a converted print works with a free studio, a scout
hut and a sports pavilion. Between them every price band, every layout
and every reply time.

## Not built, and known

- A space cannot move between venues.
- No day or session price bands.
- No booking, availability calendar or payment.
- No confirmation email to the enquirer, and enquiries are not kept.
- No distance search; the map shows what the filters found.
