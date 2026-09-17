<?php
/**
 * What an organisation's profile holds.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Schema\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The organisation profile, as fields.
 *
 * Same {@see Field} objects the content types use, so the profile form renders,
 * validates and saves through exactly the same code as a submission. One form
 * engine, not two.
 *
 * The important distinction here is not which fields exist but which of them a
 * member can change on their own. Wireframe 1i puts it plainly: "Changes to
 * your organisation name or logo are checked by the team before they show on
 * listings." Those two carry the organisation's identity across every listing it
 * has ever posted, so they go back through review. A phone number does not.
 */
final class Schema {

	/**
	 * Fields a member changes and sees take effect immediately.
	 *
	 * @return Field[]
	 */
	public static function open_fields(): array {
		return [
			new Field(
				key: 'org_number',
				label: __( 'Charity or company number', 'dgl-platform' ),
				type: Field::TEXT,
				step: 1,
				help: __( 'If you have one. It helps the team verify you.', 'dgl-platform' ),
				max_length: 40,
			),
			new Field(
				key: 'org_website',
				label: __( 'Website', 'dgl-platform' ),
				type: Field::URL,
				step: 1,
			),
			new Field(
				key: 'org_description',
				label: __( 'Short description', 'dgl-platform' ),
				type: Field::TEXTAREA,
				step: 1,
				help: __( 'Shown on your listings. Two or three sentences about what you do.', 'dgl-platform' ),
				max_length: 400,
			),
			new Field(
				key: 'org_email',
				label: __( 'Public contact email', 'dgl-platform' ),
				type: Field::EMAIL,
				required: true,
				step: 1,
				help: __( 'Shown publicly, so use an inbox somebody watches.', 'dgl-platform' ),
			),
			new Field(
				key: 'org_phone',
				label: __( 'Phone', 'dgl-platform' ),
				type: Field::TEL,
				step: 1,
			),

			/* Where you are. */
			new Field( key: 'org_address_1', label: __( 'Address line 1', 'dgl-platform' ), type: Field::TEXT, step: 2, max_length: 120 ),
			new Field( key: 'org_address_2', label: __( 'Address line 2', 'dgl-platform' ), type: Field::TEXT, step: 2, max_length: 120 ),
			new Field( key: 'org_city', label: __( 'Town or city', 'dgl-platform' ), type: Field::TEXT, step: 2, max_length: 80 ),
			new Field( key: 'org_postcode', label: __( 'Postcode', 'dgl-platform' ), type: Field::POSTCODE, step: 2 ),
			new Field(
				key: 'org_ward',
				label: __( 'Ward you are based in', 'dgl-platform' ),
				type: Field::SELECT,
				step: 2,
				options: Options::wards(),
				help: __( 'Choose Leeds-wide if you work across the city.', 'dgl-platform' ),
			),

			/* What you do. */
			new Field( key: 'org_type', label: __( 'Type of organisation', 'dgl-platform' ), type: Field::CHOICES, step: 3, options: Options::org_type() ),
			new Field( key: 'org_specialism', label: __( 'Areas of work', 'dgl-platform' ), type: Field::CHOICES, step: 3, options: Options::specialism() ),
			new Field( key: 'org_services', label: __( 'Services you provide', 'dgl-platform' ), type: Field::CHOICES, step: 3, options: Options::services() ),
			new Field( key: 'org_delivery', label: __( 'How you deliver them', 'dgl-platform' ), type: Field::CHOICES, step: 3, options: Options::delivery() ),

			/* Who you help. */
			new Field( key: 'org_service_users', label: __( 'Who you work with', 'dgl-platform' ), type: Field::CHOICES, step: 4, options: Options::service_users() ),
			new Field( key: 'org_accessibility', label: __( 'Accessibility at your premises', 'dgl-platform' ), type: Field::CHOICES, step: 4, options: Options::accessibility() ),

			/* Size and status. */
			new Field( key: 'org_legal_status', label: __( 'Legal status', 'dgl-platform' ), type: Field::SELECT, step: 5, options: Options::legal() ),
			new Field( key: 'org_staff', label: __( 'Paid staff', 'dgl-platform' ), type: Field::SELECT, step: 5, options: Options::sizes() ),
			new Field( key: 'org_volunteers', label: __( 'Volunteers, roughly', 'dgl-platform' ), type: Field::SELECT, step: 5, options: Options::sizes() ),
			new Field( key: 'org_accreditations', label: __( 'Accreditations', 'dgl-platform' ), type: Field::CHOICES, step: 5, options: Options::accreditations() ),
		];
	}

	/**
	 * Headings for the profile form, keyed by the step number the fields
	 * carry. Twenty-odd controls in one column need signposts; these are
	 * them.
	 *
	 * @return array<int, string>
	 */
	public static function sections(): array {
		return [
			1 => __( 'About your organisation', 'dgl-platform' ),
			2 => __( 'Where you are', 'dgl-platform' ),
			3 => __( 'What you do', 'dgl-platform' ),
			4 => __( 'Who you help', 'dgl-platform' ),
			5 => __( 'Size and status', 'dgl-platform' ),
		];
	}

	/**
	 * Fields whose change waits for the review team.
	 *
	 * @return Field[]
	 */
	public static function approval_fields(): array {
		return [
			new Field(
				key: 'org_name',
				label: __( 'Organisation name', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				step: 1,
				help: __( 'A name change goes back to the team before it shows on your listings.', 'dgl-platform' ),
				max_length: 120,
			),
			new Field(
				key: 'org_logo',
				label: __( 'Logo', 'dgl-platform' ),
				type: Field::IMAGE,
				step: 1,
				help: __( 'A square or landscape image, up to 20MB. A change goes back to the team.', 'dgl-platform' ),
				in_csv: false,
			),
		];
	}

	/**
	 * Every profile field, approval-gated ones first so the form reads in the
	 * order wireframe 1i shows: logo and name at the top.
	 *
	 * @return Field[]
	 */
	public static function fields(): array {
		return array_merge( self::approval_fields(), self::open_fields() );
	}

	/**
	 * Whether changing this field needs the team to agree.
	 */
	public static function needs_approval( string $key ): bool {
		foreach ( self::approval_fields() as $field ) {
			if ( $field->key === $key ) {
				return true;
			}
		}

		return false;
	}

	public static function find( string $key ): ?Field {
		foreach ( self::fields() as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * The member's own account, as fields.
	 *
	 * Their sign-in email is deliberately not here. Changing the address an
	 * account authenticates with is an account-takeover route if it can be done
	 * from a form somebody left logged in, so it belongs behind confirmation
	 * rather than beside a phone number.
	 *
	 * @return Field[]
	 */
	public static function person_fields(): array {
		return [
			new Field(
				key: 'person_name',
				label: __( 'Your name', 'dgl-platform' ),
				type: Field::TEXT,
				required: true,
				step: 1,
				help: __( 'Shown to the review team on anything you submit.', 'dgl-platform' ),
				max_length: 100,
			),
			new Field(
				key: 'person_job',
				label: __( 'Your role', 'dgl-platform' ),
				type: Field::TEXT,
				step: 1,
				help: __( 'Optional. For example "Volunteer coordinator".', 'dgl-platform' ),
				max_length: 100,
			),
			new Field(
				key: 'person_phone',
				label: __( 'Your phone', 'dgl-platform' ),
				type: Field::TEL,
				step: 1,
				help: __( 'Only the review team see this. It is not published.', 'dgl-platform' ),
			),
		];
	}
}
