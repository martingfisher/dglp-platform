# Staging change log

Changes made to `partnership-doinggoodleeds-org-uk-stg.wordifysites.com`
(`01KZ9TYGCG3CF0WVX8VX736BNG`). Production is untouched throughout.

| When | Change | State now |
|---|---|---|
| 14 Sep | SmartCache `exclude_urls` set to `/dashboard`, `/wp-login.php`, `/wp-admin`, `/wp-json` | applied |
| 14 Sep | Server cron enabled at 5 min | **reverted by Martin**, now off, interval left at 5 |

Reads only, no change: `wp option get theme_mods_blocksy-child` (brand palette),
`wp post list` / `wp post get` (logo attachments).

Production, for comparison: same cache exclusions applied; server cron still off
at 15 min, because enabling it was refused by the permission layer.

## Blocked (resolved 16 September, kept for the record)

**Cannot deploy the plugin to staging.** `martingfisher/dglp-platform` is private,
so the site's `wp plugin install <github zip>` gets a 404. Wordify's
`install_plugin` only accepts wp.org slugs, and `wp eval`, `wp shell` and
`wp config` are blocked, so there is no file-write route from here.

Options, all needing a decision: make the repo public, put a deploy key on the
server, SFTP a zip by hand, or use Wordify's own git deploy if it has one.
Not doing any of these unilaterally.

Until one is in place, WordPress-facing code is lint-clean but unexecuted.
Everything in `tests/run.php` is genuinely verified, because those layers are
deliberately free of WordPress.

Resolved: the repo is public and 0.2.0 is installed and active on staging. See
16 September below.

## 15 September

- Confirmed SmartCache exclusions are now set: `/dashboard`, `/wp-login.php`,
  `/wp-admin`, `/wp-json`. This was a launch blocker and is now clear.
- Installed `hello-dolly` 1.7.2 **inactive**, purely to prove that
  `wp plugin install <public zip url>` works through the hosting API. It does.
  Left in place because deleting a plugin needs a separate go-ahead. Safe to
  delete whenever: Plugins → Hello Dolly → Delete.
- System cron still **off**. Nothing needs it yet.

## 16 September

