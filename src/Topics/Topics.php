<?php
/**
 * The topic list DGLP decided on, and how it reaches the site.
 *
 * One list for every content type. DGLP settled it on 22 September 2026 from
 * a spreadsheet of three columns: the new topics, which of the old site's
 * categories each one absorbs, and which old categories go. The list lives
 * here rather than in the database alone so staging and production get the
 * same terms with the same slugs, and so the decision is on the record.
 *
 * The plugin creates and renames terms in its own topic taxonomy. It never
 * touches the old site's core categories: those belong to the legacy posts,
 * and what happens to them is a separate decision recorded in
 * `docs/topics.md`.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Topics;

use DGL\Taxonomies;

defined( 'ABSPATH' ) || exit;

final class Topics {

	/** Bumped whenever the list below changes, so `maybe_sync()` runs once per change. */
	public const LIST_VERSION = 1;

	public const OPTION = 'dgl_topics_version';

	/**
	 * The topics, in the order DGLP listed them. Slug => name.
	 *
	 * Slugs are fixed here rather than derived at run time, because a slug is
	 * a public address (`/news/?topic=<slug>`) and must not drift.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return [
			'arts-culture-and-heritage'          => 'Arts, Culture and Heritage',
			'children-and-young-people'          => 'Children and Young People',
			'communities-of-interest'            => 'Communities of Interest',
			'community-power'                    => 'Community Power',
			'cultural-diverse-communities'       => 'Cultural Diverse Communities',
			'data-and-digital'                   => 'Data and Digital',
			'doing-good-leeds-partnership'       => 'Doing Good Leeds Partnership',
			'environment-and-nature'             => 'Environment and Nature',
			'equality-diversity-and-inclusion'   => 'Equality, Diversity and Inclusion',
			'featured'                           => 'Featured',
			'grants-and-funding'                 => 'Grants and Funding',
			'have-your-say'                      => 'Have your Say (Surveys and consultations)',
			'health-and-social-care'             => 'Health and Social Care',
			'insight-learning-and-evaluation'    => 'Insight, Learning and Evaluation',
			'leadership'                         => 'Leadership',
			'learning-disability'                => 'Learning Disability',
			'local-place-based'                  => 'Local, Place-based (LCAN, LCP, NN)',
			'mens-health'                        => "Men's Health",
			'mental-health'                      => 'Mental Health',
			'older-people'                       => 'Older People',
			'physical-and-sensory-impairments'   => 'Physical and Sensory Impairments (PSI)',
			'professional-development'           => 'Professional Development',
			'reps'                               => 'Reps',
			'support-for-vcse-organisations'     => 'Support for VCSE Organisations',
			'third-sector-leeds'                 => 'Third Sector Leeds (TSL)',
			'volunteering'                       => 'Volunteering',
			'wellbeing'                          => 'Wellbeing',
			'workforce'                          => 'Workforce',
		];
	}

	/**
	 * Which old-site category each topic absorbs. Old category slug => topic slug.
	 *
	 * Recorded from DGLP's decision. Nothing in the plugin acts on this yet;
	 * it is the mapping a migration of the legacy posts would use.
	 *
	 * @return array<string, string>
	 */
	public static function legacy(): array {
		return [
			'communities-of-interest'             => 'communities-of-interest',
			'harnessing-the-power-of-communities' => 'community-power',
			'inclusion'                           => 'equality-diversity-and-inclusion',
			'featured'                            => 'featured',
			'featured-2'                          => 'featured',
			'funding'                             => 'grants-and-funding',
			'have-your-say'                       => 'have-your-say',
			'forumcentral'                        => 'health-and-social-care',
			'health-care'                         => 'health-and-social-care',
			'learning-disability'                 => 'learning-disability',
			'local-care-partnerships'             => 'local-place-based',
			'mens-health'                         => 'mens-health',
			'mental-health'                       => 'mental-health',
			'older-people'                        => 'older-people',
			'psi'                                 => 'physical-and-sensory-impairments',
			'training-mailchimp'                  => 'professional-development',
			'reps'                                => 'reps',
			'cost-of-living'                      => 'support-for-vcse-organisations',
		];
	}

	/**
	 * Old-site categories DGLP want removed. They are not topics and map to none.
	 *
	 * @return list<string>
	 */
	public static function retired(): array {
		return [ 'blog', 'events-2', 'jobs-2', 'mailchimp', 'events', 'jobs', 'member-updates', 'news-mailchimp', 'news' ];
	}

	/**
	 * Bring the taxonomy's terms in line with the list. Idempotent.
	 *
	 * A term whose slug is on the list is renamed if its name differs and
	 * left alone otherwise. A slug not yet present is created. Terms that are
	 * not on the list are never deleted here: somebody may have added one on
	 * purpose, and removing content's terms is not a job for a page load.
	 *
	 * @return array{created: list<string>, renamed: list<string>, unchanged: list<string>, extra: list<string>, errors: list<string>}
	 */
	public static function sync(): array {
		$result = [ 'created' => [], 'renamed' => [], 'unchanged' => [], 'extra' => [], 'errors' => [] ];

		if ( ! taxonomy_exists( Taxonomies::TOPIC ) ) {
			$result['errors'][] = 'The topic taxonomy is not registered.';
			return $result;
		}

		foreach ( self::all() as $slug => $name ) {
			$term = get_term_by( 'slug', $slug, Taxonomies::TOPIC );

			if ( $term instanceof \WP_Term ) {
				if ( $term->name === $name ) {
					$result['unchanged'][] = $slug;
					continue;
				}

				$updated = wp_update_term( $term->term_id, Taxonomies::TOPIC, [ 'name' => $name ] );

				if ( is_wp_error( $updated ) ) {
					$result['errors'][] = $slug . ': ' . $updated->get_error_message();
				} else {
					$result['renamed'][] = $slug;
				}
				continue;
			}

			$inserted = wp_insert_term( $name, Taxonomies::TOPIC, [ 'slug' => $slug ] );

			if ( is_wp_error( $inserted ) ) {
				$result['errors'][] = $slug . ': ' . $inserted->get_error_message();
			} else {
				$result['created'][] = $slug;
			}
		}

		$present = get_terms( [ 'taxonomy' => Taxonomies::TOPIC, 'hide_empty' => false, 'fields' => 'slugs' ] );

		if ( is_array( $present ) ) {
			$result['extra'] = array_values( array_diff( $present, array_keys( self::all() ) ) );
		}

		return $result;
	}

	/**
	 * Once per list version, on `init` after the taxonomy is registered.
	 *
	 * The version is stamped only when the sync had no errors, so a failed
	 * run is retried on the next load rather than silently marked done.
	 */
	public static function maybe_sync(): void {
		if ( (int) get_option( self::OPTION, 0 ) === self::LIST_VERSION ) {
			return;
		}

		$result = self::sync();

		if ( [] === $result['errors'] ) {
			update_option( self::OPTION, self::LIST_VERSION, false );
		}
	}
}
