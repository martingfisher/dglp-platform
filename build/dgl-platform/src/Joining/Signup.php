<?php
/**
 * One sign-up, as read from the table.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

defined( 'ABSPATH' ) || exit;

final class Signup {

	/** Link sent, address not yet proven. */
	public const UNVERIFIED = 'unverified';
	/** Address proven, account not yet made. */
	public const VERIFIED = 'verified';
	/** Account made, straight in by domain match. */
	public const JOINED = 'joined';
	/** Account made, new organisation waiting on the team. */
	public const AWAITING = 'awaiting';
	public const APPROVED = 'approved';
	public const REFUSED = 'refused';
	/** A newer sign-up for the same address replaced this one. */
	public const SUPERSEDED = 'superseded';

	/**
	 * @param array<string, mixed> $new_org_details
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $email,
		public readonly string $domain,
		public readonly string $state,
		public readonly int $org_id,
		public readonly int $user_id,
		public readonly string $new_org_name,
		public readonly array $new_org_details,
		public readonly string $reason,
		public readonly string $created_at,
		public readonly string $expires_at,
		public readonly ?string $verified_at,
		public readonly ?string $completed_at,
		public readonly ?string $decided_at,
		public readonly int $decided_by,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$details = json_decode( (string) ( $row['new_org_details'] ?? '' ), true );

		return new self(
			(int) $row['id'],
			(string) $row['email'],
			(string) $row['domain'],
			(string) $row['state'],
			(int) $row['org_id'],
			(int) $row['user_id'],
			(string) $row['new_org_name'],
			is_array( $details ) ? $details : [],
			(string) $row['reason'],
			(string) $row['created_at'],
			(string) $row['expires_at'],
			$row['verified_at'] ?? null,
			$row['completed_at'] ?? null,
			$row['decided_at'] ?? null,
			(int) $row['decided_by'],
		);
	}

	public function is_expired( string $now ): bool {
		return $this->expires_at < $now;
	}
}
