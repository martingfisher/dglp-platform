<?php
/**
 * One date a repeating event runs on.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

final readonly class Occurrence {

	public function __construct(
		public DateTimeImmutable $start,
		public ?DateTimeImmutable $end,
	) {}

	/** The calendar date, Y-m-d, in the rule's timezone. */
	public function date(): string {
		return $this->start->format( 'Y-m-d' );
	}

	/** Wall-clock start, Y-m-d H:i:s, the same convention as the stored fields. */
	public function wall(): string {
		return $this->start->format( 'Y-m-d H:i:s' );
	}

	/** When it is over: the end, or the start when there is no end. */
	public function finish(): DateTimeImmutable {
		return $this->end ?? $this->start;
	}
}
