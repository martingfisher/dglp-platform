<?php
/**
 * Find a space: search and filters over the venues, results grouped by
 * venue with the matching rooms under each, and a map of them.
 *
 * Expects `$data['args']` (SpacesQuery::args_from), `$data['result']`
 * (SpacesQuery::run) and, when the map is on, `$data['pins']`.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;
use DGL\Frontend\Frontend;
use DGL\PostTypes;
use DGL\Schema\Types\Space;
use DGL\Schema\Types\Venue;
use DGL\Spaces\SpacesQuery;

defined( 'ABSPATH' ) || exit;

$args   = (array) ( $data['args'] ?? SpacesQuery::args_from( [] ) );
$result = (array) ( $data['result'] ?? [ 'ids' => [], 'total' => 0, 'pages' => 0 ] );
$pins   = (array) ( $data['pins'] ?? [] );
$base   = Frontend::archive_url( PostTypes::VENUE );
$active = SpacesQuery::is_active( $args );
$total  = (int) $result['total'];

$page_url = static function ( int $page ) use ( $args, $base ): string {
	$query = array_filter(
		[
			'q'      => $args['q'],
			'ward'   => $args['ward'],
			'people' => $args['people'] > 0 ? (string) $args['people'] : '',
			'type'   => $args['type'],
			'price'  => $args['price'],
		],
		static fn( string $v ): bool => '' !== $v
	);

	if ( [] !== $args['access'] ) {
		$query['access'] = $args['access'];
	}

	if ( $page > 1 ) {
		$query['pg'] = $page;
	}

	return [] === $query ? $base : add_query_arg( $query, $base );
};

$filters_on = count( array_filter( [ $args['ward'], $args['type'], $args['price'] ] ) ) + ( [] !== $args['access'] ? 1 : 0 );
?>
<div class="dgl-pub dgl-spaces">
	<header class="dgl-pub__head">
		<h1 class="dgl-pub__title"><?php esc_html_e( 'Find a space in Leeds', 'dgl-platform' ); ?></h1>
		<p class="dgl-pub__lede"><?php esc_html_e( 'Rooms, halls and whole buildings run by organisations in the Doing Good Leeds Partnership. Enquire direct with the venue.', 'dgl-platform' ); ?></p>
	</header>

	<form class="dgl-dir__search dgl-spaces__search" method="get" action="<?php echo esc_url( $base ); ?>" role="search">
		<div class="dgl-dir__query">
			<label class="dgl-dir__label" for="dgl-spaces-q"><?php esc_html_e( 'Venue or what you are planning', 'dgl-platform' ); ?></label>
			<input class="dgl-dir__input" id="dgl-spaces-q" type="search" name="q" value="<?php echo esc_attr( $args['q'] ); ?>" placeholder="<?php esc_attr_e( 'A venue name, or a word from its description', 'dgl-platform' ); ?>">
		</div>
		<div class="dgl-dir__filter">
			<label class="dgl-dir__label" for="dgl-spaces-people"><?php esc_html_e( 'People', 'dgl-platform' ); ?></label>
			<input class="dgl-dir__input" id="dgl-spaces-people" type="number" name="people" min="1" max="<?php echo (int) SpacesQuery::MAX_PEOPLE; ?>" inputmode="numeric" value="<?php echo $args['people'] > 0 ? (int) $args['people'] : ''; ?>" placeholder="<?php esc_attr_e( 'Any', 'dgl-platform' ); ?>">
		</div>

		<button class="dgl-dir__toggle" type="button" hidden data-dgl-fold aria-expanded="<?php echo $filters_on > 0 ? 'true' : 'false'; ?>" aria-controls="dgl-spaces-filters">
			<?php
			echo $filters_on > 0
				/* translators: %d: how many filters are set. */
				? esc_html( sprintf( _n( 'Filters (%d set)', 'Filters (%d set)', $filters_on, 'dgl-platform' ), $filters_on ) )
				: esc_html__( 'Filters', 'dgl-platform' );
			?>
		</button>

		<div class="dgl-dir__filters dgl-spaces__filters" id="dgl-spaces-filters" data-dgl-open="<?php echo $filters_on > 0 ? '1' : '0'; ?>">
			<div class="dgl-dir__filter">
				<label class="dgl-dir__label" for="dgl-spaces-ward"><?php esc_html_e( 'Ward', 'dgl-platform' ); ?></label>
				<select class="dgl-dir__input" id="dgl-spaces-ward" name="ward">
					<option value=""><?php esc_html_e( 'All of Leeds', 'dgl-platform' ); ?></option>
					<?php foreach ( Venue::wards() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $args['ward'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="dgl-dir__filter">
				<label class="dgl-dir__label" for="dgl-spaces-type"><?php esc_html_e( 'Type of space', 'dgl-platform' ); ?></label>
				<select class="dgl-dir__input" id="dgl-spaces-type" name="type">
					<option value=""><?php esc_html_e( 'Any space', 'dgl-platform' ); ?></option>
					<?php foreach ( Venue::labelled( Space::TYPES ) as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $args['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="dgl-dir__filter">
				<label class="dgl-dir__label" for="dgl-spaces-price"><?php esc_html_e( 'Price', 'dgl-platform' ); ?></label>
				<select class="dgl-dir__input" id="dgl-spaces-price" name="price">
					<option value=""><?php esc_html_e( 'Any price', 'dgl-platform' ); ?></option>
					<?php foreach ( Venue::labelled( SpacesQuery::BANDS ) as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $args['price'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<fieldset class="dgl-dir__filter dgl-spaces__access">
				<legend class="dgl-dir__label"><?php esc_html_e( 'Access', 'dgl-platform' ); ?></legend>
				<div class="dgl-spaces__checks">
					<?php foreach ( Venue::labelled( Venue::ACCESS ) as $key => $label ) : ?>
						<label class="dgl-spaces__check">
							<input type="checkbox" name="access[]" value="<?php echo esc_attr( $key ); ?>"<?php checked( in_array( $key, $args['access'], true ) ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		</div>

		<div class="dgl-dir__actions">
			<button class="dgl-pub__button dgl-dir__button" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
			<?php if ( $active ) : ?>
				<a class="dgl-dir__clear" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</div>
	</form>

	<?php View::output( 'public/fold-script' ); ?>

	<div class="dgl-spaces__head">
		<p class="dgl-dir__count" role="status">
			<?php
			if ( 0 === $total ) {
				esc_html_e( 'No venues match. Try fewer filters, or a different word.', 'dgl-platform' );
			} elseif ( $active ) {
				/* translators: %s: a number. */
				echo esc_html( sprintf( _n( '%s venue matches.', '%s venues match.', $total, 'dgl-platform' ), number_format_i18n( $total ) ) );
			} else {
				/* translators: %s: a number. */
				echo esc_html( sprintf( _n( '%s venue with spaces to hire.', '%s venues with spaces to hire, priced ones first.', $total, 'dgl-platform' ), number_format_i18n( $total ) ) );
			}
			?>
		</p>
		<?php if ( [] !== $pins ) : ?>
			<div class="dgl-spaces__view" role="group" aria-label="<?php esc_attr_e( 'Show results as', 'dgl-platform' ); ?>" data-dgl-mapview>
				<button type="button" class="dgl-spaces__viewbtn" aria-pressed="true" data-dgl-view="list"><?php esc_html_e( 'List', 'dgl-platform' ); ?></button>
				<button type="button" class="dgl-spaces__viewbtn" aria-pressed="false" data-dgl-view="map"><?php esc_html_e( 'Map', 'dgl-platform' ); ?></button>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( [] !== $pins ) : ?>
		<div class="dgl-spaces__map" id="dgl-spaces-map" hidden></div>
		<script type="application/json" id="dgl-map-data"><?php echo wp_json_encode( $pins, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
	<?php endif; ?>

	<?php if ( [] !== $result['ids'] ) : ?>
		<ul class="dgl-venue-list" data-dgl-autoload="li">
			<?php foreach ( $result['ids'] as $venue_id ) : ?>
				<?php View::output( 'public/venue-row', [ 'venue_id' => (int) $venue_id, 'args' => $active ? $args : [] ] ); ?>
			<?php endforeach; ?>
		</ul>

		<?php if ( (int) $result['pages'] > 1 ) : ?>
			<nav class="dgl-pub__pagination" data-dgl-pager="hide" aria-label="<?php esc_attr_e( 'More pages', 'dgl-platform' ); ?>">
				<ul>
					<?php if ( $args['page'] > 1 ) : ?>
						<li><a rel="prev" href="<?php echo esc_url( $page_url( $args['page'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'dgl-platform' ); ?></a></li>
					<?php endif; ?>
					<?php for ( $p = 1; $p <= (int) $result['pages']; $p++ ) : ?>
						<li>
							<?php if ( $p === $args['page'] ) : ?>
								<span class="current" aria-current="page"><?php echo esc_html( (string) $p ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $page_url( $p ) ); ?>"><?php echo esc_html( (string) $p ); ?></a>
							<?php endif; ?>
						</li>
					<?php endfor; ?>
					<?php if ( $args['page'] < (int) $result['pages'] ) : ?>
						<li><a rel="next" href="<?php echo esc_url( $page_url( $args['page'] + 1 ) ); ?>"><?php esc_html_e( 'Next', 'dgl-platform' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<p class="dgl-dir__foot"><?php esc_html_e( 'Member organisations list their venues from their own dashboard. Every one is checked by the partnership team before it appears.', 'dgl-platform' ); ?></p>
</div>
