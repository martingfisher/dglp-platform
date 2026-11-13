import { AREA, APPLICANTS, BENEFICIARIES, COSTS, FUND_TYPE, TOPICS } from './vocab';

const str = { type: 'string' };
const nullableStr = { anyOf: [{ type: 'string' }, { type: 'null' }] };
const nullableNum = { anyOf: [{ type: 'number' }, { type: 'null' }] };
const enumArray = (values: string[]) => ({ type: 'array', items: { type: 'string', enum: values } });

export const EXTRACTION_SCHEMA = {
	type: 'object',
	additionalProperties: false,
	required: ['page_kind', 'grants'],
	properties: {
		page_kind: { type: 'string', enum: ['funding', 'not_funding', 'unreadable'] },
		grants: {
			type: 'array',
			items: {
				type: 'object',
				additionalProperties: false,
				required: [
					'key', 'title', 'funder', 'summary', 'body', 'amount_min', 'amount_max', 'deadline', 'rolling',
					'eligibility', 'how_to_apply', 'application_url', 'fund_type', 'applicants', 'costs',
					'beneficiaries', 'area', 'topics', 'confidence',
				],
				properties: {
					key: str,
					title: str,
					funder: str,
					summary: str,
					body: str,
					amount_min: nullableNum,
					amount_max: nullableNum,
					deadline: nullableStr,
					rolling: { type: 'boolean' },
					eligibility: str,
					how_to_apply: str,
					application_url: str,
					fund_type: enumArray(FUND_TYPE),
					applicants: enumArray(APPLICANTS),
					costs: enumArray(COSTS),
					beneficiaries: enumArray(BENEFICIARIES),
					area: { type: 'string', enum: AREA },
					topics: enumArray(TOPICS),
					confidence: { type: 'number' },
				},
			},
		},
	},
} as const;
