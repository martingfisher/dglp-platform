# Privacy tools

The plugin registers with WordPress's own privacy tools, so a subject access
request or an erasure request is answered from Tools > Export Personal Data
and Tools > Erase Personal Data, by email address, alongside core's data.

## What is exported

Grouped as the export screen shows it:

- **Membership**: organisation, role, account status.
- **Email digest preferences**: types, topics, frequency, the date consent
  was given, the last digest sent.
- **Invitations** addressed to the email: organisation, role offered, dates.
  Invitations the person sent are the invitee's data and are not included.
- **Joining requests** for the email: domain, state, the organisation named
  or described, the decision note and dates.
- **Activity**: the audit rows where this person was the actor. Date,
  action and what it was about. Not the IP hash, not the field diff.
- **Listings you wrote**: title, type, status, date. Not the content, which
  belongs to the organisation.

No token, hash or password ever leaves the database.

## What erasure does

Removed: digest subscription, invitations to the address, joining requests
for the address, the organisation link and account status, every session.
The index stops naming them as author.

Anonymised: audit rows lose the actor and the IP hash and keep the event.
The erase screen says so.

Retained: listings, which stay under the organisation. The erase screen says
so. Delete the WordPress account afterwards if that is what was asked for;
core's tools expect the administrator to take that step.

## Policy text

Settings > Privacy > Policy Guide carries a suggested paragraph about the
member area's data, generated from what the plugin actually stores.

## Checking it

```
wp dgl privacy export someone@example.org
```

prints the same data the export screen would produce. The integration suite
covers registration with core, every export group, erasure of each store and
the two retained items.
