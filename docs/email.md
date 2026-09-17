# Transactional email

Eleven messages, one per thing the workflow can do to a submission. They hang
off `dgl_item_transitioned`, so a mail failure can never undo a decision a
moderator has already made.

| Message | Goes to | Sent when |
|---|---|---|
| `submitted` | member + review team | A member sends something for review. The member's copy is a receipt |
| `published_on_trust` | member + review team | A trusted organisation's item goes live unreviewed |
| `approved` | member | A moderator approves |
| `changes_requested` | member | A moderator asks for a change. Reason is mandatory |
| `rejected` | member | A moderator refuses. Reason is mandatory |
| `taken_down` | member + review team | Live content pulled back to the queue |
| `expired` | member | The system takes it off the listings on its date |
| `archived_by_team` | member | DGLP archive somebody's item |
| `restored` | review team | A member pulls an item back out of their archive |

**Member** means the author plus the organisation's owners. The organisation
publishes, not the individual, so an owner is copied even when a colleague
submitted. **Review team** means everybody with `moderate_dgl_items`, minus
whoever just acted: nobody needs an email about their own decision. Suspended
and closed accounts are never written to.

## Settings

| Setting | Constant | Option | Default |
|---|---|---|---|
| Send at all | `DGL_MAIL_ENABLED` | `dgl_mail_enabled` | **off** |
| Divert all mail | `DGL_MAIL_REDIRECT` | `dgl_mail_redirect` | none |
| From address | — | `dgl_mail_from` | WordPress default |
| From name | — | `dgl_mail_from_name` | Site name |

Sending is off until somebody turns it on. Mail that goes out before anyone has
decided what it sends from is mail that trains a spam filter against the domain.

### The staging rule

Staging is a clone of production, which means it is a clone of production's
user table, with every real member address in it. One approval on staging and a
live organisation is told their event is on a site that does not exist.

So **staging must have a redirect set**, and it should be the constant in
`wp-config.php`, not the option:

```php
define( 'DGL_MAIL_REDIRECT', 'someone@resultsyoucanmeasure.com' );
```

The constant wins over the option deliberately. A database pull from production
overwrites options, which is exactly how a staging site ends up mailing real
members: somebody refreshes staging from live and the row that was protecting
everybody comes across with it. A constant in `wp-config.php` survives that.

With a redirect in force every message goes to that one address and the
intended recipients are written into the subject: `[DIVERTED: jo@charity.org]
Approved: Coffee morning`. Twenty diverted emails in one inbox are otherwise
indistinguishable.

## Checking it

```
wp dgl mail status                          # what this site is configured to do
wp dgl mail list                            # every message and its subject line
wp dgl mail preview --dir=/tmp/dgl-email    # render all eleven to HTML and text
wp dgl mail send you@example.com --key=approved
```

`preview` and `send` use made-up content, so neither can leak a real member's
submission into a test. `send` goes through the site's real mail path, which is
the only way to find out whether SMTP2GO, SPF and DKIM actually work.

## Why it is built the way it is

**Tables and inline styles.** Outlook renders through Word and Gmail strips
`<style>` blocks on forwarded mail. This is the only approach that renders the
same everywhere. No web fonts, no background images, no SVG, no `var()`.

**Raster logo only.** `Logo::email_url()` walks the configured logos and takes
the first PNG, JPEG or GIF. Gmail drops SVG, so an SVG logo is an invisible one.

**A plain-text alternative on every message.** Generated from the same message
object, not written twice. It carries the same words, facts, note and link,
because somebody reading in plain text is reading the whole message.

**One thing to click.** Never two.

**No unsubscribe link.** These are transactional, not marketing. A link that
does nothing is worse than saying plainly what the email is. Digest preferences
are separate and live in the dashboard.

## Organisation change decisions

Added 17 September 2026. When the review team accepts or refuses a name or
logo change, every approved owner of the organisation is emailed:
`org_change_approved` ("Your new organisation name is live") or
`org_change_rejected`, which carries the team's note word for word under
"From the review team". Contributors and pending or suspended accounts are
not emailed. Both go through `Mailer::send()` like everything else, so the
staging redirect applies. Sent from `Profile::approve_pending()` and
`Profile::reject_pending()`, which the front-end review screen and the
wp-admin Organisations box both call.
