<?php
/**
 * One pager for every member-area list.
 *
 * Newer and Older rather than numbers: these lists are newest-first and
 * nobody wants page 7 of their archive by number. The next link carries
 * rel="next" so the autoload script can follow it; without JavaScript the
 * links are the whole mechanism.
 *
 * @var array<string,mixed> $data  total, page, pages, first, last, base, noun
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$pages = (int) ( $data['pages'] ?? 1 );
$page  = (int) ( $data['page'] ?? 1 );
$base  = (string) ( $data['base'] ?? '' );

if ( $pages < 2 ) {
	return;
}
?>
<nav class="dgl-pager" data-dgl-pager="hide" aria-label="<?php echo esc_attr( (string) ( $data['label'] ?? __( 'More pages', 'dgl-platform' ) ) ); ?>">
	<p class="dgl-pager__count">
		<?php
		printf(
			/* translators: 1: first row number, 2: last row number, 3: total, 4: what they are ("waiting", "items"). */
			esc_html__( 'Showing %1$d to %2$d of %3$d %4$s.', 'dgl-platform' ),
			(int) ( $data['first'] ?? 0 ),
			(int) ( $data['last'] ?? 0 ),
			(int) ( $data['total'] ?? 0 ),
			esc_html( (string) ( $data['noun'] ?? __( 'items', 'dgl-platform' ) ) )
		);
		?>
	</p>

	<p class="dgl-pager__links">
		<?php if ( $page > 1 ) : ?>
			<a class="dgl-button dgl-button--secondary dgl-button--small" rel="prev"
				href="<?php echo esc_url( $page - 1 > 1 ? add_query_arg( 'paged', $page - 1, $base ) : $base ); ?>">
				<?php esc_html_e( 'Newer', 'dgl-platform' ); ?>
			</a>
		<?php endif; ?>

		<span class="dgl-pager__where">
			<?php
			printf(
				/* translators: 1: current page, 2: total pages. */
				esc_html__( 'Page %1$d of %2$d', 'dgl-platform' ),
				$page,
				$pages
			);
			?>
		</span>

		<?php if ( $page < $pages ) : ?>
			<a class="dgl-button dgl-button--secondary dgl-button--small" rel="next"
				href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $base ) ); ?>">
				<?php esc_html_e( 'Older', 'dgl-platform' ); ?>
			</a>
		<?php endif; ?>
	</p>
</nav>
