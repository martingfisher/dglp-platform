<?php
/**
 * Grant fields.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema\Types;

use DGL\Schema\Field;
use DGL\Schema\TypeDefinition;

/**
 * Grants: amount, deadline and eligibility, per the dashboard tile.
 *
 * The deadline is required rather than optional, because a funding listing with
 * no closing date never leaves the site and the archive fills with dead money.
 */
final class Grant implements TypeDefinition {

	/**
	 * @return Field[]
	 */
	public static function fields(): array {
		return [
			new Field(
				key: 'funder',
				label: __( 'Who is offering the funding?', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				max_length: 120,
				in_digest: true,
			),
			new Field(
				key: 'amount_min',
				label: __( 'Smallest award', 'dgl-platform' ),
				type: Field::MONEY,
				help: __( 'In pounds. Leave blank if there is no minimum.', 'dgl-platform' ),
				in_digest: true,
			),
			new Field(
				key: 'amount_max',
				label: __( 'Largest award', 'dgl-platform' ),
				type: Field::MONEY,
				in_digest: true,
			),
			new Field(
				key: 'deadline',
				label: __( 'Application deadline', 'dgl-platform' ),
				type: Field::DATE,
				required: true,
				help: __( 'The listing comes down automatically the day after this.', 'dgl-platform' ),
				in_digest: true,
			),
			new Field(
				key: 'rolling_deadline',
				label: __( 'Applications are considered on a rolling basis', 'dgl-platform' ),
				type: Field::CHECKBOX,
				help: __( 'Still give a date above. It can be reviewed and extended.', 'dgl-platform' ),
			),
			new Field(
				key: 'eligibility',
				label: __( 'Who can apply?', 'dgl-platform' ),
				type: Field::TEXTAREA,
				required: true,
				max_length: 1200,
			),
			new Field(
				key: 'how_to_apply',
				label: __( 'How to apply', 'dgl-platform' ),
				type: Field::TEXTAREA,
				required: true,
				max_length: 1200,
			),
			new Field(
				key: 'application_url',
				label: __( 'Application link', 'dgl-platform' ),
				type: Field::URL,
			),
		];
	}

	public static function expiry_field(): ?string {
		return 'deadline';
	}

	public static function expiry_fallback(): ?string {
		return null;
	}
}
