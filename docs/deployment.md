> From 23 September 2026 the site is production, partnership.doinggoodleeds.org.uk.

# Getting this on to a server

The plugin is self-contained. It needs no Composer install, no build step and no
other plugin. Deploying it is: get the folder into `wp-content/plugins/`,
activate, configure three things.

## Routes on to the server

| Route | Works? | Notes |
|---|---|---|
| **Upload the zip in wp-admin** | Yes | Plugins → Add New → Upload Plugin. Nothing else needed |
| **WP-CLI from a public URL** | Yes, but **not from a GitHub archive URL** | `wp plugin install <url> --activate --force`. The zip's top-level folder becomes the plugin folder, so it has to be named `dgl-platform` |
| Wordify `install_plugin` | No | wp.org slugs only |
| Wordify git deploy | Does not exist | Not in the hosting API |
| `wp eval` / `wp config` / SSH | Blocked | Wordify blocks these through the API |

The mechanism was tested for real on 15 September: `wp plugin install <public
zip url>` ran successfully on staging against `hello-dolly`.

**But a GitHub archive URL is the wrong zip.** The archive at
`.../dglp-platform/archive/refs/heads/main.zip` unpacks to a top-level folder
called `dglp-platform-main/`, and WordPress names the plugin after that folder.
Installing it would create a second, duplicate plugin directory alongside
`dgl-platform` rather than upgrading it, and `--force` would not help because the
two are different plugins as far as WordPress is concerned. Verified 16
September:

```
$ unzip -l main.zip | head -5
        0  2026-09-15 22:02   dglp-platform-main/
```

So one-command deploys need a **GitHub Release asset**: build the zip with the
`git archive` recipe below, which sets `--prefix=dgl-platform/`, and attach it to
a tagged release. That URL is stable, public and correctly named, and
`wp plugin install <asset url> --activate --force` then upgrades in place.

Version 0.2.0 reached staging on 16 September by Martin uploading the built zip
through Plugins - Add New - Upload Plugin, not by URL.

Nothing in the plugin code is sensitive -
no keys, no credentials. `docs/wireframes/design-conversation.md` is the one file
worth removing first: it carries RYCM design-system detail and names other
private repositories. It is a handoff artefact, not plugin code.

## Building the zip

```
git archive --format=tar --prefix=dgl-platform/ HEAD | tar -x -C build/
rm -rf build/dgl-platform/docs/wireframes build/dgl-platform/.claude
cd build && zip -qr ../dgl-platform.zip dgl-platform
```

Around 205KB. The `tests/` folder is left in deliberately: it runs on the server
with `php tests/run.php` and proves the install is sound in about a second.

## What activation does

`Install::activate()` creates the custom tables, registers the two roles and
their capabilities, and flushes rewrite rules so `/dashboard/*` resolves. It is
safe to run repeatedly. Deactivating removes nothing.

## Configure after activation

Three things, and the site is not finished without them.

**1. Mail.** Off by default. In `wp-config.php`:

```php
define( 'DGL_MAIL_ENABLED', true );
// Staging only. Every message goes here and nowhere near a real member.
define( 'DGL_MAIL_REDIRECT', 'someone@resultsyoucanmeasure.com' );
```

Then `wp option update dgl_mail_from partnership@doinggoodleeds.org.uk` and
`wp dgl mail status` to confirm. See `docs/email.md` for why the redirect is a
constant rather than an option.

**2. Cron.** The expiry sweep and the digests need real cron. Enable Server
Cron in Wordify (Sites > the site > Cron), 15 minutes. Production had it off
until 27 September 2026, and every scheduled job sat overdue. Check with
`wp dgl digest status`, which prints the next scheduled run or NOT SCHEDULED,
and `wp cron event list`, where nothing should read "now" for long.

**2b. Throughput.** The digest job runs every five minutes (its own
`dgl_five_minutes` interval) and sends up to 400 per cadence per run under a
lock, so 5,000 subscribers due at 08:00 are away by about 09:05. Check
`wp dgl digest status`: "then every dgl_five_minutes". Staying out of spam:
set a From address on the domain SMTP2GO has verified (`wp option update
dgl_mail_from hello@...`), keep the SMTP2GO plan above the month's volume,
and never remove the List-Unsubscribe headers or the plain-text part.

**2a. The weekly round-up.** Every approved member is subscribed on approval
(`Store::subscribe_default()`), and `wp dgl digest subscribe-approved`
backfills members approved before 0.38.0. Slots are fixed in the site's
timezone: Tuesday 08:00 weekly, 08:00 daily, the 1st at 08:00 monthly; each
digest covers the one period before its slot. A quiet period sends nothing
and still counts as handled. `wp dgl digest send <user>` sends one now,
covering the last period up to this minute.

