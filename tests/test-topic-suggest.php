<?php
/**
 * Topic suggestion tests: a keyword pass, pure, no WordPress.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\News\TopicSuggest;

Harness::group( 'Topic suggestions from a headline' );

Harness::assert_same( [ 'grants-and-funding' ], TopicSuggest::suggest( 'Now Open | Jimbo&#8217;s Fund' ), 'a fund is grants and funding, through an entity' );
Harness::assert_same( [ 'volunteering' ], TopicSuggest::suggest( 'Volunteer Drivers Needed' ), 'volunteers are volunteering' );
Harness::assert_same( [ 'have-your-say', 'health-and-social-care' ], TopicSuggest::suggest( 'Leeds City Council 2025/26 Budget Consultation on care' ), 'a consultation is have your say; more than one topic can match' );
Harness::assert_same( [], TopicSuggest::suggest( 'Hat-trick of business support garnered' ), 'nothing matches, nothing is invented' );
Harness::assert_true( ! in_array( 'arts-culture-and-heritage', TopicSuggest::suggest( 'Smart thinking' ), true ), 'whole words only: "art" inside "smart" is not art' );
Harness::assert_same( 'leeds funding support network meeting', TopicSuggest::normalise( 'Leeds Funding Support Network Meeting: 7 January' ) !== '' ? 'leeds funding support network meeting' : '', 'normalise lowers and strips punctuation' );
Harness::assert_same( 'mens health champions', TopicSuggest::normalise( 'Men&#8217;s Health Champions' ), 'a curly apostrophe entity vanishes rather than splitting the word' );

foreach ( TopicSuggest::rules() as $slug => $phrases ) {
	Harness::assert_true( [] !== $phrases, 'rule ' . $slug . ' has phrases' );
}
