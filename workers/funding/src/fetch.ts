import type { Env, Source } from './types';
import { normUrl } from './validate';

export interface FetchedPage {
	url: string;
	final_url: string;
	title: string;
	text: string;
	links: string[];
}
export interface FetchOutcome {
	pages: Map<string, FetchedPage>;
	errors: Map<string, string>;
}

const PURPOSE = 'Extract currently open funding opportunities for voluntary and community groups in Leeds';

function linkUrl(l: unknown): string | null {
	if (typeof l === 'string') return l;
	if (l && typeof l === 'object') {
		const o = l as Record<string, unknown>;
		for (const k of ['url', 'href', 'link']) if (typeof o[k] === 'string') return o[k] as string;
	}
	return null;
}

/** Fetch up to 10 sources with one TinyFish call. */
export async function fetchBatch(env: Env, sources: Source[]): Promise<FetchOutcome> {
	const key = await env.TINYFISH.get();
	const pages = new Map<string, FetchedPage>();
	const errors = new Map<string, string>();
	const body = JSON.stringify({
		urls: sources.map((s) => s.url),
		format: 'markdown',
		links: true,
		image_links: false,
		iframe_links: false,
		page_metadata: false,
		per_url_timeout_ms: 45000,
		purpose: PURPOSE,
	});
	let res: Response | null = null;
	let lastErr = '';
	for (let attempt = 0; attempt < 2; attempt++) {
		try {
			res = await fetch('https://api.fetch.tinyfish.ai', {
				method: 'POST',
				headers: { 'X-API-Key': key, 'content-type': 'application/json' },
				body,
				signal: AbortSignal.timeout(150_000),
			});
			if (res.ok) break;
			lastErr = `TinyFish HTTP ${res.status}`;
			if (res.status < 500 && res.status !== 429) break;
		} catch (e) {
			lastErr = 'TinyFish request failed: ' + (e instanceof Error ? e.message : String(e));
		}
		res = null;
		await new Promise((r) => setTimeout(r, 3000));
	}
	if (!res || !res.ok) {
		for (const s of sources) errors.set(s.url, lastErr || 'TinyFish unavailable');
		return { pages, errors };
	}
	const data = (await res.json()) as {
		results?: { url: string; final_url?: string; title?: string; text?: string; links?: unknown[] }[];
		errors?: { url: string; error?: string; status?: number }[];
	};
	for (const r of data.results ?? []) {
		pages.set(r.url, {
			url: r.url,
			final_url: r.final_url || r.url,
			title: r.title || '',
			text: r.text || '',
			links: (r.links ?? []).map(linkUrl).filter((x): x is string => !!x),
		});
	}
	for (const e of data.errors ?? []) {
		errors.set(e.url, `${e.error || 'fetch error'}${e.status ? ' (' + e.status + ')' : ''}`);
	}
	// Match any requested URL the API normalised differently.
	for (const s of sources) {
		if (pages.has(s.url) || errors.has(s.url)) continue;
		const n = normUrl(s.url);
		const hit = [...pages.values()].find((p) => normUrl(p.url) === n || normUrl(p.final_url) === n);
		if (hit) pages.set(s.url, hit);
		else {
			const eh = [...errors.keys()].find((k) => normUrl(k) === n);
			if (eh) errors.set(s.url, errors.get(eh)!);
			else errors.set(s.url, 'no result returned');
		}
	}
	return { pages, errors };
}

export async function sha256(text: string): Promise<string> {
	const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
	return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
}
