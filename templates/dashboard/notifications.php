<?php
/**
 * Everything that has happened to this organisation's submissions. Wireframe 1j.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;

$rows = $data['rows'] ?? [];
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Notifications', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php esc_html_e( 'Every decision the team has made on your submissions. You get an email for each of these too.', 'dgl-platform' ); ?>
		</p>
	</div>
</header>

<?php if ( empty( $rows ) ) : ?>
	<p class="dgl-empty">
		<?php esc_html_e( 'Nothing yet. Decisions on anything you submit will appear here.', 'dgl-platform' ); ?>
	</p>
<?php else : ?>
	<ol class="dgl-feed" data-dgl-autoload="li">
		<?php foreach ( $rows as $row ) : ?>
			<li class="dgl-feed__item dgl-feed__item--<?php echo esc_attr( $row['tone'] ); ?>">
				<p class="dgl-feed__what">
					<?php echo esc_html( $row['title'] ); ?>
					<?php if ( ! empty( $row['is_edit'] ) ) : ?>
						<span class="dgl-edit-flag"><?php esc_html_e( 'Edit', 'dgl-platform' ); ?></span>
					<?php endif; ?>
				</p>

				<?php if ( '' !== $row['subject'] ) : ?>
					<p class="dgl-feed__subject">
						<?php if ( '' !== $row['url'] ) : ?>
							<a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['subject'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $row['subject'] ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== trim( (string) $row['note'] ) ) : ?>
					<p class="dgl-feed__note"><?php echo esc_html( $row['note'] ); ?></p>
				<?php endif; ?>

				<p class="dgl-feed__when">
					<?php
					printf(
						/* translators: 1: who did it, 2: when. */
						esc_html__( '%1$s, %2$s', 'dgl-platform' ),
						esc_html( $row['who'] ),
						esc_html( View::date( $row['when'], true ) )
					);
					?>
				</p>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
	View::output(
		'dashboard/pager',
		[
			'total' => (int) ( $data['total'] ?? 0 ),
			'page'  => (int) ( $data['page'] ?? 1 ),
			'pages' => (int) ( $data['pages'] ?? 1 ),
			'first' => (int) ( $data['first'] ?? 0 ),
			'last'  => (int) ( $data['last'] ?? 0 ),
			'base'  => (string) ( $data['base'] ?? '' ),
			'noun'  => __( 'notifications', 'dgl-platform' ),
		]
	);
	?>
<?php endif; ?>
