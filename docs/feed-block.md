# Topic feed block

One block, two choices: what kind of content (news, events, training) and
which topic. It goes on any page, as many times as the page wants.

```
[dgl_feed type="news" topic="children-and-young-people"]
[dgl_feed type="event" topic="older-people" count="8" heading="Coming up" link="no"]
```

In the block editor it is **Topic feed** (Widgets), with the same options in
the sidebar. Code: `src/Frontend/Feed.php` (what it shows),
`src/Frontend/FeedBlock.php` (the editor block and the pictures screen),
`templates/public/home/feed.php` (the markup), `assets/public.css` (`.dgl-feed*`).

## What it does

- Four across on a wide column, two in a medium one, one in a narrow one.
  It answers to the column it sits in, not the window.
- Events and training: soonest first. News: newest first. Featured items lead.
- A topic with no items shows the newest of that type instead, under the
  type's plain heading, with an "All news" link to the whole list rather than
  to the topic.
- An item without a picture, or whose picture file is gone, uses the picture
  chosen for its type under Settings, Topic feed pictures (News, Events,
  Training; venues and spaces have none). None chosen: the plain coloured tile.
  The fallback lives in `Cards::picture()` / `Cards::stand_in()`, so every list
  surface gets it, not just the feed.
- Several blocks on one page: every block takes its own id
  (`dgl-feed-1`, `dgl-feed-2`, ...) from `wp_unique_id()`, and so does its
  JSON-LD `@id`.

## Dates

Events and training cards carry the calendar badge on the picture's top left
corner and the date sentence under the title ("Running now, until Thu 22 Oct
2026"). News cards show when the story was posted. The wording is decided in
`src/Frontend/DateSpan.php` (pure, tested in `tests/test-datespan.php`) and
shared with the listing pages through `Cards::when_line()` and
`Cards::date_block()`.

## For search engines

Server-rendered HTML, no script needed to read it. Each item is an
`<article>` with an `<h3>` that links to it, a `<time datetime>` and the
organisation. Each block also prints an `ItemList` (JSON-LD, one per block)
naming every item and its URL. The picture is decoration beside the title
link, so it has an empty `alt` and is hidden from assistive technology.

## Which topic goes on which page

From DGLP's sheet of pages that take a feed. Where the sheet's name is not
a topic, this is the closest one. The first six are exact or near-exact; the
rest are judgement calls the team should confirm.

| Page on the sheet | Topic | Confidence |
|---|---|---|
| Young Lives Leeds | Children and Young People | Judgement: the topic has few items today, so the block backfills |
| Cultural Diverse Hub | Cultural Diverse Communities | Clear |
| Funding Support and Network | Grants and Funding | Clear |
| Health and Care Leaders Network | Health and Social Care | Clear |
| Learning Disability | Learning Disability | Exact |
| Local Care Partnerships | Local, Place-based (LCAN, LCP, NN) | Clear (the old site mapped it there) |
| Mental Health | Mental Health | Exact |
| Age Friendly Leeds | Older People | Judgement |
| Physical and Sensory Impairments (PSI) | Physical and Sensory Impairments (PSI) | Exact |
| Representatives | Reps | Clear |
| Support parent page | Support for VCSE Organisations | Judgement |
| Volunteering parent page or Vol Centre Leeds | Volunteering | Clear |
| Workforce Wellbeing | Not mapped | The block takes one topic and the sheet could mean Workforce or Wellbeing. Both have no items yet |

The sheet also has blank rows, so some pages are unnamed there. They take a
topic once DGLP says which.
