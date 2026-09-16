<?php
/**
 * The wp-admin list tables for submissions.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DGL\Dashboard\Router;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * What the list of submissions tells you at a glance.
 *
 * WordPress gives a title, an author and a date. None of those answer the
 * questions DGLP actually have: which organisation is this from, where is it in
 * the workflow, and how long has it been waiting. So the columns are replaced
 * with ones that do, and the list can be filtered by organisation.
 */
final class Columns {

	/** Query arg for the organisation filter. */
	private const FILTER = 'dgl_org_filter';

	public static function init(): void {
		foreach ( PostTypes::enabled_keys() as $post_type ) {
			add_filter( "manage_edit-{$post_type}_columns", [ self::class, 'columns' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ self::class, 'cell' ], 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", [ self::class, 'sortable' ] );
		}

		add_action( 'restrict_manage_posts', [ self::class, 'org_dropdown' ] );
		add_action( 'pre_get_posts', [ self::class, 'apply_filter' ] );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = [];

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			// Straight after the title, because these are what the row is for.
			if ( 'title' === $key ) {
				$out['dgl_org']    = __( 'Organisation', 'dgl-platform' );
				$out['dgl_state']  = __( 'Status', 'dgl-platform' );
				$out['dgl_waited'] = __( 'Waiting', 'dgl-platform' );
			}
		}

		// The author is the account that typed it, which is rarely the question.
		// The organisation column above answers the one people actually ask.
		unset( $out['author'] );

		return $out;
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function sortable( array $columns ): array {
		$columns['dgl_waited'] = 'dgl_submitted_at';

		return $columns;
	}

	public static function cell( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'dgl_org':
				$org_id = Org::for_item( $post_id );

				if ( $org_id <= 0 ) {
					echo '<span style="color:#b32d2e">' . esc_html__( 'None', 'dgl-platform' ) . '</span>';
					return;
				}

				$name = (string) get_the_title( $org_id );

				/*
				 * An item can outlive the organisation it was filed under, and
				 * an empty cell reads as "no organisation" when the truth is
				 * "an organisation that is no longer there". Saying so, with the
				 * ID, is what lets somebody actually go and fix it.
				 */
				if ( '' === trim( $name ) || ! Org::exists( $org_id ) ) {
					printf(
						'<span style="color:#b32d2e">%s</span>',
						esc_html(
							sprintf(
								/* translators: %d: the missing organisation's ID. */
								__( 'Missing organisation (#%d)', 'dgl-platform' ),
								$org_id
							)
						)
					);
					return;
				}

				printf(
					'<a href="%s">%s</a>',
					esc_url( add_query_arg( self::FILTER, $org_id ) ),
					esc_html( $name )
				);
				return;

			case 'dgl_state':
				echo esc_html( Statuses::label( (string) get_post_status( $post_id ) ) );
				return;

			case 'dgl_waited':
				$since = (string) get_post_meta( $post_id, Meta::ITEM_SUBMITTED_AT, true );

				if ( Statuses::PENDING !== get_post_status( $post_id ) || '' === $since ) {
					echo '—';
					return;
				}

				$stamp = strtotime( $since . ' UTC' );

				if ( false === $stamp ) {
					echo '—';
					return;
				}

				/*
				 * How long somebody has been waiting on DGLP, not when it was
				 * sent. "Three days" is a prompt; a date is a fact you have to
				 * do arithmetic on.
				 */
				printf(
					/* translators: %s: a human-readable duration, for example "3 days". */
					esc_html__( '%s so far', 'dgl-platform' ),
					esc_html( human_time_diff( $stamp, time() ) )
				);
				return;
		}
	}

	/**
	 * An organisation dropdown above the list.
	 */
	public static function org_dropdown( string $post_type ): void {
		if ( ! PostTypes::is_submittable( $post_type ) ) {
			return;
		}

		$orgs = get_posts(
			[
				'post_type'      => PostTypes::ORG,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		if ( [] === $orgs ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
		$current = isset( $_GET[ self::FILTER ] ) ? (int) $_GET[ self::FILTER ] : 0;

		echo '<select name="' . esc_attr( self::FILTER ) . '">';
		echo '<option value="0">' . esc_html__( 'All organisations', 'dgl-platform' ) . '</option>';

		foreach ( $orgs as $org ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $org->ID,
				selected( $current, (int) $org->ID, false ),
				esc_html( get_the_title( $org ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Narrow the list to one organisation.
	 */
	public static function apply_filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = (string) $query->get( 'post_type' );

		if ( ! PostTypes::is_submittable( $post_type ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
		$org_id = isset( $_GET[ self::FILTER ] ) ? (int) $_GET[ self::FILTER ] : 0;

		if ( $org_id > 0 ) {
			/*
			 * A meta_query rather than the index table. This is one admin
			 * screen with a page of results on it, not a hot path, and going
			 * through WP_Query keeps every other list-table feature working.
			 */
			$query->set(
				'meta_query',
				[
					[
						'key'   => Meta::ITEM_ORG,
						'value' => (string) $org_id,
					],
				]
			);
		}

		if ( 'dgl_submitted_at' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', Meta::ITEM_SUBMITTED_AT );
			$query->set( 'orderby', 'meta_value' );
		}
	}
}
