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
				schedule: true,
			),
			new Field(
				key: 'end_datetime',
				label: __( 'End date and time', 'dgl-platform' ),
				type: Field::DATETIME,
				help: __( 'A one-off event comes off the listings once this passes. For a repeating event it is the finish time of each session.', 'dgl-platform' ),
				schedule: true,
			),
			/*
			 * The repeat rule. Public is false because the public page renders
			 * the schedule itself, in words, with the next dates; a raw rule
			 * in the facts card would say the same thing worse.
			 */
			new Field(
				key: 'repeat',
				label: __( 'Repeats', 'dgl-platform' ),
				type: Field::REPEAT,
				public: false,
				in_csv: false,
				schedule: true,
			),
			new Field(
				key: 'recurrence_note',
				label: __( 'Anything else about the timing', 'dgl-platform' ),
				type: Field::TEXT,
				help: __( 'For example, doors open at 12:45. Leave blank if the schedule above says it all.', 'dgl-platform' ),
				max_length: 120,
			),
			/*
			 * Where it happens decides what else is asked. An online event
			 * has no venue, so the venue fields only appear, and are only
			 * required, when there is somewhere to go.
			 */
			new Field(
				key: 'format',
				label: __( 'Where it happens', 'dgl-platform' ),
				type: Field::SELECT,
				required: true,
				options: [
					'in_person' => __( 'In person', 'dgl-platform' ),
					'online'    => __( 'Online', 'dgl-platform' ),
					'hybrid'    => __( 'In person and online', 'dgl-platform' ),
				],
				in_digest: true,
			),
			new Field(
				key: 'venue_name',
				label: __( 'Venue name', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 120,
				in_digest: true,
				depends_on: [ 'field' => 'format', 'value' => [ 'in_person', 'hybrid' ] ],
			),
			new Field(
				key: 'address',
				label: __( 'Address', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 200,
				depends_on: [ 'field' => 'format', 'value' => [ 'in_person', 'hybrid' ] ],
			),
			new Field(
				key: 'postcode',
				label: __( 'Postcode', 'dgl-platform' ),
				type: Field::POSTCODE,
				required: true,
				depends_on: [ 'field' => 'format', 'value' => [ 'in_person', 'hybrid' ] ],
			),
			new Field(
				key: 'online_url',
				label: __( 'Link to join online', 'dgl-platform' ),
				type: Field::URL,
				help: __( 'If people get the link after booking, leave this blank and add the booking link below.', 'dgl-platform' ),
				depends_on: [ 'field' => 'format', 'value' => [ 'online', 'hybrid' ] ],
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
