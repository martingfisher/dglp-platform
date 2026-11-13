# Mobile layout, type and colour audit (13 November 2026)

Martin reported words splitting and odd text sizes on phones, and colours that
differ between phone and desktop. This records what was found, the rule agreed
for fixing it, and the per-page list.

## The rule (agreed with Martin, 13 November)

**Blocksy owns the look. Greenshift owns the layout.**

- Blocksy (Customizer): the colour palette, the type scale (body and H1 to H4,
  with desktop, tablet and phone sizes), buttons and page widths.
- Greenshift (blocks): grids, columns, spacing and tile positions only. A block
  picks a heading level and a palette colour; it does not set its own font size
  or a different colour per screen size. Exception: a deliberate one-off, such
  as the home page hero.
- Where a colour differs between screen sizes, the desktop colour is correct.
- No separate CSS. Fixes go into Blocksy and Greenshift settings. Greenshift
  builds its CSS in the block editor when a page is saved and prints it in the
  page as `greenshift-post-css-inline-css`, so a changed block setting takes
  effect only after the page is saved through the editor.

## How it was checked

Every page linked from the header, menus and footer (39 pages), loaded in
headless Chromium at 390px wide with an iPhone user agent, and the home page
also at 800px and 1280px. For each page: words broken across two lines,
Greenshift blocks running off the screen, grid tiles with fixed positions, and
Greenshift headings of 40px or more on a phone. Colour differences were read
from Greenshift's own CSS: any colour or background set inside a tablet
(`max-width: 999.98px`) or phone (`max-width: 689.98px`) rule that differs from
the desktop value, or exists only on tablet and phone.

Not checked: a real device, 320px wide, Android browsers, signed-in screens.

## What is wrong, and why

1. **Support tiles on the home page run off the screen.** The grid is set to
   one column on phones (`gridcolumns [3, null, null, 1]`), and that works. But
   its five tiles each carry a fixed desktop position with no phone value
   (`columnStart [3], columnEnd [4], rowStart [1], rowEnd [2]`), which forces
   extra columns. Tiles 3 and 5 sit at 274 to 474px on a 390px screen; the
   page hides sideways overflow, so they are cut off with no way to see them.
   Fix: in each tile's Grid child settings, give the phone value column 1 to 2
   and rows 1 to 5 in page order.
2. **Headings too big for a phone.** Blocksy's own type scale has no phone
   step for H1 (75px on every screen), H3 (34px) or body (20px); only H2 steps
   down (50px to 35px). Blocksy's base size is 18px, so a Greenshift heading
   set to `3rem` on phones is 54px. 48 Greenshift headings are 40px or more on
   a phone; several (75px page titles) break words across lines. Fix: add
   tablet and phone sizes to Blocksy's type scale, then remove the per-block
   font sizes in Greenshift so Blocksy's apply. Keep the desktop look by
   matching Blocksy's desktop sizes to the approved design first.
3. **Colours that change between desktop and phone.** Some Greenshift blocks
   store a different palette colour for tablet and phone (for example a row
   that is yellow on desktop and orange on phones, a dark teal row that turns
   white, a button with a colour only on phones). Fix: clear the tablet and
   phone colour values so the desktop colour applies everywhere.
4. **Colours outside the Blocksy palette.** Blocksy defines 16 colours, but
   some blocks reference palette colours 17 to 24 (for example
   `palette-color-21`, `-22`, `-23`, `-24`). Those do not exist, so the browser
   falls back to a fixed hex value written into the block. Fix: repoint them
   to the nearest Blocksy palette colour, or add them to the palette if they
   are wanted.
5. **A site-wide row differs slightly by screen size.** Row `a60de25`, on 38
   of the 39 pages (header or footer area), is `#f3f7ff` on desktop and
   `#edf3f3` on tablet and phone.
6. **Content to tidy (not layout):** `young-lives-leeds` shows the text
   `WORDPROCESSINGML` (pasted from Word); `support/workforce-hr-network/`
   returns 404 from a menu link; the home page Training tile links to the old
   `/category/training/` address.

Block IDs repeat across pages (for example heading `687d941` on many pages),
because the pages were built from the same template; each page holds its own
settings for them.

## Per page

