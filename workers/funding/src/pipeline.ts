import { extract } from './extract';
import { fetchBatch, sha256 } from './fetch';
import { addUsage, emptyUsage, type BatchOutput, type Grant, type Env, type Source, type SourceResult, type StoredState } from './types';
import { slug, stillOpen } from './validate';

export const BATCH_SIZE = 10;

export async function readState(env: Env, id: string): Promise<StoredState | null> {
	const v = await env.STATE.get<StoredState>('src:' + id, 'json');
	return v && typeof v === 'object' && Array.isArray(v.grants) ? v : null;
}

/**
 * Fetch and read one batch of up to 10 sources. With `useState` an unchanged page
 * reuses last week's grants and makes no model call.
 */
export async function processBatch(env: Env, sources: Source[], useState: boolean): Promise<BatchOutput> {
	const { pages, errors } = await fetchBatch(env, sources);
	let usage = emptyUsage();
	const results = await Promise.all(
		sources.map(async (s): Promise<SourceResult> => {
			const base = { id: s.id, name: s.name, url: s.url, listing: s.notes?.startsWith('Listing') ?? false };
			const page = pages.get(s.url);
			if (!page) return { ...base, ok: false, error: errors.get(s.url) ?? 'no result', grants: [], changed: false };
			const text = page.text.trim();
			if (text.length < 200) return { ...base, ok: false, error: 'page had no readable text', grants: [], changed: false };
			const hash = await sha256(text);
			const stored = await readState(env, s.id);
			if (useState && stored && stored.hash === hash) {
				return { ...base, ok: true, error: '', grants: stillOpen(stored.grants), hash, changed: false, note: 'unchanged' };
			}
			const ex = await extract(
				env,
				s,
				{ ...page, text },
				(stored?.grants ?? []).map((g) => ({ key: g.key, title: g.title })),
			);
			usage = addUsage(usage, ex.usage);
			const note = ex.problems.length ? ex.problems.slice(0, 6).join('; ') : undefined;
			if (ex.page_kind === 'unreadable') {
				return { ...base, ok: false, error: 'could not read the page into funding data', grants: [], changed: true, page_kind: ex.page_kind, model: ex.model, note };
			}
			return { ...base, ok: true, error: '', grants: ex.grants, hash, changed: true, page_kind: ex.page_kind, model: ex.model, note };
		}),
	);
	return { results, usage };
}

export function chunk<T>(a: T[], n: number): T[][] {
	const out: T[][] = [];
	for (let i = 0; i < a.length; i += n) out.push(a.slice(i, i + n));
	return out;
}

const titleKey = (t: string) =>
	slug(t)
		.replace(/^the-/, '')
		.replace(/^national-lottery-/, '')
		.replace(/-england(?=-|$)/g, '')
		.replace(/-+$/, '');

const quality = (g: Grant) =>
	g.confidence + (g.eligibility ? 0.1 : 0) + (g.how_to_apply ? 0.05 : 0) + (g.deadline ? 0.05 : 0) + (g.amount_max !== null ? 0.05 : 0);

/**
 * The same programme can appear on a funder's listing page and on its own page
 * (or on two funders' pages). Keep the best copy and drop the rest from their sources.
 */
export function dedupeAcrossSources(results: SourceResult[]): SourceResult[] {
	const best = new Map<string, { g: Grant; id: string; q: number }>();
	for (const r of results) {
		if (!r.ok) continue;
		for (const g of r.grants) {
			const k = titleKey(g.title);
			const cur = best.get(k);
			const q = quality(g) - (r.listing ? 0.3 : 0);
			if (!cur || q > cur.q + 1e-9) best.set(k, { g, id: r.id, q });
		}
	}
	return results.map((r) => ({
		...r,
		grants: r.ok ? r.grants.filter((g) => best.get(titleKey(g.title))?.g === g) : r.grants,
	}));
}
