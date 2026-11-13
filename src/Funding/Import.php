<?php
/**
 * Funding opportunities from the weekly import, brought in as Grant items.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

use DGL\Audit\Log;
use DGL\Events\Series;
use DGL\Index\Sync;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * The Worker in workers/funding reads funders' pages every Monday and posts
 * what is open to /wp-json/dgl/v1/funding/sync (see Endpoint). This class
 * brings the site in line with that, the way the VAL training import does:
 *
 * - A fund is known by the key the Worker gives it, kept in META_KEY.
 * - New funds go live at once (DGLP's choice, 13 November 2026). Setting
 *   dgl_funding_autopublish to 0 sends them to the review queue instead.
 * - The facts (amounts, deadline, link) are refreshed every week. The words
 *   and categories are written once, so the review team's edits stand.
 * - A source that could not be read changes nothing. A fund that has gone
 *   from a source that was read is withdrawn.
 * - A rolling fund (or one whose page gives no closing date) is listed as open
 *   all year; its stored deadline is always six weeks after the last week it was
 *   seen, so if the import stops, rolling funds come down by themselves.
 *
 * Everything in the request is untrusted: text is reduced to plain text and
 * capped, categories are kept only when they are on the list, links only
 * when they are https.
 */
final class Import {

	public const META_KEY     = 'dgl_funding_key';
	public const META_SOURCE  = 'dgl_funding_source';
	public const META_CHECKED = 'dgl_funding_checked';

	/** How the last run went. */
	public const OPTION_LAST = 'dgl_funding_last';

	/** '1' (the default) publishes new funds without review; '0' sends them to the review queue. */
	public const OPTION_AUTOPUBLISH = 'dgl_funding_autopublish';

	/** The organisation that owns imported funds: an id, else VAL's. */
	public const OPTION_ORG = 'dgl_funding_org';

	/** When somebody was last told the import has gone quiet. */
	public const OPTION_ALERTED = 'dgl_funding_alerted';

	/** A rolling fund stays listed this long after the week it was last seen. */
	public const ROLLING_DAYS = 42;

	public const MAX_PER_SOURCE = 25;

	/** New items in one run, so a surprise answer cannot flood the queue. */
	public const MAX_NEW = 250;

	/** No run for this long and somebody is told. */
	public const STALE_DAYS = 10;

	/** Caps, in words, matching what the Worker is asked for (with a little room). */
	private const WORDS = [
		'summary'      => 60,
		'body'         => 260,
		'eligibility'  => 160,
		'how_to_apply' => 110,
	];

	// ------------------------------------------------------------------
	// Pure: no WordPress beyond the plain-text helpers, so it is tested.
	// ------------------------------------------------------------------

