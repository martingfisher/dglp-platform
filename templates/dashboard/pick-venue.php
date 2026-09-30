<?php
/**
 * Which venue is the new space at?
 *
 * Shown when a member starts a space without coming from a venue.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Spaces\Link;

defined( 'ABSPATH' ) || exit;

$venues = (array) ( $data['venues'] ?? [] );
?>
<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Dashboard', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span> <?php esc_html_e( 'New space', 'dgl-platform' ); ?>
		</p>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Which venue is it at?', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'A space is a room, hall, kitchen, garden or whole building at one of your venues. Pick the venue first.', 'dgl-platform' ); ?></p>
	</div>
</header>

<?php if ( [] === $venues ) : ?>
	<section class="dgl-card">
		<p><?php esc_html_e( 'You have no venues yet. Add the building first, then its spaces.', 'dgl-platform' ); ?></p>
		<p><a class="dgl-button" href="<?php echo esc_url( (string) $data['venue_url'] ); ?>"><?php esc_html_e( 'Add a venue', 'dgl-platform' ); ?></a></p>
	</section>
<?php else : ?>
	<ul class="dgl-tiles">
		<?php foreach ( $venues as $venue ) : ?>
			<li>
				<a class="dgl-tile" href="<?php echo esc_url( Link::add_space_url( (int) $venue->ID ) ); ?>">
					<span class="dgl-tile__label"><?php echo esc_html( '' !== $venue->post_title ? $venue->post_title : __( 'Untitled venue', 'dgl-platform' ) ); ?></span>
					<span class="dgl-tile__blurb"><?php echo View::chip( (string) $venue->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="dgl-tile__cta"><?php esc_html_e( 'Add a space here', 'dgl-platform' ); ?> <span aria-hidden="true">-&gt;</span></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="dgl-help"><a href="<?php echo esc_url( (string) $data['venue_url'] ); ?>"><?php esc_html_e( 'Or add another venue', 'dgl-platform' ); ?></a></p>
<?php endif; ?>
