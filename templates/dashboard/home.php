<?php
/**
 * Dashboard home. Wireframe 1d.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

$counts = $data['counts'] ?? [];
$org    = $data['org'] ?? null;

$tiles = [
	[ 'label' => __( 'Awaiting review', 'dgl-platform' ), 'value' => $counts[ Statuses::PENDING ] ?? 0, 'accent' => false ],
	[ 'label' => __( 'Live on site', 'dgl-platform' ), 'value' => $counts[ Statuses::LIVE ] ?? 0, 'accent' => false ],
	[ 'label' => __( 'Needs your attention', 'dgl-platform' ), 'value' => $counts[ Statuses::CHANGES ] ?? 0, 'accent' => true ],
	[ 'label' => __( 'Drafts', 'dgl-platform' ), 'value' => $counts[ Statuses::DRAFT ] ?? 0, 'accent' => false ],
];
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Your dashboard', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php if ( null !== $org ) : ?>
				<?php
				printf(
					/* translators: %s: organisation name. */
					esc_html__( 'Posting as %s. Everything you submit is checked before it appears on the site.', 'dgl-platform' ),
					esc_html( get_the_title( $org ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Everything you submit is checked before it appears on the site.', 'dgl-platform' ); ?>
			<?php endif; ?>
		</p>
	</div>
</header>

<ul class="dgl-stats">
	<?php foreach ( $tiles as $tile ) : ?>
		<li class="dgl-stat<?php echo $tile['accent'] && $tile['value'] > 0 ? ' dgl-stat--attention' : ''; ?>">
			<span class="dgl-stat__label"><?php echo esc_html( $tile['label'] ); ?></span>
			<span class="dgl-stat__value"><?php echo esc_html( (string) $tile['value'] ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>

<section class="dgl-section" aria-labelledby="dgl-submit-heading">
	<div class="dgl-section__head">
		<h2 class="dgl-section__title" id="dgl-submit-heading"><?php esc_html_e( 'Submit something', 'dgl-platform' ); ?></h2>
		<p class="dgl-section__note"><?php esc_html_e( 'Each type has its own short form', 'dgl-platform' ); ?></p>
	</div>

	<ul class="dgl-tiles">
		<?php foreach ( $data['tiles'] ?? [] as $tile ) : ?>
			<li>
				<a class="dgl-tile" href="<?php echo esc_url( $tile['url'] ); ?>">
					<span class="dgl-tile__label"><?php echo esc_html( $tile['label'] ); ?></span>
					<span class="dgl-tile__blurb"><?php echo esc_html( $tile['blurb'] ); ?></span>
					<span class="dgl-tile__cta"><?php esc_html_e( 'Start a submission', 'dgl-platform' ); ?> <span aria-hidden="true">-&gt;</span></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</section>

<section class="dgl-section" aria-labelledby="dgl-recent-heading">
	<div class="dgl-section__head">
		<h2 class="dgl-section__title" id="dgl-recent-heading"><?php esc_html_e( 'Recent activity', 'dgl-platform' ); ?></h2>
	</div>

	<?php if ( empty( $data['recent'] ) ) : ?>
		<p class="dgl-empty">
			<?php esc_html_e( 'Nothing here yet. Once you submit something it will show up here with its status.', 'dgl-platform' ); ?>
		</p>
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
				<?php foreach ( $data['recent'] as $row ) : ?>
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
</section>
