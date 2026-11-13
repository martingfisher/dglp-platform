# Page SEO settings (13 November 2026)

Rank Math went live on 13 November with nothing filled in per page, so every
page showed "no focus keyword" errors. Each of the 42 published WordPress
pages now has a focus keyword, an SEO title and a meta description. They are
in `page-meta-2026-11-13.json` in this folder, keyed by page id, and were
saved through Rank Math's own `rankmath/v1/updateMeta` endpoint (42 of 42
accepted). The privacy policy and terms of use have a description only.

- **Keywords** were chosen from what each page is about, checked against UK
  search volumes where there was a choice: "volunteering in Leeds" and
  "volunteer opportunities Leeds" about 1,600 searches a month, "charity
  jobs Leeds" 1,300, "volunteer centre Leeds" 170, "become a trustee" 70.
  Most network and strategy pages use their own name, which is what people
  search for.
- **Titles** are 60 characters or fewer and contain the keyword;
  **descriptions** are 160 or fewer, contain the keyword, and say only what
  the page's own headings show is on it.
- **Checked live** on six pages from outside: the new title and description
  are printed.

Not changed: the words on the pages. Rank Math's remaining checks on each
page (keyword in the first paragraph, in a subheading, in image alt text,
links out and in) need the page's own copy edited, which is for the team.
Not covered: news, events, training, directory and spaces pages, whose
titles and descriptions the platform writes itself.

## Fixes after the technical SEO audit (13 November, evening)

- **Site title** (Settings, General) changed from "DGL.org.uk" to "Doing Good
  Leeds", and the tagline to "Support for the third sector in Leeds". The
  platform's own pages take their title suffix from it, so news, events,
  training and directory pages now read "News – Doing Good Leeds" like the
  rest. It is also the name in the member area and in emails that use the
  site name.
- **About page** moved from `/about-2/` to `/about/`. A forgotten draft page
  called "About" (8132) held the `about` slug; it is now `about-draft`.
  `/about-2/` and the old site's `/about-doing-good-leeds/` both redirect to
  `/about/` through the Cloudflare list.
- **Redirects:** the old grant-funding address goes straight to
  `/support/funding/` (it used to hop through `/funding/`), and the `/funding`
  short link is a 301 where it was a 302.
- **Broken tile on `/support/`:** the Training Opportunities tile linked to
  `train`, which went nowhere. Its link in the page now points at
  `/training/`. This was changed in the stored page HTML; the block's own
  setting still says `train`, so open the page in the editor, set the tile's
  link to `/training/` and save, or the editor may ask to recover the block.
- **Not fixed:** the "Volunteering England" link on `/volunteer-centre-leeds/`
  points at `volunteering.org.uk`, which answers with a server error on both
  http and https. It needs a new destination or removing.
- **Not done:** removing the old `/sitemap.xml` from Search Console. The
  browser connection dropped at the confirmation step; check the Sitemaps
  list and remove it by hand if it is still there.

## Agent readiness (Cloudflare, Agent Readiness)

Scanned with the same checker Cloudflare links to (isitagentready.com).

- **Before:** level 1, "Basic Web Presence". **After:** level 2, "Bot-Aware".
- **What changed:** `robots.txt` now carries
  `Content-Signal: search=yes, ai-input=yes, ai-train=no`. It is set in Rank
  Math, General Settings, Edit robots.txt, and that text now also holds the
  `/dashboard/` rule. The three values are a policy choice made on the
  partnership's behalf: search engines may index, AI assistants may use the
  pages to answer questions, and the content is not offered for training
  models. Change the line there if the partnership decides otherwise.
- **Next level needs Markdown for Agents**, which Cloudflare refused for this
  zone: "not entitled to use the config rule content_converter". The zone is
  on the free plan.
- **Left failing on purpose:** API catalogue, `auth.md`, OAuth protected
  resource, MCP server card, A2A agent card, agent skills, WebMCP and the
  rest. They describe an API or a service an agent can sign in to and act
  through. This site publishes pages for people; it has no public API to
  describe, and publishing those files would claim something that is not
  there.

## Page speed check (13 November, later)

- **Fonts are already local.** Blocksy's local Google Fonts are on: the 12
  `@font-face` rules point at `uploads/blocksy/local-google-fonts/` and
  nothing is requested from `fonts.googleapis.com` or `fonts.gstatic.com`.
  The files are served through the site's CDN host
  (`cdn-partnership-doinggoodleeds-org-uk-ysfb.b-cdn.net`), which is why a
  checker can report them as coming from another domain.
- **Broken Link Notifier was running in every visitor's browser.** Its
  front-end stylesheet and script loaded on every public page and checked
  the page's links as people browsed. That is now paused
  (`blnotifier_pause_frontend_scanning` = 1; in wp-admin, Broken Links,
  Settings, "Pause Front-End Scanning"). Checked on the home page
  afterwards: no reference to the plugin's files remains, and the stylesheet
  count went from 13 to 12. Scans run from wp-admin still work; the emails
  that were triggered by visitors' page loads will stop.
- **Still heavy, not changed:** the home page's HTML is about 365KB, of
  which about 196KB is CSS that Greenshift and the theme print inline, and
  jQuery loads in the head. Both need a decision in Greenshift's and
  Blocksy's own performance settings and a visual check afterwards.
