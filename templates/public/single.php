<?php
/**
 * One published submission, as the public sees it.
 *
 * Follows the wireframe's item layout: breadcrumb, type and date line, a large
 * heading, the summary as a standfirst, then the facts the member gave, the
 * description, and whatever action the thing actually has.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

$post = $data['post'] ?? null;

if ( ! $post instanceof WP_Post ) {
	return;
}

$type      = (string) ( $data['type'] ?? $post->post_type );
$facts     = Frontend::facts( $post );
$org       = Frontend::organisation( $post );
$booking   = Frontend::booking_url( $post );
$summary   = (string) get_post_meta( (int) $post->ID, 'summary', true );
$body      = (string) get_post_meta( (int) $post->ID, 'body', true );
$image_id  = (int) get_post_meta( (int) $post->ID, 'image', true );
$archive   = Frontend::archive_url( $type );
$passed    = Frontend::has_passed( $post );
$has_body  = '' !== trim( wp_strip_all_tags( $body ) );

/**
 * Whether to render the plugin's own breadcrumb.
 *
 * Off by default. Most themes worth using already output one, and Blocksy -
 * the theme this is built for - certainly does, so rendering ours as well
 * gives the visitor the same trail twice in two different styles. The
 * "See all" link at the foot of the page is the navigation that is actually
 * ours to provide.
 *
 * @param bool   $show Whether to render it.
 * @param string $type The content type being viewed.
 */
$show_crumbs = (bool) apply_filters( 'dgl_public_breadcrumb', false, $type );
?>
<div class="dgl-pub">
	<article class="dgl-pub__item">

		<?php if ( $show_crumbs ) : ?>
		<nav class="dgl-pub__crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'dgl-platform' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'dgl-platform' ); ?></a>
			<?php if ( '' !== $archive ) : ?>
				<span aria-hidden="true">/</span>
				<a href="<?php echo esc_url( $archive ); ?>"><?php echo esc_html( Frontend::type_label( $type, true ) ); ?></a>
			<?php endif; ?>
		</nav>
		<?php endif; ?>

		<p class="dgl-pub__kicker">
			<?php echo esc_html( Frontend::type_label( $type ) ); ?>
			<?php if ( '' !== $org['name'] ) : ?>
				<span class="dgl-pub__org">
					<?php
					printf(
						/* translators: %s: organisation name. */
						esc_html__( 'from %s', 'dgl-platform' ),
						esc_html( $org['name'] )
					);
					?>
				</span>
			<?php endif; ?>
		</p>

		<h1 class="dgl-pub__title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>

		<?php if ( $passed ) : ?>
			<p class="dgl-pub__passed">
				<?php esc_html_e( 'This has already taken place. It is kept here for reference.', 'dgl-platform' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== trim( $summary ) ) : ?>
			<p class="dgl-pub__standfirst"><?php echo esc_html( $summary ); ?></p>
		<?php endif; ?>

		<?php if ( $image_id > 0 ) : ?>
			<div class="dgl-pub__image">
				<?php echo wp_get_attachment_image( $image_id, 'large', false, [ 'loading' => 'eager' ] ); ?>
			</div>
		<?php endif; ?>

		<?php /* No description means no left column, or the facts sit beside an empty half-page. */ ?>
		<div class="dgl-pub__layout<?php echo $has_body ? '' : ' dgl-pub__layout--nobody'; ?>">
			<?php if ( $has_body ) : ?>
				<div class="dgl-pub__body">
					<?php echo wp_kses_post( wpautop( $body ) ); ?>
				</div>
			<?php endif; ?>

			<aside class="dgl-pub__aside">
				<?php if ( [] !== $facts ) : ?>
					<div class="dgl-pub__card">
						<h2 class="dgl-pub__card-title"><?php esc_html_e( 'Details', 'dgl-platform' ); ?></h2>
						<dl class="dgl-pub__facts">
							<?php foreach ( $facts as $fact ) : ?>
								<dt><?php echo esc_html( $fact['label'] ); ?></dt>
								<dd><?php echo wp_kses_post( $fact['value'] ); ?></dd>
							<?php endforeach; ?>
						</dl>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $booking && ! $passed ) : ?>
					<div class="dgl-pub__card dgl-pub__card--action">
						<a class="dgl-pub__button" href="<?php echo esc_url( $booking ); ?>" rel="nofollow noopener" target="_blank">
							<?php esc_html_e( 'Book or find out more', 'dgl-platform' ); ?>
						</a>
						<p class="dgl-pub__note">
							<?php esc_html_e( 'This opens the organisation\'s own page. Booking is handled by them, not by us.', 'dgl-platform' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $org['name'] ) : ?>
					<div class="dgl-pub__card">
						<h2 class="dgl-pub__card-title"><?php esc_html_e( 'Posted by', 'dgl-platform' ); ?></h2>
						<p class="dgl-pub__orgname"><?php echo esc_html( $org['name'] ); ?></p>
						<p class="dgl-pub__note">
							<?php esc_html_e( 'A member of the Doing Good Leeds Partnership.', 'dgl-platform' ); ?>
						</p>
					</div>
				<?php endif; ?>
			</aside>
		</div>

		<?php if ( '' !== $archive ) : ?>
			<p class="dgl-pub__back">
				<a href="<?php echo esc_url( $archive ); ?>">
					<?php
					printf(
						/* translators: %s: plural type label, e.g. Events. */
						esc_html__( 'See all %s', 'dgl-platform' ),
						esc_html( strtolower( Frontend::type_label( $type, true ) ) )
					);
					?>
				</a>
			</p>
		<?php endif; ?>

	</article>
</div>
