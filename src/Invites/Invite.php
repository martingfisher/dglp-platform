<?php
/**
 * One invitation to join an organisation.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Invites;

defined( 'ABSPATH' ) || exit;

/**
 * An invitation as data.
 *
 * Note what is not here: a WordPress user ID for the person being invited.
 * An invite is an offer to an email address, and until somebody accepts it
 * there is no account. Creating the account up front leaves a login nobody
 * has ever used, with a password nobody chose, sitting in the user table of a
 * site whose whole access model is "which organisation does this person belong
 * to". Those accounts are what get found later and nobody can say who they are.
 *
 * The token is held hashed for the same reason a password reset key is. Anyone
 * who can read the table can otherwise mint a working join link for any
 * organisation, and the people who can read this table are not always the
 * people who should be able to add members to a charity's account.
 */
final readonly class Invite {

	/**
	 * @param int     $id          Row ID, 0 for one not yet stored.
	 * @param string  $email       Who it was sent to, already normalised.
	 * @param int     $org_id      Organisation post ID.
	 * @param string  $org_role    UserContext::ORG_OWNER or ORG_CONTRIBUTOR.
	 * @param int     $invited_by  User who sent it, 0 for the system.
	 * @param string  $token_hash  Hash of the token. Never the token itself.
	 * @param string  $created_at  UTC `Y-m-d H:i:s`.
	 * @param string  $expires_at  UTC `Y-m-d H:i:s`.
	 * @param ?string $accepted_at UTC `Y-m-d H:i:s`, or null.
	 * @param ?string $revoked_at  UTC `Y-m-d H:i:s`, or null.
	 * @param int     $accepted_by The account that took it up, 0 until then.
	 */
	public function __construct(
		public int $id,
		public string $email,
		public int $org_id,
		public string $org_role,
		public int $invited_by,
		public string $token_hash,
		public string $created_at,
		public string $expires_at,
		public ?string $accepted_at = null,
		public ?string $revoked_at = null,
		public int $accepted_by = 0,
	) {}

	/**
	 * Build one from a database row.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			email: (string) ( $row['email'] ?? '' ),
			org_id: (int) ( $row['org_id'] ?? 0 ),
			org_role: (string) ( $row['org_role'] ?? '' ),
			invited_by: (int) ( $row['invited_by'] ?? 0 ),
			token_hash: (string) ( $row['token_hash'] ?? '' ),
			created_at: (string) ( $row['created_at'] ?? '' ),
			expires_at: (string) ( $row['expires_at'] ?? '' ),
			accepted_at: self::nullable( $row['accepted_at'] ?? null ),
			revoked_at: self::nullable( $row['revoked_at'] ?? null ),
			accepted_by: (int) ( $row['accepted_by'] ?? 0 ),
		);
	}

	/**
	 * MySQL hands back the zero date and empty strings as well as NULL,
	 * and all three mean the same thing here.
	 */
	private static function nullable( mixed $value ): ?string {
		$value = (string) ( $value ?? '' );

		return ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) ? null : $value;
	}
}
