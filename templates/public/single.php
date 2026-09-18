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
$summary   = (string) Frontend::value( $post, 'summary' );
$body      = (string) Frontend::value( $post, 'body' );
$image_id  = (int) Frontend::value( $post, 'image' );
$archive   = Frontend::archive_url( $type );
$passed    = Frontend::has_passed( $post );
$schedule  = Frontend::schedule( $post );
$has_body  = '' !== trim( wp_strip_all_tags( $body ) );
$cancelled = \DGL\Events\Cancel::is_cancelled( (int) $post->ID );
$ics       = $cancelled || ( null !== $schedule && $schedule['ended'] ) || ( $passed && null === $schedule ) ? '' : \DGL\Events\Ics::url_for( $post );

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

		<?php if ( $cancelled ) : ?>
			<p class="dgl-pub__passed dgl-pub__passed--off">
				<strong><?php echo esc_html( null !== $schedule ? __( 'This series has been cancelled.', 'dgl-platform' ) : __( 'This event has been cancelled.', 'dgl-platform' ) ); ?></strong>
				<?php $cancel_note = \DGL\Events\Cancel::note( (int) $post->ID ); ?>
				<?php if ( '' !== $cancel_note ) : ?>
					<?php echo esc_html( $cancel_note ); ?>
				<?php else : ?>
					<?php esc_html_e( 'The organisation that posted it has taken it off.', 'dgl-platform' ); ?>
				<?php endif; ?>
			</p>
		<?php elseif ( null !== $schedule && $schedule['ended'] ) : ?>
			<p class="dgl-pub__passed">
				<?php esc_html_e( 'This series has finished. It is kept here for reference.', 'dgl-platform' ); ?>
			</p>
		<?php elseif ( $passed && null === $schedule ) : ?>
			<p class="dgl-pub__passed">
				<?php esc_html_e( 'This has already taken place. It is kept here for reference.', 'dgl-platform' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( null !== $schedule && ! $schedule['ended'] && ! $cancelled ) : ?>
			<div class="dgl-pub__when">
				<p class="dgl-pub__whenline"><?php echo esc_html( $schedule['wording'] ); ?></p>
				<p class="dgl-pub__nextlabel"><?php esc_html_e( 'Next dates', 'dgl-platform' ); ?></p>
				<ul class="dgl-pub__dates">
					<?php foreach ( $schedule['next'] as $occurrence ) : ?>
						<li<?php echo $occurrence->cancelled ? ' class="dgl-pub__date--off"' : ''; ?>>
							<?php echo esc_html( wp_date( 'l j F', $occurrence->start->getTimestamp() ) ); ?>,
							<?php echo esc_html( wp_date( 'H:i', $occurrence->start->getTimestamp() ) ); ?><?php if ( null !== $occurrence->end ) : ?> <?php esc_html_e( 'to', 'dgl-platform' ); ?> <?php echo esc_html( wp_date( 'H:i', $occurrence->end->getTimestamp() ) ); ?><?php endif; ?>
							<?php if ( $occurrence->cancelled ) : ?>
								<span class="dgl-pub__offtag"><?php esc_html_e( 'Cancelled', 'dgl-platform' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="dgl-pub__note"><a href="<?php echo esc_url( \DGL\Events\Calendar::url() ); ?>"><?php esc_html_e( 'See it on the events calendar', 'dgl-platform' ); ?></a></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $ics ) : ?>
			<p class="dgl-pub__addcal">
				<a href="<?php echo esc_url( $ics ); ?>" download><?php esc_html_e( 'Add to your calendar', 'dgl-platform' ); ?></a>
				<span class="dgl-pub__addcal-note"><?php echo esc_html( null !== $schedule ? __( 'Every date it runs, as one entry.', 'dgl-platform' ) : __( 'Opens in Google, Outlook or Apple Calendar.', 'dgl-platform' ) ); ?></span>
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

				<?php if ( '' !== $booking && ! $passed && ! $cancelled ) : ?>
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
