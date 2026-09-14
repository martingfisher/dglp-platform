# DGLP Platform — custom WordPress plugin

## Context

Doing Good Leeds Partnership need verified member organisations to submit content to
partnership.doinggoodleeds.org.uk through a review process, and to receive digest emails matching
their interests. The August 2026 proposal costed this as Gravity Forms plus ACF plus WP Activity Log
with a custom layer on top. Twelve wireframes now exist (Claude Design handoff, screens `1a`–`1l`).

The question asked was whether a fully custom build is valid. It is, and the proposal is its own
evidence: it already scoped the bespoke layer as "the multi-user organisation accounts, the
moderation experience, the dashboard, the suspend/delete handling and the Mailchimp preferences".
That is the bulk of the work. Gravity Forms and ACF were only ever covering form rendering and field
storage.

The decisive point is the thing Gravity Forms does worst: a member editing an approved item and
resubmitting it. Gravity Forms entries are submissions, not stateful documents. Front-end editing of
an existing entry needs GravityView or a large amount of glue, and the glue fights the plugin. Going
custom removes glue, not features.

**Decisions taken.** Five content types including Volunteering in this phase. No ACF, no Gravity
Forms, no WP Job Manager, no legacy plugins — the DGLP Platform is self-contained. Microsoft and
Google SSO included. Digests built in WordPress, sent via SMTP2GO. DGLP brand, not RYCM. Every edit
to approved content re-moderates, softened by admin-granted trust levels. **Target scale is 5,000 to
10,000 users over the next few years**, which drives the data model and the email design below.

## Verified environment

Checked via the Wordify API, 14 September 2026.

The legacy doinggoodleeds.org.uk site is out of scope: it comes down when this goes live.

| | partnership (production) | staging |
|---|---|---|
| Site id | `01KVZAKGKNP7XYZ6Z8E50WVJ0N` | `01KZ9TYGCG3CF0WVX8VX736BNG` |
| Domain | partnership.doinggoodleeds.org.uk | partnership-doinggoodleeds-org-uk-stg.wordifysites.com |
| Theme | Blocksy Child active | Blocksy Child active |
| PHP | 8.4, 256M, exec 30s, input_vars 2000, upload 64M | identical |
| System cron | `cron_config` null | **`system_cron_enabled: false`** |
| SmartCache | on, TTL 86400, **no exclusions** | on, TTL 86400, **no exclusions** |
| CDN | **enabled**, Bunny, optimizer + WebP + minify on | disabled |
| Redis | off | off |
| Email | SMTP2GO **inactive** | — |
| Notable plugins | ACF free 6.8.10, Gravity Forms 3.1.1, Rank Math Pro, Wordfence, GreenShift, FileBird Pro | — |
| Hosting country | uk | uk |

Provisioned: production 25 Jun 2026 (cloned from `dgl.wordifysites.com`), staging 5 Aug 2026.

**Code repository**: `github.com/martingfisher/dglp-platform`, verified empty, default branch `main`.
Server sizing is Martin's to change as load requires and is not treated as a constraint here.

## Blockers to clear before code

1. **SmartCache exclusions.** A 24-hour page cache with no exclusions will serve one logged-in
   member's dashboard to another member. `/dashboard/*` and all auth routes must be excluded before
   any dashboard route is reachable. This is a correctness and privacy bug, not a tuning task.
2. **System cron.** Confirmed off. Enable it (Wordify supports a 15-minute interval) and set
   `DISABLE_WP_CRON`. Digests cannot run on pseudo-cron.
3. **SMTP2GO** activated and keyed on partnership, and **the plan checked against projected volume**
   (see the digest maths below). Do not assume the current tier covers it.
4. **Commercial variation.** Volunteering as a fifth type and SSO are both beyond the signed £9,900
   Phase 1. Agree in writing before build starts.
5. **`block_post_requests: true`** on the production CDN. Confirm the POST-based wizard reaches the
   origin unaffected before building on that assumption.
6. **DGLP logo files** for the dashboard header and the email templates. The colours and typography
   are resolved (below); the mark itself is attachment 7156 in the media library and needs pulling
   out as a file, or supplying separately.

## Brand tokens

Read from the live staging site, not guessed: `wp option get theme_mods_blocksy-child` on site
`01KZ9TYGCG3CF0WVX8VX736BNG`. Blocksy already exposes all eighteen as CSS custom properties on every
page, and the dashboard renders inside the Blocksy child theme, so **the dashboard consumes
`--theme-palette-color-N` with a hardcoded fallback** rather than restating hexes:

```css
--dgl-primary: var(--theme-palette-color-14, #2D3B6B);
```

A palette change in the customizer then flows into the dashboard with no code edit. Fallbacks exist
because the auth screens and the email templates render outside theme context.

| Token | Hex | DGLP name | Role in the dashboard |
|---|---|---|---|
| color14 | `#2D3B6B` | Deep navy | Primary buttons, sidebar, headings |
| color15 | `#5B6BAE` | Partnership purple | Links, active nav rail, focus ring |
| color1 | `#57b0b6` | Teal | **Decorative only** (see below) |
| color3 | `#1D6265` | Dark teal | Solid teal that can carry white text |
| color4 | `#1e3232` | Ink | Body copy |
| color5 | `#fdf8f0` | Background / Primary Light | Page background |
| color18 | `#F4F6FB` | Off white | Card and table header fills |
| color9 | `#ffc043` | Accent | Pending review chip source |
| color6 | `#4ca42f` | VAL Green | Live on site chip source |
| color10 | `#cc0053` | Accent Hover | Changes requested chip source |
| color11 | `#ff6633` | Accent 3 | Not approved chip source |

Typography is **Montserrat** throughout: root 18px/1.65 weight 400, headings weight 700
(h1 55px, h2 40px, h3 28px, h4 20px, h5 18px, h6 16px). Buttons weight 500, 15px.
Geometry from the same theme mods: button radius 8px (identical to the wireframes), card radius
20px, form field radius 10px, form border 2px, input height 45px.

### Contrast, measured

Computed against WCAG 2.1, white text on each solid colour:

```
color14 #2D3B6B  10.77:1  AA      color3  #1D6265   7.03:1  AA
color15 #5B6BAE   5.05:1  AA      color7  #4364ad   5.73:1  AA
color10 #cc0053   5.69:1  AA      color4  #1e3232  13.48:1  AA
color6  #4ca42f   3.15:1  large only
color1  #57b0b6   2.53:1  FAIL    color11 #ff6633   2.92:1  FAIL
```

**The site's signature teal cannot carry white text and cannot be small text.** At 2.53:1 on white
and 2.40:1 on the page background it fails AA both ways. So teal is borders, tints, rails and
illustration only; `#1D6265` is the solid teal when white text is needed. This is the same
brand-colour versus interactive-colour split RYCM already runs, and it needs saying to DGLP because
the teal is the colour they will think of as "the brand colour".

Primary action colour is therefore **Deep navy `#2D3B6B`**, with Partnership purple `#5B6BAE` for
links, which is already the site's own link colour.

### Status chips, all measured AA

Backgrounds are each palette colour mixed 88% into white, text darkened until it clears 4.5:1. So
every chip is derived from the DGLP palette rather than invented, and every one passes:

| Status | Background | Text | Ratio |
|---|---|---|---|
| Draft | `#e4e6e6` | `#1e3232` | 10.76:1 |
| Pending review | `#fff7e8` | `#8f6c26` | 4.54:1 |
| Live on site | `#eaf4e6` | `#387923` | 4.73:1 |
| Changes requested | `#f9e0ea` | `#cc0053` | 4.57:1 |
| Expired | `#e6e7ed` | `#2d3b6b` | 8.73:1 |
| Archived | `#ebedf5` | `#5767a7` | 4.60:1 |
| Not approved | `#ffede7` | `#b84925` | 4.61:1 |

Colour is never the only signal: each chip carries its text label, as the wireframes already show.

## Architecture

### Principle

WordPress primitives everywhere, custom tables only where they earn their place. The proposal sold
"maintainable by a competent WordPress developer in future". A custom build keeps that promise only
if it uses CPTs, custom post statuses, roles and capabilities, taxonomies, cron and `wp_mail` rather
than inventing parallel machinery.

Zero runtime dependency on ACF, Gravity Forms or WP Job Manager. Those plugins staying installed for
other parts of the site is a separate decision; this plugin neither needs nor touches them.

### The field schema registry — what replaces ACF

One PHP array per content type in `src/Schema/types/`. Each field declares key, label, control type,
required, wizard step, validation rule, sanitiser and public render hint.

Everything generates from it: wizard rendering, server-side validation, `register_post_meta` with
`show_in_rest` and `sanitize_callback`, wp-admin meta boxes, the moderator's raw-fields tab, digest
assembly and CSV export columns. Around 300 lines, and it removes the ACF dependency outright.

### Data model