| Page | Headings 40px+ on a phone (block, size) | Colours that change on tablet/phone (block) | Split words |
|---|---|---|---|
| `/` | 753d478 45px, b5ff850 54px, 4b70712 54px, 9529049 54px, 44b5e4e 63px, 855094a 54px, dc1d437 54px, e647c12 54px | accordion 96df6b3, row 57257a1, row 5c6a02b | Neighbourhood, Opportunities, Representation, Volunteering, manage |
| `/volunteering/leeds-volunteering-strategy/` | 687d941 40px | button 00c45ee, button 22836c6, button 40e984c | - |
| `/volunteering/corporate-volunteering-social-value/` | 687d941 40px | row 3322e46 | - |
| `/volunteers-of-leeds/` | 687d941 40px | row 96b5086 | - |
| `/volunteer-centre-leeds/` | 687d941 60px | - | - |
| `/volunteering/find-a-volunteer-role/` | 144e3a4 60px | button 99706a1 | - |
| `/become-a-trustee/` | 687d941 42.9px | - | - |
| `/volunteering/advertise-volunteer-roles/` | 687d941 42.9px | button 00c45ee, button 40e984c | - |
| `/support/trustees/` | 687d941 75px | - | Trustees |
| `/volunteering/inclusive-volunteering/` | 687d941 75px | button 5d9e82b, row 96b5086 | Inclusive, Volunteering |
| `/volunteering/leeds-volunteering-quality-mark/` | 687d941 40px | - | - |
| `/support/governance-how-to-start-and-run-your-group/` | 8afd688 40px | - | - |
| `/support/safeguarding/` | 687d941 45px | col 5f01a23 | - |
| `/support/funding/` | 687d941 40px | - | - |
| `/workforce-wellbeing/` | 98a0f3a 45px | - | - |
| `/jobs/` | 98a0f3a 45px | - | - |
| `/networks-and-projects/culturally-diverse-hub/` | 8afd688 40px, 44b5e4e 60px, bca498d 40px | row 4696ff5, row 5c6a02b, row 781f239 | - |
| `/networks-and-projects/health-and-care-leaders/` | 8afd688 75px, 44b5e4e 60px | - | Leaders, Network |
| `/networks-and-projects/learning-disability/` | 687d941 40px | row 3322e46, row 96b5086 | forumcentral |
| `/networks-and-projects/mental-health-network/` | f602e90 50px, 44b5e4e 60px | button 99706a1 | forumcentral |
| `/networks-and-projects/physical-sensory-impairment/` | 687d941 45px, 44b5e4e 60px | row 24ecf9e | - |
| `/networks-and-projects/third-sector-leeds/` | 8afd688 40px | button 5d9e82b | members |
| `/networks-and-projects/young-lives-leeds/` | 8afd688 40px | row 781f239 | WORDPROCESSINGML |
| `/representation/` | 687d941 40px | col 5f01a23 | Representation |
| `/networks-and-projects/local-care-partnerships/` | 98a0f3a 45px | - | - |
| `/signposting-to-support/` | 687d941 40px | button 295cb50, button ec453e9 | - |
| `/resources/state-of-the-sector/` | 687d941 75px | - | - |
| `/about-2/` | 5f1ec11 75px, 44b5e4e 60px, 49ab2e4 60px | - | Membership, Partnership |
| `/volunteering/trustee-recruitment/` | 687d941 40px | - | - |
| `/volunteering/volunteer-managers-network/` | 5f1ec11 75px, 44b5e4e 60px | button 8ed9b61, button bfa55b3 | Managers, Network, Volunteer |
| `/networks-and-forums/age-friendly-leeds/` | 687d941 40px, 44b5e4e 60px | - | - |
| `/resources/state-of-the-leeds-third-sector/` | 687d941 75px | - | - |

## Fixes applied (13 November, afternoon)

All on the live site, through Blocksy's Customizer and the block editor. No
CSS was added anywhere. Checked afterwards at 390px on all 32 addresses above:
no Greenshift block runs off the screen, body text is 18px, and every page
title is 40px except the three listed under "Left alone".

**Blocksy type scale, phone step added (desktop and tablet unchanged):**
H1 75px to 40px, H2 50px to 32px, H3 34px to 26px, H4 22px to 20px, body 20px
to 18px.

Writing these with WP-CLI (`wp option patch update theme_mods_blocksy-child`)
stores them but does not rebuild Blocksy's stylesheet
(`uploads/blocksy/css/global.css`). The sizes only appeared once the
Customizer was published. Change Blocksy settings in the Customizer, or
publish it afterwards.

**Home page (9548):**

- Support grid `0f35bab`: its five children now have phone positions, column
  1 to 2, rows 1 to 5 in page order. Nothing is cut off.
