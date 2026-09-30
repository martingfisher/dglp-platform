<?php
/**
 * Venue fields: a building with spaces to hire.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema\Types;

use DGL\Org\Options;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Schema\TypeDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * A venue is one building at one address: what it is, where it is, how
 * you get in and what is there for everybody. The rooms themselves are
 * spaces, each its own item under the venue, so the questions here are
 * only the ones that are true of the whole building.
 *
 * The shared basics give it a name, a summary, a description and a main
 * photo; three more photos are asked here, each with its own words for
 * people who cannot see it. The contact step stays: enquiries go there.
 * A venue never expires on its own.
 */
final class Venue implements TypeDefinition {

	/** The kinds of building. */
	public const TYPES = [
		'community_centre' => 'Community centre',
		'church_hall'      => 'Church or faith hall',
		'meeting_rooms'    => 'Meeting rooms or offices',
		'arts_space'       => 'Arts or performance space',
		'sports'           => 'Sports or leisure',
		'outdoor'          => 'Outdoor or garden',
		'other'            => 'Other',
	];

	/** What the building offers people with access needs. */
	public const ACCESS = [
		'step_free'           => 'Step-free entrance',
		'accessible_toilet'   => 'Accessible toilet',
		'hearing_loop'        => 'Hearing loop',
		'changing_places'     => 'Changing Places toilet',
		'lift'                => 'Lift to upper floors',
		'blue_badge_parking'  => 'Blue badge parking',
	];

	/** What is there for every hirer. */
	public const FACILITIES = [
		'wifi'          => 'Free Wi-Fi',
		'kitchen'       => 'Kitchen use',
		'parking'       => 'Parking on site',
		'projector'     => 'Screen or projector',
		'tables_chairs' => 'Tables and chairs',
		'baby_changing' => 'Baby changing',
		'bike_racks'    => 'Bike racks',
	];

	/** How quickly the venue usually answers an enquiry. */
	public const REPLY = [
		'same_day' => 'Usually the same day',
		'two_days' => 'Within two working days',
		'week'     => 'Within a week',
	];

	/**
	 * @return Field[]
	 */
	public static function fields(): array {
		$photo = static fn( int $n ): array => [
			new Field(
				key: 'image_' . $n,
				label: sprintf(
					/* translators: %d: 2, 3 or 4. */
					__( 'Photo %d', 'dgl-platform' ),
					$n
				),
				type: Field::IMAGE,
				step: FieldRegistry::STEP_BASICS,
				help: __( 'Another view: the main hall, a meeting room, the outside. JPG or PNG, up to 20MB.', 'dgl-platform' ),
				in_csv: false,
			),
			new Field(
				key: 'image_' . $n . '_alt',
				label: sprintf(
					/* translators: %d: 2, 3 or 4. */
					__( 'What photo %d shows', 'dgl-platform' ),
					$n
				),
				type: Field::TEXT,
				step: FieldRegistry::STEP_BASICS,
				max_length: 150,
				public: false,
				in_csv: false,
				suggested_from: 'image_' . $n,
			),
		];

		return array_merge(
			$photo( 2 ),
			$photo( 3 ),
			$photo( 4 ),
			[
				new Field(
					key: 'venue_type',
					label: __( 'What kind of place is it?', 'dgl-platform' ),
					type: Field::SELECT,
					required: true,
					options: self::labelled( self::TYPES ),
				),
				new Field(
					key: 'address',
					label: __( 'Address', 'dgl-platform' ),
					type: Field::TEXT,
					required: true,
					max_length: 200,
					help: __( 'Street and area. The postcode goes in the next box.', 'dgl-platform' ),
				),
				new Field(
					key: 'postcode',
					label: __( 'Postcode', 'dgl-platform' ),
					type: Field::POSTCODE,
					required: true,
					help: __( 'Puts the venue on the map.', 'dgl-platform' ),
				),
				new Field(
					key: 'ward',
					label: __( 'Ward', 'dgl-platform' ),
					type: Field::SELECT,
					required: true,
					options: self::wards(),
					help: __( 'People searching can narrow by ward.', 'dgl-platform' ),
				),
				new Field(
					key: 'access',
					label: __( 'Access', 'dgl-platform' ),
					type: Field::CHOICES,
					options: self::labelled( self::ACCESS ),
					help: __( 'Tick everything that is true of the building.', 'dgl-platform' ),
				),
				new Field(
					key: 'facilities',
					label: __( 'Facilities for every hirer', 'dgl-platform' ),
					type: Field::CHOICES,
					options: self::labelled( self::FACILITIES ),
				),
				new Field(
					key: 'getting_there',
					label: __( 'Getting there', 'dgl-platform' ),
					type: Field::TEXTAREA,
					max_length: 600,
					help: __( 'Buses, parking, the nearest station, the door to use.', 'dgl-platform' ),
				),
				new Field(
					key: 'availability',
					label: __( 'Usually available', 'dgl-platform' ),
					type: Field::TEXTAREA,
					max_length: 400,
					help: __( 'For example "Weekday evenings from 6pm, Saturdays, Sundays after 1pm".', 'dgl-platform' ),
				),
				new Field(
					key: 'good_to_know',
					label: __( 'Good to know', 'dgl-platform' ),
					type: Field::TEXTAREA,
					max_length: 600,
					help: __( 'House rules, insurance, alcohol, noise, anything a hirer should know before they ask.', 'dgl-platform' ),
				),
				new Field(
					key: 'reply_time',
					label: __( 'How quickly do you usually reply?', 'dgl-platform' ),
					type: Field::SELECT,
					options: self::labelled( self::REPLY ),
					help: __( 'Shown next to the enquiry form, so people know what to expect.', 'dgl-platform' ),
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
		return true;
	}

	public static function has_topics(): bool {
		return false;
	}

	/**
	 * The Leeds wards, without the "Leeds-wide" entry the directory offers:
	 * a building is in one ward.
	 *
	 * @return array<string, string>
	 */
	public static function wards(): array {
		$wards = Options::wards();
		unset( $wards['leeds_wide'] );

		return $wards;
	}

	/**
	 * Translate a constant list at call time, so the labels pass through
	 * the text domain without the constants having to.
	 *
	 * @param array<string, string> $list
	 * @return array<string, string>
	 */
	public static function labelled( array $list ): array {
		$out = [];

		foreach ( $list as $key => $label ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the strings are the class constants above.
			$out[ $key ] = __( $label, 'dgl-platform' );
		}

		return $out;
	}
}
