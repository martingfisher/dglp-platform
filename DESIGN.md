---
name: DGLP Member Area
description: The member area and public listing pages of partnership.doinggoodleeds.org.uk
colors:
  council-navy: "#2d3b6b"
  navy-deep: "#253057"
  partnership-purple: "#5b6bae"
  slate-ink: "#1e3232"
  slate-muted: "#4f5e5c"
  notice-cream: "#fdf8f0"
  desk-grey: "#f4f6fb"
  paper-white: "#ffffff"
  leeds-teal: "#57b0b6"
  teal-solid: "#1d6265"
  accent-danger: "#cc0053"
  status-live-bg: "#eaf4e6"
  status-live-fg: "#387923"
  status-pending-bg: "#fff7e8"
  status-pending-fg: "#7a5a1c"
  status-changes-bg: "#f9e0ea"
  status-changes-fg: "#cc0053"
  status-draft-bg: "#e4e6e6"
  status-expired-bg: "#e6e7ed"
  status-archived-bg: "#ebedf5"
  status-archived-fg: "#5767a7"
  status-rejected-bg: "#ffede7"
  status-rejected-fg: "#b84925"
  status-live-text-deep: "#2f6a1d"
typography:
  display:
    fontFamily: "Montserrat, system-ui, sans-serif"
    fontSize: "clamp(1.6rem, 3vw, 2.2rem)"
    fontWeight: 700
    lineHeight: 1.2
  headline:
    fontFamily: "Montserrat, system-ui, sans-serif"
    fontSize: "1.05em"
    fontWeight: 700
    lineHeight: 1.3
  body:
    fontFamily: "Montserrat, system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: 400
    lineHeight: 1.65
  control:
    fontFamily: "Montserrat, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 500
    lineHeight: 1
  label:
    fontFamily: "Montserrat, system-ui, sans-serif"
    fontSize: "0.78em"
    fontWeight: 600
    letterSpacing: "0.03em"
  help:
    fontFamily: "Montserrat, system-ui, sans-serif"
    fontSize: "0.8em"
    fontWeight: 400
rounded:
  control: "8px"
  field: "10px"
  card: "20px"
  pill: "999px"
spacing:
  xs: "6px"
  sm: "12px"
  md: "16px"
  lg: "24px"
  xl: "40px"
components:
  button-primary:
    backgroundColor: "{colors.council-navy}"
    textColor: "{colors.paper-white}"
    typography: "{typography.control}"
    rounded: "{rounded.control}"
    padding: "0 20px"
    height: "45px"
  button-primary-hover:
    backgroundColor: "{colors.navy-deep}"
    textColor: "{colors.paper-white}"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.council-navy}"
    typography: "{typography.control}"
    rounded: "{rounded.control}"
    padding: "0 20px"
    height: "45px"
  button-secondary-hover:
    backgroundColor: "{colors.desk-grey}"
    textColor: "{colors.council-navy}"
  input:
    backgroundColor: "{colors.paper-white}"
    textColor: "{colors.slate-ink}"
    typography: "{typography.control}"
    rounded: "{rounded.field}"
    padding: "0 12px"
    height: "45px"
  card:
    backgroundColor: "{colors.paper-white}"
    textColor: "{colors.slate-ink}"
    rounded: "{rounded.card}"
    padding: "24px"
  sidebar:
    backgroundColor: "{colors.navy-deep}"
    textColor: "{colors.paper-white}"
    padding: "24px"
  nav-item:
    backgroundColor: "transparent"
    textColor: "{colors.paper-white}"
    typography: "{typography.control}"
    rounded: "{rounded.control}"
    padding: "9px 12px"
  chip-status:
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "3px 10px"
  tab-active:
    backgroundColor: "{colors.council-navy}"
    textColor: "{colors.paper-white}"
    typography: "{typography.control}"
    rounded: "{rounded.control}"
    padding: "10px 16px"
---

# Design System: DGLP Member Area

## Overview

**Creative North Star: "The Community Office"**

A well-run local office. The reception desk is dark navy and always in the
same place (the sidebar); the paperwork is white on a cream desk; what is
waiting for you sits in a clear tray at the top; the forms are obvious and the
person behind the desk tells you plainly what happens next. Nothing performs.
The member who comes in twice a year on a phone is served exactly as well as
the comms officer who comes in every week.

