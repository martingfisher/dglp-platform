<?php
/**
 * The public directory of member organisations.
 *
 * A search box and three filters over one grid of cards. Read mode: the
 * visitor is finding an organisation, so the page is built to scan. Every
 * card carries the same four things in the same places: the mark, the
 * name, where they are, what they do.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Org\DirectoryQuery;
use DGL\Org\Options;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public search form; nothing is written.
$args    = DirectoryQuery::args_from( wp_unslash( $_GET ) );
$result  = DirectoryQuery::run( $args );
$filters = DirectoryQuery::filters();
$base    = home_url( '/' . \DGL\Org\Directory::BASE . '/' );
$active  = '' !== $args['q'] || [] !== $args['filters'];

$page_url = static fn( int $page ): string => DirectoryQuery::search_url( [ 'page' => $page ] + $args );

/*
 * The chips above the results: one per value in use, each a link to the
 * same search without it, in the order the filters are defined.
 */
$chips = [];
foreach ( $filters as $param => $filter ) {
	foreach ( (array) ( $args['filters'][ $param ] ?? [] ) as $value ) {
		$chips[] = [
			'label' => (string) ( $filter['options'][ (string) $value ] ?? $value ),
			'url'   => DirectoryQuery::search_url( DirectoryQuery::without( $args, $param, (string) $value ) ),
		];
	}
}
?>
<div class="dgl-pub dgl-dir">
	<header class="dgl-pub__head">
		<h1 class="dgl-pub__title"><?php esc_html_e( 'Organisation Directory', 'dgl-platform' ); ?></h1>
		<p class="dgl-pub__lede">
			<?php esc_html_e( 'Charities, community groups and social enterprises in Leeds. Search by name or by what they do. Want to appear in this directory? Become a member of one of the DGL partners.', 'dgl-platform' ); ?>
		</p>
	</header>

	<form class="dgl-dir__search" method="get" action="<?php echo esc_url( $base ); ?>" role="search">
		<div class="dgl-dir__query">
			<label class="dgl-dir__label" for="dgl-dir-q"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></label>
			<input class="dgl-dir__input" id="dgl-dir-q" type="search" name="q" value="<?php echo esc_attr( $args['q'] ); ?>" placeholder="<?php esc_attr_e( 'Name, or a word from their description', 'dgl-platform' ); ?>">
		</div>

		<?php
		/*
		 * On a phone the four filters fold behind one button, so the search
		 * box and the first results are on the first screen. They start
		 * open when any filter is set, and always show without JavaScript.
		 */
		$filters_on = count( array_filter( $args['filters'] ) );
		?>
		<button class="dgl-dir__toggle" type="button" hidden data-dgl-fold aria-expanded="<?php echo $filters_on > 0 ? 'true' : 'false'; ?>" aria-controls="dgl-dir-filters">
			<?php
			echo $filters_on > 0
				/* translators: %d: how many filters are set. */
				? esc_html( sprintf( _n( 'Filters (%d set)', 'Filters (%d set)', $filters_on, 'dgl-platform' ), $filters_on ) )
				: esc_html__( 'Filters', 'dgl-platform' );
			?>
		</button>

		<div class="dgl-dir__filters" id="dgl-dir-filters" data-dgl-open="<?php echo $filters_on > 0 ? '1' : '0'; ?>">
		<?php foreach ( $filters as $param => $filter ) : ?>
			<?php if ( ! $filter['list'] ) : ?>
			<div class="dgl-dir__filter">
				<label class="dgl-dir__label" for="dgl-dir-<?php echo esc_attr( $param ); ?>"><?php echo esc_html( $filter['label'] ); ?></label>
				<select class="dgl-dir__input" id="dgl-dir-<?php echo esc_attr( $param ); ?>" name="<?php echo esc_attr( $param ); ?>">
					<option value=""><?php esc_html_e( 'Any', 'dgl-platform' ); ?></option>
					<?php foreach ( $filter['options'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $args['filters'][ $param ] ?? '', (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php else : ?>
			<?php
			/*
			 * Several of a list: a panel of checkboxes behind one button. The
			 * button is a <details> summary, so without JavaScript it is a native
			 * disclosure that opens the list in the page; directory.js makes it
			 * float, counts the ticks and adds the type-to-narrow box.
			 */
			$chosen = array_map( 'strval', (array) ( $args['filters'][ $param ] ?? [] ) );
			$ticked = count( $chosen );
			$dd_id  = 'dgl-dir-' . $param;
			?>
			<div class="dgl-dir__filter dgl-dir__multi" data-dgl-multi>
				<span class="dgl-dir__label" id="<?php echo esc_attr( $dd_id ); ?>-label"><?php echo esc_html( $filter['label'] ); ?></span>
				<details class="dgl-dir__dd">
					<summary class="dgl-dir__input dgl-dir__ddbtn" aria-labelledby="<?php echo esc_attr( $dd_id ); ?>-label <?php echo esc_attr( $dd_id ); ?>-text">
						<span class="dgl-dir__ddtext" id="<?php echo esc_attr( $dd_id ); ?>-text" data-dgl-multi-text>
							<?php
							echo $ticked > 0
								/* translators: %d: how many options are ticked. */
								? esc_html( sprintf( _n( '%d selected', '%d selected', $ticked, 'dgl-platform' ), $ticked ) )
								: esc_html__( 'Any', 'dgl-platform' );
							?>
						</span>
					</summary>
					<div class="dgl-dir__ddpanel">
						<label class="dgl-dir__ddfind">
							<span class="screen-reader-text"><?php esc_html_e( 'Narrow the list', 'dgl-platform' ); ?></span>
							<input class="dgl-dir__input" type="search" data-dgl-multi-find autocomplete="off" placeholder="<?php esc_attr_e( 'Type to narrow', 'dgl-platform' ); ?>">
						</label>
						<p class="dgl-dir__ddstatus screen-reader-text" role="status" data-dgl-multi-status></p>
						<?php foreach ( Options::grouped( $filter['options'] ) as $group => $items ) : ?>
							<fieldset class="dgl-dir__ddgroup">
								<legend class="dgl-dir__ddlegend"><?php echo '' === $group ? esc_html__( 'General', 'dgl-platform' ) : esc_html( $group ); ?></legend>
								<?php foreach ( $items as $value => $label ) : ?>
									<label class="dgl-dir__check">
										<input type="checkbox" name="<?php echo esc_attr( $param ); ?>[]" value="<?php echo esc_attr( (string) $value ); ?>"<?php checked( in_array( (string) $value, $chosen, true ) ); ?>>
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php endforeach; ?>
					</div>
				</details>
			</div>
			<?php endif; ?>
		<?php endforeach; ?>
		</div>

		<div class="dgl-dir__actions">
			<button class="dgl-pub__button dgl-dir__button" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
			<?php if ( $active ) : ?>
				<a class="dgl-dir__clear" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</div>
	</form>

	<?php \DGL\Dashboard\View::output( 'public/fold-script' ); ?>

	<?php if ( [] !== $chips ) : ?>
		<ul class="dgl-dir__chips" aria-label="<?php esc_attr_e( 'Filters in use', 'dgl-platform' ); ?>">
			<?php foreach ( $chips as $chip ) : ?>
				<li>
					<a class="dgl-dir__chip" rel="nofollow" href="<?php echo esc_url( $chip['url'] ); ?>">
						<span class="screen-reader-text"><?php esc_html_e( 'Remove filter:', 'dgl-platform' ); ?> </span><?php echo esc_html( $chip['label'] ); ?>
						<span class="dgl-dir__chipx" aria-hidden="true">&times;</span>
					</a>
				</li>
			<?php endforeach; ?>
			<li>
				<a class="dgl-dir__chip dgl-dir__chip--clear" rel="nofollow" href="<?php echo esc_url( DirectoryQuery::search_url( [ 'q' => $args['q'], 'page' => 1, 'filters' => [] ] ) ); ?>"><?php esc_html_e( 'Clear filters', 'dgl-platform' ); ?></a>
			</li>
		</ul>
	<?php endif; ?>

	<p class="dgl-dir__count" role="status">
		<?php
		if ( 0 === $result['total'] && ! $active ) {
			// Nobody listed at all (every organisation confirms its own listing), not a search that found nothing.
			esc_html_e( 'Organisations are being added. Each one appears here once it has confirmed its own listing.', 'dgl-platform' );
		} elseif ( 0 === $result['total'] ) {
			esc_html_e( 'No organisations match. Try fewer filters, or a different word.', 'dgl-platform' );
		} elseif ( $active ) {
			/* translators: %s: number of organisations. */
			echo esc_html( sprintf( _n( '%s organisation matches.', '%s organisations match.', $result['total'], 'dgl-platform' ), number_format_i18n( $result['total'] ) ) );
		} else {
			/* translators: %s: number of organisations. */
			echo esc_html( sprintf( _n( '%s organisation.', '%s organisations, A to Z.', $result['total'], 'dgl-platform' ), number_format_i18n( $result['total'] ) ) );
		}
		?>
	</p>

	<?php if ( [] !== $result['ids'] ) : ?>
		<ul class="dgl-dir__grid" data-dgl-autoload="li">
			<?php foreach ( $result['ids'] as $org_id ) : ?>
				<?php \DGL\Dashboard\View::output( 'public/org-card', [ 'org_id' => $org_id ] ); ?>
			<?php endforeach; ?>
		</ul>

		<?php if ( $result['pages'] > 1 ) : ?>
			<nav class="dgl-pub__pagination" data-dgl-pager="hide" aria-label="<?php esc_attr_e( 'More pages', 'dgl-platform' ); ?>">
				<ul>
					<?php if ( $args['page'] > 1 ) : ?>
						<li><a rel="prev" href="<?php echo esc_url( $page_url( $args['page'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'dgl-platform' ); ?></a></li>
					<?php endif; ?>
					<?php for ( $p = 1; $p <= $result['pages']; $p++ ) : ?>
						<li>
							<?php if ( $p === $args['page'] ) : ?>
								<span class="current" aria-current="page"><?php echo esc_html( (string) $p ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $page_url( $p ) ); ?>"><?php echo esc_html( (string) $p ); ?></a>
							<?php endif; ?>
						</li>
					<?php endfor; ?>
					<?php if ( $args['page'] < $result['pages'] ) : ?>
						<li><a rel="next" href="<?php echo esc_url( $page_url( $args['page'] + 1 ) ); ?>"><?php esc_html_e( 'Next', 'dgl-platform' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<p class="dgl-dir__foot">
		<?php esc_html_e( 'Member organisations choose whether to appear here from their own dashboard.', 'dgl-platform' ); ?>
	</p>
</div>