**3. SmartCache exclusions.** A page cache that serves one member's dashboard to
another is a privacy bug, not a tuning problem. Staging now excludes
`/dashboard`, `/wp-login.php`, `/wp-admin` and `/wp-json`. **Production has not
been checked since these were added to staging** and must be confirmed before
launch.

## Checking it worked

On the server, in the plugin directory:

```
php tests/run.php          # ~1000 assertions, no database needed
wp dgl mail status         # what mail is configured to do
wp plugin list --name=dgl-platform
```

The integration suite is **not** for a real site. It creates and deletes
content, and refuses to run unless `DGL_TEST_SITE` is defined, which should never
be defined on staging or production.

## After 0.10.0

Repeating events add a column to the index and a public route:

- `wp option get dgl_platform_db_version` should print `7` (5 added the
  `next_at` column; 6 marks every earlier event as in person; 7 gives every
  live news story an end date three months from when it went live, never
  sooner than two weeks from the upgrade).
- `wp rewrite list --match=/events/calendar/` should show
  `^events/calendar/?$` going to `dgl_calendar=1`; if not, load any page
  once (the version bump flushes rules) or run `wp rewrite flush`.
- `wp dgl reindex` stamps every live event's next date. Until it runs, the
  events list keeps its old order.
- Add `/events/calendar` to the page cache exclusions alongside `/directory`.
- `wp rewrite list --match=/events/calendar.ics` should show
  `^events/calendar\.ics$` going to `dgl_ics=calendar`, and
  `^events/([^/]+)\.ics$` must sit above the single-event rule. Fetch
  `/events/calendar.ics` and expect `text/calendar`.
- Settings > General > Timezone should be London, not UTC+0. Stored times
  are wall clock either way, but the .ics files and the hourly sweep read
  them in the site zone, and UTC+0 is an hour out from April to October.
- The old site's stories: `wp dgl news import --org=<id> --dry-run` reports
  what would convert; the real run needs the owning organisation ids agreed
  first. See `docs/legacy-news.md`. Afterwards `/news/` lists them and an
  old `/category/story/` address answers 301 to `/news/story/`.
- The reminder and the roll-forward run on the existing hourly
  `dgl_run_expiry_sweep` hook, so cron must be on. `wp dgl series status <id>`
  prints every check the hook makes for one event; `wp dgl series remind <id>`
  sends its reminder by hand; `wp dgl series roll` restamps. Note that the
  Wordify console runs `wp cron event run` without plugins loaded, so it
  cannot exercise the hook; the server cron can.

## After 0.21.0

The topic list ships in the plugin and seeds itself:

- `wp option get dgl_topics_version` should print `1` after any page has
  loaded. If it prints nothing, load a page, then check again.
- `wp dgl topics list` should show all twenty-eight as `present`. Anything
  the team added by hand shows as "not on the list" and is kept.
- The old site's categories are untouched. `wp dgl topics legacy` shows what
  DGLP's decision would do to them; it changes nothing.

## After 0.22.0

- `wp option get dgl_platform_db_version` should print `8`. Schema 8 withdraws
  the three-month spell on news: every story loses its end date and expiry
  stamp, and a story the sweep had taken off for running out of days is put
  back on the site with a `listing_restored` line in the audit trail.
- Imported organisations now carry a "please check these details" prompt on
  the dashboard and the Organisation tab until an owner saves that tab. Check
  one: `wp post meta get <org id> dgl_org_checked_at` is empty before, a UTC
  datetime after.

## After 0.34.0

- `wp option get dgl_platform_db_version` should print `10`. Schema 10 gives
  every organisation the new trust setting (`dgl_trust`, an array with `on`,
  `types` and `edits`) worked out from its old level: 2 becomes everything
  on, 1 becomes edits only, 0 stays off. The old `dgl_trust_level` is kept in
  step for the index. Check one: `wp post meta get <org id> dgl_trust`.
- Moderators gain the `grant_dgl_trust` capability, so the review team can
  set trust from Organisations in the user area:
  `wp cap list dgl_moderator` should include it.
- The wp-admin Organisations box no longer has a trust select. It shows the
  setting and links to the user area page.

## After 0.37.0

- Every public page the plugin owns prints its own description, canonical,
  Open Graph and Twitter tags and JSON-LD. Check one: view the source of a
  live news story and look for `<!-- DGLP: page description -->`.
