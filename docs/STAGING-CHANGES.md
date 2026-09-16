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
