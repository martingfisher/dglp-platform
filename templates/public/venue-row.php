<?php
/**
 * One venue in the Find a space results: picture, name, where, who runs
 * it, and the rooms that matched, with capacity and rate.
 *
 * Expects `$data['venue_id']` and, optionally, `$data['args']` (the
 * search arguments, so the row can say which spaces matched) and
 * `$data['limit']` (how many spaces to list, default 3).
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
use DGL\Frontend\Frontend;
use DGL\Org\Directory;
use DGL\Org\DirectoryQuery;
use DGL\Schema\Types\Space;
use DGL\Schema\Types\Venue;
use DGL\Spaces\SpacesQuery;

defined( 'ABSPATH' ) || exit;

$venue = get_post( (int) ( $data['venue_id'] ?? 0 ) );

if ( ! $venue instanceof WP_Post ) {
	return;
}

$args   = (array) ( $data['args'] ?? [] );
$limit  = max( 1, (int) ( $data['limit'] ?? 3 ) );
$url    = (string) get_permalink( $venue );
$org    = Frontend::organisation( $venue );
$ward   = Venue::wards()[ (string) Frontend::value( $venue, 'ward' ) ] ?? '';
$type   = Venue::labelled( Venue::TYPES )[ (string) Frontend::value( $venue, 'venue_type' ) ] ?? '';
$access = array_intersect_key( Venue::labelled( Venue::ACCESS ), array_flip( (array) Frontend::value( $venue, 'access' ) ) );
$spaces = SpacesQuery::spaces( (int) $venue->ID );
$facts  = SpacesQuery::quick_facts( array_column( $spaces, 'meta' ) );

// The rooms that matched what was asked for lead; the rest follow.
$matched = [] === $args ? $spaces : array_values( array_filter( $spaces, static fn( array $s ): bool => SpacesQuery::space_matches( $s['meta'], $args ) ) );
$shown   = array_slice( $matched, 0, $limit );
$line    = array_values( array_filter( [ $ward, $org['name'], isset( $access['step_free'] ) ? __( 'Step-free', 'dgl-platform' ) : '' ] ) );
?>
<li class="dgl-venue-row">
	<a class="dgl-venue-row__pic" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $venue, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
	<div class="dgl-venue-row__body">
		<?php if ( '' !== $type ) : ?>
			<p class="dgl-card__chip"><?php echo esc_html( $type ); ?></p>
		<?php endif; ?>
		<h2 class="dgl-venue-row__title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( get_the_title( $venue ) ); ?></a></h2>
		<?php if ( [] !== $line ) : ?>
			<p class="dgl-card__meta"><?php foreach ( $line as $part ) : ?><span><?php echo esc_html( $part ); ?></span><?php endforeach; ?></p>
		<?php endif; ?>
		<?php if ( [] !== $shown ) : ?>
			<ul class="dgl-venue-row__rooms">
				<?php foreach ( $shown as $space ) : ?>
					<?php $largest = SpacesQuery::largest( $space['meta'] ); ?>
					<li class="dgl-venue-row__room<?php echo 'whole_building' === (string) ( $space['meta']['space_type'] ?? '' ) ? ' dgl-venue-row__room--whole' : ''; ?>">
						<span class="dgl-venue-row__name">
							<a class="dgl-venue-row__roomname" href="<?php echo esc_url( $url . '#space-' . (int) $space['post']->ID ); ?>"><?php echo esc_html( get_the_title( $space['post'] ) ); ?></a>
							<?php if ( 'whole_building' === (string) ( $space['meta']['space_type'] ?? '' ) ) : ?>
								<span class="dgl-venue-row__whole"><?php echo esc_html( Venue::labelled( Space::TYPES )['whole_building'] ); ?></span>
							<?php endif; ?>
						</span>
						<?php if ( $largest > 0 ) : ?>
							<span class="dgl-venue-row__cap">
								<?php
								/* translators: %s: a number. */
								echo esc_html( sprintf( __( 'up to %s', 'dgl-platform' ), number_format_i18n( $largest ) ) );
								?>
							</span>
						<?php endif; ?>
						<span class="dgl-venue-row__rate"><?php echo esc_html( SpacesQuery::rate_words( $space['meta'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $facts['count'] > count( $shown ) ) : ?>
			<p class="dgl-venue-row__more">
				<a href="<?php echo esc_url( $url ); ?>">
					<?php
					/* translators: %s: a number. */
					echo esc_html( sprintf( _n( 'See all %s spaces', 'See all %s spaces', $facts['count'], 'dgl-platform' ), number_format_i18n( $facts['count'] ) ) );
					?>
				</a>
			</p>
		<?php endif; ?>
	</div>
</li>
