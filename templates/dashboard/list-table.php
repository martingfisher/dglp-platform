<?php
/**
 * Shared item table used by the category and archive screens.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;

$items = $data['items'] ?? [];
?>
<?php if ( empty( $items ) ) : ?>
	<p class="dgl-empty"><?php echo esc_html( $data['empty'] ?? __( 'Nothing here yet.', 'dgl-platform' ) ); ?></p>
<?php else : ?>
	<table class="dgl-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Item', 'dgl-platform' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'dgl-platform' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'dgl-platform' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Updated', 'dgl-platform' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $items as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
					<td><?php echo esc_html( $row['type'] ); ?></td>
					<td><?php echo View::chip( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo esc_html( View::date( $row['updated'], true ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
