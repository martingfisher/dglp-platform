<?php
/**
 * The public directory of member organisations.
 *
 * A search box and four filters over one grid of cards. Read mode: the
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

$page_url = static function ( int $page ) use ( $args, $base ): string {
	$query = array_merge( [ 'q' => $args['q'] ], $args['filters'] );
	$query = array_filter( $query, static fn( string $v ): bool => '' !== $v );

	if ( $page > 1 ) {
		$query['pg'] = $page;
	}

	return [] === $query ? $base : add_query_arg( array_map( 'rawurlencode', $query ), $base );
};
?>
<div class="dgl-pub dgl-dir">
	<header class="dgl-pub__head">
		<h1 class="dgl-pub__title"><?php esc_html_e( 'Member organisations', 'dgl-platform' ); ?></h1>
		<p class="dgl-pub__lede">
			<?php esc_html_e( 'Charities, community groups and social enterprises in the Doing Good Leeds Partnership. Search by name or by what they do.', 'dgl-platform' ); ?>
		</p>
	</header>

	<form class="dgl-dir__search" method="get" action="<?php echo esc_url( $base ); ?>" role="search">
		<div class="dgl-dir__query">
			<label class="dgl-dir__label" for="dgl-dir-q"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></label>
			<input class="dgl-dir__input" id="dgl-dir-q" type="search" name="q" value="<?php echo esc_attr( $args['q'] ); ?>" placeholder="<?php esc_attr_e( 'Name, or a word from their description', 'dgl-platform' ); ?>">
		</div>

		<?php foreach ( $filters as $param => $filter ) : ?>
			<div class="dgl-dir__filter">
				<label class="dgl-dir__label" for="dgl-dir-<?php echo esc_attr( $param ); ?>"><?php echo esc_html( $filter['label'] ); ?></label>
				<select class="dgl-dir__input" id="dgl-dir-<?php echo esc_attr( $param ); ?>" name="<?php echo esc_attr( $param ); ?>">
					<option value=""><?php esc_html_e( 'Any', 'dgl-platform' ); ?></option>
					<?php foreach ( $filter['options'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $args['filters'][ $param ] ?? '', (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endforeach; ?>

		<div class="dgl-dir__actions">
			<button class="dgl-pub__button dgl-dir__button" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
			<?php if ( $active ) : ?>
				<a class="dgl-dir__clear" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</div>
	</form>

	<p class="dgl-dir__count" role="status">
		<?php
		if ( 0 === $result['total'] ) {
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
				<?php
				$name    = (string) get_the_title( $org_id );
				$logo_id = (int) get_post_meta( $org_id, 'dgl_org_logo', true );
				$blurb   = (string) get_post_meta( $org_id, 'dgl_org_description', true );
				$ward    = Options::wards()[ (string) get_post_meta( $org_id, 'dgl_org_ward', true ) ] ?? '';
				$city    = (string) get_post_meta( $org_id, 'dgl_org_city', true );
				$areas   = array_intersect_key( Options::specialism(), array_flip( (array) get_post_meta( $org_id, 'dgl_org_specialism', true ) ) );
				// The ward, else the town. Forum Central's City column holds street lines in places, so anything with a digit or a comma is not a town.
				$where   = '' !== $ward ? $ward : ( 1 === preg_match( '/^[^\d,]+$/', $city ) ? $city : '' );
				?>
				<li class="dgl-dir__card">
					<a class="dgl-dir__cardlink" href="<?php echo esc_url( DirectoryQuery::url( $org_id ) ); ?>">
						<span class="dgl-dir__mark" aria-hidden="true">
							<?php if ( $logo_id > 0 ) : ?>
								<?php echo wp_get_attachment_image( $logo_id, 'thumbnail', false, [ 'alt' => '', 'loading' => 'lazy' ] ); ?>
							<?php else : ?>
								<span class="dgl-dir__initial"><?php echo esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ); ?></span>
							<?php endif; ?>
						</span>
						<span class="dgl-dir__cardbody">
							<span class="dgl-dir__name"><?php echo esc_html( $name ); ?></span>
							<?php if ( '' !== $where ) : ?>
								<span class="dgl-dir__where"><?php echo esc_html( $where ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $blurb ) : ?>
								<span class="dgl-dir__blurb"><?php echo esc_html( wp_trim_words( $blurb, 24, '…' ) ); ?></span>
							<?php endif; ?>
							<?php if ( [] !== $areas ) : ?>
								<span class="dgl-dir__tags">
									<?php foreach ( array_slice( $areas, 0, 3 ) as $label ) : ?>
										<span class="dgl-dir__tag"><?php echo esc_html( $label ); ?></span>
									<?php endforeach; ?>
									<?php if ( count( $areas ) > 3 ) : ?>
										<span class="dgl-dir__tag dgl-dir__tag--more">+<?php echo esc_html( (string) ( count( $areas ) - 3 ) ); ?></span>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</span>
					</a>
				</li>
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
