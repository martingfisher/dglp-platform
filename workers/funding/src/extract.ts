import Anthropic from '@anthropic-ai/sdk';
import { SYSTEM_PROMPT } from './prompt';
import { EXTRACTION_SCHEMA } from './schema';
import { addUsage, emptyUsage, type Env, type Grant, type Source, type Usage } from './types';
import type { FetchedPage } from './fetch';
import { normUrl, today, validateGrants } from './validate';

const HAIKU = 'claude-haiku-5-5';
const SONNET = 'claude-sonnet-5-5';
const MAX_MARKDOWN_CHARS = 90_000;
const MAX_LINKS = 400;

export interface Extraction {
	page_kind: 'funding' | 'not_funding' | 'unreadable';
	grants: Grant[];
	model: string;
	usage: Usage;
	problems: string[];
}

let client: Anthropic | null = null;
async function getClient(env: Env): Promise<Anthropic> {
	if (!client) {
		// baseURL is explicit so a local ANTHROPIC_BASE_URL can never leak in.
		client = new Anthropic({ apiKey: await env.ANTHROPIC.get(), baseURL: 'https://api.anthropic.com', maxRetries: 2, timeout: 240_000 });
	}
	return client;
}

function userMessage(source: Source, page: FetchedPage, prev: { key: string; title: string }[]): string {
	const links = [...new Set(page.links)].slice(0, MAX_LINKS);
	const md = page.text.length > MAX_MARKDOWN_CHARS ? page.text.slice(0, MAX_MARKDOWN_CHARS) + '\n[page truncated]' : page.text;
	return [
		`Today's date: ${today()}`,
		`Source id: ${source.id}`,
		`Source name: ${source.name}`,
		source.funder ? `Funder: ${source.funder}` : '',
		source.notes ? `Notes: ${source.notes}` : '',
		`Page URL: ${page.url}`,
		page.final_url !== page.url ? `Final URL after redirects: ${page.final_url}` : '',
		page.title ? `Page title: ${page.title}` : '',
		'',
		"Last week's programmes from this source (reuse the key when it is the same programme):",
		prev.length ? prev.map((p) => `- ${p.key} | ${p.title}`).join('\n') : '(none)',
		'',
		'Links on the page (application_url must be one of these or the page URL):',
		links.length ? links.map((l) => `- ${l}`).join('\n') : '(none)',
		'',
		'Page content (markdown):',
		'<<<PAGE',
		md,
		'PAGE>>>',
	]
		.filter((l, i, a) => !(l === '' && a[i - 1] === ''))
		.join('\n');
}

async function callModel(
	env: Env,
	model: string,
	msg: string,
): Promise<{ raw: unknown | null; usage: Usage; failure: string }> {
	const c = await getClient(env);
	const usage = emptyUsage();
	try {
		const res = await c.messages.create({
			model,
			max_tokens: 16000,
			system: [{ type: 'text', text: SYSTEM_PROMPT, cache_control: { type: 'ephemeral' } }],
			messages: [{ role: 'user', content: msg }],
			output_config: { effort: 'low', format: { type: 'json_schema', schema: EXTRACTION_SCHEMA as unknown as Record<string, unknown> } },
		} as never);
		const r = res as unknown as {
			stop_reason: string;
			content: { type: string; text?: string }[];
			usage: { input_tokens: number; output_tokens: number; cache_read_input_tokens?: number; cache_creation_input_tokens?: number };
		};
		usage.calls = 1;
		if (model === HAIKU) usage.haiku_calls = 1;
		else usage.sonnet_calls = 1;
		usage.input_tokens = r.usage.input_tokens;
		usage.output_tokens = r.usage.output_tokens;
		usage.cache_read_tokens = r.usage.cache_read_input_tokens ?? 0;
		usage.cache_write_tokens = r.usage.cache_creation_input_tokens ?? 0;
		if (r.stop_reason !== 'end_turn' && r.stop_reason !== 'stop_sequence') return { raw: null, usage, failure: 'stop_reason ' + r.stop_reason };
		const text = r.content.filter((b) => b.type === 'text').map((b) => b.text ?? '').join('');
		try {
			return { raw: JSON.parse(text), usage, failure: '' };
		} catch {
			return { raw: null, usage, failure: 'unparsable JSON' };
		}
	} catch (e) {
		usage.calls = 1;
		return { raw: null, usage, failure: 'API error: ' + (e instanceof Error ? e.message.slice(0, 300) : String(e)) };
	}
}

function check(
	raw: unknown,
	source: Source,
	page: FetchedPage,
): { ok: boolean; kind: Extraction['page_kind']; grants: Grant[]; problems: string[]; why: string } {
	const o = raw as { page_kind?: string; grants?: unknown } | null;
	if (!o || typeof o !== 'object' || !Array.isArray(o.grants) || !['funding', 'not_funding', 'unreadable'].includes(o.page_kind ?? '')) {
		return { ok: false, kind: 'unreadable', grants: [], problems: [], why: 'answer did not match the schema' };
	}
	const allowed = new Set<string>([normUrl(page.url), normUrl(page.final_url), ...page.links.map(normUrl)]);
	const v = validateGrants(o.grants, source.id, page.final_url.startsWith('https://') ? page.final_url : page.url, allowed, source.funder ?? source.name);
	const kind = o.page_kind as Extraction['page_kind'];
	if (kind !== 'funding' && v.rawCount > 0) return { ok: false, kind, grants: [], problems: v.problems, why: 'grants returned for a non-funding page' };
	if (v.rawCount > 0 && v.grants.length === 0) return { ok: false, kind, grants: [], problems: v.problems, why: 'every grant failed validation' };
	return { ok: true, kind, grants: v.grants, problems: v.problems, why: '' };
}

export async function extract(
	env: Env,
	source: Source,
	page: FetchedPage,
	prev: { key: string; title: string }[],
): Promise<Extraction> {
	const msg = userMessage(source, page, prev);
	let usage = emptyUsage();
	const problems: string[] = [];
	for (const model of [HAIKU, SONNET]) {
		const r = await callModel(env, model, msg);
		usage = addUsage(usage, r.usage);
		if (r.failure) {
			problems.push(`${model}: ${r.failure}`);
			continue;
		}
		const c = check(r.raw, source, page);
		problems.push(...c.problems.map((p) => `${model}: ${p}`));
		if (c.ok) return { page_kind: c.kind, grants: c.grants, model, usage, problems };
		problems.push(`${model}: ${c.why}`);
	}
	return { page_kind: 'unreadable', grants: [], model: SONNET, usage, problems };
}
