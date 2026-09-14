# WordPress User Admin Dashboard

_Started 2026-09-10 12:46 UTC_

---

## User

Wireframe the screens for the dashboard that would drive this wordpress user admin system. 
This is extracted from the breif to my dev by the way. Ask anything you need

One of the things they would like, and I appreciate that there will be some additional cost, is the ability for people to register for an account with their MS or Google SSOs. I was anticipating that we might need to use one of these https://clerk.com/ or https://www.miniorange.com/ but there might be a simpler custom way to do it of course?

Users can create an account or Edit as an individual or an organisation 
This is approved/denied by internal team

Once approved users will be able to submit the following as forms for approval by the admins:

Events
News
Training
Funding
Volunteering opportunities

These are not much more than templated posting are always held for approval by a site admin before being allowed to appear on the site.

 Non admin users will never get access to the main admin section of the site so I’m not sure if we need a custom admin dash board which will allow them to submit their (ABOVE) Items for approval or to edit existing ones (which will need to be re-approved of course)


Edit user information
Tiles for each category of submission
Previous items
Archived items 
Approved/unapproved posts

## Assistant



## Assistant

_[tool: ask_user]_

_[tool: ask_user]_

Form's up — waiting on your answers before I start wireframing.

## User

Answered — who_for: Members + a lightweight admin review queue in the same front-end UI; org_model: Org can invite multiple members who submit on its behalf, Individuals can also be linked to an org later; submit_flow: Multi-step wizard; screens: Sign in / SSO choice, Register (individual vs org), Pending-approval holding screen, Dashboard home (tiles), Category list view, Submission form, Submission detail + status history, Archived items, P…; statuses: yes add expired and or archived also; categories_differ: Meaningfully different (Events need dates/venue, Funding needs deadline/amount); sc…

## Assistant

_[tool: list_files]_

_[tool: read_file]_

_[tool: grep]_

_[tool: read_file]_

_[tool: grep]_

_[tool: read_file]_

_[tool: dc_write]_

_[tool: ready_for_verification]_

Twelve screens on one canvas, ids `1a`–`1l`. Member side is the brand surface (navy/red/DM Sans); admin review queue and item review use the RYCM console styling, since those two audiences are different products. Statuses: Draft, Pending review, Live on site, Changes requested, Expired, Archived, plus "Not approved" in the archive.

Assumptions worth checking: editing a live item sends it back to Pending; events auto-expire on end date; org owners invite colleagues as seats; admins can request changes with a note rather than only approve/deny.

**On SSO — my take**

You almost certainly don't need Clerk. Clerk is excellent but it wants to own your user model, which fights WordPress: you end up syncing Clerk users to WP users anyway, and you pay monthly forever (~$25/mo + per-MAU above the free tier).

Ranked:

1. **Custom, using a maintained WP OAuth library** — a small plugin wrapping Microsoft Entra ID and Google OAuth2, mapping to WP users with a custom role. Cheapest long term, no vendor, no per-user fees. Half a day to a day of dev each provider plus testing. This is what I'd brief for. There are solid free plugins (e.g. "OpenID Connect Generic") that do 80% of it as a starting point.
2. **miniOrange** — fine if you want it configured rather than coded, but the free tier is deliberately thin and licences are per-site with paid support tiers. Faster to stand up, more ongoing cost, and its admin UI is not friendly.
3. **Clerk** — only worth it if you also want their hosted user management, MFA, org/invite handling out of the box. Your org-with-multiple-members model is the one thing Clerk genuinely gives you free. If the invite/seat flow in `1i` matters a lot, Clerk becomes more defensible.

Either way the approval step is yours, not the SSO provider's: SSO proves who someone is, your `pending → approved` flag decides what they can do. Keep that in WP.

## User

Save as standalone HTML: `Member Dashboard Wireframes.dc.html`

## Assistant

_[tool: read_file]_

_[tool: run_script]_

_[tool: super_inline_html]_

_[tool: show_html]_

_[tool: get_webview_logs]_

_[tool: show_html]_

Renders offline with fonts and styles intact.

