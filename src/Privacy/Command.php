<?php
/**
 * WP-CLI: see what the privacy tools would export for an address.
 *
 * @package DGL
 */

declare(strict_types=1);

namespace DGL\Privacy;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl privacy', self::class );
	}

	/**
	 * Show everything the plugin would export for an email address.
	 *
	 * The same data Tools > Export Personal Data produces, on the command
	 * line, so an administrator can answer "what do you hold on me" without
	 * generating a download. Read-only.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The address to look up.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl privacy export someone@example.org
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string> $args
	 */
	public function export( array $args ): void {
		$result = Privacy::export( (string) ( $args[0] ?? '' ) );
		$rows   = [];

		foreach ( $result['data'] as $item ) {
			foreach ( $item['data'] as $field ) {
				$rows[] = [
					'group' => $item['group_label'],
					'item'  => $item['item_id'],
					'field' => $field['name'],
					'value' => $field['value'],
				];
			}
		}

		if ( [] === $rows ) {
			WP_CLI::log( 'Nothing held for that address.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'group', 'item', 'field', 'value' ] );
	}
}
