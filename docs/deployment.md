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

**2. Cron.** The expiry sweep and the digests need real cron. Enable system cron
in Wordify and set `define( 'DISABLE_WP_CRON', true );`. Staging currently has
system cron **off**, so digests will not send there until it is on. Check with
`wp dgl digest status`, which prints the next scheduled run or NOT SCHEDULED.

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
