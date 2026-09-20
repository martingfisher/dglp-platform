<?php
/**
 * Contract for a content type's own fields.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * What makes each of the five types different.
 *
 * Steps 1 and 3 of the wizard are the same everywhere, so they live in
 * {@see FieldRegistry}. A type definition supplies step 2, the part the
 * wireframe labels "fields specific to events", and says which date, if any,
 * takes the item off the listings.
 */
interface TypeDefinition {

	/**
	 * Step 2 fields, in display order.
	 *
	 * @return Field[]
	 */
	public static function fields(): array;

	/**
	 * The field key whose date removes the item from the listings, or null when
	 * the type never expires on its own.
	 */
	public static function expiry_field(): ?string;

	/**
	 * Fallback expiry field, used when the primary is empty. An event with a
	 * start but no end still has to come down.
	 */
	public static function expiry_fallback(): ?string;
}
