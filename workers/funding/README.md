# dgl-funding

Weekly Cloudflare Worker that reads UK funding pages and posts the result to the
Doing Good Leeds site. Architecture and the JSON it posts: [`docs/funding.md`](../../docs/funding.md).
Categories come from [`src/Funding/vocab.json`](../../src/Funding/vocab.json), imported at build time.

- Cron `0 4 * * 1` starts the Workflow `dgl-funding-run`: batches of 10 sources per step
  (TinyFish Fetch, then Claude Haiku for pages that changed; Sonnet only on a failed answer),
  then one POST to `DGL_SYNC_URL`, then KV state written (`src:<id>`) only after the POST succeeded.
- Sources: [`sources.json`](sources.json) (`[{id, name, url, funder?, notes?}]`), notes in [`SOURCES.md`](SOURCES.md).
- Live: https://dgl-funding.martingfisher.workers.dev

## Endpoints

`GET /` returns `ok`. The rest are `POST` with `Authorization: Bearer <ADMIN_KEY>` (plain Worker secret):

- `/run` starts the workflow now (`?ids=a,b` for some sources). It POSTs to the site, so it needs the token below.
- `/dry-run?ids=a,b` reads up to 10 sources and returns what would be posted. Posts nothing, writes no KV.
- `/check?offset=0` fetch-only health check of the sources (40 per call, no model calls).

## Secrets

Secrets Store `77bc305d0352491093bff8533d866287`: `tinyfish`, `anthropic_api_key` (both bound).
**`dgl_funding_token` is not bound yet** because it does not exist (a binding to a missing secret fails the deploy).
Once `wp dgl funding token` has printed the token, create it:

```
npx wrangler@4 secrets-store secret create 77bc305d0352491093bff8533d866287 --name dgl_funding_token --scopes workers --remote
```

then add `{ "binding": "DGL_TOKEN", "store_id": "77bc305d0352491093bff8533d866287", "secret_name": "dgl_funding_token" }`
to `secrets_store_secrets` in `wrangler.jsonc` and `npx wrangler@4 deploy`. Until then a run
reads everything but fails at the POST step and leaves KV untouched.

## Develop

```
corepack pnpm install
corepack pnpm exec tsc --noEmit
npx wrangler@4 deploy
```

Do not set `ANTHROPIC_BASE_URL` for this Worker: the SDK client pins `https://api.anthropic.com`.
If `vocab.json` or the topic list in `src/Topics/Topics.php` changes, redeploy (topics are copied into `src/vocab.ts`).