_[tool: present_fs_item_for_download]_

Single file, 831 KB, works offline — fonts and design-system styles are inlined.

## User

<!-- The user explicitly selected the following skills for this project, as attachments to their message. These are not optional context — they define how you work. Use them. -->
<attached-skill name="Results You Can Measure Design System (design system)">
[Design System] This project uses the **Results You Can Measure Design System** design system. This is a binding choice for visual style — every visual must follow it. Don't invent colors, type, spacing, or components not grounded here.

Scope: the design system is a visual style reference only. Its guide may describe example products, brands, or people that are unrelated to the user and unrelated to the subject of this conversation. Never treat anything in the design system as a fact about the user, their work, or the topic they asked about.

This project has the **ResultsYouCanMeasureDesignSystem_3a5dcd** design system bound at `_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/`. If anything under that path is missing or fails to load, the folder name may differ — `list_files` `_ds/` and use the folder whose name ends with 3a5dcd52-6bda-45d2-837b-9c79c8e758ef instead, including in the <link>/<script> paths below.

**Loading the bundle is how you use this design system.** Every Design Component you build must load `_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/_ds_bundle.js` in `<helmet>` (include it in child DCs too — de-duped by URL) and compose with its components — do not recreate those components from scratch or restyle raw HTML to look like them. At the top of the template:

```html
<helmet>
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/fonts.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/colors.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/typography.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/spacing.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/effects.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/motion.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/admin.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/tokens/patterns.css">
  <link rel="stylesheet" href="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/styles.css">
  <script src="_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef/_ds_bundle.js"></script>
</helmet>
```

Then mount any component directly in the template — no logic class needed:

```html
<x-import component-from-global-scope="ResultsYouCanMeasureDesignSystem_3a5dcd.Button" hint-size="auto,40px">Label</x-import>
```

Props are template attributes (kebab → camelCase); template children pass through as `props.children`. The linked stylesheet(s) carry the design tokens (colors, type, spacing) — style against those via `var(--*)` and the design system's classes rather than guessing values.

**Start from a template if one matches.** Before building from scratch, check whether a template below matches the user's request. Read the template (list its folder, `read_file` the entry) and decide: if it's close to what's asked, `copy_files` the folder as your starting point; if you'd rather re-author in DC, `dc_write` a new page using the template as your structural reference. When this design system is bound, `copy_files` repoints the copied `ds-base.js` automatically — only edit its base line yourself if the copy result says so, or when copying from an unbound project (change `'../..'` to your `_ds/results-you-can-measure-design-system-3a5dcd52-6bda-45d2-837b-9c79c8e758ef` path). (Older templates have no `ds-base.js` and carry `../../…` bundle/stylesheet refs in their pages instead — repoint those by hand.)

- `Client report` (`/projects/3a5dcd52-6bda-45d2-837b-9c79c8e758ef/templates/client-report/`) — Branded RYCM monthly client report — cover, summary, results table, next steps.

