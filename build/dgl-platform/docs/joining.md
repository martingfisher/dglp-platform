# Joining the site

How an organisation and its people get onto the member area. This is the
structural change DGLP asked for on 16 September, worked through against what
is actually built. Nothing in it is started yet.

## What DGLP have decided

1. **Organisations own dashboards, not people.** An organisation has one
   dashboard with several members, and any validated member can draft, edit
   and archive the organisation's content, whoever first wrote it.
2. **DGLP supply the organisation list.** We create the organisations from it
   before launch.
3. **Joining starts with an email address.** If its domain matches an
   organisation on the list, the person joins that organisation and is
   pre-approved. If it does not, they are asked to find their organisation on
   the list, or to create a new one.
4. **New organisations need DGLP's approval.** Domain-matched members do not.
5. **Every account is verified by email** before it is anything: a link is
   sent, and nothing exists until it is clicked.

## What is already true, and should not be rebuilt

**Permissions are organisation-scoped today.** `Access\Policy::owns()` compares
the user's organisation with the item's organisation and nothing else. The
author is recorded and confers no rights. `Access::map_meta_cap()` was written
to collapse every approval to `edit_dgl_items` for exactly this reason, and the
comment says so: "on this site the organisation owns the content, not the
individual who typed it." Decision 1 is the current behaviour.

**The verification pattern exists.** Invitations store a SHA-256 hash of a
single-use token, put the token in one email, and create the account only when
the link is used. Email verification for sign-up is the same mechanism with a
different email in front of it. Reuse `Invites\Store` as the model; do not
write a second token system.

**Organisation verification exists.** `dgl_org_status` is pending, approved or
suspended, and the wp-admin Organisations screen changes it. What is missing is
a queue: staff have to know which organisation to open.

**Account status exists as data.** `dgl_account_status` is pending, approved,
suspended or closed. Nothing writes pending, suspended or closed, and there is
no screen where staff approve a pending person. The dashboard banner promises
"as soon as the team approves you"; nothing backs it.

## What DGLP are assuming exists, and does not

**Archiving.** Decision 1 says members can archive the organisation's content.
Until 0.7.1 nothing called the state machine's ARCHIVE, RESTORE or TAKE_DOWN.
Now the item screen offers "Take off the site" (live) or "Archive" (anything
else not with the team) and "Restore" (which goes back through review), and
the review screen offers "Take it down" with a required reason. wp-admin and
the CLI still do not.

## The joining flow, step by step

Email first, as Martin said. Matching before creating is what stops a second
"Leeds Mind" appearing because somebody at Leeds Mind signed up with a Gmail
address.

```
1. Email address
      |
      v
2. Verification email sent. Nothing stored but a hashed token and the address.
      |
      v  (link clicked)
3. Domain looked up against every organisation's recorded domains.
      |
      +-- match ----------> join that organisation. Account approved.
      |                     Organisation's owner is told.
      |
      +-- no match -------> "Which organisation are you part of?"
                            search of the list, with the top matches shown.
                                |
                                +-- picked one --> join request. Account PENDING.
                                |                  Staff approve the person.
                                |
                                +-- not listed --> create the organisation.
                                                   Organisation PENDING and
                                                   account PENDING. Staff
                                                   approve the organisation,
                                                   which approves the person
                                                   as its first owner.
4. Set a name and password. Signed in.
```

Public email providers never match. `gmail.com`, `hotmail.co.uk`,
`outlook.com` and the rest go straight to step 3's search. If an organisation
has recorded `gmail.com` as its domain by mistake, anybody with a Gmail address
would otherwise walk in. The blocklist is a hard rule, not a warning.

## What has to be built