- Social cards are made with GD into `wp-content/uploads/dgl-og/`. Check
  the `og:image` of an item without a picture answers 200. If GD or
  `imagettftext` is missing on the host, the site icon is used instead and
  nothing breaks; `wp dgl probe page <url> og:image` shows which.
- Rank Math: when it prints the head, the plugin feeds it through its
  filters and prints only the schema. That path is not exercised by the
  test suites because Rank Math is not installed locally.
- Launch day: Settings > Reading, untick "Discourage search engines". Until
  then every page carries noindex from WordPress core, whatever else is
  printed.

## After 0.41.0

- Spaces to hire: two new post types, a `parent_id` column on the index
  (schema 11, added on activation) and two public routes, `/spaces/` and
  `/spaces/<slug>/`. Rewrites are flushed by the version bump; if `/spaces/`
  answers 404, Settings > Permalinks > Save.
- Leaflet is vendored inside the plugin; the map's tiles come from
  `tile.openstreetmap.org`. Nothing to configure.
- Geocoding calls `api.postcodes.io` from the server when a venue goes
  live. Check the host can reach it: `wp dgl probe page
  https://api.postcodes.io/postcodes/LS13AD status` should show
  `"status":200`. Then `wp dgl spaces geocode` places any venue that went
  live while it could not.
- The enquiry form posts to the venue page. SmartCache and the CDN must
  pass POST requests through to PHP (they do by default); a form that
  reloads the page with nothing sent means a cached POST response.

## After 0.42.0

- The header's search box (Blocksy Pro's "Search Input" element) is taken
  out of the header layout by the plugin as the layout is read
  (`theme_mod_header_placements`). Nothing stored changes: it is still in
  the header builder's settings and comes back when the plugin is
  deactivated. To bring it back while the plugin runs, drop the filter in
  `Finder::init()`.
- The magnifying glass is a menu item the plugin appends to the Top Bar
  menu (slug `top-bar`) and the Main Menu (slug `main-menu`, for the phone
  drawer). If either menu is renamed, change the two constants at the top
  of `src/Frontend/Finder.php`.
- The "Spaces to hire" link is an ordinary menu item in the Top Bar menu
  after Jobs, added on 30 September 2026 with
  `wp menu item add-custom top-bar "Spaces to hire" /spaces/ --position=4`
  (it sat in the Main Menu for an hour first). Appearance > Menus to move
  or rename it.
- Check after installing: the home page source has `dgl-finder-trigger`
  in the top bar and no `ct-search-box`; `dgl probe page / ct-search-box`
  should find nothing.

## After 0.45.0: mail settings without the console

- The mail settings (sending, From address and name, team inbox,
  redirect) are on the System health page in wp-admin under Email
  settings, and as the abilities `dgl/get-mail-settings` and
  `dgl/update-mail-settings`. `wp option update` still works too. If the
  Wordify connector ever stops listing `run_wp_cli` after a reconnect,
  load it by its exact name: it was still there on 10 October 2026 when
  a catalogue search could not find it.
- Production mail from 10 October 2026: redirect off; From address and
  team inbox hello@doinggoodleeds.org.uk. A venue's enquiries still go
  to the venue's own contact address, and members are mailed at their
  own.

## After 0.47.6: the email footer

- Every email ends with the same small print under a thin line: who sent
  it and why, a link to email settings (members), "Questions? Reply to
  this email..." where a reply reaches the team, and a link to the
  privacy notice. Venue enquiries and email to the review team leave out
  the "Questions?" line.
- Every email is sent with `Reply-To:` the team inbox, except a venue
  enquiry, which replies to the person enquiring.
- The privacy link is "Privacy notice link" under Email settings on the
  System health page (option `dgl_mail_privacy_url`). Empty means the
  WordPress privacy page, if one is published; with neither, the line is
  left out. Production from 15 October 2026: the interim privacy notice
  PDF in `uploads/2026/10/`.
- Dates in emails read "10 October 2026".

## Demo content

`wp dgl demo events --org=<id> --images` makes seven varied live events
under an organisation in one call: a weekly series, a featured one, a
hybrid one with a booking link, an online paid one, one with an
accessibility note, one with a capacity, and one cancelled. `--images`
uses the newest photos in the media library; two are left without a
picture on purpose. `--dry-run` lists them first. Every one carries
`dgl_demo`, so `wp dgl demo events --remove` deletes exactly those.

`wp dgl demo training --org=<id> --images` does the same for seven training
listings: one-day, three-day and two-date courses, in person, online and
blended, free, paid and donation, two without a picture.
`wp dgl demo training --remove` deletes them.