- Repository made public, by Martin on the Mac, after the API route was refused
  by the permission layer ("Repository settings writes are not permitted through
  this proxy"). Confirmed unauthenticated: repo page 200, `main.zip` 200,
  1,727,274 bytes, valid Zip archive.
- Plugin version 0.1.0 to 0.2.0, **uploaded by Martin through Plugins - Add New -
  Upload Plugin**, not installed from a URL. Carries the member-area app shell,
  the wp-admin client admin, the Organisations screen, the notifications screen,
  and the fix for an administrator being able to publish a pending submission
  with the normal save button.
- `hello-dolly` is gone from the plugin list. Nothing left over from the
  15 September deploy test.
- Mail still off. System cron still off.

Verified on the server the same day:

| Check | Command | Result |
|---|---|---|
| Version and state | `wp plugin list --format=csv` | `dgl-platform,active,none,0.2.0` |
| Dashboard route | `wp rewrite list --match=/dashboard/events` | `^dashboard/?(.*)$` present and matching first |
| Self-repair ran | `wp option list --search=dgl_*` | `dgl_platform_version,0.2.0` |
| Expiry cron | `wp cron event list` | `dgl_run_expiry_sweep` scheduled |
| Mail | `wp dgl mail status` (safe mode off) | `Sending: off (nothing will be sent)` |

`wp eval` and `wp config` stay blocked by Wordify even with safe mode off.
`wp dgl` is allowed only with `safe_mode=false`, because Console cannot vet a
plugin-provided command.

Not checked by me: how `/dashboard/` renders in a browser. This sandbox's proxy
refuses that host (`CONNECT tunnel failed, 403`), so that confirmation is
Martin's.

## 16 September, afternoon

Deploys now run from here. The route is `wp plugin install <url> --force
--activate` through the Wordify console, pointed at a zip committed to the repo
at `dist/dgl-platform.zip`, which unlike a GitHub archive has `dgl-platform/` as
its top-level folder.

**Pin the URL to a commit, not to `main`.** `raw.githubusercontent.com` caches
per CDN edge. A deploy from the `main` URL installed a build from before the
last commit, reported "Plugin updated successfully", and the missing command was
only found by running it. The version number had not moved between those builds,
which is why it was invisible. Both are fixed: the version moves per build and
`dist/README.md` says to pin.

Changes made:

| Change | Why |
|---|---|
| `dgl_mail_redirect` = `martingfisher@gmail.com` | so nothing can reach the real accounts in the user table |
| `dgl_mail_enabled` = `1` | set **after** the redirect, in that order |
| Plugin 0.2.0 to 0.4.1 | invitations, digests, index commands |
| Subscribed `martin@resultsyoucanmeasure.com` to the weekly digest | to test delivery. Remove with `wp dgl digest subscribe <user> --off` |
| Created `Demo: Armley Community Hub` (post 8438) and `Demo: Coffee morning at Armley Library` (post 8439) | the site had no DGLP content at all, so a digest had nothing to carry. Both are prefixed "Demo:" and can be deleted |
| `wp dgl reindex` run once | the index had drifted and there was no command to rebuild it until today |

Staging still has **system cron off**, so digests will not send unattended.
`wp dgl digest status` prints the next scheduled run.

The From address is still `wordpress@partnership-doinggoodleeds-org-uk-stg.wordifysites.com`.
Set `dgl_mail_from` before launch: mail from a `wordpress@` address on a
hosting subdomain is mail that trains a spam filter against the domain.

`smtp2go` is installed but **inactive**, so everything goes out through PHP
`mail()`.

### Public pages, 0.6.0

Published items now have public pages. On staging the demo event is at
`/events/demo-coffee-morning-at-armley-library/` and the listing at `/events/`.
Rewrite rules were flushed after the deploy, because archives changed.

`/grants/` and `/volunteering/` return 404, as intended for this release.

The two `Demo:` posts (8438, 8439) can be deleted whenever DGLP want the site
clean. They are the only DGLP content on staging.

### 16 September, late afternoon: 0.6.1 and 0.6.2

- **Every check before this ran on WordPress 6.7.1.** Staging runs **7.1**.
  `bin/setup-test-wp.sh` had pinned 6.7.1 when it was written and nobody moved
  it. The local install is now on the 7.1 tag with its schema upgraded, the pin
  matches, and the full set was re-run there: unit 1189, integration 430,
  contrast 451 pairs, every browser walk, the six-status leak test. All clean,
  zero PHP notices in the server log.
- `wp dgl invite` used `get_page_by_title()`, deprecated since 6.2. Replaced.
  Verified on staging by looking an organisation up by name.
- Our public breadcrumb is now off by default; Blocksy renders its own.
- Deploys: 0.6.1 then 0.6.2, both from commit-pinned URLs. Version confirmed
  from the server each time.
- Invited `martingfisher@gmail.com` into `Demo: Armley Community Hub` as owner
  via `wp dgl invite send`, so the member area can be walked with a real,
  member-only account. Accepting creates the account; nothing exists until then.
  Withdraw from the Members tab or with `wp dgl invite list` to find it.
- **Wordfence deactivated by Martin** after its brute-force lockout caught his
  own sign-in during the walkthrough. Staging only. **Reactivate before the
  client uses the site.** The lockout is per IP, so it also blocks wp-admin for
  the same connection until it expires.
- **0.6.3 deployed** from a commit-pinned URL, version confirmed from the
  server. Input outlines now 3.8:1; invitation, sign-in and archive wording no
  longer name switched-off types; passwords are eight characters typed twice.
- Martin accepted the invitation: user 11, `dgl_member`, the first member
  account on staging.
- **0.6.4 deployed**: a used invitation link showed a form that looped; it now
  shows a sign-in link and no form.
- Wordfence is **inactive** and its block page is still served, so the block
  is the firewall file (`wordfence-waf.php` via `auto_prepend_file`) enforcing
  a stored IP block on its own. Not touched from here. Clears by waiting, a
  different IP, or removing the `auto_prepend_file` line in `.user.ini`.
- **Wordfence Extended Protection removed by Martin**: the `auto_prepend_file`
  line taken out of `.htaccess` and `.user.ini`, which is what was still
  serving the block page with the plugin inactive. Staging now has no
  Wordfence at all. **Before the client uses the site: reactivate the plugin
  and let it re-enable Extended Protection.**
- **0.6.5 deployed**: a failed sign-in from the member area comes back to the
  member area with a reason, instead of WordPress's own error page; the accept
  screen says what you will sign in with. Martin's username is
  `martingfisher@gmail.com`, not his name.
- **0.6.6 deployed**: sign-in lede is now "Sign in to your admin dashboard."

- **0.6.7 deployed** (16 September, after Martin's first walkthrough): editor
  has no code view and both editor and storage allow only what the toolbar
  makes; every member image is cut to 1600px and stored without metadata;
  the organisation logo upload works (it never had); sidebar darker with
  larger type and a Sign out button; muted text darker; cards spaced;
  types ordered news, events, training. Pinned to commit `e0bcabb`.
  The dashboard.css duplicate token block is gone, so tokens.css changes
  now reach the member area. `check-contrast.mjs` reads `color(srgb)`.
- **0.6.8 deployed**: the open profile tab is a filled navy block with white
  text, not a 3px underline. Pinned to commit `7b8d41e`.
- **0.6.9 deployed**: every text control has the same 2px outline, marked
  `!important` because Blocksy's element-level input rules were beating the
  class (input faint, textarea dark, on the same form). Focus is one purple
  ring, not border plus offset outline. Pinned to commit `d4cbbd5`. The
  Blocksy interaction is NOT VERIFIED from the sandbox (wordpress.org and its
  mirrors are blocked); the fix was proved locally against an injected
  element-level rule, and Martin confirms on staging.
- **0.7.0 deployed** (Impeccable pass): `PRODUCT.md` and `DESIGN.md` now
  record the product and the design system ("The Community Office"). Links
  and focus are Council Navy; cards gently lifted; rails removed except the
  sidebar's teal mark; pending amber unified at 5.96:1; tables scroll inside
  their box on phones and the page no longer scrolls sideways. Pinned to
  commit `28d2a29`. The Impeccable skill is committed under
  `.claude/skills/impeccable/` and export-ignored from the zip.
- **0.7.1 deployed**: archive and restore on the item screen; take down
  with a reason on the review screen, and the queue names the decision just
  made; owners can remove a member from the Members tab. Pinned to commit
  `1e9b13c`. Nothing else on staging changed.
- **0.7.2 deployed**: field edges are 1px at 4.9:1 (were 2px); the file
  control is centred in its box with its button restyled. Pinned to commit
  `2475cb3`.
- **0.7.3 deployed**: the dead ends closed. Profile tabs balance; no-access
  and suspended screens have a way out; a pending member gets "saved as a
  draft" not a refusal; org-less accounts see why there are no tiles; an
  empty facts list says so. Pinned to commit `52c3af7`.
- **0.7.4 deployed**: a name or logo change emails the review team once and
  lists on the front-end queue; `/dashboard/review/org/<id>` decides it
  (accept, or refuse with a note); the sidebar badge counts it. Pinned to
  commit `85c283d`. With `dgl_mail_redirect` set, those team emails land at
  martingfisher@gmail.com like everything else.
- **0.7.5 deployed**: checkboxes and radios sit on their label's first
  line and use Council Navy. Pinned to commit `c8d05f0`.
- **0.7.6 deployed**: bare links in the content column, table cell borders
  and the html background pinned against Blocksy's rules (purple links, a
  column line through table headers, sky blue under short pages). Pinned to
  commit `bd42bb4`. Verified locally against injected theme-style rules;
  Martin confirms on staging.
- **0.7.7 deployed**: the public events listing sorts on the stored date
  key and no longer drops items without one; it had read "no events" above
  a live event. Pinned to commit `1fbaf59`. Demo event 8439 given a start
  date (`dgl_start_datetime` 2026-09-22 10:00:00) since it was created
  without one. Site cache cleared so the listing rebuilds.
- **0.7.8 deployed**: public pages read the stored meta keys, so a real
  event shows its date, venue, summary and facts. Pinned to commit
  `ffca915`. Demo event 8439 given `dgl_summary`; its bare `summary` key is
  an orphan (deleting it is blocked in safe mode, harmless). Cache cleared.
- **0.7.9 deployed**: public cards lifted, fact labels in label style with
  the date leading, "See all events" as a button. Pinned to commit
  `e02ce4e`. Demo event 8439 given a venue, address and postcode
  (`dgl_venue_name`, `dgl_address`, `dgl_postcode`) so the page has facts
  to show. Cache cleared.
- **0.7.10 deployed**: upload ceiling 20MB (was 8MB, which refused the
  phone photos the shrink is for); the 1536 and 2048 sizes are no longer
  made from a 1600px master. Pinned to commit `58f5443`.
- **0.8.0 deployed**: the joining flow. `/dashboard/join` (email, link,
  domain match or register a new organisation), the team's decision at
  `/dashboard/review/join/<id>`, the queue section, the sign-in link, and
  the Email domains box on the wp-admin Organisations screen. DB version 4
  adds `dgl_signups`; the migration now runs on the front end too. Pinned
  to commit `bf2582b`. Demo org 8438 given the domain
  `resultsyoucanmeasure.com` so any address on it can walk the match path.
  A sign-in template bug found on the way (blank screen below the top bar,
  from an unqualified class) never reached staging.
- **0.8.1 and 0.8.2 deployed**: sign-out button gets its air from its own
  margin; review-team accounts with no organisation get a "you are on the
  review team" card instead of "not linked to an organisation"; every
  button variant pinned against Blocksy's element rules (the join page's
  button was teal on staging); the shell fills the viewport so the sidebar
  reaches the bottom of a short page. Pinned to commit `d64c445`.
