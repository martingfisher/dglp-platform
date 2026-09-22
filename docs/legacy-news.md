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

## The command

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
