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
