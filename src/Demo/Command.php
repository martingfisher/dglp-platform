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
use DGL\Spaces\Geocode;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Workflow\Pins;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Seven varied live events, seven training listings, or three venues with
 * nine spaces to hire, in one call, so the public lists can be seen
 * rendering without anybody filling in the forms. Every item carries a
 * marker, so `--remove` takes exactly these away and nothing else.
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
	 * Create three demo venues with nine spaces to hire under an
	 * organisation, or remove them.
	 *
	 * ## OPTIONS
	 *
	 * [--org=<id>]
	 * : The organisation the venues belong to. Required unless --remove.
	 *
	 * [--actor=<id>]
	 * : User to record the changes against. Default 0, the system.
	 *
	 * [--images]
	 * : Use the newest photos in the media library as the pictures.
	 *
	 * [--skip-geocode]
	 * : Do not look the postcodes up on postcodes.io. The venues are
	 * listed without a pin; `wp dgl spaces geocode` adds one later.
	 *
	 * [--remove]
	 * : Delete every venue and space this command made, whatever its state now.
	 *
	 * [--dry-run]
	 * : Say what would be made and stop.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl demo spaces --org=8438 --images
	 *     wp dgl demo spaces --org=8438 --skip-geocode --dry-run
	 *     wp dgl demo spaces --remove
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function spaces( array $args, array $assoc ): void {
		if ( isset( $assoc['remove'] ) ) {
			// Spaces first, so no venue is ever deleted from under one.
			$spaces = self::remove( PostTypes::SPACE );
			$venues = self::remove( PostTypes::VENUE );
			WP_CLI::success( sprintf( '%d demo space(s) and %d demo venue(s) removed.', count( $spaces ), count( $venues ) ) );
			return;
		}

		$org = (int) ( $assoc['org'] ?? 0 );

		if ( $org <= 0 || ! Org::exists( $org ) ) {
			WP_CLI::error( 'Give --org=<id>: an organisation that exists.' );
		}

		$plan = self::plan_spaces( isset( $assoc['images'] ) ? self::photos( 6 ) : [] );

		if ( isset( $assoc['dry-run'] ) ) {
			foreach ( $plan as $venue ) {
				WP_CLI::log( sprintf( '%s (%s, %s)', $venue['title'], $venue['fields']['postcode'], $venue['fields']['ward'] ) );

				foreach ( $venue['spaces'] as $space ) {
					WP_CLI::log( sprintf( '  - %s: %s', $space['title'], self::rate_words( $space['fields'] ) ) );
				}
			}

			WP_CLI::log( sprintf( '%d venue(s), %d space(s). Nothing made.', count( $plan ), array_sum( array_map( static fn( array $v ): int => count( $v['spaces'] ), $plan ) ) ) );
			return;
		}

		$made = self::seed_spaces( $org, (int) ( $assoc['actor'] ?? 0 ), $plan, ! isset( $assoc['skip-geocode'] ) );
		$rows = [];

		foreach ( $made['venues'] as $id ) {
			$coords = Geocode::coords( $id );
			$rows[] = [
				'id'       => $id,
				'title'    => wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ),
				'kind'     => 'venue',
				'postcode' => (string) get_post_meta( $id, 'dgl_postcode', true ),
				'rate'     => '',
				'pin'      => null === $coords ? 'none' : $coords['lat'] . ', ' . $coords['lng'],
				'picture'  => (int) get_post_meta( $id, 'dgl_image', true ) > 0 ? 'yes' : 'none',
			];
		}

		foreach ( $made['spaces'] as $id ) {
			$rows[] = [
				'id'       => $id,
				'title'    => wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ),
				'kind'     => 'space of #' . (int) get_post_meta( $id, Meta::SPACE_VENUE, true ),
				'postcode' => '',
				'rate'     => \DGL\Spaces\SpacesQuery::rate_words( \DGL\Spaces\SpacesQuery::space_meta( $id ) ),
				'pin'      => '',
				'picture'  => (int) get_post_meta( $id, 'dgl_image', true ) > 0 ? 'yes' : 'none',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'title', 'kind', 'postcode', 'rate', 'pin', 'picture' ] );
		WP_CLI::success( sprintf( '%d demo venue(s) and %d demo space(s) made for %s.', count( $made['venues'] ), count( $made['spaces'] ), get_the_title( $org ) ) );
	}

	/**
	 * Make the venues and their spaces, live, and pin the venues on the
	 * map when asked. Returns the ids by kind.
	 *
	 * @param array<int, array<string, mixed>> $plan
	 * @return array{venues: int[], spaces: int[]}
	 */
	public static function seed_spaces( int $org, int $actor, array $plan, bool $geocode = true ): array {
		$made = [
			'venues' => [],
			'spaces' => [],
		];

		foreach ( $plan as $venue ) {
			$venue_id = self::place( PostTypes::VENUE, $venue, $org, $actor );

			if ( 0 === $venue_id ) {
				continue;
			}

			$made['venues'][] = $venue_id;

			if ( $geocode ) {
				Geocode::stamp( $venue_id );
			}

			foreach ( $venue['spaces'] as $space ) {
				$space_id = self::place( PostTypes::SPACE, $space, $org, $actor, $venue_id );

				if ( $space_id > 0 ) {
					$made['spaces'][] = $space_id;
				}
			}
		}

		return $made;
	}

	/**
	 * One live item of a type, from its title, body and field values. A
	 * space is fixed to its venue here, as the wizard fixes it at creation.
	 *
	 * @param array<string, mixed> $p
	 */
	private static function place( string $type, array $p, int $org, int $actor, int $venue_id = 0 ): int {
		$id = wp_insert_post(
			[
				'post_type'    => $type,
				'post_status'  => Statuses::LIVE,
				'post_author'  => $actor,
				'post_title'   => (string) $p['title'],
				'post_content' => Content::clean( (string) $p['body'] ),
			],
			true
		);

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		$id  = (int) $id;
		$now = current_time( 'mysql', true );

		update_post_meta( $id, self::MARKER, '1' );
		update_post_meta( $id, Meta::ITEM_ORG, $org );

		if ( $venue_id > 0 ) {
			update_post_meta( $id, Meta::SPACE_VENUE, $venue_id );
		}

		Store::write( $id, $type, (array) $p['fields'] );
		update_post_meta( $id, Meta::ITEM_SUBMITTED_AT, $now );
		update_post_meta( $id, Meta::ITEM_APPROVED_AT, $now );

		Series::stamp( $id, $type );
		Sync::sync( $id );

		Log::record( 'seeded', 'item', $id, $org, __( 'Demo listing created by wp dgl demo.', 'dgl-platform' ), [], $actor > 0 ? $actor : null );

		return $id;
	}

	/**
	 * "£28 an hour", "Free" or "Price on request", for the table.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function rate_words( array $fields ): string {
		$rate = $fields['rate'] ?? '';

		if ( '' === $rate || null === $rate ) {
			return 'Price on request';
		}

		if ( (float) $rate <= 0 ) {
			return 'Free';
		}

		$unit = \DGL\Schema\Types\Space::UNITS[ (string) ( $fields['rate_unit'] ?? '' ) ] ?? '';

		return trim( '£' . number_format( (float) $rate, 0 === (int) round( fmod( (float) $rate, 1 ) * 100 ) ? 0 : 2 ) . ' ' . $unit );
	}

	/**
	 * Three venues and nine spaces: a community centre with a whole-building
	 * option and a kitchen at "Price on request", a church hall with a
	 * session rate, and a studio that is free to hire. Between them every
	 * price band, every capacity layout, a day rate and a session rate.
	 *
	 * @param int[] $images
	 * @return array<int, array<string, mixed>>
	 */
	public static function plan_spaces( array $images ): array {
		$photo = static fn( int $n ): int => $images[ $n % max( 1, count( $images ) ) ] ?? 0;
		$alt   = static fn( int $n, string $words ): string => $photo( $n ) > 0 ? $words : '';

		return [
			[
				'title'  => 'Westside Community Centre',
				'body'   => '<p>A busy neighbourhood centre on the edge of Armley Park, run by Westside Neighbourhood Trust since 1998. Three rooms, a community kitchen and a walled garden, all on one level. Regular hirers include a toddler group, two dance classes, a food bank and a monthly repair cafe.</p><p>We keep prices low for community groups and charities. Ask about a block booking if you need the same slot every week.</p>',
				'fields' => [
					'summary'       => 'Three rooms, a community kitchen and a walled garden on one level, a short walk from Armley Town Street.',
					'image'         => $photo( 0 ),
					'image_alt'     => $alt( 0, 'The main hall set out for a community lunch' ),
					'image_2'       => $photo( 1 ),
					'image_2_alt'   => $alt( 1, 'The garden room with its doors open' ),
					'image_3'       => $photo( 2 ),
					'image_3_alt'   => $alt( 2, 'The walled garden in summer' ),
					'venue_type'    => 'community_centre',
					'address'       => '14 Stanhope Road, Armley, Leeds',
					'postcode'      => 'LS12 3QP',
					'ward'          => 'armley',
					'access'        => [ 'step_free', 'accessible_toilet', 'hearing_loop', 'blue_badge_parking' ],
					'facilities'    => [ 'wifi', 'kitchen', 'parking', 'projector', 'tables_chairs', 'baby_changing', 'bike_racks' ],
					'getting_there' => 'Buses 4, 14 and 16 stop on Armley Town Street, two minutes away. Six parking spaces on site, two for blue badge holders, and free street parking on Stanhope Road.',
					'availability'  => 'Open 8am to 10pm every day. Weekday daytimes are the easiest to book; Saturday evenings go quickly.',
					'good_to_know'  => 'No alcohol sales without our agreement. Hirers set out and put away their own tables and chairs. A £50 deposit is asked for evening bookings and returned after the hire.',
					'reply_time'    => 'two_days',
					'contact_name'  => 'Sam Okafor',
					'contact_email' => 'bookings@example.org',
					'contact_phone' => '0113 496 0123',
					'website'       => 'https://example.org',
				],
				'spaces' => [
					[
						'title'  => 'Main hall',
						'body'   => '<p>A bright hall with a sprung wooden floor, a small stage and a hearing loop. Doors open onto the garden. Tables and chairs for 100 are stored at the side.</p>',
						'fields' => [
							'summary'          => 'The big room: sprung floor, small stage, hearing loop and doors to the garden.',
							'image'            => $photo( 0 ),
							'image_alt'        => $alt( 0, 'The main hall set out for a community lunch' ),
							'space_type'       => 'hall',
							'cap_theatre'      => 150,
							'cap_cabaret'      => 100,
							'cap_boardroom'    => 40,
							'cap_standing'     => 180,
							'size_m2'          => 154,
							'space_facilities' => [ 'stage', 'pa', 'sprung_floor', 'hearing_loop', 'screen', 'opens_outside' ],
							'rate'             => 35,
							'rate_unit'        => 'hour',
							'rate_note'        => 'Minimum two hours. Half price for partnership members.',
						],
					],
					[
						'title'  => 'Garden room',
						'body'   => '<p>A calm room at the back of the building with French doors onto the walled garden. Popular for counselling groups, small classes and meetings.</p>',
						'fields' => [
							'summary'          => 'A calm room opening onto the walled garden, good for groups of up to 40.',
							'image'            => $photo( 1 ),
							'image_alt'        => $alt( 1, 'The garden room with its doors open' ),
							'space_type'       => 'meeting_room',
							'cap_theatre'      => 40,
							'cap_cabaret'      => 24,
							'cap_boardroom'    => 16,
							'cap_standing'     => 50,
							'size_m2'          => 48,
							'space_facilities' => [ 'screen', 'flipchart', 'opens_outside' ],
							'rate'             => 16,
							'rate_unit'        => 'hour',
							'rate_note'        => '',
						],
					],
					[
						'title'  => 'Community kitchen',
						'body'   => '<p>A commercial kitchen with a food hygiene rating of 5: two ovens, a six-ring hob, a dishwasher and cold storage. Hire it on its own for cookery sessions or add it to a hall booking.</p>',
						'fields' => [
							'summary'          => 'A rated commercial kitchen, on its own for cookery sessions or added to a hall booking.',
							'image'            => $photo( 3 ),
							'image_alt'        => $alt( 3, 'The community kitchen' ),
							'space_type'       => 'kitchen',
							'cap_theatre'      => '',
							'cap_cabaret'      => '',
							'cap_boardroom'    => '',
							'cap_standing'     => 12,
							'size_m2'          => 30,
							'space_facilities' => [ 'ovens', 'dishwasher' ],
							'rate'             => '',
							'rate_unit'        => '',
							'rate_note'        => 'Depends on what you need. Ask us.',
						],
					],
					[
						'title'  => 'Whole centre',
						'body'   => '<p>All three rooms, the kitchen and the garden for the day, with the building to yourselves. Suits a conference, a community festival or a wedding reception.</p>',
						'fields' => [
							'summary'          => 'All three rooms, the kitchen and the garden, with the building to yourselves for the day.',
							'image'            => $photo( 2 ),
							'image_alt'        => $alt( 2, 'The walled garden in summer' ),
							'space_type'       => 'whole_building',
							'cap_theatre'      => 150,
							'cap_cabaret'      => 120,
							'cap_boardroom'    => '',
							'cap_standing'     => 250,
							'size_m2'          => 260,
							'space_facilities' => [ 'stage', 'pa', 'sprung_floor', 'hearing_loop', 'screen', 'flipchart', 'opens_outside', 'ovens', 'dishwasher' ],
							'rate'             => 220,
							'rate_unit'        => 'day',
							'rate_note'        => 'Weekends only. 9am to 11pm.',
						],
					],
				],
			],
			[
				'title'  => "St Bartholomew's Church Hall",
				'body'   => '<p>A Victorian church hall next to the church on Wesley Road, with a large hall, a quieter reading room and a well-equipped kitchen. We hire to community groups and families from across Armley and Wortley. Regular hirers include a lunch club, a choir and a karate class.</p>',
				'fields' => [
					'summary'       => 'A Victorian church hall on Wesley Road with a large hall, a reading room and a kitchen.',
					'image'         => $photo( 4 ),
					'image_alt'     => $alt( 4, "The hall at St Bartholomew's" ),
					'venue_type'    => 'church_hall',
					'address'       => 'Wesley Road, Armley, Leeds',
					'postcode'      => 'LS12 1SR',
					'ward'          => 'armley',
					'access'        => [ 'step_free', 'accessible_toilet' ],
					'facilities'    => [ 'kitchen', 'tables_chairs', 'parking' ],
					'getting_there' => 'On the 16 bus route. A small car park at the side with room for ten cars.',
					'availability'  => 'Most evenings and Saturdays. Sunday mornings are kept for church use.',
					'good_to_know'  => 'The hall is licensed for music and dancing until 11pm. No alcohol sales. Pay by bank transfer within 14 days of the hire.',
					'reply_time'    => 'week',
					'contact_name'  => 'Rev. Anne Whitaker',
					'contact_email' => 'hall@example.org',
					'contact_phone' => '',
					'website'       => '',
				],
				'spaces' => [
					[
						'title'  => 'Church hall',
						'body'   => '<p>A high-ceilinged hall with a stage at one end and a serving hatch to the kitchen. Seats 80 in rows or 60 at round tables.</p>',
						'fields' => [
							'summary'          => 'A high-ceilinged hall with a stage and a serving hatch to the kitchen.',
							'image'            => $photo( 4 ),
							'image_alt'        => $alt( 4, "The hall at St Bartholomew's" ),
							'space_type'       => 'hall',
							'cap_theatre'      => 80,
							'cap_cabaret'      => 60,
							'cap_boardroom'    => 30,
							'cap_standing'     => 100,
							'size_m2'          => 110,
							'space_facilities' => [ 'stage', 'pa' ],
							'rate'             => 18,
							'rate_unit'        => 'hour',
							'rate_note'        => 'Minimum three hours for a party.',
						],
					],
					[
						'title'  => 'Reading room',
						'body'   => '<p>A quieter room off the main hall with a carpet, armchairs and a large table. Good for a committee, a book group or a small class.</p>',
						'fields' => [
							'summary'          => 'A quiet, carpeted room off the hall for meetings and small classes.',
							'image'            => 0,
							'image_alt'        => '',
							'space_type'       => 'meeting_room',
							'cap_theatre'      => 45,
							'cap_cabaret'      => 24,
							'cap_boardroom'    => 16,
							'cap_standing'     => '',
							'size_m2'          => 42,
							'space_facilities' => [ 'flipchart' ],
							'rate'             => 14,
							'rate_unit'        => 'hour',
							'rate_note'        => '',
						],
					],
					[
						'title'  => 'Kitchen',
						'body'   => '<p>A domestic-style kitchen with two ovens, a large fridge and crockery for 60. Usually hired with the hall, but available on its own for a cookery session.</p>',
						'fields' => [
							'summary'          => 'Two ovens, a large fridge and crockery for 60, next to the hall.',
							'image'            => 0,
							'image_alt'        => '',
							'space_type'       => 'kitchen',
							'cap_theatre'      => '',
							'cap_cabaret'      => '',
							'cap_boardroom'    => '',
							'cap_standing'     => 8,
							'size_m2'          => 20,
							'space_facilities' => [ 'ovens' ],
							'rate'             => 30,
							'rate_unit'        => 'session',
							'rate_note'        => 'A session is a morning, an afternoon or an evening.',
						],
					],
				],
			],
			[
				'title'  => 'The Old Print Works',
				'body'   => '<p>A converted print works in Headingley shared by a dozen small charities and social enterprises. Two rooms are open to other groups: a first-floor studio with a sprung floor and a ground-floor boardroom. There is a lift, a shared kitchen and good Wi-Fi throughout.</p>',
				'fields' => [
					'summary'       => 'A converted print works in Headingley with a studio and a boardroom to hire, and a lift to both.',
					'image'         => $photo( 5 ),
					'image_alt'     => $alt( 5, 'The studio at The Old Print Works' ),
					'venue_type'    => 'arts_space',
					'address'       => '3 Brudenell Road, Headingley, Leeds',
					'postcode'      => 'LS6 1JD',
					'ward'          => 'headingley_and_hyde_park',
					'access'        => [ 'step_free', 'accessible_toilet', 'lift', 'changing_places' ],
					'facilities'    => [ 'wifi', 'kitchen', 'projector', 'tables_chairs', 'bike_racks' ],
					'getting_there' => 'Five minutes from Headingley station and on the 1, 6 and 56 bus routes. No parking on site; there is a pay and display car park on Cardigan Road.',
					'availability'  => 'Weekdays 9am to 9pm and Saturdays 10am to 6pm.',
					'good_to_know'  => 'The studio is free to partnership members on weekday daytimes. A member of staff is always in the building.',
					'reply_time'    => 'same_day',
					'contact_name'  => 'Priya Shah',
					'contact_email' => 'rooms@example.org',
					'contact_phone' => '0113 496 0456',
					'website'       => 'https://example.org',
				],
				'spaces' => [
					[
						'title'  => 'Studio',
						'body'   => '<p>A first-floor studio with a sprung floor, mirrors along one wall and a small PA. Used for dance, yoga, drama and rehearsals.</p>',
						'fields' => [
							'summary'          => 'A first-floor studio with a sprung floor, mirrors and a small PA.',
							'image'            => $photo( 5 ),
							'image_alt'        => $alt( 5, 'The studio at The Old Print Works' ),
							'space_type'       => 'studio',
							'cap_theatre'      => 40,
							'cap_cabaret'      => '',
							'cap_boardroom'    => '',
							'cap_standing'     => 40,
							'size_m2'          => 70,
							'space_facilities' => [ 'sprung_floor', 'pa' ],
							'rate'             => 0,
							'rate_unit'        => 'hour',
							'rate_note'        => 'Free to partnership members on weekday daytimes.',
						],
					],
					[
						'title'  => 'Boardroom',
						'body'   => '<p>A ground-floor meeting room with a big table, a screen for presentations and a hearing loop. Coffee and tea included.</p>',
						'fields' => [
							'summary'          => 'A ground-floor meeting room for 16 with a screen and a hearing loop.',
							'image'            => 0,
							'image_alt'        => '',
							'space_type'       => 'meeting_room',
							'cap_theatre'      => 20,
							'cap_cabaret'      => '',
							'cap_boardroom'    => 16,
							'cap_standing'     => '',
							'size_m2'          => 32,
							'space_facilities' => [ 'screen', 'flipchart', 'hearing_loop' ],
							'rate'             => 20,
							'rate_unit'        => 'hour',
							'rate_note'        => 'Coffee and tea included.',
						],
					],
				],
			],
		];
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
				// Only rows with a file behind them: a picture, not a placeholder.
				'meta_query'       => [ [ 'key' => '_wp_attached_file', 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a small library, run by hand.
			]
		);

		$ids = [];

		foreach ( $found as $att ) {
			$title = strtolower( (string) $att->post_title );

			if ( str_contains( $title, 'logo' ) || str_contains( $title, 'design' ) ) {
				continue;
			}

			// A library row with no file behind it makes no picture.
			if ( '' === (string) get_attached_file( (int) $att->ID ) ) {
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
