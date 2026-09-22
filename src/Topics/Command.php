<?php
/**
 * `wp dgl topics`: see and apply the topic list.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Topics;

use DGL\Taxonomies;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl topics', self::class );
	}

	/**
	 * The topics on the site against the list the plugin carries.
	 *
	 * @when after_wp_load
	 */
	public function list(): void {
		$rows = [];

		foreach ( Topics::all() as $slug => $name ) {
			$term   = get_term_by( 'slug', $slug, Taxonomies::TOPIC );
			$rows[] = [
				'slug'  => $slug,
				'name'  => $name,
				'state' => $term instanceof \WP_Term ? ( $term->name === $name ? 'present' : 'present, named "' . $term->name . '"' ) : 'MISSING',
				'items' => $term instanceof \WP_Term ? (string) $term->count : '',
			];
		}

		$present = get_terms( [ 'taxonomy' => Taxonomies::TOPIC, 'hide_empty' => false ] );

		if ( is_array( $present ) ) {
			foreach ( $present as $term ) {
				if ( ! isset( Topics::all()[ $term->slug ] ) ) {
					$rows[] = [ 'slug' => $term->slug, 'name' => $term->name, 'state' => 'not on the list', 'items' => (string) $term->count ];
				}
			}
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'name', 'state', 'items' ] );
		WP_CLI::log( 'List version on the site: ' . (int) get_option( Topics::OPTION, 0 ) . ' (plugin carries ' . Topics::LIST_VERSION . ')' );
	}

	/**
	 * Create the topics that are missing and rename any whose name has changed.
	 *
	 * Safe to run repeatedly. Never deletes a term.
	 *
	 * @when after_wp_load
	 */
	public function sync(): void {
		$result = Topics::sync();

		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( $error );
		}

		WP_CLI::log( sprintf( 'Created %d, renamed %d, unchanged %d.', count( $result['created'] ), count( $result['renamed'] ), count( $result['unchanged'] ) ) );

		foreach ( $result['created'] as $slug ) {
			WP_CLI::log( '  created  ' . $slug );
		}
		foreach ( $result['renamed'] as $slug ) {
			WP_CLI::log( '  renamed  ' . $slug );
		}
		foreach ( $result['extra'] as $slug ) {
			WP_CLI::log( '  not on the list, left alone: ' . $slug );
		}

		if ( [] === $result['errors'] ) {
			update_option( Topics::OPTION, Topics::LIST_VERSION, false );
			WP_CLI::success( 'Topics match the list.' );
		} else {
			WP_CLI::error( 'Some topics could not be written. Nothing was stamped; the sync runs again on the next page load.' );
		}
	}

	/**
	 * What DGLP's decision means for the old site's categories. Reports only.
	 *
	 * Counts the posts under each legacy category, which topic it maps to,
	 * and which are to be removed. Changes nothing.
	 *
	 * @when after_wp_load
	 */
	public function legacy(): void {
		$rows = [];

		foreach ( Topics::legacy() as $old => $topic ) {
			$term   = get_term_by( 'slug', $old, 'category' );
			$rows[] = [
				'category' => $old,
				'posts'    => $term instanceof \WP_Term ? (string) $term->count : 'not on this site',
				'becomes'  => Topics::all()[ $topic ] ?? $topic,
			];
		}

		foreach ( Topics::retired() as $old ) {
			$term   = get_term_by( 'slug', $old, 'category' );
			$rows[] = [
				'category' => $old,
				'posts'    => $term instanceof \WP_Term ? (string) $term->count : 'not on this site',
				'becomes'  => 'REMOVE',
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'category', 'posts', 'becomes' ] );
		WP_CLI::log( 'Reports only. The plugin does not change core categories.' );
	}
}
