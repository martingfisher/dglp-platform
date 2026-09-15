# Pending edits

An edit to published content never touches the published content.

Editing a live item opens a `dgl_revision` post hanging off it. The member works
on that. It reaches the public only when a moderator approves it, at which point
its fields are written on to the live item and the edit is archived as a record.

This is the answer to the attack the whole review workflow exists for: submit
something clean, wait for approval, then edit inappropriate text into it. The
inappropriate text sits in the queue, not on the site.

## What the member sees

The live version stays up the whole time. A member fixing a typo does not take
their own event off the site for three days, and nothing is gained by making
them, because the public never sees the pending edit either way.

Every screen that mentions an edit says the published version is unchanged. That
is the thing members worry about, and it is worth repeating in each place rather
than assuming they read it once.

| State | What happens |
|---|---|
| Draft or changes requested | Edited in place. Nothing of it is on the site |
| Live or expired | Opens an edit. The published version is untouched |
| Pending | Locked. What the team approve has to be what they read |

**One edit per item, ever.** Opening an edit a second time reopens the one that
exists. Two pending edits to the same item is a question with no good answer:
whichever a moderator approves second silently discards the other's work.

**An edit that changes nothing is not sendable.** Walking through the wizard and
pressing to the end should not cost a moderator a review, and should not lock the
member's own content while they wait for a decision about nothing.

**Discarding is the member's to do**, up until they send it. After that it is
frozen. A moderator refusing an edit rejects it instead, so the refusal leaves a
record.

## What the moderator sees

The difference, first, above everything else. Reading a form twice and spotting
the one altered sentence is proofreading, not review, and at volume nobody does
it reliably.

The comparison ignores the visual editor's own noise — reflowed whitespace and
line endings — but keeps markup, because a description losing its links or its
bold is a real change.

Approving writes the edit on to the live item. `approved_at` is deliberately not
restamped: it drives the digest, and an edit is not a reason to email people
again about something they have already seen. The expiry date **is** recomputed,
because an edit can move an event's end date.

## Trust

`Trust::TRUSTED_EDITS` (level 1) exists for exactly this. New work is still read
first; edits go straight on to the site. `StateMachine::next()` takes an
`$is_edit` flag so the two are separate permissions rather than one.

## Where edits live in the index

Edits are rows in `dgl_items` alongside the items they would replace, so the
moderation queue stays one indexed query instead of a union of two.

Everywhere else an edit is **not** a thing in its own right, and
`ItemsTable::content_only()` filters them out: a member's list, their dashboard
counts, the digest and — most importantly — the expiry sweep. A revision that
reached the expiry sweep would be "expired" while the item it belonged to carried
on, which is a state nothing else in the system understands.

## Two traps worth knowing about

**`post_status => 'any'` does not mean any.** WP_Query drops statuses registered
with `exclude_from_search`, which is most of this plugin's. Querying revisions
with `'any'` found drafts and pending ones but not archived or rejected ones, so
an item's history went blank the moment its edit was approved. Every revision
query names its statuses.

**Meta is written after the post.** An image control posts a hidden `0` when
nothing is attached, and a stored `0` reads back as an answer. "Image: Not given"
was being reported as changing to "Image: Not given". The wizard now deletes the
row instead.
