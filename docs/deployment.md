# Getting this on to a server

The plugin is self-contained. It needs no Composer install, no build step and no
other plugin. Deploying it is: get the folder into `wp-content/plugins/`,
activate, configure three things.

## Routes on to the server

| Route | Works? | Notes |
|---|---|---|
| **Upload the zip in wp-admin** | Yes | Plugins → Add New → Upload Plugin. Nothing else needed |
| **WP-CLI from a public URL** | **Verified on staging, 15 Sep** | `wp plugin install <url> --activate`. Needs the zip at a public URL |
| Wordify `install_plugin` | No | wp.org slugs only |
| Wordify git deploy | Does not exist | Not in the hosting API |
| `wp eval` / `wp config` / SSH | Blocked | Wordify blocks these through the API |

The zip route was tested for real: `wp plugin install <public zip url>` ran
successfully on staging, so once the plugin zip has a public URL, redeploying is
a single command and can be repeated on every change.

**Repeatable deploys need the repo public**, or a public release asset, or
somebody uploading the zip each time. Nothing in the plugin code is sensitive —
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
system cron **off**.

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

## Staging to production

Do **not** use Wordify's `push_staging` to move the plugin. That pushes the whole
site, database included, and would overwrite production's live content with
staging's. Deploy the plugin by itself, the same way it reached staging.

The plugin's own data is in its own tables plus post meta, so nothing about it
requires a database push in either direction.

## What is not built yet

Listed so nobody deploys expecting it: SSO, digest sending, the notifications
screen, CSV export, the privacy exporters, the wp-admin screens and the public
templates. What works today is accounts, submissions, the wizard, moderation,
pending edits and transactional email.