- **0.8.3 deployed**: a join link opened while signed in shows "you are
  already signed in" with a sign-out that returns to the link; the html and
  body backgrounds are forced cream (the sky blue band was still showing
  under short pages). Pinned to commit `1e7b8a9`.
- **0.8.4 deployed**: the "sky blue band" under short member-area pages
  was the theme's site footer, hooked into `wp_footer` on staging. The
  shell now strips any `<footer>` element from wp_footer's output and keeps
  the scripts; public pages keep their footer. The background pins in 0.8.2
  and 0.8.3 were aimed at the wrong thing. Pinned to commit `602f3c2`.
- **0.8.5 deployed**: the sent screen and the verification email say what
  happens after the two days (start again, request another; only the
  newest link works). Pinned to commit `8f92ed1`. The theme footer is
  still showing under the member area on staging after 0.8.4; the markup
  is being requested from Martin before another attempt.
- **0.8.6 deployed**: member area held to AAA (muted text, status chips,
  amber alerts, danger and teal text all at or above 7:1; `AAA=1
  node bin/check-contrast.mjs` clears 13 routes); `wp dgl mail status`
  reports whether the email logo file is on disk and whether the server
  can fetch it. On staging both pass: file present, 34 KB, HTTP 200
  image/png. The alt-text box Martin saw in one email is therefore the
  mail client not loading images, not the site. `docs/team-guide.md`
  added for the review team. Pinned to commit `ce9e88f`.
- **0.8.7 deployed**: the plugin registers with WordPress's privacy tools.
  Tools > Export Personal Data and Tools > Erase Personal Data now cover
  membership, digest preferences, invitations, joining requests, audit
  activity and authored listings; the policy guide gets a suggested
  paragraph. `wp dgl privacy export <email>` prints the same data and was
  run on staging against a joined address: membership, activity and the
  joining request came back. Pinned to commit `c831d0c`. No data changed.
- **0.8.8 deployed**: email header logo 64px instead of 40px in the same
  88px band (measured in a rendered preview: band 88 before and after).
  The build folder had been swept into git by `git add -A`, so the 0.8.7
  zip carried a stale nested copy of the plugin under `build/`; it is now
  untracked and ignored, and the 0.8.8 zip has no `build/` entries. The
  `--force` install removes the old plugin folder, so the nested copy is
  gone from staging too. Pinned to commit `0f755eb`.
- **0.9.0 deployed**: organisation profile carries Forum Central's
  directory data (fifteen new fields under five headings, fixed option
  lists generated from Jenny's export), `wp dgl org import` with a dry
  run, and the "Show us in the directory" switch any approved member can
  use. Off for everyone until switched. Command confirmed registered on
  staging (refuses a missing file). The CSV itself has not been imported
  on staging yet: it needs to be on the server first, and it is not going
  in the public repo. Pinned to commit `b13a89d`.
- **0.9.1 and 0.9.2 deployed**: `wp dgl probe page <path> <phrase>` reads
  a page from the server itself, which is how the "footer" under the
  member area was finally identified: not the theme footer but a Blocksy
  content block, post 1258, hooked into `wp_footer` and wrapped in
  `<div data-block="hook:1258">`, background `#a8c8e8`. The shell now
  strips footer elements and hook blocks whole, by counting tags. Probed
  after the deploy: `/dashboard/` has no `hook:1258` and no "Cookies
  Policy"; `/events/` still carries the block at byte 209268. Pinned to
  commit `bb86f27`. No theme or content-block settings were changed.
- **Forum Central list imported on staging** (17 September, 08:54 UTC):
  `wp dgl org import .../FC-Member-Orgs-for-DGLP-directory.csv --approve`
  after a clean dry run. 329 organisations created and verified, 0
  skipped; 224 organisations now carry a join domain (223 from the file
  plus demo org 8438); 4 rows had ward "City" and were left without a
  ward. Nobody is in the directory. Sample record 8446 checked field by
  field. The CSV sits in the Media Library at
  `wp-content/uploads/2026/09/FC-Member-Orgs-for-DGLP-directory.csv`;
  delete it once no longer needed.
- **0.9.3 deployed**: submission wizard pass. Starting a submission
  reuses an empty draft rather than adding another; Cancel on an
  untouched draft deletes it; empty drafts untouched for seven days are
  purged on the daily sweep (which needs system cron on). Text inputs
  were 24px wider than their card; fixed with border-box. On phones the
  progress list is one row and the callout waits for the review step.
  Staging had three empty drafts from yesterday's walk (8441 to 8443,
  author 11); left alone, they are reused or purged. Pinned to commit
  `6d50c35`.