- Tablet and phone colours cleared on rows `57257a1` and `5c6a02b`, the five
  tiles, four buttons and accordion `96df6b3`. The desktop colour now applies
  at every width.
- Font sizes removed from 12 headings. The seven section headings (Support,
  Representation, Volunteering, Networks and Forums, Sector News, Leeds VCSE
  Directory, FAQs) now take Blocksy's H2: 32px on a phone, and **50px on
  desktop where they were 63px**. The four tile headings were H2 at 34px;
  they are now H3, which is 34px on desktop as before and 26px on a phone.

**The other 30 pages** (`/resources/state-of-the-sector/` is the same page as
`/resources/state-of-the-leeds-third-sector/`): font sizes removed from every
Greenshift heading that set one, and every tablet or phone colour that
differed from desktop cleared, including blocks the table above does not
list. Each page was saved in the editor so Greenshift rebuilt its CSS.

Desktop sizes that changed as a result, because the block had its own desktop
size that was not Blocksy's:

| Heading | Was | Now | Pages |
|---|---|---|---|
| Page title (H1) | 60px | 75px | governance, safeguarding, culturally-diverse-hub, physical-sensory-impairment, young-lives-leeds |
| Page title (H1) | 65px | 75px | leeds-volunteering-strategy |
| H2 `b5c6be0` | 65px | 50px | corporate-volunteering-social-value, volunteer-centre-leeds, learning-disability, young-lives-leeds, trustee-recruitment |
| H2 `b79fa63`, `00bc497` | 65 to 70px | 50px | safeguarding, young-lives-leeds |
| H2 `44b5e4e`, `bca498d` | 60px | 50px | culturally-diverse-hub, health-and-care-leaders, mental-health-network, physical-sensory-impairment, about-2, volunteer-managers-network, age-friendly-leeds |

The 75px titles were checked at 1280px wide on seven pages: none overflows.
If 50px is too small for the section headings on desktop, raise Blocksy's H2
desktop size in the Customizer; that changes all of them at once.

**Left alone:**

- The home page hero title (`753d478`, 45px on a phone) and its subtitle: the
  agreed one-off.
- The page titles on `/become-a-trustee/` and
  `/volunteering/advertise-volunteer-roles/` are still 42.9px on a phone. The
  size does not come from the block's font size setting; not traced.
- Font sizes on buttons (20px) and icon lists (24px). They are not headings
  and do not change by screen size.
- Items 4, 5 and 6 above: palette colours 17 to 24, row `a60de25`, and the
  content tidy-ups.

Every page has a WordPress revision from before these edits.

## Mobile header and menu (13 November, later)

Martin reported that the phone menu had no route to the top menu (News,
Events, Training, Jobs), that its logo was missing, and that the header bar
was too deep. All three were settings in Blocksy's header builder.

The live header is the custom one named "DGL Partnership"
(`ct-custom-NWQeb6Oy8c1stUf33NoG4`), not the default `type-1` that the
Customizer opens on. Editing `type-1` changes nothing on the site. Both were
given the same changes so they stay in step.

- **Logo in the menu panel:** the off-canvas logo's phone image pointed at
  attachment 3321, which no longer exists. Now the header logo (7156), 64px
  high.
- **Top menu:** a "Mobile Menu 2" element showing menu 23 (News, Events,
  Training, Jobs) added to the panel under the main menu, styled the same.
- **Bar depth:** phone logo height 105px to 56px and phone row height 90px to
  70px. The bar is 82px deep where it was 131px. Tablet and desktop values
  are unchanged; the desktop rows measure 82px and 137px as before.
- **Order at the bottom of the panel:** Sign in and Register now come before
  the three partner buttons (Forum Central, VAL, LOPF).

Tidied afterwards, on Martin's go-ahead:

- **One News:** the News group (All News, Events, Training) was removed from
  the phone menu, "Mobile Menu" (172). News, Events, Training and Jobs are
  reached from the top menu below it.
- **About:** added as the first item of the phone menu, linking to
  `/about-2/`. The phone menu now reads About, Volunteering, Support,
  Networks and Forums, Resources, Contact, the same as desktop.
- **Partner buttons:** a text element reading "Our partners' websites" sits
  above Forum Central, VAL and LOPF.

Not changed:

- The three partner buttons are still different widths and colours.
- "Sign in" is a text link 23px high, a small target next to 42px buttons.
