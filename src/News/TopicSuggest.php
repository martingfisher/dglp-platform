<?php
/**
 * Suggest topics for a story from its words.
 *
 * For the stories that came over from the old site with no topic. This is a
 * keyword pass, not a judgement: it offers, a person confirms. Every rule is
 * a phrase that in DGLP's own titles has meant that topic, matched against the
 * headline and summary, whole words only, case blind. A story can match more
 * than one topic; a story that matches none is listed as such.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\News;

defined( 'ABSPATH' ) || exit;

final class TopicSuggest {

	/**
	 * Topic slug => phrases. Order is the order suggestions are listed in.
	 *
	 * @return array<string,string[]>
	 */
	public static function rules(): array {
		return [
			'grants-and-funding'               => [ 'grant', 'grants', 'fund', 'funds', 'funding', 'funder', 'crowdfunder', 'crowdfunding', 'award', 'awards', 'bursary', 'bursaries', 'share offer', 'fundraiser', 'in kind direct' ],
			'volunteering'                     => [ 'volunteer', 'volunteers', 'volunteering', 'trustee', 'trustees', 'trusteeship' ],
			'have-your-say'                    => [ 'survey', 'consultation', 'have your say', 'have you say', 'share your thoughts', 'your views', 'panel', 'nominate', 'nominations', 'research project', 'take part' ],
			'professional-development'         => [ 'training', 'course', 'courses', 'webinar', 'workshop', 'workshops', 'guide', 'guides', 'skills', 'sessions', 'podcast', 'learning' ],
			'support-for-vcse-organisations'   => [ 'charity', 'charities', 'vcse', 'civil society', 'third sector', 'governance', 'national insurance', 'tender', 'welcome spaces', 'uk shared prosperity', 'ukspf', 'covenant', 'community centre', 'organisations' ],
			'mental-health'                    => [ 'mental health', 'suicide', 'unmasked', 'safeguarding' ],
			'older-people'                     => [ 'older people', 'older', 'elders', 'seniors', 'menopause', 'mae care' ],
			'children-and-young-people'        => [ 'children', 'young people', 'young', 'youth', 'baby', 'babies', 'families', 'healthy holidays', 'healthy start' ],
			'health-and-social-care'           => [ 'nhs', 'health', 'care', 'domestic abuse', 'safeguarding adults' ],
			'equality-diversity-and-inclusion' => [ 'race equality', 'lgbtq', 'inclusion', 'inclusive', 'neurodiversity', 'autism', 'disabled', 'disability', 'evisa', 'evisas' ],
			'cultural-diverse-communities'     => [ 'culturally diverse', 'black histories', 'black history', 'refugee', 'refugees', 'asylum' ],
			'environment-and-nature'           => [ 'seeds', 'gardening', 'compost', 'food growing', 'community energy', 'energy', 'feed leeds', 'growing' ],
			'data-and-digital'                 => [ 'digital', 'online', 'zoom' ],
			'arts-culture-and-heritage'        => [ 'art', 'arts', 'film', 'creative', 'history', 'heritage', 'philosophical and literary', 'sound system', 'culture' ],
			'wellbeing'                        => [ 'wellbeing', 'sauna', 'healthy', 'loneliness' ],
			'leadership'                       => [ 'leaders', 'leadership', 'board directors' ],
			'workforce'                        => [ 'recruitment', 'recruiting', 'vacancy', 'vacancies', 'job', 'jobs', 'interns', 'convener' ],
			'insight-learning-and-evaluation'  => [ 'report', 'research', 'evaluation', 'summary', 'overview' ],
			'community-power'                  => [ 'community share', 'crowdfunder for', 'community project', 'community-led', 'roadblock' ],
			'third-sector-leeds'               => [ 'tsl', 'third sector leeds' ],
			'local-place-based'                => [ 'armley', 'bramley', 'woodhouse', 'harehills', 'seacroft', 'chapeltown', 'holbeck', 'gipton', 'morley', 'otley', 'wetherby', 'high rise', 'highrise' ],
		];
	}

	/**
	 * Topics suggested for a piece of text, most specific match first.
	 *
	 * @return string[] Topic slugs, in rule order, no duplicates.
	 */
	public static function suggest( string $text ): array {
		$haystack = ' ' . self::normalise( $text ) . ' ';
		$out      = [];

		foreach ( self::rules() as $slug => $phrases ) {
			foreach ( $phrases as $phrase ) {
				if ( str_contains( $haystack, ' ' . self::normalise( $phrase ) . ' ' ) ) {
					$out[] = $slug;
					break;
				}
			}
		}

		return $out;
	}

	/** Lower case, entities decoded, punctuation to spaces, one space between words. */
	public static function normalise( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( $text );
		$text = str_replace( [ '’', '‘', "'" ], '', $text );
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}
}
