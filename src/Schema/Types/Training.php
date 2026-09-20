<?php
/**
 * Training fields.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema\Types;

use DGL\Schema\Field;
use DGL\Schema\TypeDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Training: provider, cost, dates and who it is for, per the dashboard tile.
 */
final class Training implements TypeDefinition {

	/**
	 * @return Field[]
	 */
	public static function fields(): array {
		return [
			new Field(
				key: 'provider',
				label: __( 'Training provider', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 120,
				in_digest: true,
			),
			new Field(
				key: 'start_date',
				label: __( 'Start date', 'dgl-platform' ),
				type: Field::DATE,
				required: true,
				in_digest: true,
			),
			new Field(
				key: 'end_date',
				label: __( 'End date', 'dgl-platform' ),
				type: Field::DATE,
				help: __( 'Leave blank for a one-day course.', 'dgl-platform' ),
			),
			new Field(
				key: 'delivery',
				label: __( 'How is it delivered?', 'dgl-platform' ),
				type: Field::SELECT,
				required: true,
				options: [
					'in_person' => __( 'In person', 'dgl-platform' ),
					'online'    => __( 'Online', 'dgl-platform' ),
					'blended'   => __( 'Blended', 'dgl-platform' ),
				],
				in_digest: true,
			),
			new Field(
				key: 'location',
				label: __( 'Location', 'dgl-platform' ),
				type: Field::TEXT,
				help: __( 'Where it is held. Leave blank if it is online only.', 'dgl-platform' ),
				max_length: 200,
			),
			new Field(
				key: 'postcode',
				label: __( 'Postcode', 'dgl-platform' ),
				type: Field::POSTCODE,
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
				max_length: 120,
				depends_on: [ 'field' => 'cost', 'value' => [ 'paid', 'donation' ] ],
			),
			new Field(
				key: 'who_for',
				label: __( 'Who is it for?', 'dgl-platform' ),
				type: Field::TEXTAREA,
				required: true,
				help: __( 'Be specific. Someone reading this should know in one line whether it is for them.', 'dgl-platform' ),
				max_length: 600,
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
				max_length: 600,
			),
		];
	}

	public static function expiry_field(): ?string {
		return 'end_date';
	}

	public static function expiry_fallback(): ?string {
		return 'start_date';
	}
}