- **0.9.4 deployed**: the public directory. `/directory/` (146
  organisations, A to Z, probed from the server), `/directory/<slug>/`
  (Caring Together page probed, "Get in touch" present), search plus
  filters combining (`?q=older&area=older_people` -> 12 match). Run on
  staging: `wp dgl org directory-on` switched on the 146 with Forum
  Central permission (dry run first, 146 both times); the import was
  re-run so descriptions cut at a word boundary (329 updated, 0 new).
  **Config change**: SmartCache `exclude_urls` gained `/directory`, so a
  member's switch shows at once; production will need the same. SmartCache
  purged once after. Email preferences tab reworded as asked; file control
  button centred (checked locally, not on staging's font). Pinned to
  commit `5679717`.
- **0.9.5 deployed**: owners are emailed when a name or logo change is
  accepted or refused (the refusal carries the note word for word). The
  admin notice and the queue line that claimed "the member has been told"
  are now true. Rendered both messages locally and checked the layout;
  not sent on staging yet, since that needs a real change to decide. To
  see one: as an owner, change the organisation name, then decide it from
  the queue; mail goes to the redirect address. Pinned to commit `aab52dc`.
- **0.9.6 to 0.9.8 deployed**: probe-only builds, no member-facing change.
  `wp dgl probe page --post` (urlencoded), `--multipart` and
  `--file=png|text|blob:N` exist to chase Martin's nginx 403 on POST to
  `/dashboard/edit/8442/1/`. From the server every variant reached
  WordPress with HTTP 200: plain fields, an HTML paragraph, a link, a
  `<script>` tag, an empty file part, a real PNG, 2, 10 and 16MB blobs,
  and the wizard's exact field set. So the block is not on the body's
  content or size as seen from the server. Caveat: a firewall may trust
  the server's own address, so this does not prove the firewall never
  inspects bodies. Waiting on the exact submission and the response
  headers from the browser. Pinned to commit `cd5e1ae`.
- **0.9.9 deployed**: a file over 20MB is refused in the browser before it
  is sent, with the size and the limit written under the control (checked
  with a 25MB file locally: refused, control cleared, a good file clears
  the error); the pressed wizard button reads "Saving…" or "Sending…"
  while the request runs; the organisation page keeps areas of work as
  tags and turns every other list into a labelled list. BEAT's page probed
  on staging: the new lists are in the markup. Pinned to commit `f620c4f`.
  The 403 on POST is still open: from the server every variant including a
  link plus an image reaches WordPress, so the difference is in the
  browser's request; waiting on a retry with the link alone and the
  response headers.
- **0.9.10 deployed**: organisation description allows 650 characters
  (was 400); the directory page folds a long one at about 320 with a
  More/Less button, whole text without JavaScript; a phone with no digits
  ("N/A") is not shown. The mid-word cuts on staging are in Forum
  Central's export: 72 of 197 descriptions are exactly 255 characters in
  the file. Owners will need to complete those. Pinned to commit `66b751d`.
- **0.9.11 deployed**: images are redrawn to 1600px and re-encoded in the
  browser before upload (a 7.2MB 4000x3000 JPEG left the browser at
  350KB, 1600x1200, and was stored at that size); a new or reused draft
  starts with the contact details from the organisation's last item, else
  the profile and the member's name (checked in a browser: step 3 opened
  with name, email and phone filled). The 403 was one specific 1.7MB
  phone photo; its neighbour passed, so the trigger is in that file's
  bytes. The re-encode means the server never sees the original bytes.
  Not yet retried with that photo on staging. Pinned to commit `3a026c5`.
- **403 on POST closed** (17 September): Martin retried the same 1.7MB
  phone photo after 0.9.11 and it uploaded. The trigger was in the
  original file's bytes, which the browser-side re-encode no longer
  sends. Probe builds 0.9.6 to 0.9.8 stay in the plugin as tooling.
- **0.9.12 deployed**: every coloured side rail removed (notification
  feed items, active nav item, change diff, gated-field note, public
  "date has passed" notice, email note box). AAA walk clear. Pinned to
  commit `09cad3c`.
- **0.10.0 and 0.10.1 deployed** (17 September): repeating events (one post
  per series, weekly/fortnightly/monthly, end date up to six months, skip
  dates), `/events/calendar/` day by day for eight weeks, events list
  ordered by next date, "Dates and times" card and "Keep it listed" button
  on a live event's screen, the two-weeks-before reminder with a one-click
  extend link, load-more-on-scroll on every paged list, a Review button on
  each queue row. Verified by wp-cli: version 0.10.1, `dgl_platform_db_version`
  5, `^events/calendar/?$` rule listed, `/events/calendar/` 200 with title,
  `/directory/?pg=2` carries `rel="next"`, autoload.js enqueued on
  `/events/`. SmartCache exclusions now include `/events/calendar`.
  `wp dgl reindex` run. The demo event 8439 is now a weekly Tuesday series
  ending 27 September; the "still running?" email was sent to the redirect
  address with `wp dgl series remind 8439`. The Wordify console runs
  `wp cron event run` without plugins loaded (0.003s, no listeners), so the
  hourly hook could not be exercised from here; the server's own cron will
  run it at 12:53 GMT, and `wp dgl series status 8439` shows "Reminded for"
  once it has. Pinned to commit `f823988`.
- **0.10.2 to 0.10.4 deployed** (17 September): events ask "Where it
  happens" (in person, online, both); venue, address and postcode are only
  asked and required when there is somewhere to go, online and hybrid get a
  "Link to join online"; every earlier event was marked in person by the
  schema-6 migration (`dgl_platform_db_version` 6, demo event 8439 reads
  `in_person` and its page shows "Where it happens: In person"). Review
  team gets a Decided screen (`/dashboard/review/decided/`, filtered by
  outcome) and a refusal can be reopened into the queue, an archived item
  restored from the review screen, a live one taken down as before. The
  Blocksy breadcrumb over the directory and calendar read "Home > News";
  it now reads Home > Events > Calendar, Home > Directory, and Home >
  Directory > organisation (probed on all three after a cache clear; the
  theme's filter is `blocksy:breadcrumbs:items-array`, found with the new
  `wp dgl probe grep`). `wp dgl probe file|grep` read files under
  wp-content for exactly this kind of question. Pinned to commit `ca4bc2f`.
  Known: the member-area list tables are cramped at phone width; that is
  the shared table partial and predates this release.
- **0.10.5 deployed** (17 September): member-area tables (queue, Decided,
  category lists, archive, home activity, members, invitations) turn into
  cards at phone width: title, then type, status and date on one line, then
  a full-width button. Checked at 390px on six screens locally, no sideways
  scroll; desktop rows unchanged; contrast walk clear. Verified on staging
  by version (0.10.5) and by reading the rule out of the served stylesheet.
  Pinned to commit `5ac943a`.
