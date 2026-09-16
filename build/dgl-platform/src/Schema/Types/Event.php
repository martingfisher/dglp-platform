<?php
/**
 * Event fields.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema\Types;

use DGL\Schema\Field;
use DGL\Schema\TypeDefinition;

/**
 * Events, as wireframe 1f lays them out.
 *
 * The accessibility note is prominent on purpose. The wireframe has an admin
 * asking for one, and the help text says admins often do, so making a member
 * scroll past it invites the round trip the workflow is meant to avoid.
 */
final class Event implements TypeDefinition {

	/**
	 * @return Field[]
	 */
	public static function fields(): array {
		return [
			new Field(
				key: 'start_datetime',
				label: __( 'Start date and time', 'dgl-platform' ),
				type: Field::DATETIME,
				required: true,
				in_digest: true,
			),
			new Field(
				key: 'end_datetime',
				label: __( 'End date and time', 'dgl-platform' ),
				type: Field::DATETIME,
				help: __( 'The event comes off the listings automatically once this passes.', 'dgl-platform' ),
			),
			new Field(
				key: 'is_recurring',
				label: __( 'This event repeats', 'dgl-platform' ),
				type: Field::CHECKBOX,
			),
			new Field(
				key: 'recurrence_note',
				label: __( 'How often does it repeat?', 'dgl-platform' ),
				type: Field::TEXT,
				help: __( 'For example, every Tuesday at 13:00.', 'dgl-platform' ),
				max_length: 120,
				depends_on: [ 'field' => 'is_recurring', 'value' => true ],
			),
			new Field(
				key: 'venue_name',
				label: __( 'Venue name', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 120,
				in_digest: true,
			),
			new Field(
				key: 'address',
				label: __( 'Address', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 200,
			),
			new Field(
				key: 'postcode',
				label: __( 'Postcode', 'dgl-platform' ),
				type: Field::POSTCODE,
				required: true,
			),
			new Field(
				key: 'cost',
				label: __( 'Cost', 'dgl-platform' ),
				type: Field::SELECT,
				required: true,
				options: [
					'free'     => __( 'Free', 'dgl-platform' ),
					'paid'     => __( 'Paid', 'dgl-platform' ),
					'donation' => __( 'Donation', 'dgl-platform' ),
				],
				in_digest: true,
			),
			new Field(
				key: 'cost_detail',
				label: __( 'Cost detail', 'dgl-platform' ),
				type: Field::TEXT,
				help: __( 'For example, £5 waged, £2 unwaged.', 'dgl-platform' ),
				max_length: 120,
				depends_on: [ 'field' => 'cost', 'value' => [ 'paid', 'donation' ] ],
			),
			new Field(
				key: 'capacity',
				label: __( 'Capacity', 'dgl-platform' ),
				type: Field::NUMBER,
				// A planning note for DGLP, not a public fact. Printing
				// "Capacity: 12" turns it into a scarcity claim the
				// organiser never made.
				public: false,
			),
			new Field(
				key: 'booking_url',
				label: __( 'Booking link', 'dgl-platform' ),
				type: Field::URL,
			),
			new Field(
				key: 'accessibility',
				label: __( 'Accessibility notes', 'dgl-platform' ),
				type: Field::TEXTAREA,
				help: __( 'Step-free access, hearing loop, quiet space. Reviewers ask for this often, so filling it in now usually saves a round trip.', 'dgl-platform' ),
				max_length: 600,
			),
		];
	}

	public static function expiry_field(): ?string {
		return 'end_datetime';
	}

	public static function expiry_fallback(): ?string {
		return 'start_datetime';
	}
}
