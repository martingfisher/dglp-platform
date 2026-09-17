<?php
/**
 * The events calendar: eight weeks, day by day.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Frontend;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

$from  = $data['from'];
$to    = $data['to'];
$days  = (array) ( $data['days'] ?? [] );
$month = '';
?>
<div class="dgl-pub dgl-cal">
	<header class="dgl-pub__head">
		<h1 class="dgl-pub__title"><?php esc_html_e( 'Events calendar', 'dgl-platform' ); ?></h1>
		<p class="dgl-pub__lede">
			<?php
			printf(
				/* translators: 1: first date, 2: last date. */
				esc_html__( '%1$s to %2$s, day by day. Repeating events appear on every day they run.', 'dgl-platform' ),
				esc_html( wp_date( 'j F', $from->getTimestamp() ) ),
				esc_html( wp_date( 'j F Y', $to->getTimestamp() ) )
			);
			?>
			<a class="dgl-pub__callink" href="<?php echo esc_url( Frontend::archive_url( PostTypes::EVENT ) ); ?>"><?php esc_html_e( 'See the list instead', 'dgl-platform' ); ?></a>
		</p>
	</header>

	<nav class="dgl-cal__nav" aria-label="<?php esc_attr_e( 'Other dates', 'dgl-platform' ); ?>">
		<?php if ( ! empty( $data['earlier'] ) ) : ?>
			<a class="dgl-cal__navlink" href="<?php echo esc_url( (string) $data['earlier'] ); ?>"><?php esc_html_e( 'Earlier', 'dgl-platform' ); ?></a>
		<?php endif; ?>
		<span class="dgl-cal__months">
			<span class="dgl-cal__monthslabel"><?php esc_html_e( 'Jump to', 'dgl-platform' ); ?></span>
			<?php foreach ( (array) ( $data['months'] ?? [] ) as $label => $url ) : ?>
				<a class="dgl-cal__navlink" href="<?php echo esc_url( (string) $url ); ?>"><?php echo esc_html( (string) $label ); ?></a>
			<?php endforeach; ?>
		</span>
		<?php if ( ! empty( $data['later'] ) ) : ?>
			<a class="dgl-cal__navlink" rel="next" href="<?php echo esc_url( (string) $data['later'] ); ?>"><?php esc_html_e( 'Later', 'dgl-platform' ); ?></a>
		<?php endif; ?>
	</nav>

	<?php if ( [] === $days ) : ?>
		<div class="dgl-pub__card dgl-pub__empty">
			<p><?php esc_html_e( 'Nothing is listed for these weeks yet.', 'dgl-platform' ); ?></p>
		</div>
	<?php else : ?>
		<div class="dgl-cal__days" data-dgl-autoload>
			<?php foreach ( $days as $date => $rows ) : ?>
				<?php
				$day  = new DateTimeImmutable( $date, wp_timezone() );
				$this_month = wp_date( 'F Y', $day->getTimestamp() );
				?>
				<?php if ( $this_month !== $month ) : ?>
					<?php $month = $this_month; ?>
					<h2 class="dgl-cal__month"><?php echo esc_html( $month ); ?></h2>
				<?php endif; ?>
				<section class="dgl-cal__day" aria-labelledby="dgl-cal-<?php echo esc_attr( $date ); ?>">
					<h3 class="dgl-cal__date" id="dgl-cal-<?php echo esc_attr( $date ); ?>"><?php echo esc_html( wp_date( 'l j', $day->getTimestamp() ) ); ?></h3>
					<ul class="dgl-cal__list">
						<?php foreach ( $rows as $row ) : ?>
							<li class="dgl-cal__row">
								<span class="dgl-cal__time">
									<?php echo esc_html( wp_date( 'H:i', $row['start']->getTimestamp() ) ); ?><?php if ( null !== $row['end'] ) : ?> <?php esc_html_e( 'to', 'dgl-platform' ); ?> <?php echo esc_html( wp_date( 'H:i', $row['end']->getTimestamp() ) ); ?><?php endif; ?>
								</span>
								<span class="dgl-cal__body">
									<a class="dgl-cal__title" href="<?php echo esc_url( get_permalink( $row['post'] ) ); ?>"><?php echo esc_html( get_the_title( $row['post'] ) ); ?></a>
									<?php
									$venue = (string) Frontend::value( $row['post'], 'venue_name' );
									$org   = Frontend::organisation( $row['post'] );
									$where = implode( ' · ', array_filter( [ $venue, $org['name'] ] ) );
									?>
									<?php if ( '' !== $where ) : ?>
										<span class="dgl-cal__where"><?php echo esc_html( $where ); ?></span>
									<?php endif; ?>
								</span>
								<?php if ( $row['series'] ) : ?>
									<span class="dgl-cal__tag"><?php esc_html_e( 'Repeats', 'dgl-platform' ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p class="dgl-pub__back"><a href="<?php echo esc_url( Frontend::archive_url( PostTypes::EVENT ) ); ?>"><?php esc_html_e( 'All events', 'dgl-platform' ); ?></a></p>
</div>
