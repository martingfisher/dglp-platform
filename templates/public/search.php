<?php
/**
 * Search results, one section per kind of thing.
 *
 * `$data` from Search::view_data(): term, only (a group key or ''), groups
 * (label, ids, total, more) and total.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;
use DGL\Frontend\Frontend;
use DGL\Frontend\Search;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

$term   = (string) ( $data['term'] ?? '' );
$only   = (string) ( $data['only'] ?? '' );
$groups = (array) ( $data['groups'] ?? [] );
$total  = (int) ( $data['total'] ?? 0 );
?>
<div class="dgl-pub dgl-search">
	<header class="dgl-pub__head">
		<h1 class="dgl-pub__title"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></h1>
		<form class="dgl-search__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="dgl-search-q"><?php esc_html_e( 'Search the site', 'dgl-platform' ); ?></label>
			<input class="dgl-search__input" id="dgl-search-q" type="search" name="s" value="<?php echo esc_attr( $term ); ?>" placeholder="<?php esc_attr_e( 'Organisations, news, events, training', 'dgl-platform' ); ?>" autocomplete="off">
			<button class="dgl-search__go" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
		</form>
		<p class="dgl-pub__lede" aria-live="polite">
			<?php
			if ( '' === $term ) {
				esc_html_e( 'Type a word or two: an organisation, a place, a subject.', 'dgl-platform' );
			} elseif ( 0 === $total ) {
				/* translators: %s: what was searched for. */
				echo esc_html( sprintf( __( 'Nothing matches “%s”.', 'dgl-platform' ), $term ) );
			} elseif ( '' !== $only ) {
				/* translators: 1: a number, 2: a kind of thing (lower case), 3: what was searched for. */
				echo esc_html( sprintf( _n( '%1$s %2$s matches “%3$s”.', '%1$s %2$s match “%3$s”.', $total, 'dgl-platform' ), number_format_i18n( $total ), strtolower( Search::label( $only ) ), $term ) );
			} else {
				/* translators: 1: a number, 2: what was searched for. */
				echo esc_html( sprintf( _n( '%1$s result for “%2$s”.', '%1$s results for “%2$s”.', $total, 'dgl-platform' ), number_format_i18n( $total ), $term ) );
			}
			?>
		</p>
	</header>

	<?php if ( '' !== $term && 0 === $total ) : ?>
		<div class="dgl-search__empty">
			<p><?php esc_html_e( 'Try fewer words, or a different spelling. Or browse:', 'dgl-platform' ); ?></p>
			<ul class="dgl-search__browse">
				<li><a href="<?php echo esc_url( home_url( '/' . \DGL\Org\Directory::BASE . '/' ) ); ?>"><?php esc_html_e( 'Organisations', 'dgl-platform' ); ?></a></li>
				<li><a href="<?php echo esc_url( Frontend::archive_url( PostTypes::NEWS ) ); ?>"><?php esc_html_e( 'News', 'dgl-platform' ); ?></a></li>
				<li><a href="<?php echo esc_url( Frontend::archive_url( PostTypes::EVENT ) ); ?>"><?php esc_html_e( 'Events', 'dgl-platform' ); ?></a></li>
				<li><a href="<?php echo esc_url( Frontend::archive_url( PostTypes::TRAINING ) ); ?>"><?php esc_html_e( 'Training', 'dgl-platform' ); ?></a></li>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $only && '' !== $term ) : ?>
		<p class="dgl-search__back"><a href="<?php echo esc_url( Search::url( $term ) ); ?>"><?php esc_html_e( 'All results', 'dgl-platform' ); ?></a></p>
	<?php endif; ?>

	<?php foreach ( $groups as $key => $group ) : ?>
		<section class="dgl-search__group" aria-labelledby="dgl-search-<?php echo esc_attr( $key ); ?>">
			<div class="dgl-search__grouphead">
				<h2 class="dgl-listing__heading" id="dgl-search-<?php echo esc_attr( $key ); ?>">
					<?php echo esc_html( $group['label'] ); ?>
					<span class="dgl-search__count"><?php echo esc_html( number_format_i18n( $group['total'] ) ); ?></span>
				</h2>
				<?php if ( count( $group['ids'] ) < $group['total'] ) : ?>
					<a class="dgl-search__more" href="<?php echo esc_url( $group['more'] ); ?>">
						<?php
						/* translators: 1: a number, 2: a kind of thing (lower case). */
						echo esc_html( sprintf( __( 'See all %1$s %2$s', 'dgl-platform' ), number_format_i18n( $group['total'] ), strtolower( $group['label'] ) ) );
						?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( 'organisations' === $key ) : ?>
				<ul class="dgl-dir__grid dgl-search__orgs">
					<?php foreach ( $group['ids'] as $org_id ) : ?>
						<?php View::output( 'public/org-card', [ 'org_id' => (int) $org_id ] ); ?>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<ul class="dgl-pub__list">
					<?php foreach ( $group['ids'] as $item_id ) : ?>
						<?php $item = get_post( (int) $item_id ); ?>
						<?php if ( $item instanceof WP_Post ) : ?>
							<?php View::output( 'public/card-row', [ 'item' => $item ] ); ?>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
