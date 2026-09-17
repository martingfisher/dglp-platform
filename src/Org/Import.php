<?php
/**
 * Turning one row of Forum Central's member export into organisation values.
 *
 * Pure: no WordPress calls, so the mapping is unit-tested on its own and the
 * command that writes it is thin. Everything here is about one CSV layout,
 * the one supplied on 17 September 2026; a different export is a different
 * mapping, not a switch inside this one.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Joining\Domains;

defined( 'ABSPATH' ) || exit;

final class Import {

	/** Columns the mapping reads. Anything else in the file is ignored. */
	public const NAME       = 'Organisation Name';
	public const EMAIL      = 'Email';
	public const PHONE      = 'Phone';
	public const WEBSITE    = 'Website';
	public const STREET     = 'Street Address';
	public const SUPP_1     = 'Supplemental Address 1';
	public const SUPP_2     = 'Supplemental Address 2';
	public const POSTCODE   = 'Postal Code';
	public const LAT        = 'Latitude';
	public const LNG        = 'Longitude';
	public const DESC       = 'Short Description of Organisation';
	public const DESC_2     = 'Short description of services delivered';
	public const ORG_TYPE   = 'Organisation Type';
	public const ACCESS     = 'Accessibility Provision';
	public const ACCRED     = 'Accreditations';
	public const WARD       = 'Ward Organisation is based in';
	public const SPECIALISM = 'FC specialism (relevant to organisation)';
	public const USERS      = 'General Service Users';
	public const SERVICES   = 'General Service Provision';
	public const DELIVERY   = 'General Service Delivery Type';
	public const LEGAL      = 'Legal Status';
	public const NUMBER     = 'Charity/Company Number';
	public const STAFF      = 'Size - Number of Paid Staff';
	public const VOLUNTEERS = 'Size - Number of Volunteers (approx)';
	public const PERMISSION = 'Permission to publish online';
	public const VOLITION   = 'Volition Member Status';
	public const LOPF       = 'LOPF Membership Status';
	public const FC_ID      = 'Contact ID';
	public const CITY       = 'City';
	public const SUBTYPE    = 'Contact Subtype';

	/**
	 * The columns a file must have to be this export.
	 *
	 * @return string[]
	 */
	public static function required_columns(): array {
		return [ self::NAME, self::FC_ID, self::EMAIL ];
	}

	/**
	 * Map one row.
	 *
	 * @param array<string, string> $row Keyed by column heading.
	 * @return array{
	 *   name: string, fc_id: string, fields: array<string, mixed>, domains: string[],
	 *   facts: array<string, string>, unmatched: array<string, string[]>, problems: string[]
	 * }
	 */
	public static function map( array $row ): array {
		$get  = static fn( string $col ): string => trim( (string) ( $row[ $col ] ?? '' ) );
		$name = $get( self::NAME );

		$fields    = [];
		$unmatched = [];
		$problems  = [];

		if ( '' === $name ) {
			$problems[] = 'no organisation name';
		}

		// Plain text.
		$fields['org_phone']   = $get( self::PHONE );
		$fields['org_website'] = self::website( $get( self::WEBSITE ) );
		$fields['org_number']  = $get( self::NUMBER );

		$description = $get( self::DESC );
		if ( '' === $description ) {
			$description = $get( self::DESC_2 );
		}
		$fields['org_description'] = self::cut( $description, 650 );

		// The public contact address. Any address, including a Gmail one.
		$email = strtolower( $get( self::EMAIL ) );
		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$problems[] = 'email address does not look valid: ' . $email;
			$email      = '';
		}
		$fields['org_email'] = $email;

		// Address.
		$fields['org_address_1'] = $get( self::STREET );
		$fields['org_address_2'] = trim( implode( ', ', array_filter( [ $get( self::SUPP_1 ), $get( self::SUPP_2 ) ] ) ) );
		$fields['org_city']      = $get( self::CITY );
		$fields['org_postcode']  = strtoupper( $get( self::POSTCODE ) );

		// One of a list.
		foreach ( [ 'org_ward' => self::WARD, 'org_legal_status' => self::LEGAL, 'org_staff' => self::STAFF, 'org_volunteers' => self::VOLUNTEERS ] as $key => $col ) {
			$label = $get( $col );
			$found = '' === $label ? '' : Options::key_for( Options::all()[ $key ], $label );

			if ( '' !== $label && null === $found ) {
				$unmatched[ $col ][] = $label;
				$found               = '';
			}

			$fields[ $key ] = (string) $found;
		}

		// Several of a list.
		foreach ( [ 'org_type' => self::ORG_TYPE, 'org_specialism' => self::SPECIALISM, 'org_services' => self::SERVICES, 'org_delivery' => self::DELIVERY, 'org_service_users' => self::USERS, 'org_accessibility' => self::ACCESS, 'org_accreditations' => self::ACCRED ] as $key => $col ) {
			[ $keys, $left ] = self::pick_many( Options::all()[ $key ], $get( $col ) );
			$fields[ $key ]  = $keys;

			if ( [] !== $left ) {
				$unmatched[ $col ] = $left;
			}
		}

		// Facts carried over unchanged.
		$facts = [
			'fc_id'        => $get( self::FC_ID ),
			'volition'     => $get( self::VOLITION ),
			'lopf'         => $get( self::LOPF ),
			'permission'   => str_starts_with( strtolower( $get( self::PERMISSION ) ), 'i am happy' ) ? '1' : '',
			'age_friendly' => 'Age_and_Dementia_Friendly_Business' === $get( self::SUBTYPE ) ? '1' : '',
			'lat'          => self::coordinate( $get( self::LAT ), 90 ),
			'lng'          => self::coordinate( $get( self::LNG ), 180 ),
		];

		// The domain people can join on. Public providers never count.
		$domains = [];
		if ( '' !== $email ) {
			$domain = Domains::of( $email );
			if ( '' !== $domain && ! Domains::is_public( $domain ) ) {
				$domains[] = $domain;
			}
		}

		return [
			'name'      => $name,
			'fc_id'     => $facts['fc_id'],
			'fields'    => $fields,
			'domains'   => $domains,
			'facts'     => $facts,
			'unmatched' => $unmatched,
			'problems'  => $problems,
		];
	}

	/**
	 * Split a "several of a list" cell into option keys.
	 *
	 * The cells are comma separated, but so are some of the labels ("Gypsy,
	 * Roma and Traveller Communities"), so a plain split is wrong. Labels are
	 * matched longest first against the remaining text instead, and whatever
	 * is left over is reported rather than dropped silently.
	 *
	 * @param array<string, string> $options
	 * @return array{0: string[], 1: string[]} Keys in option order, then leftovers.
	 */
	public static function pick_many( array $options, string $cell ): array {
		$cell = trim( $cell );

		if ( '' === $cell ) {
			return [ [], [] ];
		}

		$labels = array_map( 'strval', $options );
		uasort( $labels, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		$rest  = $cell;
		$found = [];

		foreach ( $labels as $key => $label ) {
			$at = stripos( $rest, $label );

			while ( false !== $at ) {
				$found[] = (string) $key;
				$rest    = substr( $rest, 0, $at ) . '|' . substr( $rest, $at + strlen( $label ) );
				$at      = stripos( $rest, $label );
			}
		}

		$left = array_values( array_filter( array_map( 'trim', preg_split( '/[|,]+/', $rest ) ?: [] ) ) );

		// Option order, not match order, so two people ticking the same boxes store the same array.
		$ordered = [];
		foreach ( array_keys( $options ) as $key ) {
			if ( in_array( (string) $key, $found, true ) ) {
				$ordered[] = (string) $key;
			}
		}

		return [ $ordered, $left ];
	}

	/**
	 * Cut at a word boundary, not mid-word. The field allows 400 characters
	 * and a fifth of the file's descriptions are longer.
	 */
	private static function cut( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$short = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $short, ' ' );

		return rtrim( false !== $space && $space > $max / 2 ? mb_substr( $short, 0, $space ) : $short, " ,;:-" );
	}

	private static function website( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}

	private static function coordinate( string $value, int $limit ): string {
		if ( '' === $value || ! is_numeric( $value ) || abs( (float) $value ) > $limit ) {
			return '';
		}

		return (string) round( (float) $value, 6 );
	}
}