The full design-system source tree lives at `/projects/3a5dcd52-6bda-45d2-837b-9c79c8e758ef/` — use `list_files` / `read_file` from there for anything not under `_ds/` (layout varies by brand; don't assume folder names). Its guide is reproduced below so you don't need to fetch it:

<design-system-guide>
# Results You Can Measure — Design System

Brand and product design system for **Results You Can Measure (RYCM)**, a small, founder-led UK marketing agency based in Bradford, West Yorkshire, run by Martin Fisher. RYCM sells branding, marketing consultancy, web development, hosting and maintenance, SEO, PPC management and copywriting to UK businesses — and the whole proposition is accountability: *marketing you can prove*.

The brand promise shapes the design language. Nothing decorative claims a result it cannot show. The one recurring device on the marketing site is a scripted client/agency conversation that handles a real objection, and the one recurring proof device is a row of real Lighthouse scores.

## What this system covers

Two live surfaces, both built from the same brand:

| Surface | What it is | Source |
|---|---|---|
| **resultsyoucanmeasure.com** | The marketing site. Astro 5 on Cloudflare Workers. Navy heroes, uppercase display type, red accents, editorial case-study cards, a big rising footer wordmark. | `martingfisher/results` |
| **RYCM Admin** | Internal forms + ops console (submissions, sites, site health, scheduled jobs, backups, SEO reports, task board). Deliberately quiet slate UI; only the logo and status colours carry meaning. | `martingfisher/RYCM_forms_system` |

A third repo, `martingfisher/rycm-platform`, is the canonical Astro + Cloudflare *starter* used to spin up new client sites. Its token file is a generic placeholder (blue, Inter) and is **not** the RYCM brand — it is included here only as the structural pattern for how a site's tokens are organised.

## Sources used

Everything in this system was lifted from these, not from memory:

- **Brand Identity Guidelines v1.0, 13 March 2024** (PDF, 29pp) — `uploads/RYCM_Guidelines-compressed.pdf`. Colour palette, typography, logo rules, tone of voice, brand values.
- **Brand kit** — `001 RYCM Branding - New March 2024/RYCM Logo Files/Claude-Brand-Kit/BRAND.md`, Martin's own canonical spec for RYCM deliverables (also copied to `uploads/BRAND.md`). This is where the house style rules live (no em dashes, no Oxford comma, UK English).
- **Logo files** — `001 RYCM Branding - New March 2024/RYCM Logo Files/` (Logotype 01–03, Isotype 01–02, SVG + PNG).
- **Live site CSS** — `martingfisher/results` → `src/styles/global.css` (59 KB). The single richest source in the project: every number in `tokens/` traces to it.
- **Site content** — `martingfisher/results` → `public/llms.txt`, `src/content/services/*.md`, `src/data/navigation.ts`, `CLAUDE.md`.
- **Admin console** — `martingfisher/RYCM_forms_system` → `apps/admin/src/app/components/ui.tsx`, `AppLayout.tsx`, `pages/Dashboard.tsx`, `pages/Submissions.tsx`, `pages/Sites.tsx`, `src/styles/global.css`.
- **Starter tokens** — `martingfisher/rycm-platform` → `src/styles/tokens.css`, `fonts.css`, `reset.css`, `global.css`.

Repository URLs (private — you will need Martin's access):
`https://github.com/martingfisher/results` ·
`https://github.com/martingfisher/RYCM_forms_system` ·
`https://github.com/martingfisher/rycm-platform`

**Read these repos directly if you can.** `results/src/styles/global.css` in particular contains hundreds of exact values, hover behaviours and layout rules that this system summarises but does not exhaust. `results/CLAUDE.md` documents the publishing workflow, content schema and deployment. Anything you want pixel-perfect, get from there.

---

## CONTENT FUNDAMENTALS

### The voice in one line
Direct, plain-spoken, quietly confident. Says what does not work before it says what it sells.

The guidelines name ten qualities: **dynamic, confident, authoritative, professional, approachable, direct, honest, respectful, empathetic, inspiring**. The five brand values behind them: **accountability, transparency, innovation, integrity, excellence**.

### Hard house-style rules
These come from Martin's own brand kit and are non-negotiable:

- **UK English.** Optimise, colour, organise, specialise, analyse. Also *programme*, *licence* (noun), *whilst* is fine.
- **No em dashes.** Use a hyphen or a full stop. (The live CSS even ships comments about it, and the repo has a hook that fails the build on one.)
- **No Oxford comma.**
- **Short sentences.** Often one sentence per paragraph. Fragments are allowed for rhythm: *"Too much complexity. Unclear messaging. Slow performance. No clear next step."*
- **Plain words.** No hype, no "leverage", no "synergy", no "we're passionate about".
- **Starting a sentence with "and" or "but" is fine.**
- **No emoji. Anywhere.** Not in the product, not in the marketing, not in the console.
- **StoryBrand framing:** the customer is the hero, RYCM is the guide. Every page ends with a conclusion and one call to action.

### Person and address
Second person for the reader, first-person plural for the agency. *"Your website may not need more features. It may need more clarity."* / *"We focus heavily on usability, positioning and communication."* The site occasionally shifts to third person for the agency in formal contexts (*"Results You Can Measure designs and develops websites that…"*) — use that only in about/credentials copy.

### Casing
- **Hero headlines: UPPERCASE.** No exceptions. *MARKETING YOU CAN PROVE.*
- **Section headings (h2): sentence case, with a full stop.** *"Joined-up marketing, measured end to end."*
- **Eyebrows and labels: UPPERCASE**, 0.07em tracking.
- **Buttons: sentence case.** *"Start the conversation"*, *"Discuss your website"*.
- **Client names in case-study cards: UPPERCASE.**
- Service names are title case (*Web Development*, *PPC Management*) and are treated as proper nouns.

### Copy patterns that recur
- **Problem-first headlines.** *"Most marketing is not badly made. It is badly measured."*
- **The correction.** State the common assumption, then correct it: *"We thought we just needed a redesign." / "Design is rarely the whole problem."*
- **The reason after the claim.** *"Because confusion usually costs more than design ever fixes."* — a one-line paragraph starting with "Because" is a signature move.
- **Named measures instead of adjectives.** Not "lightning fast" but "100/100 on Lighthouse Performance and SEO". Not "lots of pages" but "a 200+ page local-SEO build".
- **Chat scripts** end with the client conceding: *"That is a completely different way of thinking about it."*
- **CTAs**: *Start the conversation* (default), *Discuss your website*, *Book a review meeting*, *Send us your project*. Never "Get started" or "Learn more".

### Admin console copy
Different register: terse, factual, imperative. Page descriptions state scope and window (*"Submissions across all sites - last 14 days."*). Button labels are verbs (*Resend*, *Archive*, *Rotate submit secret*). Destructive actions spell out the consequence in the confirm text. Empty states explain what will make the view fill up. Status labels are single past-tense words: Delivered, Quarantined, Failed, Draft.

---

## VISUAL FOUNDATIONS

### Colour
Five colours, from the guidelines: **Cetacean Blue #070630**, **Venetian Red #F83739**, **Anti-Flash White #F1F1F1**, White, Black.

In practice the live site uses a sixth: **#d6262a**, a deepened red introduced on 2026-05-27 so white text on red clears WCAG AA (4.5:1; #F83739 only managed 3.74:1). **Use `--rycm-red` (#d6262a) for anything interactive or text-bearing, and `--rycm-red-brand` (#F83739) only decoratively** — radial glows, large-scale display panels, the footer glow.

Proportion is the point. Navy and off-white carry almost every surface; red appears as eyebrows, buttons, rules, the process numerals and — at most once per page — a full red band (CTA or pull-quote). Two background colours per composition, maximum. Extended neutrals: `--rycm-ink` #161622 for body copy, `--rycm-muted` #6d6d7c for secondary copy, `--rycm-line` rgba(7,6,48,.13) for hairlines. On navy, everything is a white alpha step: .90 / .82 / .72 / .50 / .14 / .07.

### Type
**DM Sans, and only DM Sans** — the guidelines name it as both primary and secondary typeface. Weights: 300–900. The guidelines specify Semibold/Medium for titles and Regular/Light for body; the live site pushes harder, using **900 for display and 800 for UI emphasis**.

The display voice is unmistakable: **uppercase, weight 900, leading below 1 (0.9–0.92), zero letter-spacing**. `clamp(2.6rem, 5vw, 6rem)` for heroes, up to 5.5rem for full-bleed panels, and a footer wordmark at `clamp(5rem, 18vw, 23rem)`. Section h2s drop to sentence case at 1.05 leading with `text-wrap: balance`. Body copy is 1.15rem at 1.65 leading in muted grey; article bodies 1.18rem at 1.7 with a 900px measure. Eyebrows are a fixed 14px — they never scale.

### Layout
No fixed container. Symmetric fluid page padding (`clamp(18px, 5vw, 72px)`) plus content max-widths do the framing: 980px for display, 710px for a lede, 900px for article text. Section rhythm is `clamp(40px, 5vw, 72px)`. Card grids are three-up at an 18px gap (two-up at 18–28px for editorial cards; the third spans full width). Service-area grids are four-up. The header is sticky; nothing else is fixed.

### Backgrounds and texture
Three devices, no gradients-as-decoration:

1. **A 72px navy grid**, at 3.5% opacity, masked to transparent by 75% down the page — the site's ambient texture. On navy it inverts to white at 3.5%, masked at both ends.
2. **Full-bleed photography under a navy scrim.** Always the navy, never neutral black. Left-to-right scrim on heroes, bottom-up scrim on cards, both stacked where type sits low.
3. **A single red radial glow**, top-left of the footer and behind case-study nav cards.

Imagery is the work itself: device montages, real client screens, real interiors. Cool and neutral in tone, never warm-graded, never grainy, never abstract stock. The one hero background photo pans slowly (Ken Burns, 22s, alternating). Team portraits are the only people shots.

### Corners, borders, shadows
Radius **8px is the default** (buttons, cards, panels, the inset CTA band). **4px** where a card is nearly full-bleed (editorial case cards, chips on navy). **14px** for the mega menu, **18px** for chat bubbles and proof panels, **999px** for tags only, **50%** for process numerals and avatars.

Cards are a 1px hairline (`rgba(7,6,48,.13)`) over a **78% white fill** — so the page's grid texture shows faintly through — plus `0 10px 30px rgba(7,6,48,.06)`. Every shadow is navy-tinted; there is no `#000` shadow on the brand except on chat bubbles over video. Borders on navy are `rgba(255,255,255,.14)`. The only coloured left border in the system is the 8px red rule on the article TL;DR callout, and the 3px red rule on dark metric cards — do not generalise the pattern.

### Transparency and blur
Used for exactly two things: the sticky header (`rgba(7,6,48,.92)` + `blur(16px)`) and the mega menu (`rgba(7,6,48,.98)` + `blur(20px)`). Card fills use alpha (78% white, 7% white on navy) but no blur. Nothing else is frosted.

### Motion
Two easings do all the work. `cubic-bezier(0.16, 1, 0.3, 1)` (expo out) for reveals, card rises and the footer wordmark. `cubic-bezier(0.175, 0.885, 0.32, 1.275)` — the one overshoot on the brand — for chat bubbles only. Durations: hover 0.15s, menu 0.18s, UI 0.25s, reveal 0.45s, imagery 0.65s, footer wordmark 1.45s.

Signature animations: chat bubbles fly in from their own side on a 0.52s stagger; the footer wordmark's letters rise from a clipped box on a 0.11s stagger with a 3° rotation; the tech marquee scrolls -50% over 30s and pauses on hover; hero backgrounds pan. `prefers-reduced-motion` collapses all of it.

### States
- **Hover on buttons:** the fill darkens, or a bordered button fills with navy. Never a lift, never a scale, never a glow.
- **Hover on cards:** the shadow deepens (`0 26px 64px rgba(7,6,48,.16)`). Position does not change.
- **Hover on nav-style cards on navy:** translate -4px and lighten the fill to 8% white.
- **Hover on editorial case cards:** the image scales 1.04 and darkens to 58% brightness while an extra line of copy rises 18px into place.
- **Hover on links:** colour goes red. On the arrow link, the gap widens 6px → 10px and opacity drops to .82.
- **Press:** opacity .9. Nothing shrinks.
- **Focus:** a 2px red outline at 2px offset on the site; a blue accent ring in the console.

### The admin console, by contrast
Slate on near-white: bg #fafafa, text #0f172a, muted #64748b, borders #e2e8f0, primary slate-900, accent blue #2563eb, danger #b91c1c. 6px radius, 14px type, 40px controls, 240px sidebar, 32px page inset, near-invisible shadows. **It does not use the brand typeface either** — the real console sets **Inter** (`apps/admin/src/styles/global.css`), exposed here as `--admin-font` on the `.rycm-admin` scope. Brand red does **not** appear — red in the console means an error or a destructive action. This restraint is deliberate: it is a tool, not a brochure.

---

## ICONOGRAPHY

**There is no RYCM icon set.** The marketing site is almost entirely icon-free — it uses type, rules and photography instead. Where a glyph is needed the site draws it in CSS or uses a plain text character:

- **Chevrons** are CSS: a 6×6px box with two 1.5px borders, rotated 45° (and to -135° when open). Used for the mega-menu caret.
- **Arrows** are literal text characters — `-&gt;` and `&lt;-` on the case-study nav cards, `→` in the mega-menu footer link. Not an icon font, not an SVG.
- **The isotype** (three nested arrows, red) doubles as an icon: favicon, avatar, and a 7%-opacity watermark in the bottom-right of dark metric cards.
- **No emoji, ever.** No unicode dingbats. No decorative icon set.

The **admin console** does use icons: **lucide-react** at 16px, stroke style, one per nav row (`kanban-square`, `layout-dashboard`, `inbox`, `file-text`, `globe`, `activity-square`, `package`, `timer`, `database-backup`, `trending-up`, `chevron-right`, `chevron-down`). The admin UI kit here loads Lucide from the CDN (`unpkg.com/lucide@0.469.0`), which is the same icon set the real console imports from npm — matched, not substituted.

**Third-party platform logos** are real SVGs copied from the site repo and live in `assets/brand-logos/` (Astro, Cloudflare, WordPress, Shopify, Laravel, Webflow, Google Ads, Google Analytics, Claude, OpenAI, Figma, Adobe, Meta, Stripe, LinkedIn). They render flat at 38px height and 78% opacity, never in coloured tiles.

### Logo assets

`assets/` holds the real files, unmodified:

| File | Use |
|---|---|
| `logotype-primary.svg/.png` | **Primary** lockup: red isotype + navy wordmark. Light backgrounds, documents, reports. |
| `logotype-mid.svg/.png` | Mid / light-grey backgrounds. |
| `logotype-white.svg/.png` | All-white lockup for navy or red backgrounds. |
| `isotype-red.svg/.png` | The three-arrow mark alone: favicon, avatar, watermark, tight spaces. |
| `isotype-alt.svg/.png` | Secondary isotype variant. |
| `site-logos/` | The three SVGs the live site actually ships (`rycm-logotype-dark`, `-light`, `rycm-isotype-red`). |
| `lines-white.png`, `map-lines-grey.png` | Line-pattern graphic elements from the brand folder. |
| `photo-abstract-01.png`, `og-default.jpg` | Full-bleed background imagery. |
| `projects/`, `blog/` | Real client work and article art from the site. |
| `martin-fisher.jpg` | Founder portrait. |

**Rules (from the guidelines):** never recolour, rotate, distort or add effects. Never separate the wordmark from the isotype. Never use a stacked version. Never change the wordmark's weight or typography — it is a custom cut. Minimum size 10mm in print, 30px digital. In the site header the logotype is 147px wide; in the footer 150–165px.

---

## Index

### Root
- `styles.css` — the entry point. `@import` lines only. Link this one file.
- `readme.md` — this file.
- `SKILL.md` — Agent Skills front matter, so this folder works as a Claude Code skill.
- `github.md` — source-repo association and sync record.
- `thumbnail.html` — homepage tile.

### `tokens/`
`fonts.css` · `colors.css` · `typography.css` · `spacing.css` · `effects.css` · `motion.css` · `admin.css` (scoped to `.rycm-admin`) · `patterns.css` (hover/press states, keyframes, the grid texture — real CSS that inline styles cannot express).

### `guidelines/`
22 specimen cards, rendered in the Design System tab: brand core · the two reds · neutrals · on-navy scale · admin palette · DM Sans · display scale · headings · lede and body · eyebrows and labels · space scale · fluid page rhythm · card grid in use · corner radii · shadows · grid texture · protection gradients · motion · logotype · isotype · logo rules · imagery.

### `components/site/` — marketing-site primitives
`Button` · `Eyebrow` · `SectionHead` · `ServiceCard` · `MetricCard` · `CaseCard` · `ChatStack` (+ `TypingDots`) · `TagRow` · `BlogCard` · `FaqList` · `CtaBand` · `ProcessSteps` · `ArticleCallout` · `PullQuote` · `ScoreRow` · `TechMarquee`

### `components/admin/` — console primitives
`AdminButton` · `AdminInput` · `AdminTextarea` · `AdminSelect` · `AdminLabel` · `AdminCard` (+ `AdminCardHeader`, `AdminCardTitle`, `AdminCardDescription`, `AdminCardBody`) · `AdminBadge` · `AdminStat` · `AdminPageHeader` (+ `AdminPageBody`) · `AdminSidebar` · `AdminEmptyState` · `AdminSpinner`

Every component has a sibling `.d.ts` (props contract) and `.prompt.md` (what, when, a usage example and the notable variants). Each directory has one `@dsCard` HTML showing the states side by side.

### `ui_kits/`
- `website/` — resultsyoucanmeasure.com. Home, service page, case study, insights index, contact. Clickable: the services mega menu opens, cards navigate, the contact form submits.
- `admin/` — RYCM Admin. Overview, submissions (with filters and expandable rows), submission detail, sites. Routes that were not read in detail render an honest "not recreated" state rather than invented UI.

### `templates/`
- `client-report/ClientReport.dc.html` — **Client report**. The branded three-page A4 monthly report: cover with the primary logotype at 330px over a red rule, summary page with KPI tiles and a navy-headed results table, then what-we-did / next-month / closing red panel. Prints to A4 with no further work. Client name and report period are editable props. This mirrors the deliverable `Claude-Brand-Kit/report-template.js` generates as a .docx.

### Intentional additions
- **`ScoreRow`** — the live site renders its Lighthouse gauges inline in an Astro page rather than as a named component. It is promoted to a component here because it is the brand's standing proof device and gets reused constantly.
- **`tokens/patterns.css`** — not a file in any source repo. It exists because hover/press states and keyframes cannot live in inline styles, and consumers need them to get the interaction feel right.
- **`--rycm-red-brand` / `--rycm-red` split** — the source repo has one `--red`. Both hexes are real (guidelines vs. live site); naming them separately stops the AA-safe value being reverted by accident.

## Caveats

- **Fonts are loaded from Google Fonts, not self-hosted.** DM Sans *is* the correct brand font (a free Google Font, per the guidelines and Martin's brand kit) — but no binaries were provided, so `tokens/fonts.css` uses the Google Fonts CSS API. For production, self-host via `@fontsource/dm-sans` the way `martingfisher/results` does. **If you have licensed woff2 files, send them and I will swap in real `@font-face` rules.**
- The guidelines PDF has copy-paste artefacts from its template — several page footers read "Sober Support Coalition Brand Guidelines". Ignored; the palette, type and logo pages are RYCM's.
- `.astro` component files could not be read from `martingfisher/results` (the importer filters them out), so the website kit's markup is reconstructed from `global.css` class names, `llms.txt` and the service content files. The values are exact; the DOM structure is inferred.
- `RYCM_forms_system` routes for Tasks, Forms, Site health, Versions, Backups, Scripts and SEO Reports were not read; they are stubbed honestly rather than invented.
- **The console runs Inter, not DM Sans.** That is the source repo's choice, kept deliberately so the internal tool stays visually separate from the brand. If you would rather unify the console on DM Sans, change `--admin-font` in `tokens/admin.css` to `var(--rycm-font-sans)` — one line.
- The website kit has no **About** or **Reviews** screen (both nav items land on home). They exist on the live site; say the word and I will build them.

</design-system-guide>
</attached-skill>

## Assistant

Nothing new to act on in that — the wireframes and the standalone file are both in place. What would you like next: mobile versions, the admin account-requests screen, or a pass on the copy?

## User

Save as standalone HTML: `Member Dashboard Wireframes.dc.html`

## Assistant

_[tool: run_script]_

_[tool: super_inline_html]_

_[tool: show_html]_

_[tool: get_webview_logs]_

_[tool: present_fs_item_for_download]_

Regenerated from the current source — 831 KB, no external dependencies.

