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

Secrets Store `77bc305d0352491093bff8533d866287`: `tinyfish`, `anthropic_api_key` and `dgl_funding_token`,
all bound. The site keeps only the SHA-256 hash of `dgl_funding_token` (option `dgl_funding_token_hash`),
set on 13 November 2026.

To rotate the token without it passing through a terminal or a transcript:

```
umask 077; openssl rand -hex 32 | tr -d '\n' > token
shasum -a 256 token          # the hash, for: wp option update dgl_funding_token_hash <hash> --autoload=no
cat token | npx wrangler@4 secrets-store secret update 77bc305d0352491093bff8533d866287 --secret-id <id> --remote
rm token
```

(`wp dgl funding token` also works, but prints the token.)

## Develop

```
corepack pnpm install
corepack pnpm exec tsc --noEmit
npx wrangler@4 deploy
```

Do not set `ANTHROPIC_BASE_URL` for this Worker: the SDK client pins `https://api.anthropic.com`.
If `vocab.json` or the topic list in `src/Topics/Topics.php` changes, redeploy (topics are copied into `src/vocab.ts`).
