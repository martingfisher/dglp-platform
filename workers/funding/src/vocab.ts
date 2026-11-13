import vocabJson from '../../../src/Funding/vocab.json';

type Dict = Record<string, string>;
const v = vocabJson as unknown as Record<string, Dict | string[]>;

const keys = (name: string): string[] => Object.keys(v[name] as Dict);

export const FUND_TYPE = keys('fund_type');
export const APPLICANTS = keys('applicants');
export const COSTS = keys('costs');
export const BENEFICIARIES = keys('beneficiaries');
export const AREA = keys('area');
export const TOPICS_EXCLUDED = v.topics_excluded as string[];

/**
 * Topic slugs, copied from src/Topics/Topics.php all() minus topics_excluded in
 * vocab.json. Topics.php is PHP so it cannot be imported; keep in step with it.
 */
const ALL_TOPICS = [
	'arts-culture-and-heritage',
	'children-and-young-people',
	'communities-of-interest',
	'community-power',
	'cultural-diverse-communities',
	'data-and-digital',
	'doing-good-leeds-partnership',
	'environment-and-nature',
	'equality-diversity-and-inclusion',
	'featured',
	'grants-and-funding',
	'have-your-say',
	'health-and-social-care',
	'insight-learning-and-evaluation',
	'leadership',
	'learning-disability',
	'local-place-based',
	'mens-health',
	'mental-health',
	'older-people',
	'physical-and-sensory-impairments',
	'professional-development',
	'reps',
	'support-for-vcse-organisations',
	'third-sector-leeds',
	'volunteering',
	'wellbeing',
	'workforce',
];
export const TOPICS = ALL_TOPICS.filter((t) => !TOPICS_EXCLUDED.includes(t));

export const VOCAB_LABELS = {
	fund_type: v.fund_type as Dict,
	applicants: v.applicants as Dict,
	costs: v.costs as Dict,
	beneficiaries: v.beneficiaries as Dict,
	area: v.area as Dict,
};
