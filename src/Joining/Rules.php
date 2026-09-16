<?php
/**
 * The decisions joining makes, with no WordPress in them.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

use DGL\Access\UserContext;

defined( 'ABSPATH' ) || exit;

final class Rules {

	/** How long a verification link works. Two days covers a weekend. */
	public const LINK_HOURS = 48;

	public const OUTCOME_MATCH = 'match';
	public const OUTCOME_NEW   = 'new';

	/**
	 * What a proven address is offered.
	 *
	 * @param int[] $matched_org_ids Organisations recording the address's domain.
	 */
	public static function outcome( string $email, array $matched_org_ids ): string {
		return Domains::can_match( $email ) && [] !== $matched_org_ids ? self::OUTCOME_MATCH : self::OUTCOME_NEW;
	}

	/**
	 * The first person into an organisation runs it; everyone after can post.
	 *
	 * An organisation loaded from DGLP's list has nobody in it. If the first
	 * person to arrive by domain were only a contributor, nobody could invite
	 * a colleague or change the details, and the organisation would be
	 * managed by nobody. The fifth person from a big charity does not get to
	 * remove the first four.
	 */
	public static function role_for( int $existing_members ): string {
		return 0 === $existing_members ? UserContext::ORG_OWNER : UserContext::ORG_CONTRIBUTOR;
	}

	/**
	 * Whether a sign-up can still be acted on from its link.
	 *
	 * @return string '' if usable, otherwise a reason a person can read.
	 */
	public static function link_problem( Signup $signup, string $now ): string {
		if ( Signup::SUPERSEDED === $signup->state ) {
			return 'A newer link was sent to this address. Use the most recent email, or start again.';
		}

		if ( in_array( $signup->state, [ Signup::JOINED, Signup::AWAITING, Signup::APPROVED, Signup::REFUSED ], true ) ) {
			return 'This link has already been used. If you have an account, sign in.';
		}

		if ( $signup->is_expired( $now ) ) {
			return 'This link has expired. Start again and a new one will be sent.';
		}

		return '';
	}

	/**
	 * A new organisation needs a name that is not a duplicate of one on the
	 * list, or the second "Leeds Mind" is how the list stops being one.
	 *
	 * @param string[] $existing_names Titles already on the list, any case.
	 */
	public static function org_name_problem( string $name, array $existing_names ): string {
		$name = trim( preg_replace( '/\s+/', ' ', $name ) ?? '' );

		if ( mb_strlen( $name ) < 3 ) {
			return 'Give the organisation its full name.';
		}

		foreach ( $existing_names as $existing ) {
			if ( 0 === strcasecmp( trim( $existing ), $name ) ) {
				return 'An organisation with that name is already on the list. If it is yours, you need an email address on its domain, or an invitation from somebody there.';
			}
		}

		return '';
	}
}
