<?php
/**
 * Volunteering fields.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema\Types;

use DGL\Schema\Field;
use DGL\Schema\TypeDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Volunteering: role, commitment, location and contact, per the dashboard tile.
 *
 * Whether a DBS check is needed is asked up front because it is the question
 * that most often stops an application late, and a would-be volunteer deserves
 * to know before they invest in the process.
 */
final class Volunteering implements TypeDefinition {

	/**
	 * @return Field[]
	 */
	public static function fields(): array {
		return [
			new Field(
				key: 'role_title',
				label: __( 'Role title', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 120,
				in_digest: true,
			),
			new Field(
				key: 'commitment',
				label: __( 'Time commitment', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				help: __( 'For example, one morning a week for six months.', 'dgl-platform' ),
				max_length: 160,
				in_digest: true,
			),
			new Field(
				key: 'location',
				label: __( 'Location', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 200,
				in_digest: true,
			),
			new Field(
				key: 'postcode',
				label: __( 'Postcode', 'dgl-platform' ),
				type: Field::POSTCODE,
			),
			new Field(
				key: 'arrangement',
				label: __( 'Where is it carried out?', 'dgl-platform' ),
				type: Field::SELECT,
				required: true,
				options: [
					'on_site' => __( 'On site', 'dgl-platform' ),
					'remote'  => __( 'Remote', 'dgl-platform' ),
					'hybrid'  => __( 'A mix of both', 'dgl-platform' ),
				],
				in_digest: true,
			),
			new Field(
				key: 'dbs_required',
				label: __( 'A DBS check is needed', 'dgl-platform' ),
				type: Field::CHECKBOX,
			),
			new Field(
				key: 'expenses_paid',
				label: __( 'Expenses are paid', 'dgl-platform' ),
				type: Field::CHECKBOX,
			),
			new Field(
				key: 'start_date',
				label: __( 'Start date', 'dgl-platform' ),
				type: Field::DATE,
				help: __( 'Leave blank if the role is open ended.', 'dgl-platform' ),
			),
			new Field(
				key: 'closing_date',
				label: __( 'Closing date for applications', 'dgl-platform' ),
				type: Field::DATE,
				required: true,
				help: __( 'The listing comes down automatically the day after this.', 'dgl-platform' ),
			),
			new Field(
				key: 'how_to_apply',
				label: __( 'How to apply', 'dgl-platform' ),
				type: Field::TEXTAREA,
				required: true,
				max_length: 1200,
			),
		];
	}

	public static function expiry_field(): ?string {
		return 'closing_date';
	}

	public static function expiry_fallback(): ?string {
		return null;
	}
}
