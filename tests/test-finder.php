<?php
/**
 * The search dialog: the layout stripper and the kinds to look in.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Finder;

Harness::group( 'Finder: the theme search box is dropped from the header layout, nothing else' );

$layout = [
	'current_section' => 'type-1',
	'sections'        => [
		[
			'id'    => 'type-1',
			'items' => [
				[ 'id' => 'logo', 'values' => [ 'x' => 1 ] ],
				[ 'id' => 'search-input', 'values' => [ 'sb_radius' => 30 ] ],
			],
			'desktop' => [
				[
					'id'         => 'top-row',
					'placements' => [
						[ 'id' => 'start', 'items' => [ 'button', 'button~x' ] ],
						[ 'id' => 'end', 'items' => [ 'search-input', 'menu-secondary', 'account' ] ],
					],
				],
			],
			'mobile' => [
				[ 'id' => 'offcanvas', 'placements' => [ [ 'id' => 'start', 'items' => [ 'mobile-menu', 'search-input' ] ] ] ],
			],
		],
	],
];

$stripped = Finder::strip( $layout, 'search-input' );
Harness::assert_same( [ 'menu-secondary', 'account' ], $stripped['sections'][0]['desktop'][0]['placements'][1]['items'], 'the element leaves the desktop placement and the rest keep their order' );
Harness::assert_same( [ 'button', 'button~x' ], $stripped['sections'][0]['desktop'][0]['placements'][0]['items'], 'a placement without it is untouched' );
Harness::assert_same( [ 'mobile-menu' ], $stripped['sections'][0]['mobile'][0]['placements'][0]['items'], 'and from the phone drawer' );
Harness::assert_same( 2, count( $stripped['sections'][0]['items'] ), 'the element\'s own settings stay (an array with an id, not a placement string)' );
Harness::assert_same( 'search-input', $stripped['sections'][0]['items'][1]['id'], 'so switching the plugin off brings the box straight back' );
Harness::assert_same( $stripped, Finder::strip( $stripped, 'search-input' ), 'stripping twice changes nothing more' );
Harness::assert_same( $layout, Finder::strip( $layout, 'nothing-here' ), 'an id that is not placed anywhere leaves the layout as it was' );
Harness::assert_same( 'type-1', Finder::without_search_box( $layout )['current_section'], 'the filter takes the whole option' );
Harness::assert_same( 'not an array', Finder::without_search_box( 'not an array' ), 'and passes anything else through' );

Harness::group( 'Finder: the kinds to look in' );

$kinds = Finder::categories();
Harness::assert_same( [ '', 'events', 'news', 'training', 'spaces', 'organisations' ], array_keys( $kinds ), 'everything first, then events, news, training, spaces to hire, organisations' );
Harness::assert_same( 'All', $kinds[''], 'the first is "All"' );
Harness::assert_same( 'Spaces to hire', $kinds['spaces'], 'spaces carry the public name' );

$chips = Finder::chips( $kinds, 'news', 'k' );
Harness::assert_same( 6, substr_count( $chips, '<input type="radio" name="type"' ), 'six radios named for the results page\'s parameter' );
Harness::assert_same( 1, substr_count( $chips, ' checked' ), 'one is checked' );
Harness::assert_same( true, str_contains( $chips, 'id="k-news" value="news" checked' ), 'the chosen one' );
Harness::assert_same( true, str_contains( $chips, 'id="k-all" value=""' ), 'and "All" posts an empty type, which the results page reads as everything' );
