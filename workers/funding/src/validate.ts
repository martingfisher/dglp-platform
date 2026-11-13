import type { Grant } from './types';
import { APPLICANTS, AREA, BENEFICIARIES, COSTS, FUND_TYPE, TOPICS } from './vocab';

export const MAX_GRANTS_PER_SOURCE = 25;
const WORD_CAPS = { summary: 45, body: 200, eligibility: 120, how_to_apply: 80 } as const;

export function slug(s: string): string {
	return s
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/&/g, ' and ')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 80);
}

export function normUrl(u: string): string {
	try {
		const x = new URL(u);
		x.hash = '';
		let p = x.pathname.replace(/\/+$/, '');
		if (p === '') p = '';
		return (x.protocol + '//' + x.host.toLowerCase().replace(/^www\./, '') + p + x.search).toLowerCase();
	} catch {
		return u.trim().toLowerCase();
	}
}

function cleanText(v: unknown, maxWords: number): string {
	if (typeof v !== 'string') return '';
	let s = v
		.replace(/<[^>]*>/g, ' ')
		.replace(/[*_`#>]+/g, '')
		.replace(/[—―]/g, ', ')
		.replace(/\s+,/g, ',')
		.replace(/\bhonest(ly)?\b/gi, '')
		.replace(/[ \t]+/g, ' ')
		.replace(/ ?\n ?/g, '\n')
		.replace(/\n{3,}/g, '\n\n')
		.trim();
	// Drop sentences that talk about the page instead of the fund.
	s = s
		.split(/(?<=[.!?])\s+/)
		.filter((x) => !/\b(the|this) page\b/i.test(x) && !/\b(the |this )?(page|content provided|website)\b[^.]{0,40}\b(does not|doesn't|gives no|give no|says nothing|not state|not set out|not say|not list)/i.test(x) && !/\b(not stated|not specified|not given) (on|in) the (page|content)/i.test(x))
		.join(' ')
		.trim();
	const words = s.split(/\s+/);
	if (words.length > maxWords) {
		const cut = words.slice(0, maxWords).join(' ');
		const lastStop = Math.max(cut.lastIndexOf('. '), cut.lastIndexOf('.'));
		s = lastStop > cut.length * 0.5 ? cut.slice(0, lastStop + 1) : cut.replace(/[,;:]$/, '') + '.';
	}
	return s;
}

function pickEnum(v: unknown, allowed: string[]): string[] {
	if (!Array.isArray(v)) return [];
	return [...new Set(v.filter((x): x is string => typeof x === 'string' && allowed.includes(x)))];
}

function money(v: unknown): number | null {
	if (typeof v !== 'number' || !Number.isFinite(v) || v < 0) return null;
	return Math.round(v);
}

export function today(): string {
	return new Date().toISOString().slice(0, 10);
}

export interface ValidateResult {
	grants: Grant[];
	problems: string[];
	rawCount: number;
}

/**
 * Check the model's answer. Drops invalid fields before dropping whole grants.
 * `allowedLinks` are the page's links plus the page URL (normalised).
 */
export function validateGrants(
	raw: unknown,
	sourceId: string,
	pageUrl: string,
	allowedLinks: Set<string>,
	defaultFunder: string,
	now = today(),
): ValidateResult {
	const problems: string[] = [];
	const arr = Array.isArray(raw) ? raw : [];
	const out: Grant[] = [];
	const seen = new Set<string>();
	for (const r of arr as Record<string, unknown>[]) {
		if (!r || typeof r !== 'object') continue;
		const title = cleanText(r.title, 20).replace(/\n/g, ' ');
		if (!title) {
			problems.push('grant without title');
			continue;
		}
		let key = typeof r.key === 'string' ? r.key.trim() : '';
		const prefix = sourceId + ':';
		if (!key.startsWith(prefix) || slug(key.slice(prefix.length)) === '') {
			key = prefix + slug(key.includes(':') ? key.split(':').slice(1).join(':') : title);
		} else {
			key = prefix + slug(key.slice(prefix.length));
		}
		if (seen.has(key)) {
			problems.push('duplicate key ' + key);
			continue;
		}
		seen.add(key);

		let deadline: string | null = null;
		if (typeof r.deadline === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(r.deadline) && !Number.isNaN(Date.parse(r.deadline))) {
			deadline = r.deadline;
		}
		let rolling = r.rolling === true;
		if (deadline && deadline < now) {
			problems.push(`${key}: deadline ${deadline} already passed`);
			continue;
		}
		if (deadline) rolling = false;

		let appUrl = typeof r.application_url === 'string' ? r.application_url.trim() : '';
		if (!/^https:\/\//i.test(appUrl) || !allowedLinks.has(normUrl(appUrl))) {
			if (appUrl) problems.push(`${key}: application_url not on page, using page URL`);
			appUrl = pageUrl;
		}
		if (!/^https:\/\//i.test(appUrl)) {
			// http-only pages: the plugin needs https; upgrade the scheme.
			appUrl = appUrl.replace(/^http:\/\//i, 'https://');
		}

		let min = money(r.amount_min);
		let max = money(r.amount_max);
		if (min !== null && max !== null && min > max) [min, max] = [max, min];

		const topics = pickEnum(r.topics, TOPICS);
		if (!topics.includes('grants-and-funding')) topics.unshift('grants-and-funding');
		const area = typeof r.area === 'string' && AREA.includes(r.area) ? r.area : 'england';
		const fund_type = pickEnum(r.fund_type, FUND_TYPE);
		let conf = typeof r.confidence === 'number' && Number.isFinite(r.confidence) ? r.confidence : 0.5;
		conf = Math.max(0, Math.min(1, conf));

		const summary = cleanText(r.summary, WORD_CAPS.summary).replace(/\n/g, ' ');
		if (!summary) problems.push(`${key}: empty summary`);

		out.push({
			key,
			title,
			funder: cleanText(r.funder, 20).replace(/\n/g, ' ') || defaultFunder,
			summary,
			body: cleanText(r.body, WORD_CAPS.body),
			amount_min: min,
			amount_max: max,
			deadline,
			rolling,
			eligibility: cleanText(r.eligibility, WORD_CAPS.eligibility),
			how_to_apply: cleanText(r.how_to_apply, WORD_CAPS.how_to_apply),
			application_url: appUrl,
			fund_type: fund_type.length ? fund_type : ['grant'],
			applicants: pickEnum(r.applicants, APPLICANTS),
			costs: pickEnum(r.costs, COSTS),
			beneficiaries: pickEnum(r.beneficiaries, BENEFICIARIES),
			area,
			topics,
			confidence: Math.round(conf * 100) / 100,
		});
		if (out.length >= MAX_GRANTS_PER_SOURCE) break;
	}
	return { grants: out, problems, rawCount: arr.length };
}

/** Drop grants whose deadline passed since they were stored. */
export function stillOpen(grants: Grant[], now = today()): Grant[] {
	return grants.filter((g) => !g.deadline || g.deadline >= now);
}
