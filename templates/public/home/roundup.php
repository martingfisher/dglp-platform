<?php
/**
 * The weekly round-up: the closing call to action, Join and Sign in.
 *
 * Expects `$data['heading']`, `$data['text']`, `$data['join_url']`,
 * `$data['signin_url']`, `$data['id']`.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$heading = (string) ( $data['heading'] ?? '' );
$text    = (string) ( $data['text'] ?? '' );
$id      = (string) ( $data['id'] ?? 'dgl-home-' . wp_unique_id() );
?>
<section class="dgl-home__section dgl-home__roundup" id="<?php echo esc_attr( $id ); ?>"<?php echo '' !== $heading ? ' aria-labelledby="' . esc_attr( $id ) . '-h"' : ' aria-label="' . esc_attr__( 'Join the partnership', 'dgl-platform' ) . '"'; ?>>
	<div>
		<?php if ( '' !== $heading ) : ?>
			<h2 class="dgl-home__h2" id="<?php echo esc_attr( $id ); ?>-h"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>
		<?php if ( '' !== $text ) : ?>
			<p class="dgl-home__lede"><?php echo esc_html( $text ); ?></p>
		<?php endif; ?>
	</div>
	<div class="dgl-home__actions dgl-home__actions--stack">
		<a class="dgl-pub__button dgl-home__button" href="<?php echo esc_url( (string) $data['join_url'] ); ?>"><?php esc_html_e( 'Join the partnership', 'dgl-platform' ); ?></a>
		<a class="dgl-home__button dgl-home__button--quiet" href="<?php echo esc_url( (string) $data['signin_url'] ); ?>"><?php esc_html_e( 'Already a member? Sign in', 'dgl-platform' ); ?></a>
	</div>
</section>