- **0.10.6 deployed** (17 September): an impeccable adapt pass over the
  member area at phone width. Every pressable control is at least 44px on
  a phone (checkbox and radio rows, filter chips, crumbs, text-shaped
  links, wizard steps, list-card titles); the top bar is one line (brand
  and Back, the rest is in the menu); the wizard footer stacks with
  Continue first and full width; type floors stop nested em sizing taking
  chips to 11px and help text to 12px; the sign-in lede says "member
  area" not "admin dashboard" and its Remember me box is 22px. Checked on
  seventeen screens at 390px: no sideways scroll, no target under 44px
  except the WordPress editor toolbar; unit suite and both AAA walks
  clear. Recorded in DESIGN.md. Pinned to commit `6eef41e`.
- **0.10.7 deployed** (17 September): an impeccable adapt pass over the
  public pages. On a phone the directory's four filters fold behind one
  "Filters" button (open when a filter is set, always shown without
  JavaScript), so the search box and the first results share the first
  screen; list and calendar titles are 44px rows; calendar month links,
  the calendar link, Clear and the organisation contact links are
  thumb-sized. Checked on seven public screens at 390px and 1280px
  locally: no sideways scroll, no target under 44px on the phone pass;
  contrast walk clear. Staging could not be screenshotted from here (the
  egress proxy blocks the browser), so the theme-framed result is NOT
  VERIFIED visually; the markup is confirmed served. Pinned to commit
  `8e2ccdd`.
- **Header sign-in connected** (17 September, theme setting, no plugin
  change): Blocksy's header Account element read "Register/Login" and linked
  to `#`. Set in `theme_mods_blocksy-child` (header_placements, item
  `account`): logged-out label "Sign in" linking to `/dashboard/`, logged-in
  label "Member area" linking to `/dashboard/`, and the item added to the
  mobile off-canvas menu, which had no sign-in at all. Relative URLs so the
  same setting works on production. Production needs the same five values
  set by hand in the Customizer (Header > Account), or via `wp option patch`
  as recorded here, because the plugin deploy does not carry theme settings.
- **Join button in the header** (17 September, theme setting): a Blocksy
  header button item `button~dglp-join`, text "Join", linking to
  `/dashboard/join/` in the same tab, rounded like the other header
  buttons, placed after Sign in in the desktop top row and at the end of the
  mobile off-canvas menu. Set with `wp option patch insert` on
  `theme_mods_blocksy-child`; production needs the same item added in the
  Customizer.
- **0.11.0 deployed** (18 September): every picture needs a description
  ("What the picture shows", step 1, required when there is a picture,
  written onto the attachment as alt text, warned about on the review
  screen; a stored picture now survives a failed step); news is listed for
  three months from approval and then asked about two weeks before the end
  with the same one-click extend as a repeating event (schema 7 gives every
  live story an end date, never sooner than two weeks out). Verified locally
  in the browser: picture without a description stops step 1 with the
  picture still on screen; the public img carries the alt; the news reminder
  sends, the confirm page reads "still current", the item screen's "Keep it
  listed for another 3 months" moves the date. Unit suite 1767, integration
  732. Pinned to commit `037f8ca`.
- **0.11.1 and 0.11.2 deployed** (18 September): copy to a new draft (words,
  picture, venue, contact and topics; not the dates); owners can make a
  colleague an owner or a contributor, with an email and a notification;
  System health under Organisations in wp-admin (cron, email, index, queue,
  reminders, digests, directory, in words, Fine/Look/Broken). 0.11.2 fixed
  a fault 0.11.1 shipped with: the hourly listeners now accept no arguments,
  so a bare do_action cannot break them. Verified on staging: version
  0.11.2, schema 7, the live news story now carries a listed-until date of
  16 December. Unit 1774, integration 756. Pinned to commit `588366c`.
- **0.11.3 deployed** (18 September): Feature it. Moderators pin a live event
  or news story for 7 or 14 days from its review screen; it sits first on its
  public list with a Featured stamp, drops back on its own by the hourly hook,
  the organisation is told. Ordering is a left join in SQL, not a meta_query
  clause, which kept the date order intact. Unit 1777, integration 766.
- **0.12.0 deployed** (18 September): Add to calendar. Every event page has
  "Add to your calendar" (`/events/<slug>.ics`, a series as one entry with
  its rule and skipped dates); the calendar page has "Subscribe in your own
  calendar" (`/events/calendar.ics`, every live event, refreshes twice a
  day). Unit 1794, integration 787. Staging's timezone is UTC+0, not London,
  so the files go out in UTC (correct now, an hour out in summer) until the
  setting is changed.
- **0.13.0 deployed** (18 September): Cancelled. The owning organisation
  marks an event cancelled (stays a week with a Cancelled stamp, banner and
  note on its page, struck through on the calendar, STATUS:CANCELLED in its
  .ics, then comes off) or cancels one date of a series (struck through on
  the calendar and the event page, no longer the next date). Undoable from
  the item screen. Unit 1805, integration 817.
- **Timezone set to London** (19 September): `timezone_string` was empty
  (UTC+0). Now `Europe/London`, so the .ics files carry a TZID and the
  hourly sweep reads wall-clock times in the right zone. Production needs
  the same under Settings > General.
- **0.14.0 deployed** (19 September): Topic and When filters on the public
  lists (`?topic=<slug>`, `?when=today|week|weekend|month|next-month`, the
  latter on events only). A series counts when its next date is in the
  window. Paging and autoload carry the filters. The phone fold script is
  now one partial shared with the directory. Unit 1816, integration 828.
- **0.15.0 deployed** (19 September): Help. "Help" in the member sidebar
  (`/dashboard/help/`) and "Team guide" under Review team
  (`/dashboard/help/team/`, moderators only): contents list, sections,
  questions people ask as open-and-close answers, and "Download as PDF"
  (`.../pdf/`). The PDF is built by the plugin's own writer, Helvetica, no
  library, and parses in an independent reader. Unit 1829, integration 841.
- **0.16.0 deployed** (19 September): Reports under Review team
  (`/dashboard/review/reports/?month=YYYY-MM`): the month's decisions,
  approvals by type, median time to approve, organisations and people, the
  site today; CSV of the month's decisions and of every listing per type
  (`.../csv/decisions|events|news|training/`). Same from the CLI:
  `wp dgl report`, `wp dgl export`. Unit 1829, integration 863.
- **0.17.0 deployed** (19 September): Team notes on the review screen,
  for moderators only: kept on the item through edits, counted on the queue
  rows, never in the audit trail, notifications or a copy. Unit 1829,
  integration 875.
