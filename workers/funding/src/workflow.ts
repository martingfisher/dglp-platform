import { WorkflowEntrypoint, type WorkflowEvent, type WorkflowStep } from 'cloudflare:workers';
import sourcesJson from '../sources.json';
import { BATCH_SIZE, chunk, dedupeAcrossSources, processBatch } from './pipeline';
import { addUsage, emptyUsage, type BatchOutput, type Env, type Grant, type Source, type SourceResult, type StoredState, type Usage } from './types';
import { stillOpen, today } from './validate';
import manualJson from '../manual.json';

export const SOURCES = sourcesJson as Source[];

interface ManualSource {
	id: string;
	name: string;
	url: string;
	check_by: string;
	grants: Grant[];
}

/** Hand-written sources still within their check_by date, in the shape the site expects. */
export function manualSources(day: string) {
	return (manualJson.sources as ManualSource[])
		.filter((s) => s.check_by >= day)
		.map((s) => ({ id: s.id, name: s.name, url: s.url, ok: true, error: '', grants: stillOpen(s.grants) }));
}
export const DEFAULT_SYNC_URL = 'https://doinggoodleeds.org.uk/wp-json/dgl/v1/funding/sync';
const RUN_TTL = 14 * 24 * 3600;
const COMMIT_CHUNK = 35;

const RETRY = { retries: { limit: 2, delay: '20 seconds', backoff: 'exponential' }, timeout: '12 minutes' } as const;

export interface RunParams {
	runId?: string;
	ids?: string[];
}

export class FundingWorkflow extends WorkflowEntrypoint<Env, RunParams> {
	async run(event: WorkflowEvent<RunParams>, step: WorkflowStep) {
		const env = this.env;
		const runId = event.payload?.runId || new Date().toISOString().replace(/\.\d+Z$/, 'Z');
		const wanted = event.payload?.ids;
		const sources = wanted?.length ? SOURCES.filter((s) => wanted.includes(s.id)) : SOURCES;
		const batches = chunk(sources, BATCH_SIZE);

		let usage: Usage = emptyUsage();
		for (let i = 0; i < batches.length; i++) {
			const summary = await step.do(`batch-${i}`, RETRY, async () => {
				const out = await processBatch(env, batches[i], true);
				await env.STATE.put(`run:${runId}:${i}`, JSON.stringify(out), { expirationTtl: RUN_TTL });
				return {
					usage: out.usage,
					changed: out.results.filter((r) => r.changed).length,
					failed: out.results.filter((r) => !r.ok).length,
				};
			});
			usage = addUsage(usage, summary.usage);
		}

		const posted = await step.do('post', { retries: { limit: 3, delay: '60 seconds', backoff: 'exponential' }, timeout: '5 minutes' }, async () => {
			const results: SourceResult[] = [];
			for (let i = 0; i < batches.length; i++) {
				const out = await env.STATE.get<BatchOutput>(`run:${runId}:${i}`, 'json');
				if (!out) throw new Error(`batch ${i} result missing`);
				results.push(...out.results);
			}
			const payload = {
				run_id: runId,
				sources: dedupeAcrossSources(results.map((r) => ({ ...r, grants: r.ok ? stillOpen(r.grants) : [] }))).map((r) => ({
					id: r.id,
					name: r.name,
					url: r.url,
					ok: r.ok,
					error: r.error,
					grants: r.ok ? stillOpen(r.grants) : [],
				})),
			};
			// Funds written up by hand for funders whose sites refuse automated reading.
			// Only a full run sends them, and only until their check_by date.
			if (!wanted?.length) payload.sources.push(...manualSources(today()));
			const token = async () => {
				if (!env.DGL_TOKEN) throw new Error('DGL_TOKEN binding missing: add dgl_funding_token to the Secrets Store and wrangler.jsonc');
				return env.DGL_TOKEN.get();
			};
			const res = await fetch(env.DGL_SYNC_URL || DEFAULT_SYNC_URL, {
				method: 'POST',
				// Wordfence on the site refuses a POST with no user-agent and no referer, so say who we are.
				headers: {
					'content-type': 'application/json',
					'user-agent': 'dgl-funding/1.0 (+https://doinggoodleeds.org.uk/grants/)',
					referer: 'https://dgl-funding.martingfisher.workers.dev/',
					authorization: `Bearer ${await token()}`,
				},
				body: JSON.stringify(payload),
				signal: AbortSignal.timeout(120_000),
			});
			const text = await res.text();
			if (!res.ok) throw new Error(`sync POST failed ${res.status}: ${text.slice(0, 300)}`);
			let answer: Record<string, unknown> = {};
			try {
				answer = JSON.parse(text);
			} catch {
				throw new Error('sync POST returned non-JSON: ' + text.slice(0, 200));
			}
			if (answer.ok !== true) throw new Error('sync POST answered not ok: ' + text.slice(0, 300));
			return {
				sources: results.length,
				ok: results.filter((r) => r.ok).length,
				grants: payload.sources.reduce((n, s) => n + s.grants.length, 0),
				answer: JSON.stringify(answer),
			};
		});

		// State is written only after the site accepted the run, so a failed week is retried in full.
		const toCommit = batches.flat();
		const commitChunks = chunk(toCommit, COMMIT_CHUNK);
		for (let c = 0; c < commitChunks.length; c++) {
			await step.do(`commit-${c}`, RETRY, async () => {
				const byBatch = new Map<number, BatchOutput>();
				const now = new Date().toISOString();
				for (const s of commitChunks[c]) {
					const bi = Math.floor(sources.indexOf(s) / BATCH_SIZE);
					if (!byBatch.has(bi)) {
						const out = await env.STATE.get<BatchOutput>(`run:${runId}:${bi}`, 'json');
						if (out) byBatch.set(bi, out);
					}
					const r = byBatch.get(bi)?.results.find((x) => x.id === s.id);
					if (!r || !r.ok || !r.hash) continue;
					const state: StoredState = { hash: r.hash, grants: r.grants, checked_at: now };
					await env.STATE.put('src:' + s.id, JSON.stringify(state));
				}
			});
		}

		console.log(
			`dgl-funding run ${runId}: ${posted.sources} sources, ${posted.ok} read, ${posted.grants} grants sent, ` +
				`${usage.haiku_calls} haiku + ${usage.sonnet_calls} sonnet calls, ${usage.input_tokens} in / ${usage.output_tokens} out tokens, answer ${posted.answer}`,
		);
		return { runId, ...posted, usage };
	}
}
