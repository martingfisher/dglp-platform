# DGLP Platform

Member organisation accounts, content submission and a moderation workflow for
[partnership.doinggoodleeds.org.uk](https://partnership.doinggoodleeds.org.uk).

A self-contained WordPress plugin. No Advanced Custom Fields, no Gravity Forms, no WP Job Manager.
It uses WordPress primitives throughout: custom post types, custom post statuses, roles and
capabilities, taxonomies, cron and `wp_mail`.

## What it does

Verified member organisations submit News, Events, Training, Grants and Volunteering opportunities
through a front-end dashboard. Nothing appears on the site until a moderator approves it. Members
receive digest emails matching the content types and topics they asked for, daily, weekly or monthly.

Twelve wireframes covering the member and moderator screens are in
[`docs/wireframes/`](docs/wireframes/member-dashboard-wireframes.html) (open it in a browser, it is
self-contained). The reasoning behind the build is in [`docs/architecture.md`](docs/architecture.md).

## Status

Phase 1a, foundations. Post types, statuses, roles, the access policy, the workflow state machine,
trust levels, the items index and the audit log. No dashboard views, no submission wizard and no
email yet.

## Requirements

PHP 8.2 or later, WordPress 6.4 or later. Target environment runs PHP 8.4 with a Blocksy child theme.

## Two things worth knowing before you read the code

**`src/Access/Policy.php` is the single gatekeeper.** Every permission decision in the plugin
resolves there, and it is pure logic with no WordPress calls so it can be tested exhaustively.
Organisation scoping is the highest risk in this build: one missed check leaks one member
organisation's unpublished drafts to another. Nothing should ever compare organisation IDs by hand.

**The organisation owns the content, not the author.** WordPress's default capability mapping would
stop a colleague editing a teammate's submission. `src/Access/Access.php` overrides that, because
organisations post as a body and their members cover for each other.

## Tests

Two suites. Both have to pass.

**Standalone** — the pure-logic classes, no WordPress and no database:

```
php tests/run.php
```

Covers the access policy, the status lifecycle, trust levels, transition planning, the field schema
and its validation, `dbDelta` formatting, and WCAG contrast on every brand colour pair.

**Integration** — real WordPress, real capability system, real `dbDelta`:

```
./bin/setup-test-wp.sh
cd <target>/core && wp eval-file <plugin>/tests/integration/run.php
```

The setup script builds a throwaway WordPress on SQLite, so it needs neither a database server nor a
web server. It is repeatable: the suite clears its own fixtures before each run. It sets pretty
permalinks and the London timezone, as on the real site, because the route tests read the rewrite
rules and without a permalink structure WordPress writes none. The import test shells out to WP-CLI
and finds the phar it is running under; set `DGL_WP_CLI` to point it elsewhere.

This suite is not optional decoration. It caught a live bug the standalone tests could not see: the
`map_meta_cap` filter receives `edit_post`, never the post type's own `edit_dgl_item`, so the
original mapping never fired and WordPress quietly fell back to author-based permissions. That is
the exact class of defect that ships silently and leaks one organisation's drafts to another.

## Brand

Colours and typography are read from the live site's Blocksy theme mods, not chosen here. The
stylesheet consumes the `--theme-palette-color-N` custom properties Blocksy already emits, so a
palette change in the customiser flows through without a code edit.

One constraint to be aware of: the site's signature teal `#57b0b6` measures 2.53:1 against white and
2.40:1 against the page background. It fails WCAG AA both ways, so it is used for borders, tints and
rails only, never for text or a button fill. Deep navy `#2d3b6b` carries primary actions at 10.77:1.
`tests/test-brand.php` enforces this.

---

Built by [Results You Can Measure](https://resultsyoucanmeasure.com).