	/**
	 * One fund from the request, cleaned. Null when it cannot be listed: no
	 * key or title, or its deadline has passed.
	 *
	 * @param array<string, mixed> $grant
	 * @param string[]             $topic_slugs Topics a fund may carry.
	 * @return array{key:string, words:array<string, mixed>, facts:array<string, mixed>, topics:string[]}|null
	 */
	public static function normalise( array $grant, string $today, array $topic_slugs ): ?array {
		$key   = self::slug_key( (string) ( $grant['key'] ?? '' ) );
		$title = self::plain( $grant['title'] ?? '', 120 );

		if ( '' === $key || '' === $title ) {
			return null;
		}

		$rolling  = ! empty( $grant['rolling'] );
		$deadline = self::date( $grant['deadline'] ?? null );

		if ( null !== $deadline && $deadline < $today ) {
			return null;
		}

		// No closing date on the funder's page means it is open until further
		// notice: listed as open all year, never with a made-up deadline. Its
		// stored deadline is only the date it is next re-checked by.
		if ( null === $deadline ) {
			$rolling = true;
		}

		if ( $rolling ) {
			$deadline = self::rolling_deadline( $today );
		}

		$min = self::money( $grant['amount_min'] ?? null );
		$max = self::money( $grant['amount_max'] ?? null );

		if ( null !== $min && null !== $max && $min > $max ) {
			[ $min, $max ] = [ $max, $min ];
		}

		$area = (string) ( $grant['area'] ?? '' );

		$topics = [ 'grants-and-funding' ];

		foreach ( (array) ( $grant['topics'] ?? [] ) as $topic ) {
			$topic = is_scalar( $topic ) ? (string) $topic : '';

			if ( in_array( $topic, $topic_slugs, true ) && ! in_array( $topic, Vocab::topics_excluded(), true ) && ! in_array( $topic, $topics, true ) ) {
				$topics[] = $topic;
			}
		}

		$summary = self::words( self::plain( $grant['summary'] ?? '', 2000 ), self::WORDS['summary'] );

		return [
			'key'    => $key,
			'words'  => [
				'title'         => $title,
				'summary'       => '' !== $summary ? self::chars( $summary, 300 ) : $title,
				'body'          => self::words( self::plain( $grant['body'] ?? '', 8000 ), self::WORDS['body'] ),
				'funder'        => self::plain( $grant['funder'] ?? '', 120 ),
				'eligibility'   => self::chars( self::words( self::plain( $grant['eligibility'] ?? '', 4000 ), self::WORDS['eligibility'] ), 1200 ),
				'how_to_apply'  => self::chars( self::words( self::plain( $grant['how_to_apply'] ?? '', 4000 ), self::WORDS['how_to_apply'] ), 1200 ),
				'fund_type'     => Vocab::keep( 'fund_type', $grant['fund_type'] ?? [ 'grant' ] ) ?: [ 'grant' ],
				'applicants'    => Vocab::keep( 'applicants', $grant['applicants'] ?? [] ),
				'costs'         => Vocab::keep( 'costs', $grant['costs'] ?? [] ),
				'beneficiaries' => Vocab::keep( 'beneficiaries', $grant['beneficiaries'] ?? [] ),
				'area'          => isset( Vocab::options( 'area' )[ $area ] ) ? $area : '',
			],
			'facts'  => [
				'amount_min'       => $min,
				'amount_max'       => $max,
				'deadline'         => $deadline,
				'rolling_deadline' => $rolling,
				'application_url'  => self::https( (string) ( $grant['application_url'] ?? '' ) ),
			],
			'topics' => $topics,
		];
	}

	/** The deadline a rolling fund is given: six weeks on. */
	public static function rolling_deadline( string $today ): string {
		return ( new \DateTimeImmutable( $today ) )->modify( '+' . self::ROLLING_DAYS . ' days' )->format( 'Y-m-d' );
	}

	/** Lower-case, letters, digits, dash and colon; at most 120 characters. */
	public static function slug_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = (string) preg_replace( '/[^a-z0-9:\-]+/', '-', $key );
		$key = trim( (string) preg_replace( '/-{2,}/', '-', $key ), '-:' );

