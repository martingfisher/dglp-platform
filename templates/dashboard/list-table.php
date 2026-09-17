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

$items  = $data['items'] ?? [];
$action = (string) ( $data['action'] ?? '' );
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
				<?php if ( '' !== $action ) : ?>
					<th scope="col"><span class="dgl-visually-hidden"><?php esc_html_e( 'Action', 'dgl-platform' ); ?></span></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody data-dgl-autoload="tr">
			<?php foreach ( $items as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
					<td>
						<?php echo esc_html( $row['type'] ); ?>
						<?php if ( ! empty( $row['is_edit'] ) ) : ?>
							<span class="dgl-edit-flag"><?php esc_html_e( 'Edit', 'dgl-platform' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo View::chip( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo esc_html( View::date( $row['updated'], true ) ); ?></td>
					<?php if ( '' !== $action ) : ?>
						<td class="dgl-table__action">
							<a class="dgl-button dgl-button--small" href="<?php echo esc_url( $row['url'] ); ?>">
								<?php echo esc_html( $action ); ?><span class="dgl-visually-hidden">: <?php echo esc_html( $row['title'] ); ?></span>
							</a>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
