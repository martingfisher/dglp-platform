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
