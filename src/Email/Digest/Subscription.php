<?php
/**
 * One member's digest preferences.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

defined( 'ABSPATH' ) || exit;

/**
 * What a subscriber asked for.
 *
 * Consent is stored alongside the preference rather than inferred from the
 * preference existing. Under PECR a marketing digest needs a recorded opt-in,
 * and "there is a row in the table" is not a record of consent: the timestamp
 * and the source are.
 */
final readonly class Subscription {

	/**
	 * @param int      $user_id          WordPress user.
	 * @param string   $email            Where it goes.
	 * @param string[] $types            Post types wanted. Empty means none, not all.
	 * @param int[]    $topic_ids        Topic terms wanted. Empty means every topic.
	 * @param string   $frequency        A {@see Frequency} constant.
	 * @param ?string  $last_sent_at     UTC `Y-m-d H:i:s`, or null if never sent.
	 * @param string   $unsubscribe_token Single-use-per-subscriber opt-out token.
	 * @param ?string  $consent_at       UTC timestamp of the recorded opt-in.
	 * @param int      $org_id           The subscriber's own organisation.
	 * @param bool     $include_own_org  Whether to include their own organisation's items.
	 */
	public function __construct(
		public int $user_id,
		public string $email,
		public array $types = [],
		public array $topic_ids = [],
		public string $frequency = Frequency::WEEKLY,
		public ?string $last_sent_at = null,
		public string $unsubscribe_token = '',
		public ?string $consent_at = null,
		public int $org_id = 0,
		public bool $include_own_org = false,
	) {}

	/**
	 * Whether this subscription may lawfully be sent.
	 *
	 * No recorded consent means no send, however the row got there.
	 */
	public function has_consent(): bool {
		return null !== $this->consent_at && '' !== $this->consent_at;
	}

	/**
	 * Whether the subscriber actually asked for anything.
	 */
	public function wants_anything(): bool {
		return count( $this->types ) > 0;
	}

	/**
	 * Whether this subscription can be sent at all, before matching content.
	 */
	public function is_sendable(): bool {
		return $this->has_consent()
			&& $this->wants_anything()
			&& '' !== $this->email
			&& '' !== $this->unsubscribe_token
			&& Frequency::is_valid( $this->frequency );
	}
}