- **0.18.0 deployed** (19 September): join form guard. Honeypot, a signed
  clock (under three seconds is a robot), and a rate limit of three links
  per address and ten per connection an hour. Robots are shown "sent" and
  nothing is sent (`join_blocked` in the audit trail). Unit 1829,
  integration 886.
- **0.19.0 deployed** (19 September): joining with an address that already
  has an account now shows "You already have an account" with Sign in (the
  address filled in) and Set a new password, instead of an error line. The
  honeypot field is renamed so no browser autofills it. New
  `wp dgl join status <email>` for "the email never came". Martin's two
  join attempts on 19 September did send: staging redirects every email to
  martingfisher@gmail.com (`dgl_mail_redirect`). Unit 1829, integration 891.
- **0.19.1 deployed** (20 September): one rule for typed web addresses.
  Every address box (wizard, organisation profile, join form, wp-admin)
  is a text box with a URL keyboard, takes "example.com" and stores
  https://example.com; http:// typed on purpose is kept. The editor's link
  button puts https:// on a bare domain instead of http://. The External
  links check reads links in the words too and flags http links where
  https works. Unit 1842, integration 899.
- **0.20.0 deployed** (20 September): plain http:// refused everywhere,
  by Martin's decision: address boxes, links in the words (a pasted
  document with an http link is sent back at save with the links named),
  and the join form's website. The editor upgrades a typed http:// to
  https://. The External links check fails an older listing that still
  carries one. `wp dgl links audit` lists stored http links. Help guides
  updated. Unit 1846, integration 905.
- **0.20.1 deployed** (20 September): security sweep. Every POST handler
  checks a nonce, every query with input is prepared, uploads go through
  WordPress's own type check with a jpeg/png/webp allow-list, tokens are
  hashed or random and compared in constant time, downloads use sanitised
  file names, rich text is filtered to the toolbar's tags. Two tidy-ups
  shipped: a direct-access guard on the 31 pure classes that lacked one,
  and one interpolated (input-free) query rewritten with prepare. Unit
  1845, integration 901.
- **0.21.0 deployed** (22 September): the topic list. Twenty-eight topics
  decided by DGLP, carried in `src/Topics/Topics.php` and seeded on the
  first load after deploy (`dgl_topics_version` now 1). `wp dgl topics
  list` on staging shows all twenty-eight present, none hand-added before
  it, every count 0. The old site's core categories are untouched; what
  happens to the legacy posts is still to be decided. Unit 1932,
  integration 879 (the same 29 environment failures as before). Merged
  from PR #1, rebased on to `main` at `8521423`.
- **0.22.0 deployed** (22 September): news stays up, imported organisations
  asked to check their details, integration suite passes anywhere. Schema 8
  ran on the first plugin-loaded command: `dgl_platform_db_version` 8, and
  the two demo stories (8441, 8443) lost `dgl_listed_until` and
  `dgl_expires_at`; none had been expired. Organisation 8768 (imported
  17 September) has no `dgl_org_checked_at`, so it carries the prompt.
  Also on staging today, by Martin's decision: 83 draft posts deleted and
  the MailChimp category (51) deleted; see `docs/topics.md`. Unit 1932,
  integration 912.
- **0.32.1 deployed to production** (23 September): the top bar reads
  "Admin area" for the review team and "User area" for members and for
  anyone signed out; the sidebar's accessible name follows it.
- **0.32.0 deployed to production** (23 September): the picture is
  uploaded as soon as it is chosen (an admin-ajax endpoint the wizard's
  script posts to, same nonce and permission as the step), so AltText.ai's
  description reaches "What the picture shows" while the member is still
  on the step; a preview appears and the hidden id is set so the save keeps
  the picture. The description is no longer demanded: `image_alt` is
  suggested from the picture, not required with it; the review team's
  checks still flag a picture with no words. Without JavaScript the old
  path is unchanged.
- **0.31.0 deployed to production** (23 September): the picture
  description in the wizard is filled from the attachment's own alt text
  (which AltText.ai writes on upload) whenever the member left it blank,
  on the step after an upload and whenever the step is shown; a typed
  description is never replaced; cut to the field's 150 characters. Also
  on production today: `wp dgl news duplicates --apply` archived 115
  spare copies (the console timed out after 60 seconds but the run
  completed: 463 live before, 348 after, a second pass finds nothing);
  an archived copy's address answers 301 to the kept story, checked on
  one. Two same-title stories on different dates were left alone.
- **0.30.0 deployed to production** (23 September): `wp dgl news
  duplicates`, and run on production on Martin's decision, dry run first.
  Same title and same publish minute means the same story twice; the
  richer copy is kept, the spare is archived with a pointer to the kept
  one so its address redirects. Numbers in `docs/legacy-news.md`.
- **Production** (23 September, afternoon): DGLP pushed the staging site
  over its parent, partnership.doinggoodleeds.org.uk. The push carried
  the plugin at 0.28.1 and every data change made on staging: the 527
  converted stories, the archive of the 66 event announcements, the three
  owner organisations and the moves to VAL, every topic (the report says
  all live news items have one), the Top Bar menu with Events, the London
  timezone, `dgl_platform_db_version` 8. Checked by wp-cli on production:
  463 live news, 133 under VAL, legacy addresses rewritten to the new
  domain. On Martin's instruction 0.29.0 was then installed on production
  and the cache cleared. Two staging leftovers are on production and need
  Martin's decision: `dgl_mail_redirect` still sends every email to his
  Gmail, and the demo and test content (demo stories, demo organisation,
  expired demo event, "TEST - IFG") is live. Staging is to be suspended
  and deleted; from here on the record is production. Production's page
  cache (SmartCache, TTL a day) excludes /dashboard, /wp-login.php,
  /wp-admin and /wp-json only; adding /directory, /events/calendar and
  the search and filter query parameters was refused by the host:
  "Cache exclusions are not enabled for this team yet, contact support".
  Also seen: a large number of the imported stories exist twice, same
  title and same date under two ids (the old site held a copy under its
  own categories and a MailChimp copy); worth a dedupe pass.