		return substr( $key, 0, 120 );
	}

	/** A YYYY-MM-DD date that exists, else null. */
	public static function date( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return null;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : null;
	}

	/** Whole pounds, from a number or "£1,500". Null when there is none or it is silly. */
	public static function money( $value ): ?float {
		if ( is_string( $value ) ) {
			$value = str_replace( [ '£', ',', ' ' ], '', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$value = round( (float) $value );

		return $value > 0 && $value < 1000000000 ? $value : null;
	}

	/** An https address, else empty. */
	public static function https( string $url ): string {
		$url = trim( $url );

		if ( ! preg_match( '#^https://[^\s/$.?\#][^\s<>"]*$#i', $url ) || strlen( $url ) > 500 ) {
			return '';
		}

		return $url;
	}

	/** Plain text, one line breaks kept as paragraphs, capped in characters. */
	public static function plain( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = html_entity_decode( strip_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[^\S\n]+/u', ' ', $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = trim( (string) preg_replace( '/ *\n */', "\n", $text ) );

		return self::chars( $text, $max );
	}

	/** At most this many words, ending at a sentence where one is near. */
	public static function words( string $text, int $max ): string {
		$words = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $words ) || count( $words ) <= $max * 2 ) {
			return $text;
		}

		$cut  = trim( implode( '', array_slice( $words, 0, $max * 2 - 1 ) ) );
		$stop = max( (int) strrpos( $cut, '. ' ), str_ends_with( $cut, '.' ) ? strlen( $cut ) - 1 : 0 );

		return $stop > strlen( $cut ) * 0.6 ? substr( $cut, 0, $stop + 1 ) : rtrim( $cut, ',;:' ) . '...';
	}

	/** At most this many characters, without splitting a character. */
	public static function chars( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 3 ) ) . '...' : $text;
	}

	/**
	 * What a fund's body becomes: paragraphs of plain text, escaped.
	 */
	public static function body_html( string $text ): string {
		$out = [];

		foreach ( preg_split( '/\n{2,}|\n/', $text ) ?: [] as $para ) {
			$para = trim( $para );

			if ( '' !== $para ) {
				$out[] = '<p>' . htmlspecialchars( $para, ENT_QUOTES, 'UTF-8' ) . '</p>';
			}
		}

		return implode( "\n", $out );
	}

	// ------------------------------------------------------------------
	// WordPress.
	// ------------------------------------------------------------------

	public static function init(): void {
		add_action( 'rest_api_init', [ Endpoint::class, 'register' ] );
		add_action( \DGL\Plugin::EXPIRY_HOOK, [ self::class, 'check_stale' ], 30, 0 );
	}

	/** Whether an item came from the import. */
	public static function is_imported( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * The organisation imported funds belong to: the saved id, else
	 * Voluntary Action Leeds, whose funding document this replaces.
	 *
	 * @return int|\WP_Error
	 */
	public static function org_id() {
		$saved = (int) get_option( self::OPTION_ORG, 0 );

		if ( $saved > 0 && \DGL\Org\Org::exists( $saved ) ) {
			return $saved;
		}

		return \DGL\Training\ValTraining::org_id();
	}

	/**
	 * Bring the Grant items in line with one run of the Worker.
	 *
	 * @param array<string, mixed> $payload The decoded request body.
	 * @return array<string, mixed>
	 */
	public static function run( array $payload, bool $dry_run = false ): array {
		$out = [
			'ok'        => false,
			'at'        => time(),
			'run_id'    => self::plain( $payload['run_id'] ?? '', 60 ),
			'sources'   => 0,
			'read'      => 0,
			'funds'     => 0,
			'added'     => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'revived'   => 0,
			'withdrawn' => 0,
			'skipped'   => 0,
			'errors'    => [],
			'new'       => [],
		];

		$org_id = self::org_id();

		if ( is_wp_error( $org_id ) ) {
			$out['errors'][] = $org_id->get_error_message();

			return self::remember( $out, $dry_run );
		}

		$sources = is_array( $payload['sources'] ?? null ) ? $payload['sources'] : [];

		if ( [] === $sources ) {
			$out['errors'][] = 'No sources in the request. Nothing was changed.';

			return self::remember( $out, $dry_run );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a weekly batch, a few hundred items.
		}

		$out['ok'] = true;
		$today     = wp_date( 'Y-m-d' );
		$topics    = array_keys( \DGL\Topics\Topics::all() );
		$known     = self::known();
		$made      = 0;

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			++$out['sources'];
			$source_id = self::slug_key( (string) ( $source['id'] ?? '' ) );

			if ( '' === $source_id || empty( $source['ok'] ) ) {
				if ( '' !== $source_id ) {
					$out['errors'][] = sprintf( '%s: %s', $source_id, self::plain( $source['error'] ?? 'not read', 200 ) );
				}
				continue;
			}

			++$out['read'];
			$seen  = [];
			$funds = array_slice( is_array( $source['grants'] ?? null ) ? $source['grants'] : [], 0, self::MAX_PER_SOURCE );

			foreach ( $funds as $grant ) {
				$fund = is_array( $grant ) ? self::normalise( $grant, $today, $topics ) : null;

				if ( null === $fund ) {
					++$out['skipped'];
					continue;
				}

				// A key always names its source, so two sources cannot fight over one fund.
				if ( ! str_starts_with( $fund['key'], $source_id . ':' ) ) {
					$fund['key'] = self::slug_key( $source_id . ':' . $fund['key'] );
				}

				if ( in_array( $fund['key'], $seen, true ) ) {
					continue;
				}

				$seen[] = $fund['key'];
				++$out['funds'];

				if ( isset( $known[ $fund['key'] ] ) ) {
					$result = self::refresh( $known[ $fund['key'] ]['id'], $fund, $today, $dry_run );
					++$out[ $result ];
					continue;
				}

				if ( $made >= self::MAX_NEW ) {
					++$out['skipped'];
					continue;
				}

				++$made;
				$out['new'][] = $fund['words']['title'];

				if ( $dry_run ) {
					++$out['added'];
					continue;
				}

				$id = self::store( $fund, $source_id, (int) $org_id, $today );

				if ( is_wp_error( $id ) ) {
					$out['errors'][] = sprintf( '%s: %s', $fund['words']['title'], $id->get_error_message() );
					continue;
				}

				++$out['added'];
			}

			// Only a page that still lists something is trusted to say a fund has gone.
			if ( [] !== $seen ) {
				$out['withdrawn'] += self::withdraw_gone( $known, $source_id, $seen, $dry_run );
			}
		}

		if ( ! $dry_run ) {
			delete_option( self::OPTION_ALERTED );

			if ( $out['added'] > 0 ) {
				self::tell_reviewers( $out );
			}
		}

		return self::remember( $out, $dry_run );
	}

	/**
	 * Every imported fund, key => id, status and source.
	 *
	 * @return array<string, array{id:int, status:string, source:string}>
	 */
	private static function known(): array {
		$ids = get_posts(
			[
				'post_type'      => PostTypes::GRANT,
				'post_status'    => Statuses::all(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- weekly, a few hundred rows.
			]
		);

		$known = [];

		foreach ( $ids as $id ) {
			$known[ (string) get_post_meta( (int) $id, self::META_KEY, true ) ] = [
				'id'     => (int) $id,
				'status' => (string) get_post_status( (int) $id ),
				'source' => (string) get_post_meta( (int) $id, self::META_SOURCE, true ),
			];
		}

		return $known;
	}

	/**
	 * Bring the facts of a fund that exists up to date.
	 *
	 * @param array{key:string, words:array<string, mixed>, facts:array<string, mixed>, topics:string[]} $fund
	 * @return string updated|unchanged|revived
	 */
	private static function refresh( int $post_id, array $fund, string $today, bool $dry_run ): string {
		$changed = false;

		foreach ( $fund['facts'] as $key => $value ) {
			$meta    = 'dgl_' . $key;
			$current = get_post_meta( $post_id, $meta, true );
			$value   = is_bool( $value ) ? ( $value ? '1' : '' ) : ( null === $value ? '' : (string) $value );

			if ( (string) $current === $value ) {
				continue;
			}

			// A link the team corrected by hand is kept when the import has none.
			if ( 'application_url' === $key && '' === $value ) {
				continue;
			}

			$changed = true;

			if ( ! $dry_run ) {
				if ( '' === $value ) {
					delete_post_meta( $post_id, $meta );
				} else {
					update_post_meta( $post_id, $meta, 'rolling_deadline' === $key ? true : ( in_array( $key, [ 'amount_min', 'amount_max' ], true ) ? (float) $value : $value ) );
				}
			}
		}

		// Back on a funder's page after it was taken off: listed again, but only
		// if the team had approved it before. A fund they turned down stays down.
		$status = (string) get_post_status( $post_id );
		$revive = Statuses::EXPIRED === $status && '' !== (string) get_post_meta( $post_id, Meta::ITEM_APPROVED_AT, true );

		if ( $dry_run ) {
			return $revive ? 'revived' : ( $changed ? 'updated' : 'unchanged' );
		}

		update_post_meta( $post_id, self::META_CHECKED, $today );

		if ( $revive ) {
			wp_update_post( [ 'ID' => $post_id, 'post_status' => Statuses::LIVE ] );
		}

		if ( $changed || $revive ) {
			Series::stamp( $post_id, PostTypes::GRANT );
			Sync::sync( $post_id );
		}

		return $revive ? 'revived' : ( $changed ? 'updated' : 'unchanged' );
	}

	/**
	 * Make one fund.
	 *
	 * @param array{key:string, words:array<string, mixed>, facts:array<string, mixed>, topics:string[]} $fund
	 * @return int|\WP_Error
	 */
	private static function store( array $fund, string $source_id, int $org_id, string $today ) {
		$live    = '1' === (string) get_option( self::OPTION_AUTOPUBLISH, '1' );
		$words   = $fund['words'];
		$post_id = wp_insert_post(
			[
				'post_type'    => PostTypes::GRANT,
				'post_status'  => $live ? Statuses::LIVE : Statuses::PENDING,
				'post_title'   => (string) $words['title'],
				'post_content' => self::body_html( '' !== (string) $words['body'] ? (string) $words['body'] : (string) $words['summary'] ),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		$now     = current_time( 'mysql', true );
		$funder  = '' !== (string) $words['funder'] ? (string) $words['funder'] : (string) $words['title'];
		$meta    = [
			'dgl_summary'         => $words['summary'],
			'dgl_funder'          => $funder,
			'dgl_eligibility'     => $words['eligibility'],
			'dgl_how_to_apply'    => $words['how_to_apply'],
			'dgl_fund_type'       => $words['fund_type'],
			'dgl_applicants'      => $words['applicants'],
			'dgl_costs'           => $words['costs'],
			'dgl_beneficiaries'   => $words['beneficiaries'],
			'dgl_area'            => $words['area'],
			'dgl_contact_name'    => $funder,
			'dgl_website'         => $fund['facts']['application_url'],
			'dgl_amount_min'      => $fund['facts']['amount_min'],
			'dgl_amount_max'      => $fund['facts']['amount_max'],
			'dgl_deadline'        => $fund['facts']['deadline'],
			'dgl_rolling_deadline' => $fund['facts']['rolling_deadline'] ? true : '',
			'dgl_application_url' => $fund['facts']['application_url'],
		];

		foreach ( $meta as $key => $value ) {
			if ( null !== $value && '' !== $value && [] !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		update_post_meta( $post_id, Meta::ITEM_ORG, $org_id );
		update_post_meta( $post_id, Meta::ITEM_SUBMITTED_AT, $now );
		update_post_meta( $post_id, self::META_KEY, $fund['key'] );
		update_post_meta( $post_id, self::META_SOURCE, $source_id );
		update_post_meta( $post_id, self::META_CHECKED, $today );

		if ( $live ) {
			update_post_meta( $post_id, Meta::ITEM_APPROVED_AT, $now );
		}

		$term_ids = [];

		foreach ( $fund['topics'] as $topic ) {
			$term = get_term_by( 'slug', $topic, Taxonomies::TOPIC );

			if ( $term instanceof \WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		wp_set_object_terms( $post_id, $term_ids, Taxonomies::TOPIC, false );

		Series::stamp( $post_id, PostTypes::GRANT );
		Sync::sync( $post_id );

		Log::record(
			'imported',
			'item',
			$post_id,
			$org_id,
			sprintf(
				/* translators: %s: the import's name for the fund. */
				__( 'Brought in by the weekly funding import: %s', 'dgl-platform' ),
				$fund['key']
			),
			[ 'source' => $source_id ],
			null
		);

		return $post_id;
	}

	/**
	 * Funds of one source that its page no longer lists. A live one is taken
	 * off by the ordinary expiry sweep; one still waiting for review is taken
	 * out of the queue, as there is nothing left to review.
	 *
	 * @param array<string, array{id:int, status:string, source:string}> $known
	 * @param string[] $seen
	 */
	private static function withdraw_gone( array $known, string $source_id, array $seen, bool $dry_run ): int {
		$count = 0;

		foreach ( $known as $key => $row ) {
			if ( $row['source'] !== $source_id || in_array( $key, $seen, true ) ) {
				continue;
			}

			if ( Statuses::LIVE === $row['status'] ) {
				++$count;

				if ( ! $dry_run ) {
					update_post_meta( $row['id'], Meta::ITEM_EXPIRES_AT, current_time( 'mysql' ) );
					Sync::sync( $row['id'] );
				}
			} elseif ( Statuses::PENDING === $row['status'] ) {
				++$count;

				if ( ! $dry_run ) {
					wp_update_post( [ 'ID' => $row['id'], 'post_status' => Statuses::EXPIRED ] );
					Sync::sync( $row['id'] );
				}
			}
		}

		return $count;
	}

	/**
	 * One email per run that brought something new, to the site's
	 * administrator address, with the list and a link to the queue.
	 *
	 * @param array<string, mixed> $run
	 */
	private static function tell_reviewers( array $run ): void {
		$to = sanitize_email( (string) get_option( 'admin_email', '' ) );

		if ( '' === $to ) {
			return;
		}

		$live  = '1' === (string) get_option( self::OPTION_AUTOPUBLISH, '1' );
		$names = array_slice( (array) $run['new'], 0, 40 );
		$more  = count( (array) $run['new'] ) - count( $names );

		\DGL\Email\Mailer::send(
			( new \DGL\Email\Message(
				key: 'funding_import',
				audience: 'team',
				subject: sprintf(
					/* translators: %d: how many funds. */
					_n( '%d new funding opportunity this week', '%d new funding opportunities this week', (int) $run['added'], 'dgl-platform' ),
					(int) $run['added']
				),
				preheader: $live ? __( 'Already on the site.', 'dgl-platform' ) : __( 'Waiting for a quick check before they go on the site.', 'dgl-platform' ),
				heading: __( 'This week\'s funding import', 'dgl-platform' ),
				paragraphs: array_values(
					array_filter(
						[
							$live
								? __( 'These funds were found on funders\' own pages this week and are already listed.', 'dgl-platform' )
								: __( 'These funds were found on funders\' own pages this week. Each is waiting in the review queue: check the amount, the deadline and who can apply against the funder\'s link, then approve it.', 'dgl-platform' ),
							implode( "\n", array_map( static fn( $t ): string => '- ' . $t, $names ) ) . ( $more > 0 ? "\n" . sprintf( /* translators: %d: a number. */ __( 'and %d more', 'dgl-platform' ), $more ) : '' ),
						]
					)
				),
				facts: [
					__( 'Funders\' pages read', 'dgl-platform' ) => sprintf( '%d of %d', (int) $run['read'], (int) $run['sources'] ),
					__( 'Funds open', 'dgl-platform' )          => (string) (int) $run['funds'],
					__( 'Taken off (gone from the funder\'s page)', 'dgl-platform' ) => (string) (int) $run['withdrawn'],
				],
				cta_label: $live ? '' : __( 'Open the review queue', 'dgl-platform' ),
				cta_url: $live ? '' : \DGL\Dashboard\Router::url( 'review' ),
			) )->for_recipients( [ $to ] )
		);
	}

	/**
	 * No run for STALE_DAYS: say so once, by email. Called by the five-minute
	 * sweep, so it is one option read when all is well.
	 */
	public static function check_stale(): void {
		$last = get_option( self::OPTION_LAST, null );

		if ( ! is_array( $last ) || false !== get_option( self::OPTION_ALERTED, false ) ) {
			return;
		}

		if ( time() - (int) ( $last['at'] ?? 0 ) < self::STALE_DAYS * DAY_IN_SECONDS ) {
			return;
		}

		update_option( self::OPTION_ALERTED, time(), false );

		$to = sanitize_email( (string) get_option( 'admin_email', '' ) );

		if ( '' === $to ) {
			return;
		}

		\DGL\Email\Mailer::send(
			( new \DGL\Email\Message(
				key: 'funding_import_quiet',
				audience: 'team',
				subject: __( 'The weekly funding import has not run', 'dgl-platform' ),
				preheader: __( 'Funds already listed are untouched.', 'dgl-platform' ),
				heading: __( 'The funding import has gone quiet', 'dgl-platform' ),
				paragraphs: [
					__( 'Nothing has arrived from the weekly funding import for over a week, so new funds are not coming through.', 'dgl-platform' ),
					__( 'Funds already listed stay up until their deadlines. Funds open all year come down six weeks after they were last seen, so the list does not fill with closed money.', 'dgl-platform' ),
					__( 'Ask whoever looks after the site to check the dgl-funding Worker on Cloudflare.', 'dgl-platform' ),
				],
				facts: [
					__( 'Last run', 'dgl-platform' ) => wp_date( 'j M Y H:i', (int) ( $last['at'] ?? 0 ) ),
				],
			) )->for_recipients( [ $to ] )
		);
	}

	/**
	 * Keep how the run went, unless it was a dry run.
	 *
	 * @param array<string, mixed> $out
	 * @return array<string, mixed>
	 */
	private static function remember( array $out, bool $dry_run ): array {
		if ( ! $dry_run ) {
			update_option( self::OPTION_LAST, array_diff_key( $out, [ 'new' => 1 ] ), false );
		}

		return $out;
	}

	/** How the last real run went, or null. */
	public static function last(): ?array {
		$last = get_option( self::OPTION_LAST, null );

		return is_array( $last ) ? $last : null;
	}
}