The system is calm, plain and dependable. One typeface at a tight scale, one
action colour, one shape vocabulary, status carried by a small labelled stamp.
Depth is gentle: cards lift a little off the cream so the page reads as papers
on a desk rather than lines on a grid. The visual rejections are confirmed: no
SaaS-dashboard gloss (gradients, glass, sparklines, hero metrics), and nothing
that looks like WordPress admin. It is DGLP's, in DGLP's colours, not the
wireframes' placeholder red and not RYCM's.

**Key Characteristics:**
- Navy desk, cream room, white paper: three surfaces and no more.
- Montserrat only, 18px body, a tight scale, weight doing the hierarchy.
- Refined and restrained components: a faint line where a line is decoration,
  a dark hairline where a line is a control.
- Status is a stamp: a tinted pill with dark text, never a bare colour.
- Held to WCAG 2.2 AA everywhere and AAA wherever the palette allows.

## Colors

DGLP's own palette, read from Blocksy at render time and never restated: every
token is `var(--theme-palette-color-N, #fallback)`, so a customiser change
reaches the member area without a code edit.

### Primary
- **Council Navy** (#2d3b6b): the one action colour. Primary buttons, the open
  tab, the selected stat, page titles, focus rings. Sits at 10.8:1 on white.
- **Navy Deep** (#253057): the sidebar and the hover state of a primary
  button. Council Navy mixed 18% to black so white type on it clears 12:1 and
  the active item's lighter fill has room to show.

### Secondary
- **Partnership Purple** (#5b6bae): the link colour on paper and the accent
  rail on the pending banner. 5.05:1 on white, which is AA and the ceiling of
  this hue; it never carries small text on colour, and under the AAA target it
  gives way to Council Navy for links inside the member area.

### Tertiary
- **Leeds Teal** (#57b0b6): a rail, never text. The active-item mark in the
  sidebar and nothing else. At 2.5:1 on white it cannot carry words, and the
  system says so rather than hoping nobody notices.
- **Teal Solid** (#1d6265): the kicker line on public pages, where teal needs
  to be read.
- **Accent Danger** (#cc0053): errors, refusals, the "needs your attention"
  stat, the required-field asterisk. Nothing else is this colour.

### Neutral
- **Slate Ink** (#1e3232): body text. A green-black, not a pure black, which
  is why muted text is mixed from it rather than from grey.
- **Slate Muted** (#4f5e5c): secondary text, labels, help lines. Ink at 78%
  over cream: 6.8:1 on white, 6.3:1 on Desk Grey. It was 68% and 5.1:1, and at
  14px it read as a watermark.
- **Notice Cream** (#fdf8f0): the room. Page background of the member area.
- **Desk Grey** (#f4f6fb): the second neutral layer. Table headers, secondary
  hover fills, the pending banner.
- **Paper White** (#ffffff): cards, fields, the top bar.
- Status tints, each the source colour mixed 88% into white with the text
  darkened until it clears 4.5:1: Live (#eaf4e6 / #387923), Awaiting review
  (#fff7e8 / #7a5a1c), Changes requested (#f9e0ea / #cc0053), Draft (#e4e6e6 /
  ink), Expired (#e6e7ed / navy), Archived (#ebedf5 / #5767a7), Not approved
  (#ffede7 / #b84925). The success alert uses a deeper green for its prose
  (#2f6a1d on #eaf4e6, 6.4:1) because a sentence is not a stamp. The amber
  alert border is the pending text colour, #7a5a1c.

### Named Rules
**The One Action Colour Rule.** Council Navy is the only colour that means
"you can press this" or "this is where you are". Purple links, teal rails and
danger red never compete with it.

**The Teal Is Not Text Rule.** Leeds Teal appears only as a mark that carries
no words. Its ratio on white is 2.5:1 and no size or weight rescues that.

**The Stamp Rule.** Status is a tinted pill with dark text and a word in it.
Colour alone never carries state, and every pill pair is asserted at 4.5:1 in
the test suite.

## Typography

**Display Font:** Montserrat (with system-ui, sans-serif)
**Body Font:** Montserrat (same family)

**Character:** one geometric sans for everything, which is right for an
Operate surface. Hierarchy comes from weight and a tight scale, not from a
second face. Montserrat's regular weight is light on the page, so nothing
below 500 is used for text smaller than body.

### Hierarchy
- **Display** (700, clamp(1.6rem, 3vw, 2.2rem), 1.2): the page title, one per
  screen, in Council Navy.
- **Headline** (700, 1.05em, 1.3): section and card titles, in Council Navy.
- **Body** (400, 18px, 1.65): prose, ledes and table cells. Ledes hold to 62ch.
- **Control** (500, 15px, 1): buttons, sidebar items, tabs, field text.
- **Label** (600, 0.78em, tracked 0.03em, uppercase): field labels, table
  headers, stat labels, sidebar section headings.
- **Help** (400, 0.8em): the line under a field, in Slate Muted.

### Named Rules
**The Weight Floor Rule.** Small text is never lighter than 500. Montserrat
400 at 14px on cream is where "the outline colour is too feint" came from.

## Layout

A two-column shell: a 260px Navy Deep sidebar on the left, the content column
to its right, together no wider than 1400px and centred. The content column
pads 24px vertically and clamp(16px, 4vw, 40px) horizontally. The top bar
above both is white with a 1px divider, 40px logo, and text links at the end.

Rhythm is a four-step scale: 6, 12, 16, 24, with 40px between page sections.
Cards stacked in the content column sit 24px apart. Inside a card the title
sits 14px above its content, a lede 16px above the form it introduces, and a
note 12px below the table it explains. Groups are tight and separations
generous; a heading has more space above it than below.

Below 782px the sidebar collapses into a `<details>` element with a "Menu"
summary, so the page starts at the top on a phone and needs no JavaScript.
Tile and stat grids use `auto-fit, minmax(150px, 1fr)` so nothing strands on
a row of its own. Screens with no sidebar (sign-in, accept an invitation) run a
single column no wider than 760px.

On a phone (a coarse pointer, or any window under 782px) every control a
member can press is at least 44px tall: checkbox and radio rows, filter
chips, breadcrumb and text-shaped links, the wizard's progress steps, and
the title link in a list card, which becomes the whole card's way in. The
top bar is one line, brand and "Back to the website" only, because the
menu below already holds Notifications, the name and Sign out. Tables
become stacked cards (title, then type, status and date on one line, then
a full-width button). The wizard footer stacks with Continue first and
full width. Type never drops below its floor through nested em sizing:
chips 12px, help and counters 14px, stat labels 13px. Confirmed 17
September 2026 across seventeen member-area screens at 390px: no sideways
scroll, no target under 44px other than the WordPress editor's toolbar.

The public pages follow the same phone rules: list and calendar titles are
44px rows, the calendar's month links and every text link are thumb-sized,
and the directory's four filters fold behind one "Filters" button under
560px (open when a filter is set, always shown without JavaScript) so the
search box and the first results share the first screen. Confirmed 17
September 2026 on seven public screens at 390px and 1280px.

## Elevation & Depth

Gently lifted, confirmed 16 September 2026. Cards, tiles and stats carry a
soft ambient shadow at rest so the page reads as papers laid on a desk; the
sidebar and top bar are flush. Fields, tables and chips are flat and rely on
their border. Hover raises the shadow a step rather than changing colour; the
selected stat adds an inset navy line instead of a shadow.

The incumbent code is flat (one 6% shadow on stat hover), so this is the
committed direction for polish, not a description of what ships today.

### Shadow Vocabulary
- **Resting card** (`box-shadow: 0 1px 2px rgb(30 50 50 / 4%), 0 4px 16px rgb(30 50 50 / 6%)`): cards, tiles, stats at rest.
- **Raised card** (`box-shadow: 0 2px 4px rgb(30 50 50 / 5%), 0 10px 28px rgb(30 50 50 / 9%)`): the same surfaces on hover, and the tile being pointed at.

### Named Rules
**The Offset Rule.** Every shadow has a y-offset and a blur. A zero-offset halo
is decoration and does not appear.

## Shapes

Three radii and a pill. Controls (buttons, tabs, sidebar items) are 8px;
fields 10px; cards, tiles, stats and tables 20px; chips and badges a full
pill. Nothing is square-cornered and nothing is a circle except a count badge.

Lines are 1px where they are decoration (card edges, dividers, table rules)
and drawn at 14% ink, faint on purpose. Lines are 1px at 4.9:1 where they are the
boundary of a control (fields, secondary buttons): a hairline, but a dark one. A refined
system is allowed thin decorative lines; it is not allowed a faint control.

## Components

### Buttons
Refined and restrained: a solid navy primary, an outlined secondary, no
shadows, no gradients, no icons unless the label needs one.
- **Shape:** rounded (8px), 45px tall, 15px medium-weight label, padding 0 20px.
- **Primary:** Council Navy fill, white text. Hover: Navy Deep.
- **Secondary:** transparent, 1px border in ink at 68%, navy text. Hover: Desk Grey fill.
- **Danger:** transparent with a 2px Accent Danger border and text; hover fills.
- **Quiet:** 1px border in the current text colour, for actions inside a table row.
- **Focus:** a 2px Council Navy ring with 2px offset on every button.

### Chips
- **Style:** pill, 0.72em, 600, 3px 10px, tinted background, dark text of the same hue.
- **State:** one chip per status; never two colours for one meaning.

### Cards / Containers
- **Corner Style:** 20px.
- **Background:** Paper White on Notice Cream.
- **Shadow Strategy:** resting card; raised on hover where the card is a link.
- **Border:** 1px at 14% ink, kept under the shadow so the edge survives on a bright screen.
- **Internal Padding:** 24px (20px 22px on detail and review cards).

### Inputs / Fields
- **Style:** Paper White, 1px border in ink at 68% (4.9:1), 10px radius, 45px tall, 15px text. Marked `!important` because the theme's element-level rules outrank the class.
- **Focus:** the border and a flush 2px outline both turn Council Navy, reading as one 3px ring. Never a border in one colour and a ring in another.
- **Error:** border Accent Danger; the message below in the same colour at 600.
- **Label:** above the field, uppercase label style in Slate Muted, with a red asterisk for required.
- **Help:** below, 0.8em Slate Muted.

### Navigation
- **Sidebar:** Navy Deep, 24px padding, sections headed in uppercase label style at 80% white. Items 15.8px medium in 92% white, 9px 12px, 8px radius on the right corners only. Hover fills 10% white; the current item fills 14% white, goes bold, and carries a 3px Leeds Teal rail on its left edge. Counts sit right, 600, 80% white.
- **Account block:** at the foot, above a 20% white rule: name in 600, organisation in 80% white, then "Sign out" as an outlined white button 44px tall.
- **Top bar:** white, 1px divider, logo left with a "Member area" label, text links right, the "Back to the website" link in Partnership Purple.
- **Tabs:** text tabs on a 1px rule; the open tab is a filled Council Navy block with white bold text and 8px top corners. `aria-current="page"` on the open one.

### Public list cards
The news and events pages open with one item large and three beside it,
then rows, all built from the same three lines: a topic chip (12px, 700,
0.08em tracking, uppercase, teal text), the title (Council Navy, 1.15rem
in a row, 1.02rem beside the lead, clamp to 2.2rem on the lead in white),
and one meta line in Slate Muted at 0.86em with segments separated by a
slash at 55% (news: organisation, date, reading time; events: when, where,
organisation). Pictures are square and rounded: 140px in a row, 104px
beside the lead, 96px and 84px on a phone. The lead's picture fills its
block under a navy gradient (92% at the foot to 8% at the top), the one
gradient the world allows because the brief asked for it, with a white
"Read more" pill. No picture: a Council Navy tile with one uppercase word
for the type. The whole card is the way in (the title link is stretched
over it); focus draws the card's ring. Filters sit under the lead as one
quiet line on a hairline: an "All news" heading left, small selects and a
40px outlined Show right; under 560px they fold behind "Filters".

### Status stamp
The chip is the system's signature: every list, every detail header and every
email carries the same six words in the same six tints. A member learns them
once.

## Do's and Don'ts

### Do:
- **Do** read every colour from `--theme-palette-color-N` with the live hex as fallback, and define it once, in `assets/tokens.css`.
- **Do** keep control boundaries at 3:1 or better and text at 4.5:1, aiming for 7:1; measure with `bin/check-contrast.mjs`, which now reads `color(srgb)` values.
- **Do** carry state with a word: a chip, a label, `aria-current`, never colour alone.
- **Do** keep one focus treatment, Council Navy, on every interactive element.
- **Do** put 24px between stacked cards and 40px between page sections.

### Don't:
- **Don't** use Leeds Teal for text at any size.
- **Don't** set text smaller than body below weight 500.
- **Don't** declare tokens in `dashboard.css`; the duplicate block there overrode `tokens.css` for weeks.
- **Don't** let a theme rule decide a control's edge; the field border is `!important` for that reason and nothing else is.
- **Don't** add gradients, glass, sparklines or a hero metric; the surface is an office, not a launch page.
