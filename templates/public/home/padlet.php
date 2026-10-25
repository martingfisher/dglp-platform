<?php
/**
 * The health and wellbeing board from Padlet, as this site's cards, in
 * groups. Each card is the post's title, its words, and where it leads;
 * the whole card is the way in and opens the resource in a new tab. A post
 * with a picture shows this site's copy of it; most have none, and the card
 * is built to read well without one.
 *
 * With more than one group, a row of links at the top jumps to each, so a
 * long board is quick to scan on a phone. No script.
 *
 * Expects `$data['groups']` (from Board::grouped()), `$data['heading']`,
 * `$data['all_url']`, `$data['fetched']` and `$data['id']`.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Padlet\Board;

defined( 'ABSPATH' ) || exit;

$groups   = array_values( array_filter( (array) ( $data['groups'] ?? [] ), 'is_array' ) );
$heading  = (string) ( $data['heading'] ?? '' );
$all_url  = (string) ( $data['all_url'] ?? '' );
$fetched  = (int) ( $data['fetched'] ?? 0 );
$id       = (string) ( $data['id'] ?? 'dgl-padlet-' . wp_unique_id() );
$new_tab  = '<span class="dgl-home__srlabel"> ' . esc_html__( '(opens in a new tab)', 'dgl-platform' ) . '</span>';
$labelled = '' !== $heading ? ' aria-labelledby="' . esc_attr( $id ) . '-h"' : ' aria-label="' . esc_attr__( 'Health and wellbeing support', 'dgl-platform' ) . '"';
$h_group  = '' !== $heading ? 'h3' : 'h2';
$h_item   = '' !== $heading ? 'h4' : 'h3';
?>
<section class="dgl-home__section dgl-padlet" id="<?php echo esc_attr( $id ); ?>"<?php echo $labelled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
	<?php if ( '' !== $heading || '' !== $all_url ) : ?>
		<div class="dgl-home__sechead">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="dgl-home__h2" id="<?php echo esc_attr( $id ); ?>-h"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $all_url ) : ?>
				<a class="dgl-home__all" href="<?php echo esc_url( $all_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See the full board on Padlet', 'dgl-platform' ); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( [] === $groups ) : ?>
		<p class="dgl-home__empty"><?php esc_html_e( 'Health and wellbeing support will appear here after the overnight update.', 'dgl-platform' ); ?></p>
	<?php else : ?>
		<?php if ( count( $groups ) > 1 ) : ?>
			<nav class="dgl-padlet__jump" aria-label="<?php esc_attr_e( 'Jump to a group', 'dgl-platform' ); ?>">
				<ul>
					<?php foreach ( $groups as $group ) : ?>
						<li><a href="#<?php echo esc_attr( $id . '-' . $group['id'] ); ?>"><?php echo esc_html( (string) $group['name'] ); ?> <span class="dgl-padlet__count"><?php echo (int) count( $group['items'] ); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>

		<?php foreach ( $groups as $group ) : ?>
			<?php $gid = $id . '-' . $group['id']; ?>
			<section class="dgl-padlet__group" id="<?php echo esc_attr( $gid ); ?>" aria-labelledby="<?php echo esc_attr( $gid ); ?>-h">
				<<?php echo $h_group; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed tag name. ?> class="dgl-padlet__h" id="<?php echo esc_attr( $gid ); ?>-h"><?php echo esc_html( (string) $group['name'] ); ?></<?php echo $h_group; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed tag name. ?>>
				<?php if ( '' !== (string) ( $group['intro'] ?? '' ) ) : ?>
					<p class="dgl-padlet__intro"><?php echo esc_html( (string) $group['intro'] ); ?></p>
				<?php endif; ?>
				<ul class="dgl-padlet__list">
					<?php foreach ( $group['items'] as $item ) : ?>
						<?php
						$picture = Board::picture_url( $item );
						$kind    = (string) ( $item['kind'] ?? '' );
						$text    = (string) ( $item['text'] ?? '' );
						$host    = (string) ( $item['host'] ?? '' );
						?>
						<li class="dgl-padlet__item">
							<article class="dgl-padlet__card<?php echo '' !== $picture ? ' dgl-padlet__card--pic' : ''; ?>">
								<?php if ( '' !== $picture ) : ?>
									<img class="dgl-padlet__pic" src="<?php echo esc_url( $picture ); ?>" alt="" loading="lazy" decoding="async">
								<?php endif; ?>
								<div class="dgl-padlet__body">
									<?php if ( '' !== $kind ) : ?>
										<p class="dgl-padlet__kind"><?php echo esc_html( $kind ); ?></p>
									<?php endif; ?>
									<<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed tag name. ?> class="dgl-padlet__title"><a href="<?php echo esc_url( (string) ( $item['link'] ?? '' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></a></<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed tag name. ?>>
									<?php if ( '' !== $text ) : ?>
										<p class="dgl-padlet__text"><?php echo esc_html( $text ); ?></p>
									<?php endif; ?>
									<?php if ( '' !== $host ) : ?>
										<p class="dgl-padlet__host"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><?php echo esc_html( $host ); ?></p>
									<?php endif; ?>
								</div>
							</article>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>

	<p class="dgl-padlet__source">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: "Leeds Health and Care Academy's Padlet board", linked. */
				__( 'Gathered by %s. Each link opens in a new tab.', 'dgl-platform' ),
				'<a href="' . esc_url( Board::BOARD_URL ) . '" target="_blank" rel="noopener">' . esc_html__( "Leeds Health and Care Academy's Padlet board", 'dgl-platform' ) . $new_tab . '</a>'
			),
			[
				'a'    => [ 'href' => true, 'target' => true, 'rel' => true ],
				'span' => [ 'class' => true ],
			]
		);
		?>
		<?php if ( $fetched > 0 ) : ?>
			<?php /* translators: %s: a date like "25 October". */ ?>
			<?php echo esc_html( sprintf( __( 'Updated %s.', 'dgl-platform' ), wp_date( 'j F', $fetched ) ) ); ?>
		<?php endif; ?>
	</p>
</section>