`wp dgl demo spaces --org=<id> --images` makes five venues with fourteen
spaces to hire: every price band, a day rate, a session rate, a free
studio and a kitchen at Price on request. Each venue is placed on the map
as it is made unless `--skip-geocode` is given. `wp dgl demo spaces
--remove` deletes the spaces, then the venues.

## The home page: shortcodes for a page built in the theme

The live parts of the home page are shortcodes, so the front page is built
in the block editor on the theme's own page (Blocksy, page 8132 on
production) with the team's hero, copy, photos and testimonials, and the
live sections dropped in as Shortcode blocks wherever they belong:

```
[dgl_home_events count="6" heading="What's on" link="yes"]
[dgl_home_news count="5" heading="Latest news"]
[dgl_home_training count="3"]
[dgl_home_funding]
[dgl_home_directory areas="6"]
[dgl_home_roundup heading="Get the weekly round-up" text="…"]
```

`count` is the number of items (1 to 24); `heading=""` drops the heading so
the page can supply its own; `link="no"` drops the "All events" link;
`areas` is how many area-of-work chips the directory block shows; `text`
is the round-up's sentence. Featured items take the first slot. The
stylesheet loads only on a page that uses one of these, and the grids
adapt to the width of the column they are dropped into.

`/samplehome/` is the same page as one plugin-rendered template, kept as
the reference until the theme page is signed off. It says `noindex`. Its
hero photo and testimonials are options (`dgl_home_hero`, an attachment
ID; `dgl_home_testimonials`, JSON rows of quote, name, role).

Funding lists from the Grants type once that is switched on; until then
the block says so.

### Jobs from Voluntary Action Leeds

```
[dgl_val_jobs layout="carousel" count="12"]
[dgl_val_jobs layout="feed" count="6" heading="Jobs in the sector"]
```

Lists the vacancies VAL advertises at val.org.uk/jobs, with VAL's
permission. `layout` is `carousel` (the default, a row that scrolls
sideways with previous and next buttons, never moving by itself) or
`feed` (cards in a grid that wraps to one column on a phone). `count`
defaults to 12 for the carousel and 6 for the feed, and unlike the blocks
above can go past 24 (up to 100), so the jobs page can list every vacancy
with `count="100"`; `heading` and `link` work as on the blocks above, and
the All link goes to VAL's jobs page.
Each card opens the full advert on VAL's site in a new tab, so the
visitor keeps this site open; screen readers are told it opens a new tab.

The list is fetched once a night at about 2am by cron
(`dgl_val_jobs_refresh`), never on a page view. VAL runs WP Job Manager,
so the plugin reads its RSS feed
(`https://val.org.uk/?feed=job_feed&posts_per_page=100`) rather than the
page, and its listing endpoint for logos. Logos are copied into
`wp-content/uploads/dgl-val-logos/`, shrunk to 128px, so visitors' browsers
never call VAL's server; VAL's firewall refused some browser requests for
them in testing. A night that fails keeps the previous list; a list over
a week old is withdrawn. On a fresh install the first fetch runs within a
minute of the update.

```
wp dgl jobs refresh   # fetch now, exactly as the nightly run does
wp dgl jobs status    # last good list, last attempt, next run
```

SmartCache keeps pages for a day, so a Cloudflare Worker clears it after
the import: `dglp-smartcache-clear` on the RYCM Cloudflare account, cron
`30 2 * * *` (UTC, so after the 02:00-02:15 UK import in summer and
winter), calling Wordify's `POST /api/v1/sites/{id}/cache/clear` with the
Secrets Store key "Wordify". That call clears every layer (SmartCache,
object cache, CDN); it has no layer option. The Worker has no web route;
its runs and any failure are in its Cloudflare logs.

System health has a "Jobs from VAL" row: green after a good night, amber
after a failed one, red once the list is a week old. If VAL stop using
WP Job Manager or put the feed behind their firewall, the row goes amber
and names the error, and the last list stays up for a week while the
import is updated.

## Staging to production

Do **not** use Wordify's `push_staging` to move the plugin. That pushes the whole
site, database included, and would overwrite production's live content with
staging's. Deploy the plugin by itself, the same way it reached staging.

The plugin's own data is in its own tables plus post meta, so nothing about it
requires a database push in either direction.

## What is not built yet

Listed so nobody deploys expecting it, checked against the code on 18
September 2026: Microsoft and Google sign-in; a read/unread store behind the notifications screen (it is the audit trail,
read back); a member closing their own account; Turnstile on the join form;
consent records at registration (consent is recorded for the digest only).

Everything else in the earlier version of this list has since been built:
the privacy exporter and eraser, the public templates, removing a colleague,
and email on an organisation-change decision.
