<?php
/**
 * Space fields: one thing you can hire at a venue.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema\Types;

use DGL\Schema\Field;
use DGL\Schema\TypeDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * A space is a room, a hall, a kitchen, a garden or the whole building,
 * at one venue. The shared basics name it, describe it and give it a
 * photo; the questions here are the ones a hirer compares rooms on:
 * how many it holds in each layout, how big it is, what is in it, and
 * what it costs. The venue it belongs to is set when the space is
 * started, not asked here, and it takes the venue's contact.
 *
 * A rate is optional. No rate means "Price on request" on the page and
 * the venue sorts below priced ones in the results. A space never
 * expires on its own.
 */
final class Space implements TypeDefinition {

	/** The kinds of space. */
	public const TYPES = [
		'hall'           => 'Hall',
		'meeting_room'   => 'Meeting room',
		'studio'         => 'Studio',
		'kitchen'        => 'Kitchen',
		'outdoor'        => 'Outdoor space',
		'whole_building' => 'Whole building',
		'other'          => 'Other',
	];

	/** The layouts a capacity is quoted for, in the order venues quote them. */
	public const LAYOUTS = [
		'cap_theatre'   => 'Theatre',
		'cap_cabaret'   => 'Cabaret',
		'cap_boardroom' => 'Boardroom',
		'cap_standing'  => 'Standing',
	];

	/** What is in the space itself. */
	public const FACILITIES = [
		'stage'         => 'Stage',
		'pa'            => 'PA system',
		'sprung_floor'  => 'Sprung floor',
		'hearing_loop'  => 'Hearing loop',
		'screen'        => 'Screen or projector',
		'flipchart'     => 'Flipchart or whiteboard',
		'opens_outside' => 'Opens to outside space',
		'ovens'         => 'Ovens and hob',
		'dishwasher'    => 'Dishwasher',
	];

	/** What a rate is per. */
	public const UNITS = [
		'hour'    => 'an hour',
		'session' => 'a session',
		'day'     => 'a day',
	];

	/**
	 * @return Field[]
	 */
	public static function fields(): array {
		$caps = [];

		foreach ( self::LAYOUTS as $key => $label ) {
			$caps[] = new Field(
				key: $key,
				label: sprintf(
					/* translators: %s: a layout, e.g. Theatre. */
					__( 'People, %s layout', 'dgl-platform' ),
					// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the class constant above.
					__( $label, 'dgl-platform' )
				),
				type: Field::NUMBER,
				max_length: 5,
				help: 'cap_theatre' === $key ? __( 'Leave blank any layout the space is not used in.', 'dgl-platform' ) : '',
			);
		}

		return array_merge(
			[
				new Field(
					key: 'space_type',
					label: __( 'What kind of space is it?', 'dgl-platform' ),
					type: Field::SELECT,
					required: true,
					options: Venue::labelled( self::TYPES ),
					help: __( '"Whole building" is for hiring the lot at once. List the rooms separately as well if they can be hired on their own.', 'dgl-platform' ),
				),
			],
			$caps,
			[
				new Field(
					key: 'size_m2',
					label: __( 'Floor area in square metres', 'dgl-platform' ),
					type: Field::NUMBER,
					max_length: 5,
				),
				new Field(
					key: 'space_facilities',
					label: __( 'In this space', 'dgl-platform' ),
					type: Field::CHOICES,
					options: Venue::labelled( self::FACILITIES ),
				),
				new Field(
					key: 'rate',
					label: __( 'Rate in pounds', 'dgl-platform' ),
					type: Field::MONEY,
					help: __( 'Leave blank to show "Price on request". Enter 0 if it is free. Priced spaces come higher in the results.', 'dgl-platform' ),
				),
				new Field(
					key: 'rate_unit',
					label: __( 'Rate is per', 'dgl-platform' ),
					type: Field::SELECT,
					options: Venue::labelled( self::UNITS ),
					required_with: 'rate',
				),
				new Field(
					key: 'rate_note',
					label: __( 'About the rate', 'dgl-platform' ),
					type: Field::TEXT,
					max_length: 120,
					help: __( 'For example "Weekends only" or "Minimum two hours".', 'dgl-platform' ),
				),
			]
		);
	}

	public static function expiry_field(): ?string {
		return null;
	}

	public static function expiry_fallback(): ?string {
		return null;
	}

	public static function has_contact(): bool {
		return false;
	}

	public static function has_topics(): bool {
		return false;
	}
}
