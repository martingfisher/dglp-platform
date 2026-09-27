<?php
/**
 * `wp dgl demo`: demo content for seeing the site render, and taking it away again.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Demo;

use DGL\Audit\Log;
use DGL\Content;
use DGL\Events\Cancel;
use DGL\Events\Series;
use DGL\Index\Sync;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Schema\Store;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Workflow\Pins;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Seven varied live events, or seven training listings, in one call, so
 * the public lists can be seen rendering without anybody filling in seven
 * forms. Every one carries a marker, so `--remove`
 * takes exactly these away and nothing else.
 */
final class Command {

	/** Meta on every seeded item. */
	public const MARKER = 'dgl_demo';

	public static function register(): void {
		WP_CLI::add_command( 'dgl demo', self::class );
	}

	/**
	 * Create seven demo events under an organisation, or remove them.
	 *
	 * ## OPTIONS
	 *
	 * [--org=<id>]
	 * : The organisation the events belong to. Required unless --remove.
	 *
	 * [--actor=<id>]
	 * : User to record the changes against. Default 0, the system.
	 *
	 * [--images]
	 * : Use the newest photos in the media library as the events' pictures.
	 *
	 * [--remove]
	 * : Delete every event this command made, whatever its state now.
	 *
	 * [--dry-run]
	 * : Say what would be made and stop.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl demo events --org=8438 --images
	 *     wp dgl demo events --remove
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function events( array $args, array $assoc ): void {
		if ( isset( $assoc['remove'] ) ) {
			$gone = self::remove();
			WP_CLI::success( sprintf( '%d demo event(s) removed.', count( $gone ) ) );
			return;
		}

		$org = (int) ( $assoc['org'] ?? 0 );

		if ( $org <= 0 || ! Org::exists( $org ) ) {
			WP_CLI::error( 'Give --org=<id>: an organisation that exists.' );
		}

		$actor  = (int) ( $assoc['actor'] ?? 0 );
		$images = isset( $assoc['images'] ) ? self::photos( 5 ) : [];

		if ( isset( $assoc['dry-run'] ) ) {
			$rows = [];

			foreach ( self::plan( $images ) as $i => $p ) {
				$rows[] = [ 'n' => $i + 1, 'title' => $p['title'], 'when' => $p['start'], 'format' => $p['format'], 'cost' => $p['cost'], 'picture' => $p['image'] > 0 ? (string) $p['image'] : 'none', 'note' => $p['note'] ];
			}

			\WP_CLI\Utils\format_items( 'table', $rows, [ 'n', 'title', 'when', 'format', 'cost', 'picture', 'note' ] );
			WP_CLI::success( 'Dry run, nothing written.' );
			return;
		}

		$made = self::seed( $org, $actor, $images );
		$rows = [];

		foreach ( $made as $id ) {
			$rows[] = [
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'when'   => (string) get_post_meta( $id, Meta::ITEM_NEXT_AT, true ),
				'format' => (string) get_post_meta( $id, 'dgl_format', true ),
				'cost'   => (string) get_post_meta( $id, 'dgl_cost', true ),
				'status' => (string) get_post_status( $id ) . ( Cancel::is_cancelled( $id ) ? ' (cancelled)' : '' ) . ( Pins::is_pinned( $id ) ? ' (featured)' : '' ),
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'title', 'when', 'format', 'cost', 'status' ] );
		WP_CLI::success( sprintf( '%d demo event(s) made for %s.', count( $made ), get_the_title( $org ) ) );
	}

	/**
	 * The seven, worked out from today so they never look stale.
	 *
	 * @param int[] $images Attachment ids to hand out to the events that want one, in order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function plan( array $images = [] ): array {
		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'today', $tz );
		$day   = static fn( int $days, string $time ): string => $today->modify( '+' . $days . ' days' )->format( 'Y-m-d' ) . ' ' . $time;
		$next  = static fn( string $weekday, string $time ): string => $today->modify( 'next ' . $weekday )->format( 'Y-m-d' ) . ' ' . $time;
		$pic   = static function () use ( &$images ): int {
			return (int) ( array_shift( $images ) ?? 0 );
		};

		return [
			[
				'title'    => 'Community coffee morning',
				'summary'  => 'Drop in for a cuppa, a biscuit and a chat. Everyone welcome, no need to book.',
				'body'     => '<p>Our coffee morning runs every Tuesday in the main hall. Come on your own or bring a friend; there is always somebody to talk to and a quiet corner if you would rather read the paper.</p><p>Tea, coffee and biscuits are free. We have step-free access, an accessible toilet and a hearing loop.</p>',
				'start'    => $next( 'tuesday', '10:00:00' ),
				'end'      => $next( 'tuesday', '12:00:00' ),
				'repeat'   => [ 'freq' => 'weekly', 'until' => $today->modify( '+3 months' )->format( 'Y-m-d' ), 'weekdays' => [ 2 ] ],
				'format'   => 'in_person',
				'venue'    => 'Armley Community Hub',
				'address'  => 'Town Street, Armley',
				'postcode' => 'LS12 1UP',
				'cost'     => 'free',
				'topics'   => [ 'health-and-social-care', 'communities-of-interest' ],
				'access'   => 'Step-free entrance, accessible toilet, hearing loop in the hall.',
				'image'    => $pic(),
				'note'     => 'weekly series',
			],
			[
				'title'    => 'Armley autumn fair',
				'summary'  => 'Stalls, food, music and a raffle in aid of the Hub. Bring the family.',
				'body'     => '<p>Our biggest day of the year. Forty stalls from local makers and community groups, hot food, live music from the Armley Brass Band and a raffle with prizes donated by shops along Town Street.</p><p>Entry is by donation. Every penny goes to keeping the Hub open through the winter.</p>',
				'start'    => $day( 12, '11:00:00' ),
				'end'      => $day( 12, '16:00:00' ),
				'repeat'   => [],
				'format'   => 'in_person',
				'venue'    => 'Armley Community Hub and Gotts Park',
				'address'  => 'Town Street, Armley',
				'postcode' => 'LS12 1UP',
				'cost'     => 'donation',
				'cost_detail' => 'Suggested £2 an adult, children free.',
				'topics'   => [ 'arts-culture-and-heritage', 'children-and-young-people' ],
				'image'    => $pic(),
				'pin'      => 14,
				'note'     => 'featured for 14 days',
			],
			[
				'title'    => 'Volunteer induction evening',
				'summary'  => 'New to volunteering with us? Meet the team, hear what we do and pick a role.',
				'body'     => '<p>An hour and a half for anyone who has signed up to volunteer, or is thinking about it. We cover what the Hub does, safeguarding basics, expenses, and the roles we need filling this season: befriending, the food pantry, the welcome desk and the garden.</p><p>Join us in the hall or online. The link is sent when you book.</p>',
				'start'    => $day( 5, '18:00:00' ),
				'end'      => $day( 5, '19:30:00' ),
				'repeat'   => [],
				'format'   => 'hybrid',
				'venue'    => 'Armley Community Hub',
				'address'  => 'Town Street, Armley',
				'postcode' => 'LS12 1UP',
				'online'   => 'https://meet.example.org/armley-induction',
				'cost'     => 'free',
				'booking'  => 'https://www.eventbrite.co.uk/',
				'topics'   => [ 'volunteering', 'leadership' ],
				'image'    => $pic(),
				'note'     => 'hybrid, booking link',
			],
			[
				'title'    => 'Managing your charity\'s money',
				'summary'  => 'A practical online workshop for treasurers and small-charity managers.',
				'body'     => '<p>Two hours online with a qualified charity accountant. Budgets that hold, restricted and unrestricted funds, what the Charity Commission wants to see, and how to read your own accounts without dread.</p><p>Bring your latest accounts if you have them. Bursary places are available for organisations with an income under £50,000: ask when you book.</p>',
				'start'    => $day( 20, '10:00:00' ),
				'end'      => $day( 20, '12:00:00' ),
				'repeat'   => [],
				'format'   => 'online',
				'online'   => 'https://meet.example.org/charity-money',
				'cost'     => 'paid',
				'cost_detail' => '£15, bursaries available.',
				'booking'  => 'https://www.eventbrite.co.uk/',
				'topics'   => [ 'grants-and-funding', 'leadership' ],
				'image'    => 0,
				'note'     => 'online, paid, no picture',
			],
			[
				'title'    => 'Wellbeing walk in Gotts Park',
				'summary'  => 'A gentle hour on level paths, with a brew at the end. All paces welcome.',
				'body'     => '<p>We meet at the park gates on Armley Ridge Road and take the flat loop past the mansion and the golf course. About an hour, with plenty of stops. Nobody is left behind.</p><p>Wear sensible shoes and bring a waterproof. We finish with tea at the Hub.</p>',
				'start'    => $day( 8, '10:30:00' ),
				'end'      => $day( 8, '11:30:00' ),
				'repeat'   => [],
				'format'   => 'in_person',
				'venue'    => 'Gotts Park gates',
				'address'  => 'Armley Ridge Road, Armley',
				'postcode' => 'LS12 2QX',
				'cost'     => 'free',
				'topics'   => [ 'health-and-social-care', 'environment-and-nature' ],
				'access'   => 'Level tarmac paths throughout, suitable for wheelchairs and pushchairs. Benches every few hundred metres.',
				'image'    => $pic(),
				'note'     => 'accessibility note',
			],
			[
				'title'    => 'Trustee recruitment open evening',
				'summary'  => 'Find out what our trustees do and whether it could be you. No experience needed.',
				'body'     => '<p>We are looking for three new trustees, and we would like at least one to be under 35 and one to live within a mile of the Hub. Come and hear from the current board about what the role involves, how much time it takes and what support you get.</p><p>Thirty places. Refreshments provided.</p>',
				'start'    => $day( 15, '17:30:00' ),
				'end'      => $day( 15, '19:00:00' ),
				'repeat'   => [],
				'format'   => 'in_person',
				'venue'    => 'Armley Community Hub',
				'address'  => 'Town Street, Armley',
				'postcode' => 'LS12 1UP',
				'cost'     => 'free',
				'capacity' => 30,
				'topics'   => [ 'leadership', 'community-power' ],
				'image'    => 0,
				'note'     => 'capacity, no picture',
			],
			[
				'title'    => 'Family craft afternoon',
				'summary'  => 'Make, paint and glue with the under-tens. Materials provided, grown-ups stay.',
				'body'     => '<p>Two hours of making things: autumn leaf prints, paper lanterns and a big shared collage for the hall wall. All materials provided. Children must be accompanied by an adult.</p>',
				'start'    => $day( 3, '14:00:00' ),
				'end'      => $day( 3, '16:00:00' ),
				'repeat'   => [],
				'format'   => 'in_person',
				'venue'    => 'Armley Community Hub',
				'address'  => 'Town Street, Armley',
				'postcode' => 'LS12 1UP',
				'cost'     => 'free',
				'topics'   => [ 'children-and-young-people', 'arts-culture-and-heritage' ],
				'image'    => $pic(),
				'cancel'   => 'Cancelled for the demo: the hall is double booked that afternoon.',
				'note'     => 'cancelled',
			],
		];
	}

	/**
	 * The seven training listings, dated from today.
	 *
	 * @param int[] $images
	 * @return array<int, array<string, mixed>>
	 */
	public static function plan_training( array $images = [] ): array {
		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'today', $tz );
		$day   = static fn( int $days ): string => $today->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		$pic   = static function () use ( &$images ): int {
			return (int) ( array_shift( $images ) ?? 0 );
		};
		$hub   = [ 'location' => 'Armley Community Hub, Town Street, Armley', 'postcode' => 'LS12 1UP' ];