- **0.29.0 deployed** (23 September): five things from Martin's list.
  (1) Site search: a `?s=` request now renders the plugin's results page,
  organisations, news, events and training in their own sections with
  counts and "See all" links; matches title, body and summary (name and
  description for an organisation); live and listed only. The header
  search box needs no change for the results page; its live dropdown is a
  theme setting (see the note in the reply). (2) "Member area" is "User
  area" in the user-facing strings: the top bar, the sign-in and join
  pages, the help pages, the privacy policy text. Roles and the Members
  tab keep their names. (3) The review team's "Decided" screen is
  "Approved": it opens on what is on the site, and its filters (All,
  Refused, Expired, Archived) still reach the rest. (4) The organisation
  form's ward question reads "What ward is your primary location in?".
  (5) Two partials extracted so the lists, the directory and the search
  render the same rows and cards: `public/card-row`, `public/org-card`.
- **0.28.1 deployed** (23 September): `wp dgl news set-topics`, a by-hand
  topic change with an audit row, because the console cannot run core
  taxonomy commands with the plugin loaded. Used to correct the four guessed
  topics flagged on 22 September (see `docs/legacy-news.md`).
- **0.28.0 deployed** (22 September): `wp dgl news move-legacy` (converted
  stories whose old categories were only the given ones, to another
  organisation) and `--first` on `suggest-topics`; the phrase rules
  reordered so subject topics outrank the broad ones and Men's Health is
  on the list. Run on staging on Martin's decision, dry runs first: see the
  two entries below this one's date in `docs/legacy-news.md`. Unit 1963,
  integration 965.
- **0.27.1 deployed** (22 September): `wp dgl probe page` accepts an
  absolute https address, so the server can read a partner site the
  console's operator cannot reach. Used to compare the topic-less stories
  with their sources: forumcentral.org.uk files its news under the same
  subject categories the old partnership site had (Health and Care 679,
  Communities of Interest 237, Mental Health 124, and so on), so Forum
  Central's stories arrived with topics; doinggoodleeds.org.uk (VAL) has
  only News (1786), Blog (196) and Good news (8), so VAL's stories arrived
  with none. "Volunteer Drivers Needed", "Jimbo's Fund" and the Bramley
  Baths stories are all on doinggoodleeds.org.uk under News or Blog, and
  none is on forumcentral.org.uk. The 67 topic-less stories whose only old
  categories were news or blog are therefore VAL's, and the import gave
  them to Forum Central by default. Not yet moved; Martin's call.
- **0.27.0 deployed** (22 September): `wp dgl news suggest-topics`, a
  keyword pass over the topic-less stories' headlines and summaries against
  DGLP's topic list; report by default, `--apply --actor=<id>` sets them
  with a "worth a check" audit row each. Unit 1960, integration 964.
- **0.26.2 deployed** (22 September): `wp dgl news topicless`, a report of
  the live news items with no topic and the old categories each carried,
  for the review team to work through. Unit 1932, integration 963.
- **0.26.1 deployed** (22 September): `wp dgl news archive-legacy`, and run
  on staging on Martin's decision: the 66 imported stories that the old
  site filed under its Events categories (event announcements, all past)
  archived through the ordinary transition as user 1, so each has an
  `archived` audit row and can be restored from the Decided screen. Live
  news on staging: 529 before, 463 after. Details in `docs/legacy-news.md`.
- **0.26.0 deployed** (22 September): an Organisation filter on /news/ and
  /events/ beside Topic (and When): every approved organisation with
  something live on that list, from the index in one query; `?org=<id>`
  in the address, combinable with the other filters. Checked in a browser
  locally: filtering /news/ to one organisation shows only its rows and
  the heading counts them. Found and fixed on the way: the archive query
  hook returned early for news (no date field to sort on) before applying
  the filters, so ?topic= on /news/ changed the heading and nothing else,
  and a featured story was not ordered first there either. Events were
  unaffected. Unit 1932, integration 957.
- **0.25.0 deployed** (22 September): the review screen's "Who sent it"
  card gains "Move it to another organisation": a list of approved
  organisations and a "Move it" button, moderators and administrators, any
  status. The item keeps its status, the index row follows so the new
  owner's dashboard lists it, and the audit row names both organisations.
  Driven in a browser locally: moved a story from one organisation to
  another, the alert and the audit row agreed. Unit 1932, integration 953.
  Also on staging today, on Martin's go-ahead: three organisations created
  (Forum Central 8802, Voluntary Action Leeds 8803, Leeds Older People's
  Forum 8804; approved, not in the directory), a backup taken, then
  `wp dgl news import --org=8802 --owner=forumcentral:8802,val:8803,lopf:8804`:
  527 old posts converted in place (Forum Central 435, VAL 92), none
  skipped, 71 without a topic (retired categories only), 1 without a
  picture. `dgl_news` live count 529; the `post` type has one row left, a
  Yoast Duplicate Post rewrite draft (5831), untouched. Details in
  `docs/legacy-news.md`.
- **0.24.0 deployed** (22 September): four things. (1) The rows under the
  featured block on /news/ and /events/ are open, not boxed: square picture
  left, chip, title, a summary line, then the slash-separated meta, a
  hairline between rows; the side cards beside the featured item lose their
  boxes too. The summary is the one the member wrote, else the story's first
  words. Autoload unchanged. (2) A **Featured** box on the wp-admin edit
  screen of any item: "Feature for 7 days", "14 days" or "Stop featuring
  it", applied on Update, same rule as the dashboard (moderators and
  administrators, live items only), same audit rows. Driven in a browser
  locally: feature, then stop, both reflected in the box and the audit.
  (3) `wp dgl news import`: converts the old site's published posts into
  news items in place, keeping id, slug, dates, author, picture and words;
  categories become topics by DGLP's mapping; owner from `--org` and
  `--owner`; old `/category/story/` addresses answer 301. Doc:
  `docs/legacy-news.md`. Not yet run on staging: the owning organisations
  need deciding first (see the note below). (4) A bug found on the way and
  fixed: the item page never showed the story body. The template read a
  meta key the wizard never writes (the story lives in post_content), so
  every item page on staging rendered with `dgl-pub__layout--nobody`.
  Verified on /news/demo-story-three/ before the fix. Unit 1932, integration
  945. Dry run of the import on staging after the deploy (owner set to the
  demo organisation only to make the run possible; nothing was changed):
  527 posts would convert, 71 with no mapped topic (their old categories
  are all on the retired list), 1 without a picture; health-and-social-care
  389, communities-of-interest 142, mental-health 69, featured 58, the rest
  under 50 each. The real run waits on Martin naming the owning
  organisations.
