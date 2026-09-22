# Topics

One topic list for every content type: news, events, training, grants and
volunteering. It is the list members pick from in the wizard, the public
lists filter by (`?topic=<slug>`), and digest preferences match on.

## Where the list is

`src/Topics/Topics.php`. Twenty-eight topics, in DGLP's order, each with a
fixed slug. Slugs are public addresses, so they are set in code rather than
derived from the name at run time, and a change of wording never moves a
link.

DGLP settled the list on 22 September 2026 from a three-column spreadsheet:
the new topics, which of the old site's categories each absorbs, and which old
categories go. All three columns are in the class, so the decision is on the
record and testable.

## How it reaches the site

On every page load the plugin compares `dgl_topics_version` with
`Topics::LIST_VERSION`. When they differ it runs the sync: a listed slug that
is missing is created, a listed slug whose name differs is renamed, anything
else is left alone. The version is stamped only after a run with no errors,
so a failed run tries again next load rather than marking itself done.

So a deploy that changes the list is enough. Nobody has to open wp-admin or
run a command. To change the list: edit the array, bump `LIST_VERSION`, add a
line to the test, deploy.

The sync never deletes a term. A topic the review team added by hand in
wp-admin (say, Safeguarding for training) survives every sync and is reported
as "not on the list" by the command below.

## The command

```
wp dgl topics list      # every topic, present or missing, with its item count
wp dgl topics sync      # apply the list now, and say what changed
wp dgl topics legacy    # what the decision means for the old categories; reports only
```

## The old site's categories

The old site's posts carry core WordPress categories (`category`), not topics.
The plugin does not touch them: they belong to the legacy posts, and what
happens to those posts is a separate decision.

DGLP's mapping, as recorded:

| Old category | Becomes |
|---|---|
| communities-of-interest | Communities of Interest |
| harnessing-the-power-of-communities | Community Power |
| inclusion | Equality, Diversity and Inclusion |
| featured, featured-2 | Featured |
| funding | Grants and Funding |
| have-your-say | Have your Say |
| forumcentral, health-care | Health and Social Care |
| learning-disability | Learning Disability |
| local-care-partnerships | Local, Place-based |
| mens-health | Men's Health |
| mental-health | Mental Health |
| older-people | Older People |
| psi | Physical and Sensory Impairments |
| training-mailchimp | Professional Development |
| reps | Reps |
| cost-of-living | Support for VCSE Organisations |

To remove: blog, events-2, jobs-2, mailchimp, events, jobs, member-updates,
news-mailchimp, news.

On staging on 22 September those categories carried between 0 and 310 posts
each (`wp term list category`). Merging or deleting them is a change to the
legacy content and is a WP-CLI job on the site, not plugin code.

Done on staging, 22 September, by Martin's decision: the 83 draft posts
deleted (newsletter items never published, half of them duplicates of
published posts, eight dated 1970), and the MailChimp category (term 51)
deleted: it was a flag for an email feed that no longer exists. Its eight
child categories moved to the top level. Permalinks are
`/%category%/%postname%/`, so the 94 published posts whose first category
was MailChimp now have a different address; WordPress's 404 guess redirects
the old one by post name. Not yet done on production.
