# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Two audiences, and the design is held to the less confident one.

- **Members.** People at organisations in the Doing Good Leeds Partnership: the
  voluntary, community and social enterprise sector in Leeds. A member is an
  account attached to an organisation, and several people can share one
  organisation's dashboard. They range from a comms officer at a large charity
  who posts weekly from a desktop, to a single volunteer at a tiny group who
  posts a few times a year, between other tasks, sometimes on a phone. Confirmed
  16 September 2026: both matter, and the interface is designed for the least
  confident. Their job is to get an event, a news item or a training
  opportunity onto the partnership site, and to know what happened to it.
- **The DGLP review team.** A small number of staff who check every submission
  before it appears, verify organisations, and approve name and logo changes.
  They work from a front-end review queue and from wp-admin. Familiar with
  WordPress.

## Product Purpose

A member area for partnership.doinggoodleeds.org.uk. Verified member
organisations submit content through a short wizard; nothing appears on the
public site until the review team approves it; members receive digest emails
matching the content types and topics they asked for. Success is a listing
going from a member's keyboard to the public site with the member never in
doubt about where it is or what is being asked of them, and the review team
never publishing by accident.

## Positioning

The organisation owns the content, not the person who typed it. Any validated
member of an organisation can draft, edit and archive that organisation's
listings, and colleagues cover for each other. Every edit to approved content
is re-moderated, softened by trust levels the team grants. This is a bespoke
build rather than a forms plugin because a member editing an approved item and
resubmitting it is a stateful document, not a form entry.

## Operating Context

- A self-contained WordPress plugin on a Wordify-hosted site, Blocksy child
  theme, PHP 8.4. Staging is `partnership-doinggoodleeds-org-uk-stg.wordifysites.com`.
- The member area renders its own document inside the theme's stylesheet: no
  site header, menu, breadcrumb or footer, but Blocksy's CSS is present on the
  page and its element-level rules compete with the plugin's.
- Content types in this version: News, Events, Training. Grants and
  Volunteering exist in code and are hidden until a later version.
- Workflow: draft, awaiting review, changes requested, live, expired, archived,
  refused. Edits to live items open a pending revision while the live version
  stays up.
- The review team reads a front-end queue at `/dashboard/review` and wp-admin
  screens for organisations, verification and trust.
- Email: transactional notifications and daily, weekly or monthly digests.
- Target scale: 5,000 to 10,000 member accounts over the next few years.
- UK English throughout.

## Capabilities and Constraints

- Built: invitations, sign-in, the submission wizard, revisions, review queue,
  notifications, organisation profile with name and logo changes held for
  approval, email preferences, digests, public event pages and listings.
- Built since, all in 0.8.x: archive, restore and take-down; removing a
  member; self-serve joining with email-domain matching; an approval queue for
  new organisations; the team told when an organisation change is waiting.
- Built 17 September 2026: the organisation profile carries Forum Central's
  directory data in fixed lists, the import loads it, and any approved member
  can switch the organisation into or out of the directory.
- Not yet built, and known: the public directory page itself; Microsoft and
  Google sign-in; CSV export; consent records at
  registration; Turnstile on the join form; member-initiated account closure.
- Constraint: the plugin must not depend on the theme's markup. Theme updates
  cannot be allowed to break the member area.
- Constraint: images members upload are cut to 1600px and stored without
  metadata. Rich text is limited to paragraphs, bold, italic, lists and links.
- Terminology in use: member, organisation, owner, contributor, review team,
  submission, listing, live on site, awaiting review, needs your attention.
- Decided 16 September 2026 and recorded in `docs/joining.md`: the first
  person to join an organisation is its owner and later joiners by domain are
  contributors; the owner is emailed when somebody joins; a join link lasts 48
  hours and is single-use; a refused registration removes the organisation.

## Brand Commitments

- The member area is DGLP's, not RYCM's. Confirmed 16 September 2026: colour
  and type come from the live DGLP site, read from Blocksy's 18-colour palette
  and its Montserrat setting, consumed as `--theme-palette-color-N` custom
  properties with hard-coded fallbacks. Concept, structure and hierarchy come
  from the twelve wireframes in `docs/wireframes/`, whose colours and DM Sans
  are placeholders and are not binding.
- Palette in use: deep navy `#2D3B6B` (primary), Partnership purple `#5B6BAE`,
  ink `#1e3232`, page cream `#fdf8f0`, off white `#F4F6FB`, teal `#57b0b6` as
  a rail only, accent hover `#cc0053` for danger.
- The DGLP mark is media attachment 7156 on the site; a file has not been
  supplied separately.

## Evidence on Hand

- Twelve wireframes, screens 1a to 1l, in `docs/wireframes/`, with built
  screenshots alongside.
- The August 2026 proposal, which fixed the scope and the five content types.
- Real palette values from `wp option get theme_mods_blocksy-child` on staging.
- No testimonials, usage data or research with members. Nothing may be
  invented to stand in for them.

## Product Principles

1. **The least confident member sets the bar.** If a volunteer posting twice a
   year on a phone can do it without help, everybody can.
2. **State is never a mystery.** A member always knows where their listing is
   and whether anything is being asked of them; the review team always knows
   what is waiting on them.
3. **Nothing reaches the public without a decision.** No path publishes by
   accident.
4. **The organisation, not the person.** Colleagues see and can act on each
   other's work, and the interface says so.
5. **Familiar over clever.** Standard controls, one vocabulary, the tool
   disappears into the task.

## Accessibility & Inclusion

Confirmed 16 September 2026: WCAG 2.2 AA is the floor and AAA is the target
where practical. That means 7:1 for body and secondary text where achievable,
3:1 for control boundaries and state indicators, state never carried by colour
alone, visible focus on every control, and keyboard operation throughout. The
audience is known to include visually impaired users. Measured 17 September
2026 with `AAA=1 node bin/check-contrast.mjs`: the faintest text across the
member area and the public event pages is the amber status chip at 7.15:1.
Nothing rendered is below 7:1.