		return [
			[
				'title'   => 'Safeguarding adults: level 1',
				'summary' => 'The one-day course every volunteer and front-line worker should have done. Certificate on the day.',
				'body'    => '<p>What safeguarding means, the kinds of harm to look out for, what to do if you are worried about someone, and how to record and report it. Taught through real cases from Leeds, with time for your own questions.</p><p>Meets the Leeds Safeguarding Adults Board level 1 standard. You get a certificate valid for three years.</p>',
				'fields'  => [ 'provider' => 'Leeds Safeguarding Adults Board', 'start_date' => $day( 6 ), 'delivery' => 'in_person', 'who_for' => 'Volunteers, trustees and staff in any role that brings them into contact with adults who may be at risk. No prior training needed.' ] + $hub,
				'cost'    => 'free',
				'topics'  => [ 'health-and-social-care', 'safeguarding' ],
				'access'  => 'Step-free venue, hearing loop, large-print handouts on request.',
				'image'   => $pic(),
				'note'    => 'one day, in person, free',
			],
			[
				'title'   => 'Introduction to fundraising for small groups',
				'summary' => 'Half a day online on where the money is and how to ask for it, for groups with no fundraiser.',
				'body'    => '<p>Trusts and foundations, community grants, individual giving and events: which suit a group your size, what funders actually read, and how to write a case for support in an afternoon. You leave with a one-page fundraising plan.</p>',
				'fields'  => [ 'provider' => 'Voluntary Action Leeds', 'start_date' => $day( 10 ), 'delivery' => 'online', 'who_for' => 'Anyone responsible for bringing money into a small charity or community group, especially if it is one job among many.' ],
				'cost'    => 'paid',
				'cost_detail' => '£25. Free for groups with an income under £10,000.',
				'booking' => 'https://www.eventbrite.co.uk/',
				'topics'  => [ 'grants-and-funding' ],
				'image'   => 0,
				'note'    => 'online, paid, no picture',
			],
			[
				'title'   => 'First aid at work (three days)',
				'summary' => 'The full HSE-approved course, three days in person, certificate valid three years.',
				'body'    => '<p>CPR and defibrillators, choking, bleeding, burns, fractures, seizures, and how to manage an incident until help arrives. Assessed on the last day. Lunch provided.</p><p>Run by a Leeds trainer with twenty years in the ambulance service.</p>',
				'fields'  => [ 'provider' => 'Yorkshire First Aid Training', 'start_date' => $day( 14 ), 'end_date' => $day( 16 ), 'delivery' => 'in_person', 'who_for' => 'Staff and volunteers who need a qualified first-aider certificate for their workplace or activity.' ] + $hub,
				'cost'    => 'paid',
				'cost_detail' => '£180 a person. Two places per organisation at £150.',
				'booking' => 'https://www.eventbrite.co.uk/',
				'topics'  => [ 'health-and-social-care' ],
				'image'   => $pic(),
				'note'    => 'three days, paid',
			],
			[
				'title'   => 'Mental health first aid',
				'summary' => 'Two days, blended: a morning online then a full day in the room. Funded places for Leeds groups.',
				'body'    => '<p>How to spot the signs of a mental health problem, how to start a conversation, and where to point somebody for help in Leeds. The online morning covers the ground; the day in the room is practice.</p><p>Places are funded by the Leeds Community Foundation, so the course is free to organisations in the partnership.</p>',
				'fields'  => [ 'provider' => 'Leeds Mind', 'start_date' => $day( 18 ), 'end_date' => $day( 25 ), 'delivery' => 'blended', 'who_for' => 'Anyone in a community role who wants to be a first point of contact for a colleague or member who is struggling.' ] + $hub,
				'cost'    => 'free',
				'topics'  => [ 'health-and-social-care', 'mental-health' ],
				'access'  => 'Breaks every hour, quiet room available.',
				'image'   => $pic(),
				'note'    => 'blended, two dates',
			],
			[
				'title'   => 'Trustee essentials: roles and responsibilities',
				'summary' => 'An evening online for new and would-be trustees: what the law expects and what the job is really like.',
				'body'    => '<p>The six duties of a trustee, what the Charity Commission needs from you, how to read a set of accounts, and how to be useful in a board meeting. Two current chairs answer questions in the second half.</p>',
				'fields'  => [ 'provider' => 'Doing Good Leeds Partnership', 'start_date' => $day( 9 ), 'delivery' => 'online', 'who_for' => 'New trustees, people thinking about becoming one, and chairs who want to induct a new board member.' ],
				'cost'    => 'free',
				'booking' => 'https://www.eventbrite.co.uk/',
				'topics'  => [ 'leadership' ],
				'image'   => 0,
				'note'    => 'online evening, no picture',
			],
			[
				'title'   => 'Using Canva for charity communications',
				'summary' => 'A hands-on afternoon making posters, social posts and a newsletter that look the part.',
				'body'    => '<p>Bring a laptop. We set up a free Canva account, build a brand kit from your logo and colours, and make three things you can use next week: an A4 poster, a set of social posts and a newsletter header. No design experience needed.</p>',
				'fields'  => [ 'provider' => 'Armley Community Hub', 'start_date' => $day( 21 ), 'delivery' => 'in_person', 'who_for' => 'Whoever does the posters, the Facebook page or the newsletter at your organisation.' ] + $hub,
				'cost'    => 'donation',
				'cost_detail' => 'Suggested £5 towards the room.',
				'topics'  => [ 'data-and-digital', 'arts-culture-and-heritage' ],
				'image'   => $pic(),
				'note'    => 'donation',
			],
			[
				'title'   => 'Volunteer management: recruit them and keep them',
				'summary' => 'A day on finding volunteers, giving them a good start and keeping them coming back.',
				'body'    => '<p>Writing a role people want, where to advertise in Leeds, the induction that makes the difference, expenses and insurance, and how to say thank you in ways that matter. Morning online, afternoon in the room.</p>',
				'fields'  => [ 'provider' => 'Voluntary Action Leeds', 'start_date' => $day( 28 ), 'delivery' => 'blended', 'who_for' => 'Anyone who looks after volunteers, paid or not, in an organisation of any size.' ] + $hub,
				'cost'    => 'paid',
				'cost_detail' => '£40. Bursaries available, ask when you book.',
				'booking' => 'https://www.eventbrite.co.uk/',
				'topics'  => [ 'volunteering', 'leadership' ],
				'image'   => $pic(),
				'note'    => 'blended, paid with bursaries',
			],
		];
	}

	/**
	 * Create seven demo training listings under an organisation, or remove them.
	 *
	 * ## OPTIONS
	 *
	 * [--org=<id>]
	 * : The organisation the listings belong to. Required unless --remove.
	 *
	 * [--actor=<id>]
	 * : User to record the changes against. Default 0, the system.
	 *
	 * [--images]
	 * : Use the newest photos in the media library as the listings' pictures.
	 *
	 * [--remove]
	 * : Delete every training listing this command made.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl demo training --org=8438 --images
	 *     wp dgl demo training --remove
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function training( array $args, array $assoc ): void {
		if ( isset( $assoc['remove'] ) ) {
			$gone = self::remove( PostTypes::TRAINING );
			WP_CLI::success( sprintf( '%d demo training listing(s) removed.', count( $gone ) ) );
			return;
		}

		$org = (int) ( $assoc['org'] ?? 0 );

		if ( $org <= 0 || ! Org::exists( $org ) ) {
			WP_CLI::error( 'Give --org=<id>: an organisation that exists.' );
		}

		$made = self::seed_training( $org, (int) ( $assoc['actor'] ?? 0 ), isset( $assoc['images'] ) ? self::photos( 5 ) : [] );
		$rows = [];

		foreach ( $made as $id ) {
			$rows[] = [
				'id'       => $id,
				'title'    => get_the_title( $id ),
				'starts'   => (string) get_post_meta( $id, 'dgl_start_date', true ),
				'delivery' => (string) get_post_meta( $id, 'dgl_delivery', true ),
				'cost'     => (string) get_post_meta( $id, 'dgl_cost', true ),
				'picture'  => (int) get_post_meta( $id, 'dgl_image', true ) > 0 ? 'yes' : 'none',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'title', 'starts', 'delivery', 'cost', 'picture' ] );
		WP_CLI::success( sprintf( '%d demo training listing(s) made for %s.', count( $made ), get_the_title( $org ) ) );
	}

	/**
	 * Make the seven. Returns their ids.
	 *
	 * @param int[] $images
	 * @return int[]
	 */
	public static function seed( int $org, int $actor, array $images = [] ): array {
		return self::make( PostTypes::EVENT, self::plan( $images ), $org, $actor );
	}

	/**
	 * Make the seven training listings. Returns their ids.
	 *
	 * @param int[] $images
	 * @return int[]
	 */
	public static function seed_training( int $org, int $actor, array $images = [] ): array {
		return self::make( PostTypes::TRAINING, self::plan_training( $images ), $org, $actor );
	}

	/**
	 * Write a plan's items as live listings of one type.
	 *
	 * @param array<int, array<string, mixed>> $plan
	 * @return int[]
	 */
	private static function make( string $type, array $plan, int $org, int $actor ): array {
		$now  = current_time( 'mysql', true );
		$made = [];

		foreach ( $plan as $p ) {
			$id = wp_insert_post(
				[
					'post_type'    => $type,
					'post_status'  => Statuses::LIVE,
					'post_author'  => $actor,
					'post_title'   => $p['title'],
					'post_content' => Content::clean( $p['body'] ),
				],
				true
			);

			if ( is_wp_error( $id ) ) {
				continue;
			}

			$id = (int) $id;
			update_post_meta( $id, self::MARKER, '1' );
			update_post_meta( $id, Meta::ITEM_ORG, $org );

			$values = ( $p['fields'] ?? [] ) + [
				'summary'       => $p['summary'],
				'cost'          => $p['cost'],
				'cost_detail'   => $p['cost_detail'] ?? '',
				'booking_url'   => $p['booking'] ?? '',
				'accessibility' => $p['access'] ?? '',
				'contact_name'  => 'Sam at the Hub',
				'contact_email' => 'hello@example.org',
				'image'         => $p['image'],
				'image_alt'     => $p['image'] > 0 ? 'Photo from a community event' : '',
			];

			if ( PostTypes::EVENT === $type ) {
				$values += [
					'start_datetime' => $p['start'],
					'end_datetime'   => $p['end'],
					'repeat'         => $p['repeat'],
					'format'         => $p['format'],
					'venue_name'     => $p['venue'] ?? '',
					'address'        => $p['address'] ?? '',
					'postcode'       => $p['postcode'] ?? '',
					'online_url'     => $p['online'] ?? '',
					'capacity'       => $p['capacity'] ?? '',
				];
			}

			Store::write( $id, $type, $values );
			update_post_meta( $id, Meta::ITEM_SUBMITTED_AT, $now );
			update_post_meta( $id, Meta::ITEM_APPROVED_AT, $now );

			$terms = [];

			foreach ( $p['topics'] as $slug ) {
				$term = get_term_by( 'slug', $slug, Taxonomies::TOPIC );

				if ( $term instanceof \WP_Term ) {
					$terms[] = (int) $term->term_id;
				}
			}

			if ( [] !== $terms ) {
				wp_set_object_terms( $id, $terms, Taxonomies::TOPIC, false );
			}

			Series::stamp( $id, $type );
			Sync::sync( $id );

			if ( ! empty( $p['pin'] ) ) {
				Pins::pin( $id, (int) $p['pin'], $actor );
			}

			if ( ! empty( $p['cancel'] ) ) {
				Cancel::cancel( $id, (string) $p['cancel'], $actor );
			}

			Log::record( 'seeded', 'item', $id, $org, __( 'Demo listing created by wp dgl demo.', 'dgl-platform' ), [], $actor > 0 ? $actor : null );

			$made[] = $id;
		}

		return $made;
	}

	/**
	 * Delete every event this command made. Returns the ids that went.
	 *
	 * @return int[]
	 */
	public static function remove( string $type = PostTypes::EVENT ): array {
		$ids = get_posts(
			[
				'post_type'        => $type,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
				'meta_key'         => self::MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of rows.
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		$gone = [];

		foreach ( $ids as $id ) {
			if ( false !== wp_delete_post( (int) $id, true ) ) {
				$gone[] = (int) $id;
			}
		}

		return $gone;
	}

	/**
	 * The newest photos in the library, leaving out logos and designs.
	 *
	 * @return int[]
	 */
	public static function photos( int $count ): array {
		$found = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_mime_type'   => [ 'image/jpeg', 'image/webp' ],
				'numberposts'      => $count * 3,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			]
		);

		$ids = [];

		foreach ( $found as $att ) {
			$title = strtolower( (string) $att->post_title );

			if ( str_contains( $title, 'logo' ) || str_contains( $title, 'design' ) ) {
				continue;
			}

			$ids[] = (int) $att->ID;

			if ( count( $ids ) >= $count ) {
				break;
			}
		}

		return $ids;
	}
}
