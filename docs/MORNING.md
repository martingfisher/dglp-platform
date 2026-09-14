# Overnight summary, 14 September

Repo: `github.com/martingfisher/dglp-platform`, branch `main`.
Screenshots of the working screens: `docs/wireframes/built-*.png`.

## Where it got to

Built and verified against a real WordPress 6.7.1: five content types, seven
statuses, roles and capabilities, the access policy, the items index, the audit
log, the full submission workflow, trust levels, the expiry sweep, digest
cadence and matching, brand tokens, and the member dashboard (home, category
list, archive, review queue).

**918 standalone assertions, 88 integration.** Both suites run on demand:

```
php tests/run.php
./bin/setup-test-wp.sh && cd <target>/core && wp eval-file <plugin>/tests/integration/run.php
```

I could not deploy to staging (see Blocked), so I stood up a throwaway
WordPress on SQLite locally instead. That turned out to be worth more than
staging would have been, because it let me drive the dashboard in a real
browser and find things reasoning alone would not have.

## Six bugs it caught

1. `map_meta_cap` receives `edit_post`, never the post type's `edit_dgl_item`.
   The mapping keyed on the latter, so it never fired: a colleague could not
   edit a teammate's submission, and a member could edit their own item while a
   moderator was reading it. That second one is the exact hole you asked about.
2. Items were indexed during `save_post`, before the caller had written
   `dgl_org`, so a new draft landed with no organisation and was invisible to
   the people who owned it, permanently.
3. A `TypeError` in a template aborted the render with partial output still
   buffered, which PHP flushed at shutdown. The member saw an unstyled fragment
   with no navigation and no error, as though their work had vanished.
4. `register_post_meta` defaults did not match their declared types, logging a
   `_doing_it_wrong` for every field on every page load.
5. A URL field prepended `https://` to anything without an `http` prefix,
   turning `javascript:alert(1)` into `https://javascript:alert(1)` — passes a
   naive scheme check, carries the payload through.
6. Status filter counts were organisation-wide on a single-type screen.

All fixed, all with regression tests.

## Blocked, needs you

**Deployment.** The repo is private, so the staging site's
`wp plugin install <github zip>` 404s. Wordify's `install_plugin` only takes
wp.org slugs, and `wp eval`, `wp shell` and `wp config` are blocked. I did not
make the repo public: that is your call on a client codebase. Options are a
deploy key on the server, SFTP, or Wordify's own git deploy if it has one.

**Production cron.** Enabling it was refused by my permission layer. Staging
cron you turned back off, which is fine, nothing needs it yet.

**`DISABLE_WP_CRON`.** Needs `wp config set DISABLE_WP_CRON true --raw` from
your WP-CLI terminal when cron goes back on. The MCP blocks `wp config`.

## Questions

1. **Field lists.** I wrote step 2 for all five types from the wireframes and
   the proposal. The proposal says DGLP owe us "field definitions for each
   content type" — these need their sign-off before they harden. Grants and
   Volunteering are the two I would most want checked.
2. **Trust levels.** Three levels: moderated, trusted for edits, trusted. Does
   DGLP want all three, or just on and off?
3. **Topics.** Who writes the topic list? It drives the digest filtering, so it
   wants to exist before the first digest goes out.
4. **Volunteering migration.** Is there live WP Job Manager content to bring
   across, or does volunteering start empty?
5. **SMTP2GO plan** against roughly 115,000 emails a month at 10k subscribers.
6. **Logo.** Found it already on the site: attachment 8268 is the landscape
   SVG, 8270 the PNG twin. Nothing needed from you. The plugin resolves it from
   the media library rather than bundling it, so swapping it is a media library
   job. Email uses the PNG deliberately, because Gmail strips SVG.

## Next

The submission wizard. Everything behind it exists, so it is the form, the
per-step save, and the review-and-submit step.
