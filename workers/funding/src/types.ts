export interface Env {
	STATE: KVNamespace;
	FUNDING_WORKFLOW: Workflow;
	TINYFISH: SecretsStoreSecret;
	ANTHROPIC: SecretsStoreSecret;
	DGL_TOKEN?: SecretsStoreSecret;
	ADMIN_KEY: string;
	DGL_SYNC_URL?: string;
}

export interface Source {
	id: string;
	name: string;
	url: string;
	funder?: string;
	notes?: string;
}

export interface Grant {
	key: string;
	title: string;
	funder: string;
	summary: string;
	body: string;
	amount_min: number | null;
	amount_max: number | null;
	deadline: string | null;
	rolling: boolean;
	eligibility: string;
	how_to_apply: string;
	application_url: string;
	fund_type: string[];
	applicants: string[];
	costs: string[];
	beneficiaries: string[];
	area: string;
	topics: string[];
	confidence: number;
}

export interface Usage {
	calls: number;
	haiku_calls: number;
	sonnet_calls: number;
	input_tokens: number;
	output_tokens: number;
	cache_read_tokens: number;
	cache_write_tokens: number;
}

export interface SourceResult {
	id: string;
	name: string;
	url: string;
	ok: boolean;
	error: string;
	grants: Grant[];
	/** Hash of the fetched markdown; absent when the page could not be read. */
	hash?: string;
	changed: boolean;
	page_kind?: string;
	model?: string;
	note?: string;
	/** True for funder listing pages, which lose to a programme's own page when both list it. */
	listing?: boolean;
}

export interface StoredState {
	hash: string;
	grants: Grant[];
	checked_at: string;
}

export interface BatchOutput {
	results: SourceResult[];
	usage: Usage;
}

export function emptyUsage(): Usage {
	return { calls: 0, haiku_calls: 0, sonnet_calls: 0, input_tokens: 0, output_tokens: 0, cache_read_tokens: 0, cache_write_tokens: 0 };
}

export function addUsage(a: Usage, b: Usage): Usage {
	return {
		calls: a.calls + b.calls,
		haiku_calls: a.haiku_calls + b.haiku_calls,
		sonnet_calls: a.sonnet_calls + b.sonnet_calls,
		input_tokens: a.input_tokens + b.input_tokens,
		output_tokens: a.output_tokens + b.output_tokens,
		cache_read_tokens: a.cache_read_tokens + b.cache_read_tokens,
		cache_write_tokens: a.cache_write_tokens + b.cache_write_tokens,
	};
}