**Five CPTs**: `dgl_news`, `dgl_event`, `dgl_training`, `dgl_grant`, `dgl_volunteering`. Separate
types give clean archives, per-type permalinks, per-type Rank Math config and per-type capabilities.
The schema registry keeps the engine type-agnostic despite five types.

**`dgl_org` CPT** for organisations — they carry fields, a logo, an approval status, a trust level
and an owner. Users link via `dgl_org_id` user meta plus an org role of `owner` or `contributor`.

**Shared taxonomy `dgl_topic`** across all five CPTs. This is what "all events of type X" means in
the digest preferences. Term relationships are indexed, so topic filtering stays fast.

**Custom post statuses**: `dgl_pending`, `dgl_changes`, `dgl_expired`, `dgl_archived`,
`dgl_rejected`, alongside core `draft` and `publish`.

### The scale change: a read-optimised index table

At 5-10k users the naive design breaks. Scoping items to an organisation through `post_meta` means
every member list, every moderation queue page and every digest query becomes a `meta_query` join
against a `wp_postmeta` table with hundreds of thousands of rows. That is the single thing that will
make this feel slow.

So all hot reads go through a projection table, `{prefix}dgl_items`, hydrating posts by ID afterwards:

```
post_id BIGINT UNSIGNED PRIMARY KEY
post_type VARCHAR(20)      org_id BIGINT UNSIGNED     author_id BIGINT UNSIGNED
status VARCHAR(20)         trust_level TINYINT        has_pending_revision TINYINT(1)
submitted_at DATETIME NULL approved_at DATETIME NULL
updated_at DATETIME        expires_at DATETIME NULL

KEY org_status    (org_id, status, updated_at)
KEY queue         (status, submitted_at)
KEY digest        (post_type, status, approved_at)
KEY expiry        (expires_at, status)
```

Every hot query becomes index-only: member list by org and type, moderation queue oldest-first,
digest items published since a timestamp, expiry sweep. Kept in sync from the state machine plus
`save_post` and `deleted_post`, with a `wp dgl reindex` WP-CLI command to rebuild from scratch.

Other custom tables, and only these: `dgl_invites` (hashed single-use tokens with expiry),
`dgl_audit`, `dgl_subscriptions`, plus Action Scheduler's own.

### Re-moderation and trust

**Every edit to approved content re-moderates.** An edit is stored as a pending revision
(`dgl_revision` child post, `post_parent` set to the live item) and never reaches the public until a
moderator approves it. This blocks the submit-clean-then-edit-dirty attack completely: the
inappropriate text sits in the queue, not on the site.

**Decided: the last approved version stays visible while the edit waits.** A member fixing a typo
does not take their own event off the site for three days, and no safety is lost, because the public
never sees the pending edit either way. Still exposed as a setting (`dgl_edit_visibility`:
`keep_live` default, or `unpublish`) so it can be flipped without code, plus a moderator "take down
while under review" action for reported content. Wireframe `1l` already implies this with its
"Changes since last version" tab.

**Trust levels**, per organisation with an optional per-user override:

| Level | Behaviour |
|---|---|
| 0 `moderated` | Default. Every submission and every edit reviewed. |
| 1 `trusted_edits` | New items reviewed. Edits to already-approved items publish immediately. |
| 2 `trusted` | Submissions and edits publish immediately, listed for post-hoc spot check. |

Only a site admin can raise trust above 0, never a moderator, and the change is audit-logged. Any
rejection or upheld report against a trusted org drops it back to 0 automatically and notifies
admins. Trust never bypasses org approval, account approval, upload validation or Turnstile.

This is the real answer to moderation load at 5-10k users. The bottleneck at that scale is human
reviewers, not MySQL.

### Access control — the highest risk in the build

Every item carries `org_id`. One gatekeeper, `DGL\Access::can( $user_id, $action, $object )`, and
every controller calls it. Post IDs arriving in requests are never trusted. A missed check leaks one
member organisation's drafts to another, so the negative cases get explicit tests.

Two roles: `dgl_member` (no wp-admin, redirected on `admin_init`) and `dgl_moderator`.

### Front end

Rewrite rules under `/dashboard/`, template supplied via `template_include`, using `get_header()` and
`get_footer()` so Blocksy chrome stays consistent. All CSS scoped under `.dgl-dash`, consuming the
`--theme-palette-color-N` properties Blocksy already emits (see Brand tokens above) so the theme
stylesheet does not fight it and a customizer palette change carries through without a code edit.