| # | Piece | Where |
|---|---|---|
| 1 | **Organisation domains.** A list of domains per organisation, shown and edited in the Organisations meta box. Exact match, lower-cased. An organisation can list several. | `Org\Schema`, `Admin\Organisations` |
| 2 | **Import.** `wp dgl org import <csv> --dry-run` for DGLP's list: name, domains, website, email, phone. Idempotent, reports duplicates by name and by domain, changes nothing without the flag removed. | new `Org\Command` |
| 3 | **Public-provider blocklist.** One list, one function, tested. | `Access` or `Org` |
| 4 | **Sign-up store.** Hashed token, address, chosen organisation or new-organisation details, expiry. No WordPress user until verified. Same shape as `Invites\Store`. | new `Signup\` |
| 5 | **The screens.** `/dashboard/join`, the "check your email" holding screen, the verification landing with the match outcome, the organisation search, the create-organisation form, the "we have your details" pending screen from wireframe 1c. | `Dashboard\Controller`, `templates/dashboard/` |
| 6 | **Approval queue.** People who claimed an organisation without a domain match, and new organisations. On the front-end review area, with approve and refuse and a reason. wp-admin gets the same list. | `Dashboard\Controller`, `Admin\` |
| 7 | **Emails.** Verify your address. You are in. Your request was approved. Your request was refused, with the reason. "Somebody has joined your organisation", to the owner. | `Email\` |
| 8 | **Archive, restore, take down.** Built 16 September (0.7.1): archive and restore on the item screen for members, take down with a reason on the review screen for staff. | done |
| 9 | **Removing a member.** Built 16 September (0.7.1): an owner removes anybody in their organisation except themselves; access ends immediately, the work stays, the person is emailed. | done |

## Decisions taken, 16 September 2026

Martin took the recommended answers, with one rule stated more firmly by
DGLP: **a person can only join a listed organisation if their email domain
matches it.** There is no "pick an organisation from the list" without a
match. Somebody at a listed organisation with a Gmail address needs an
invitation from an owner.

1. **Later joiners by domain are contributors.** The first person into an
   organisation with nobody in it, including one loaded from DGLP's list,
   becomes its owner.
2. **Owners are emailed when somebody joins by domain**, with a line saying
   the domain was the check and how to remove them if unrecognised.
3. **Exact domain match only.** `mail.charity.org.uk` is not
   `charity.org.uk`; the team record every domain an organisation uses, in
   the Organisations screen in wp-admin.
4. **A refused registration removes the organisation and closes the
   account**, with the reason emailed. Trying again means talking to the
   team. Self-retry was pointless once the must-match rule was in.

## Built in 0.8.0

Items 1, 3, 4, 5, 6, 7 and 9 above. Item 2, the CSV import, waits for the
real list; the domains field it would fill is there. The flow:

`/dashboard/join` asks for an address and sends a two-day, single-use link.
The link proves the address and looks up its domain. A match offers the
organisation: name, password, in. No match shows the register-an-organisation
form: the organisation is created pending with the address's domain recorded,
the person is its pending owner, they can draft, and the team are emailed. The
review queue lists new organisations to verify; `/dashboard/review/join/<id>`
verifies or refuses with a reason. Public providers never match.

## Decisions that were open

Each of these changes what gets built. Recommendations given.

1. **Later joiners by domain: owner or contributor?** Recommend contributor,
   with the owner able to promote. The first person at a new organisation is
   its owner. Making every domain match an owner means the fifth person to
   sign up from a big charity can remove the first four.
2. **Tell the owner when somebody joins by domain?** Recommend yes. A member
   appearing on the dashboard with nobody having been told is how a shared
   dashboard becomes a surprise.
3. **Subdomains.** `jo@mail.charity.org.uk` against `charity.org.uk`. Recommend
   exact match only, and organisations list every domain they use. Guessing at
   parent domains is how `leeds.gov.uk` matches things it should not.
4. **Refusing a join request.** A person who claimed an organisation they are
   not part of: refused with a reason, and can they try again? Recommend yes,
   with a different organisation, and the refusal is recorded against the
   address.

## Order

**First, so the dashboard can be walked now:** create one organisation on
staging and invite Martin into it with `wp dgl invite`. Data, not code. Ten
minutes.

**The dead ends the audit found** were closed in 0.7.3: the two profile tabs
balance, the no-access and suspended screens have a way out, a pending member
sees "saved as a draft" instead of a submit button and a refusal, accounts
with no organisation see why there are no tiles, and an empty facts list says
so. Archiving (#8) landed in 0.7.1.

**Then joining**, items 1 to 7 and 9, in that order. Domains and the import
first, because everything else needs an organisation list with domains on it
to match against.

**Then back to the public pages and Blocksy.**

## Verification

- Pure rules (domain matching, blocklist, which outcome an email gets, who may
  approve) in `tests/`, without WordPress, exhaustively.
- The full flow in the integration suite, reading the verification token out
  of the sent email the way the invitation tests do.
- Every screen walked in a browser, logged out, at 1200px and 390px, on
  WordPress 7.1.
- Leak test: an unverified sign-up must create no user, and a pending account
  must reach no organisation content.
- A dry-run import of a sample of DGLP's list before the real one, with the
  duplicate report read by a person.

## Next: a public directory of organisations

Asked for on 16 September 2026, for "at some point", not this release.

- A searchable directory of member organisations on the public site: name,
  description, website, logo, contact details, the things already held on
  the Organisation tab. Search by name and description; probably filter by
  the topic terms the site already has.
- **Visibility is the organisation's choice.** A toggle on the Organisation
  tab, "Show us in the directory", that any member of that dashboard can
  change, owner or contributor, with immediate effect and no review. Off by
  default until DGLP say otherwise, so nobody appears without choosing to.
- Only approved organisations can appear, whatever the toggle says. Pending
  and suspended ones never do.
- Rendered the way the event listing is: the plugin's own template inside
  the theme's header and footer, sharing `tokens.css`.

Not started. Nothing in the data model blocks it: the organisation is
already a post type with the fields, so the work is one meta flag, one
listing template with a search box, and a line on the Organisation tab.
