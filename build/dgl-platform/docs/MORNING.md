# Morning notes, 16 September

Everything below is pushed to `main` and in the zip. Five commits overnight:
`5cf8336`, `9015cb2`, `30e9217`, `f617870`.

## Decisions I need from you

1. **Grants or Funding.** You settled this: the proposal is the source of truth,
   so it is built as **Grants**. Worth a line to DGLP anyway, because the
   wireframes say Funding six times and DGLP's own live site has a
   "Funding and Finance" page. If they want the other word it is one line.
2. **"Member area"** is the label in the new top bar. DGLP may prefer their own
   wording.
3. **Repo visibility.** Still private, so I still cannot deploy to staging
   myself. One click in GitHub settings and every future deploy is a single
   command from here.
4. **The RYCM handoff notes** are out of the tree but still in git history.
   Purging them needs a force-push, which I did not do without asking.

## What I did

**The member area has its own shell.** No site menu, no breadcrumb, no site
footer, no WordPress admin bar. Slim branded top bar with a link back to the
public site. Members are also now redirected out of wp-admin, which the original
plan specified and nobody had built.

**The dashboard leads with what needs doing.** The four stat tiles are links
that filter the activity list. Anything the team sent back is named in a banner.
A brand new member is not shown four noughts.

**The client's own admin is real now.** Meta boxes so every field shows in
wp-admin, list columns for organisation, status and how long something has been
waiting, an organisation filter, and quick decisions from the list row or the
edit screen. Classic editor rather than the block editor, which could not show a
custom status at all.

**Organisations screen.** Verification, trust level, and the name and logo
changes a member can request. That last one closed a dead end I had created: the
request could be made and nothing anywhere could say yes.

**Notifications screen**, built from the audit trail that already existed.

## Bugs found, all by using it rather than reading it

1. **An administrator could publish a pending submission by accident.** Open it
   in wp-admin, fix a typo, press the normal save button, and it went live.
   WordPress's publish box cannot represent our statuses, so it posts its own
   and the save promotes the post. Member work reaching the public with nobody
   deciding and nobody being told. The status is now held on wp-admin's save
   paths. This is the most serious thing found tonight.
2. **The whole sidebar navigation was 2.13:1.** Partnership purple on deep navy,
   failing WCAG AA badly, on the primary navigation of the entire member area.
   The active filter chip and every button that was a link had the same fault.
   Found by the contrast checker I added, not by any test we had.
3. **The design tokens were scoped to `.dgl-dash`**, so the new top bar had no
   background colour at all.
4. **A decision panel cannot contain a form.** Meta boxes sit inside WordPress's
   own form, nested forms are invalid, and the browser drops the inner one
   silently. Built wrong twice before it was built right.
5. **A notice claimed "the member has been told"** after an organisation change
   decision. No such email exists. See below.

## New tool

`bin/check-contrast.mjs` walks the member area in a real browser and fails on
anything below WCAG AA. The palette test in `tests/test-brand.php` only checks
colours we *intend* to pair; this checks what actually renders, which is how the
2.13:1 navigation had survived. Run it as:

```
node bin/check-contrast.mjs <base-url> <user> <password>
```

## Known gaps, stated plainly

- **No email when an organisation's name or logo change is decided.**
  `dgl_org_change_approved` and `dgl_org_change_rejected` both fire and nothing
  listens. The screens no longer claim otherwise.
- **The notification badge counts what needs doing, not unread items.** There is
  no read/unread store yet. Deliberate, and said on the screen.
- **Members, invites, email preferences and closing an account** are still the
  three tabs on the profile screen that say they are not built.
- **Still unbuilt:** SSO, digest sending, CSV export, privacy exporters, public
  templates.

## Verification

| Check | Result |
|---|---|
| `php tests/run.php` | 1078 passed, 0 failed |
| `wp eval-file tests/integration/run.php` | 270 passed, 0 failed |
| Contrast, as member | 448 pairs, all clear of AA |
| Contrast, as moderator | 446 pairs, all clear of AA |
| Zip installed into clean WordPress | activates, five routes return 200, tests pass on the server |
| Browser walk | member and admin paths at 1200px and 390px |