**Server-rendered wizard, not an SPA.** Each step POSTs to `admin-post.php` with nonce and capability
check, saves to the draft, redirects onward. Light vanilla JS for autosave and tag input only. Works
without JS, no build pipeline inside a plugin, no REST auth complexity, better accessibility, and it
keeps the maintainability promise. `max_input_vars` of 2000 is comfortable per step.

Uploads via `wp_handle_upload` and `wp_insert_attachment`, MIME allowlist,
`wp_check_filetype_and_ext`, size cap, org stamped on the attachment for scoping, placeholder
fallback per the proposal.

### SSO

OAuth 2.0 authorization code flow with PKCE against Microsoft Entra ID and Google OIDC discovery.
Validate the `id_token` signature against the provider JWKS and check `iss`, `aud`, `nonce`, `exp`.

**Link on the provider `sub` claim, never on email alone.** Email is mutable and, on some tenants,
attacker-controllable. Email-based linking is the classic account-takeover hole here. Where an email
match is used to offer linking it requires a verified-email claim plus explicit confirmation.

No refresh tokens stored — there is no ongoing API access to maintain. SSO proves identity only;
DGLP approval still gates `dgl_pending_member` to `dgl_member`.

SSO layers onto the same user model and gatekeeper, so its phase is a scheduling choice, not an
architectural one.

### Email at 5-10k users

**Transactional** (submitted, approved, changes requested, rejected, invite, account approved):
templated, `wp_mail`, intercepted by SMTP2GO. Modest volume.

**Digests.** Preferences in `dgl_subscriptions`: user, content types, `dgl_topic` terms, frequency of
daily, weekly or monthly, `last_sent_at`, consent timestamp, consent source, unsubscribe token.

The volume needs planning, not assuming. At 10,000 subscribers, a rough split of 30% daily, 50%
weekly, 20% monthly gives **peak days around 8,000 emails and roughly 115,000 a month**. Two
consequences:

- **Check the SMTP2GO plan covers that**, with headroom. I have not verified the current tier and
  will not assume it.
- **Batch properly.** `max_execution_time` is 30 seconds. The runner queues one action per recipient
  and Action Scheduler processes N per pass on a 15-minute system cron, with batch size and send
  window configurable. Spreading 8,000 sends across a two to three hour morning window is
  comfortable; a single cron burst is not. Use SMTP2GO's API for bulk sends rather than one
  `wp_mail` per recipient where volume justifies it.

Action Scheduler is a Composer library, not a plugin — the same queue WooCommerce runs at far larger
scale. Flagging it because it does create its own tables and an admin screen, so it is a decision
rather than a silent dependency.

Every digest carries a one-click unsubscribe token honoured without login, plus a `List-Unsubscribe`
header.

### Performance work outside the plugin

Ranked by value for this workload:

1. **SmartCache exclusions** for `/dashboard/*` and auth routes. Correctness, not tuning.
2. **Redis object cache** — off on both sites, available on Wordify. The dashboard and queue are
   query-heavy and logged-in, so page cache cannot help them. This is the single best win.
3. **Raise `memory_limit`** from 256M. Server sizing is Martin's call as load requires.
4. CDN is already on in production and covers public archives. Nothing to do.

### Cloudflare — a narrower answer than you might want

**Worth doing.** Turnstile on registration, login, password reset and submission endpoints — free,
better than reCAPTCHA, small integration. WAF rate-limiting on `/wp-login.php`, the dashboard auth
routes and the OAuth callback. Both are configuration plus a few lines, and they matter more at 10k
users than at 50.

**Not worth doing.** Moving application logic to Workers or D1. WordPress remains the source of
truth, and splitting it would add a second data store and a deployment surface while undermining the
"one database, one backup, one GDPR surface" argument the proposal used against CiviCRM. 5-10k users
is modest for WordPress. What bites at that scale is unindexed queries and unbatched email, and both
are solved above.

### Privacy and GDPR

- Member-initiated close: soft close to `dgl_closed`, then confirmed hard delete.
- Deletion or suspension by DGLP: same flow, plus the org content decision from the proposal — leave
  live, unpublish, delete or reassign.
- **Register with core's privacy tools** via `wp_privacy_personal_data_exporters` and
  `wp_privacy_personal_data_erasers`, so WordPress's existing export and erase screens cover plugin
  data. Cheap, correct, already built.
- Consent records: timestamp, hashed IP and policy version, captured at registration and again at
  digest opt-in. PECR requires the digest opt-in to be a genuine opt-in with a record.
- Auth cookies are strictly necessary so need no consent, but must be declared. Partnership has no
  consent plugin installed, so one is still needed.
- Retention settings for audit rows and archived content.

### Audit log