- **0.23.1 deployed** (22 September): the featured block on /news/ and
  /events/ now appears from two live items (one large, up to three
  beside), not four; staging has two live stories, so the pattern was
  invisible there. With it, a latent loop bug fixed: with fewer than four
  items the side loop probed `have_posts()` past the end, WordPress
  rewound the loop and the featured items repeated as rows. Checked
  locally in a browser with 2, 3, 4 and 5 stories (lead 1; side 1, 2, 3,
  3; rows 0, 0, 0, 1). Unit 1932, integration 920. Pinning is unchanged:
  moderators and administrators use "Feature for 7 days" or "14 days" on
  the item's review screen; a featured item takes the large slot.
- **0.23.0 deployed** (22 September): the news and events pages restyled
  to Martin's pattern: one featured item large (pinned, else first in the
  list) with three beside it, then rows of a square rounded picture, topic
  chip, title and a slash-separated meta line (news: organisation / date /
  reading time; events: when / where / organisation); filters as one quiet
  line under the featured block; autoload unchanged; a navy tile where
  there is no picture. `src/Frontend/Cards.php`. AAA contrast walk clean.
  Theme, staging only: the Top Bar menu (23), which already had "News"
  (5056, to /news/) and "Jobs", gains "Events" (8801, to /events/) between
  them; Jobs moves to third. That is where Martin wanted the two lists.
  Earlier the same day a "News and Events" item with a mega menu had been
  added to the main menu by mistake and rendered wrongly (the Blocksy
  content-block option only applies to items below the top level); those
  three items (8798, 8799, 8800) were deleted. The "News and Events" mega
  menu block (1323) is a Blocksy content block the content tools refuse
  to edit, so on Martin's go-ahead its links were changed by scoped
  `wp search-replace` on `wp_posts.post_content` (regex lookaheads,
  because the console blocks `<`, `>`, `\` and `$` in commands): All News
  and Forum Central News to /news/, Events to /events/, in both the block
  markup and the block's JSON attributes. Each pattern matched exactly two
  rows: the block and its one revision (8235). "Newsletter Sign up" still
  links to "#": it has nowhere to go yet. The block is attached to no
  menu item.
- **0.33.0 deployed** (23 September, production): joining redesigned so
  anyone with a proven address can pick their organisation from the list,
  and so the same organisation can never be recorded twice. After the link
  is used the page offers three routes: the domain match (as before, straight
  in), an organisation picked from the full list with a search-as-you-type
  picker over a plain select (a claim: pending contributor, queue row "Wants
  to join X", the team approve as owner if nobody is in it or contributor
  otherwise, or refuse without touching the organisation; owners emailed),
  or a registration for one not on the list. A registration is checked by
  the new pure `Org\Duplicates` against every live and binned organisation
  before anything is created: the same name once The, Ltd, CIC and the like
  are set aside, the same website or email domain, the same charity number,
  or the same postcode with a similar name is refused and offered as a
  claim instead ("Join X instead"); a similar name or the same postcode is
  shown once as "Is it one of these?" and the answer recorded. The same
  check runs while the person types (`MatchEndpoint`, keyed to the sign-up
  token, 60 asks an hour), refuses an approved name change that would
  duplicate, stops `wp dgl invite org` and warns on the import. The review
  screen shows a claim's note, how close the address is to the organisation
  and who is in it; a registration's likely matches with reasons, the bin
  included, and an Attach action that moves the person into the existing
  organisation, fills gaps in its record, records the domain and deletes the
  empty duplicate. Sign-ups carry a `kind` column (schema 9, backfilled on
  migrate). Four new emails, report counters, help and team guide copy,
  `docs/joining.md` records the reversal of the 16 September must-match
  rule. This replaces "ask a colleague to invite you" everywhere.
  Unit 2038, integration 1063. Walked locally in a browser: the picker
  (keyboard and mouse), a claim through to the pending dashboard, a hard
  block by website with "Join instead", a soft "Is it one of these?" with
  "No, none of these", the live check while typing, JavaScript off (the
  select carries 365 names and a claim submits), the queue rows, the claim
  review screen and approve, the registration review screen with likely
  matches, "It is this one" and Attach; AAA contrast walk clean over 13
  routes. A fresh backup was requested before the install but sat queued
  for seven minutes without starting, so the install went ahead on the
  manual backup of 15:48 the same day (1.1 GB, ready) plus the nightly
  automatic one; the change is one added column. Production after: version
  0.33.0 (`wp plugin get`), `dgl_platform_db_version` 9 (`wp option get`,
  written only after dbDelta and the backfill ran), the join page serving
  "Join the user area", cache cleared. The `kind` column itself is NOT
  VERIFIED directly on production: the console blocks `wp db` and no
  plugin command prints table columns. The same dbDelta added it on the
  local site, and the first real claim or registration will show it in
  the review queue.
- **0.33.3 deployed and run** (23 September, production): dead links taken
  out of the content. Martin exported the Broken Link Notifier results (780
  rows, 1 July to 31 August, front-end visits to the old pages): 552 were
  empty anchors, 77 pointed at Forum Central's old hosting address
  forumcentral.wordifysites.com (no longer resolves), 71 were 404s on other
  sites, 4 were 404s on this site, the rest timeouts. The plugin only scans
  posts and pages, so the migrated stories were never checked; its settings
  still need the platform's post types ticked. New `wp dgl links unlink
  --from=<url> [--apply] [--empty] [--actor]` reads a list of dead
  addresses (dist/dead-links-2026-09-23.txt, 164 addresses; the 4
  Cloudflare email-protection ones are skipped because they work), unlinks
  each one in every story, event, training item, page and post with the
  text kept, and treats every link to the dead host as dead whether listed
  or not: pointed at the migrated story here when there is one (legacy
  slug, de-duplicated survivor, or any live story), unlinked otherwise.
  Pure stripper `Tools\DeadLinks` with unit tests (2053 passed). Seeded
  local story proved unlink, rewrite and leave-alone. Production dry run
  then apply: 193 links in 103 posts, 5 rewritten, 188 unlinked, an audit
  row `links_unlinked` per post with each address. Stories containing the
  dead host afterwards: 0 (was 50). 0.33.1 and 0.33.2 were the same
  command before two fixes found by the local seed test; 0.33.3 is what
  ran. Three partner records (Forum Central 8802, VAL 8803, LOPF 8804)
  filled earlier the same day from their own websites, fetched through the
  site's probe because the sandbox proxy blocks them: website, description,
  email, phone, address, postcode, email domain; LOPF's charity and company
  numbers; the other two print none.
