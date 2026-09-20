<?php
/**
 * News fields.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema\Types;

use DGL\Schema\Field;
use DGL\Schema\TypeDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * News carries the least of its own, because the shared basics already cover a
 * headline, a story and an image. It never expires on its own: a story about
 * something that happened stays true.
 */
final class News implements TypeDefinition {

	/**
	 * @return Field[]
	 */
	public static function fields(): array {
		return [
			new Field(
				key: 'story_date',
				label: __( 'Date of the story', 'dgl-platform' ),
				type: Field::DATE,
				required: true,
				in_digest: true,
			),
			new Field(
				key: 'location',
				label: __( 'Location', 'dgl-platform' ),
				type: Field::TEXT,
				help: __( 'The part of Leeds this relates to, if it relates to one.', 'dgl-platform' ),
				max_length: 120,
			),
			new Field(
				key: 'source_url',
				label: __( 'Read more link', 'dgl-platform' ),
				type: Field::URL,
				help: __( 'A link to the full story on your own site, if there is one.', 'dgl-platform' ),
			),
		];
	}

	public static function expiry_field(): ?string {
		return null;
	}

	public static function expiry_fallback(): ?string {
		return null;
	}
}
