# Organisation data and the directory

Jenny's file, received 17 September 2026: `FC_Member_Orgs_for_DGLP_directory.csv`,
a Forum Central (CiviCRM) export of 329 member organisations, 39 columns. This
records what was kept, what was dropped, and the two things DGLP need to
decide before any of it is public.

## What is stored, and where

Every organisation has these on the Organisation tab of the member area, in
five sections. An owner edits them; the review team sees them in wp-admin.

| Section | Field | From column | Stored as |
|---|---|---|---|
| About | Name, logo | Organisation Name | post title (name change goes through review) |
| About | Charity or company number | Charity/Company Number | text |
| About | Website | Website | URL, `https://` added when missing |
| About | Short description | Short Description of Organisation, else Short description of services delivered | text, cut at 400 |
| About | Public contact email | Email | email, any provider |
| About | Phone | Phone | text |
| Where | Address line 1 | Street Address | text |
| Where | Address line 2 | Supplemental Address 1 + 2 | text |
| Where | Town or city | City | text |
| Where | Postcode | Postal Code | postcode |
| Where | Ward | Ward Organisation is based in | one of 33 wards or Leeds-wide |
| What you do | Type of organisation | Organisation Type | several of 5 |
| What you do | Areas of work | FC specialism | several of 9 |
| What you do | Services you provide | General Service Provision | several of 52 |
| What you do | How you deliver them | General Service Delivery Type | several of 8 |
| Who you help | Who you work with | General Service Users | several of 46 |
| Who you help | Accessibility at your premises | Accessibility Provision | several of 6 |
| Size and status | Legal status | Legal Status | one of 9 |
| Size and status | Paid staff, Volunteers | Size columns | one of 4 bands |
| Size and status | Accreditations | Accreditations | several of 7 |

The lists are in `src/Org/Options.php`, generated from the values in the file
and nothing else. Stored values are stable keys, so labels can be reworded.

Read-only, shown under "From Forum Central's records": Contact ID, Volition
membership, LOPF membership, Age and Dementia Friendly Business, whether
permission to publish was given to Forum Central, and the import date.
Latitude and longitude are stored for a map later and not shown.

Dropped: Postal Greeting, Email Greeting, Street Name, Network/Board name,
Contact Type, Sort Name, Addressee, State (a county code), PCN (empty in every
row).

## What the file told us

- 329 organisations, no duplicate names.
- 251 have an email address. 24 of those are public providers (Gmail and the
  like), so 106 organisations have no domain anyone can join on. Their
  colleagues come in by invitation from the owner.
- Three domains are shared by more than one organisation (RVS, MHA, Mencap).
  The join flow offers a list in that case; nothing to do.
- 4 rows say their ward is "City", which is not a ward. Left blank; the
  owner can pick one.
- "City" and "State" columns carry street lines and county codes in places.
  Imported as given; owners can tidy.

## The public directory

Built 17 September 2026, deployed in 0.9.4.

- `/directory/` lists every organisation that is verified and has the
  directory switch on, A to Z, 24 to a page. A search box covers the name
  and the short description; four filters cover ward, area of work,
  service and who they work with. Filters and search combine.
- `/directory/<slug>/` is one organisation: description, every list
  grouped by category, contact details, legal status and size, and up to
  six of their live listings. A slug that matches nothing listed is a 404.
- Both pages render inside the theme's header and footer, like the event
  pages, and share `tokens.css`. Page titles are set for the browser tab.
- `wp dgl org directory-on` switches on every verified organisation whose
  Forum Central record says it gave permission to publish. Run once on
  staging: 146. The rest stay off until a member presses the button.
- Caching: the site's page cache excludes `/dashboard` only. A member who
  switches their listing on will not see it on `/directory/` until the
  cache expires (24 hours) unless `/directory` is excluded too. Done on
  staging; do the same on production.

## Decisions taken, 17 September 2026

Martin confirmed with the CEOs of Forum Central and DGL, who own the site
jointly, that Forum Central's permission to publish carries. So the 146 are
listed and the other 183 wait for a member to switch them on. The import
ran with `--approve`, so the list is treated as verified.

## Decision taken, 22 September 2026

The file is all there is. Where a value was cut in the export (descriptions,
address lines in the wrong column) nothing fuller is coming from Forum
Central. Members tidy their own organisation's record from the Organisation
tab once they have signed up; the import is not re-run for it. From 0.22.0
the site asks them to: an imported organisation carries a "please check
these details" prompt on the dashboard and the Organisation tab, with a line
under the short description saying it may have been cut short, until an
owner saves that tab (`Org::needs_check()`, `dgl_org_checked_at`).

## Two decisions that were open before that

1. **Permission.** 146 of 329 ticked "I am happy for the information I have
   provided above about this organisation to be made available online and
   shared where appropriate by Forum Central". That permission was given to
   Forum Central, not to DGLP, and 183 gave none. The build therefore lists
   nobody by default: every organisation is off until one of its members
   switches it on from the Organisation tab. If DGLP want the 146 shown from
   day one, that is a one-line command once they have satisfied themselves
   Forum Central's permission covers it. The other 183 need their own yes.
2. **Verification.** The import marks organisations verified only when run
   with `--approve`. Jenny's list is Forum Central's member list, so on
   staging it was run that way. DGLP should confirm they treat the list as
   verified for production too, because a verified organisation lets anyone
   with a matching email address join and submit without a check.

## Running it

```
wp dgl org import members.csv --dry-run
wp dgl org import members.csv --approve
```

Dry run writes nothing and reports counts, values that match no option, and
rows with problems. A second run matches on Contact ID, then exact name, and
updates rather than duplicates. The directory switch is never touched by an
import.