`dgl_audit`: timestamp, actor, hashed IP, object type and id, org, action, field-level before and
after JSON with a size cap, note. Written from one `Audit::log()` call inside the state machine.
Members see their own org's rows, DGLP sees everything. This replaces WP Activity Log Premium for
this plugin's events only — it will not audit core or other plugins, which is a real reduction in
coverage and should be stated to DGLP.

## Plugin structure

```
dgl-platform/
  dgl-platform.php           bootstrap, version guards, activation
  composer.json              PSR-4 DGL\, Action Scheduler
  src/
    Schema/FieldRegistry.php + types/{News,Event,Training,Grant,Volunteering}.php
    PostTypes.php  Statuses.php  Taxonomies.php
    Roles.php      Access.php              <- the single gatekeeper
    Index/{ItemsTable,Sync,ReindexCommand}.php   <- the projection table
    Org/{Org,Members,Invites,Trust}.php
    Workflow/{StateMachine,Revisions,Expiry}.php
    Dashboard/{Router,Controllers,Views}
    Moderation/{Queue,ReviewController,Checks,SpotCheck}.php
    Auth/{Oauth/Microsoft,Oauth/Google,Linking,Turnstile}.php
    Email/{Mailer,Templates,Digest/{Preferences,Builder,Runner}}.php
    Audit/{Log,Table}.php
    Privacy/{Exporter,Eraser,Consent}.php
    Admin/{MetaBoxes,Columns,Settings}.php       <- the ACF replacement in wp-admin
    Export/Csv.php
  assets/dashboard.css (DGLP tokens), dashboard.js
  templates/                 child-theme overridable
  tests/
```

## Phasing

**1a Foundations.** Plugin skeleton, five CPTs, `dgl_topic`, statuses, roles, `Access` gatekeeper,
`dgl_items` index table and sync, org model, registration and DGLP approval, email and password auth,
front-end shell, audit log. No submissions yet. Screens `1a` `1b` `1c`.

**1b Submissions.** Field registry, wizard, News and Events, moderation queue and review screen,
pending-revision flow, trust levels, transactional email, expiry cron. Screens `1d`–`1h`, `1k`, `1l`.

**1c Remaining types.** Training, Grants, Volunteering plus public templates.

**2 Additive.** Microsoft and Google SSO, digests and preferences, CSV export, privacy tools and
consent capture, Turnstile and WAF.  Screens `1i` `1j`.

Wireframe gaps to decide during 1a: register step 2, wizard steps 1, 3 and 4, four of the five
profile tabs, the remaining admin screens, all mobile layouts, and every empty, loading, validation
and error state.

## Verification

Nothing is reported as working without evidence from these.

- **Activation**: `wp plugin activate dgl-platform` on staging, then a `wp eval` smoke check. No
  fatals, no notices with `WP_DEBUG` on.
- **Automated tests** (`wp scaffold plugin-tests`, PHPUnit against a real WP install). Mandatory
  negative coverage: a member of org A cannot read, edit or list org B's items through any route;
  the state machine rejects illegal transitions; revision apply and discard leave the live post
  correct; invite tokens are single-use and expire; SSO linking refuses an email-only match; trust
  level 2 still cannot bypass account approval.
- **Index integrity**: seed several thousand items, run `wp dgl reindex`, assert `dgl_items` matches
  a `wp_posts` ground-truth query exactly. Re-assert after each state transition.
- **Performance**: with 10k items and 500 orgs seeded, `EXPLAIN` the member list, moderation queue
  and digest queries. Every one must use an index, no filesort, no full scan.
- **Cache**: log in as two different members and confirm neither sees the other's dashboard through
  SmartCache. This is the test that catches the exclusion bug.
- **Security**: `/security-review` on the diff, plus a manual pass confirming every `$_POST` and
  `$_GET` handler has nonce, capability check, input sanitisation and output escaping.
- **Email**: activate SMTP2GO, send one of each transactional template, confirm delivery and headers.
  Run the digest builder against seeded content and assert the item set matches the preference
  filters before any real send. Time a 1,000-recipient batch against the 30 second limit.
- **Cron**: enable system cron, confirm `DISABLE_WP_CRON`, then `wp cron event list` and observe a
  scheduled digest actually firing.
- **Contrast**: the palette and every status chip pair are asserted in the test suite, so a brand
  tweak that breaks WCAG AA fails the build rather than shipping.
- **UAT**: walk all twelve wireframe screens on staging as a member, as a moderator, as a site admin
  granting trust, and as an unauthenticated visitor probing for org leakage.
