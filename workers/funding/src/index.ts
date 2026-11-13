import { chunk, BATCH_SIZE, dedupeAcrossSources, processBatch } from './pipeline';
import { fetchBatch } from './fetch';
import { DEFAULT_SYNC_URL, FundingWorkflow, SOURCES } from './workflow';
import type { Env } from './types';

export { FundingWorkflow };

function json(data: unknown, status = 200): Response {
	return new Response(JSON.stringify(data, null, 1), { status, headers: { 'content-type': 'application/json; charset=utf-8' } });
}

function authorised(req: Request, env: Env): boolean {
	const h = req.headers.get('authorization') ?? '';
	const want = `Bearer ${env.ADMIN_KEY ?? ''}`;
	if (!env.ADMIN_KEY || h.length !== want.length) return false;
	let diff = 0;
	for (let i = 0; i < want.length; i++) diff |= h.charCodeAt(i) ^ want.charCodeAt(i);
	return diff === 0;
}

function pick(ids: string | null) {
	if (!ids) return null;
	const want = ids.split(',').map((s) => s.trim()).filter(Boolean);
	return SOURCES.filter((s) => want.includes(s.id));
}

export default {
	async fetch(req: Request, env: Env): Promise<Response> {
		const url = new URL(req.url);
		if (req.method === 'GET' && url.pathname === '/') return new Response('ok');
		if (req.method !== 'POST') return new Response('Not found', { status: 404 });
		if (!authorised(req, env)) return new Response('Unauthorised', { status: 401 });

		if (url.pathname === '/run') {
			const ids = url.searchParams.get('ids');
			const params = { ids: ids ? ids.split(',').filter(Boolean) : undefined };
			const inst = await env.FUNDING_WORKFLOW.create({ params });
			return json({ started: true, instance: inst.id, sources: params.ids?.length ?? SOURCES.length });
		}

		if (url.pathname === '/dry-run') {
			const chosen = (pick(url.searchParams.get('ids')) ?? SOURCES).slice(0, BATCH_SIZE);
			if (!chosen.length) return json({ error: 'no matching source ids' }, 400);
			const out = await processBatch(env, chosen, false);
			return json({
				run_id: new Date().toISOString().replace(/\.\d+Z$/, 'Z') + ' (dry run, not posted)',
				sync_url: env.DGL_SYNC_URL || DEFAULT_SYNC_URL,
				usage: out.usage,
				diagnostics: out.results.map((r) => ({ id: r.id, page_kind: r.page_kind, model: r.model, note: r.note })),
				sources: dedupeAcrossSources(out.results).map((r) => ({ id: r.id, name: r.name, url: r.url, ok: r.ok, error: r.error, grants: r.grants })),
			});
		}

		if (url.pathname === '/page') {
			// Debug: the markdown and links TinyFish returns for one source.
			const one = pick(url.searchParams.get('id'))?.[0];
			if (!one) return json({ error: 'unknown id' }, 400);
			const { pages, errors } = await fetchBatch(env, [one]);
			const p = pages.get(one.url);
			return p ? json(p) : json({ error: errors.get(one.url) }, 502);
		}

		if (url.pathname === '/check') {
			// Fetch-only health check of sources (no model calls): ?ids=a,b or all. Add &offset=0 to page through.
			const all = pick(url.searchParams.get('ids')) ?? SOURCES;
			const offset = Number(url.searchParams.get('offset') ?? 0);
			const slice = all.slice(offset, offset + 40);
			const rows: unknown[] = [];
			for (const b of chunk(slice, BATCH_SIZE)) {
				const { pages, errors } = await fetchBatch(env, b);
				for (const s of b) {
					const p = pages.get(s.url);
					rows.push(
						p
							? { id: s.id, ok: true, final_url: p.final_url, title: p.title.slice(0, 80), chars: p.text.length, links: p.links.length }
							: { id: s.id, ok: false, error: errors.get(s.url) },
					);
				}
			}
			return json({ total: all.length, offset, rows });
		}

		return new Response('Not found', { status: 404 });
	},

	async scheduled(_event: ScheduledController, env: Env): Promise<void> {
		await env.FUNDING_WORKFLOW.create({ params: {} });
	},
} satisfies ExportedHandler<Env>;
