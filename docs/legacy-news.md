# The old site's stories

The old site kept its news as ordinary WordPress posts: 527 published on
staging on 22 September 2026, filed under core categories, each with a
featured image, by two authors. The platform keeps news as `dgl_news` items
that belong to an organisation, carry topics, and sit in the index that the
lists and the review queue read. This is how one becomes the other.

## In place, not copied

`wp dgl news import` converts each published post where it stands. The post
keeps its id, its slug, its dates, its author, its picture and its words. It
gains:

- the news item type, so it appears on /news/ in date order with everything
  else;
- an owning organisation (`dgl_org`), from the `--org` and `--owner` options;
- topics, from its old categories through the mapping DGLP settled on 22
  September (`Topics::legacy()`); categories on the retired list give nothing;
- a listing summary (`dgl_summary`): the excerpt if the editor wrote one,
  else the first forty words;
- the picture under the schema's key (`dgl_image`), pointing at the same
  attachment;
- submitted and approved stamps equal to its publish date, so reports and
  the index treat it as approved then;
- three marks for the record: `dgl_legacy_post` = 1, the old categories as
  slugs in `dgl_legacy_categories`, and the old address in `dgl_legacy_url`.

The old category relationships are removed (the record of them is in meta).
An audit row `imported` names the old address. Drafts, private posts and
anything already converted are skipped and reported.

Because nothing is copied, running the command twice cannot make duplicates:
the second run finds no posts of the old kind.

## Old addresses

The old permalinks were `/category/story-name/`; a converted story is at
`/news/story-name/`. WordPress's own 404 guess usually finds it by name, but
that guess is filterable and some plugins switch it off, so the plugin adds an
explicit one: a 404 whose last path segment names a converted, live story is
sent to the story with a 301 (`LegacyRedirect`). Anything else stays a 404.

## The commands

```
wp dgl news import --org=<id> --dry-run
wp dgl news import --org=<id> --owner=forumcentral:<id>,val:<id>
wp dgl news import --org=<id> --limit=20
```

`--org` is the owner of every story not claimed by `--owner`. `--owner` is
old category slug to organisation id; a story's first category with an owner
there wins. The report says how many converted, how many had no topic and no
picture, and the count per owner and per topic. On the Wordify console the
`wp dgl` namespace needs `safe_mode=false`.

## What the old stories do not get

- No 90-day spell: news has had no automatic end since 0.22.0.
- No member: the author stays as the WordPress user; the owning organisation
  is whoever the options said. Members of that organisation can edit the
  story from the dashboard like any other.
- No review: they were published on the old site, so they are live.

## Done on staging, 22 September 2026

Three organisations were created for the purpose, approved and kept out of
the directory: Forum Central (8802), Voluntary Action Leeds (8803) and
Leeds Older People's Forum (8804). A backup was taken first. Then:

```
wp dgl news import --org=8802 --owner=forumcentral:8802,val:8803,lopf:8804
```

527 converted, none skipped: Forum Central 435, Voluntary Action Leeds 92
(nothing was filed under LOPF). 71 carry no topic because their only
categories were retired ones (news, blog, events); the review team can add
topics from the item's edit screen. One has no picture. /news/ went from 2
live stories to 529. Where the mapping put a story under the wrong name, the
review screen's "Move it to another organisation" control corrects it.

## The old event announcements

The old site had no events plugin. It announced events as posts under two
Events categories with the date in the title ("3 July | Leeds Petitions
Community event"). 66 came over in the import, all past, and sat on /news/
with no topic. On Martin's decision they were archived:

```
wp dgl news archive-legacy --actor=<user-id> --dry-run
wp dgl news archive-legacy --actor=<user-id>
```

It finds converted stories whose old categories (kept in
`dgl_legacy_categories`) include `events` or `events-2` (`--categories`
changes the list) and archives each through the ordinary transition, so the
audit rows carry the actor and the index and dashboards follow. Archived
items can be restored from the review team's Decided screen like any other.

## Stories with no topic

`wp dgl news topicless` lists every live news item with no topic, with the
old categories it carried, and a count by category set. A story with no
topic shows "News item" where the chip would be, and no topic filter finds
it. The team gives it one from the item's edit screen (step 3, Topics), from
the Topics box in wp-admin, or by asking the owning organisation to.

## Suggesting topics from the words

`wp dgl news suggest-topics` reads each topic-less story's headline and
summary against a list of phrases per topic (`src/News/TopicSuggest.php`:
"grant", "fund" and "crowdfunder" mean Grants and Funding, "survey" and
"consultation" mean Have your Say, and so on; whole words, case blind). It
prints what it would file where and changes nothing. `--apply --actor=<id>`
sets the suggested topics on the items that still have none and writes a
`topics_suggested` audit row on each, worded "worth a check", so the review
team can see which topics were guessed rather than chosen. A story that
matches no phrase is listed as "no suggestion" and left for a person.
