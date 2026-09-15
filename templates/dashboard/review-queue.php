<?php
/**
 * Moderation queue. Wireframe 1k.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'Member submissions waiting for a decision. Oldest first.', 'dgl-platform' ); ?></p>
	</div>
</header>

<?php
View::output(
	'dashboard/list-table',
	[
		'items' => $data['items'] ?? [],
		'empty' => __( 'The queue is empty. New submissions land here as soon as members send them.', 'dgl-platform' ),
	]
);
?>

<?php if ( (int) ( $data['pages'] ?? 1 ) > 1 ) : ?>
	<nav class="dgl-pager" aria-label="<?php esc_attr_e( 'Review queue pages', 'dgl-platform' ); ?>">
		<p class="dgl-pager__count">
			<?php
			printf(
				/* translators: 1: first row number, 2: last row number, 3: total. */
				esc_html__( 'Showing %1$d to %2$d of %3$d waiting.', 'dgl-platform' ),
				(int) $data['first'],
				(int) $data['last'],
				(int) $data['total']
			);
			?>
		</p>

		<p class="dgl-pager__links">
			<?php if ( (int) $data['page'] > 1 ) : ?>
				<a class="dgl-button dgl-button--secondary dgl-button--small"
					href="<?php echo esc_url( add_query_arg( 'paged', (int) $data['page'] - 1, \DGL\Dashboard\Router::url( 'review' ) ) ); ?>">
					<?php esc_html_e( 'Newer', 'dgl-platform' ); ?>
				</a>
			<?php endif; ?>

			<span class="dgl-pager__where">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'dgl-platform' ),
					(int) $data['page'],
					(int) $data['pages']
				);
				?>
			</span>

			<?php if ( (int) $data['page'] < (int) $data['pages'] ) : ?>
				<a class="dgl-button dgl-button--secondary dgl-button--small"
					href="<?php echo esc_url( add_query_arg( 'paged', (int) $data['page'] + 1, \DGL\Dashboard\Router::url( 'review' ) ) ); ?>">
					<?php esc_html_e( 'Older', 'dgl-platform' ); ?>
				</a>
			<?php endif; ?>
		</p>
	</nav>
<?php endif; ?>
