<?php
/**
 * What a pending edit would change, before and after.
 *
 * Shared by the member's own review step, the item screen and the moderator's
 * decision screen, so all three show the same comparison and cannot drift.
 *
 * @var array<string,mixed> $data Expects `changes`, optionally `changes_title`.
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;

$changes = $data['changes'] ?? [];

if ( empty( $changes ) ) {
	return;
}

/** Render one side of a comparison the way that field is normally shown. */
$side = static function ( array $change, string $which ): string {
	$value = $change[ $which ] ?? '';

	if ( $change['field'] instanceof \DGL\Schema\Field ) {
		return View::field_value( $change['field'], $value );
	}

	return '' === (string) $value
		? '<span class="dgl-review__blank">' . esc_html__( 'None', 'dgl-platform' ) . '</span>'
		: esc_html( (string) $value );
};
?>
<section class="dgl-card dgl-changes">
	<h2 class="dgl-section__title">
		<?php
		echo esc_html(
			$data['changes_title'] ?? sprintf(
				/* translators: %d: number of changed fields. */
				_n( '%d thing has changed', '%d things have changed', count( $changes ), 'dgl-platform' ),
				count( $changes )
			)
		);
		?>
	</h2>

	<p class="dgl-changes__lede">
		<?php esc_html_e( 'Everything not listed here is the same as the published version.', 'dgl-platform' ); ?>
	</p>

	<ol class="dgl-changes__list">
		<?php foreach ( $changes as $change ) : ?>
			<li class="dgl-change">
				<p class="dgl-change__label"><?php echo esc_html( (string) $change['label'] ); ?></p>
				<div class="dgl-change__pair">
					<div class="dgl-change__side dgl-change__side--before">
						<p class="dgl-change__cap"><?php esc_html_e( 'On the site now', 'dgl-platform' ); ?></p>
						<div class="dgl-change__value"><?php echo $side( $change, 'before' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by View::field_value. ?></div>
					</div>
					<div class="dgl-change__side dgl-change__side--after">
						<p class="dgl-change__cap"><?php esc_html_e( 'Proposed', 'dgl-platform' ); ?></p>
						<div class="dgl-change__value"><?php echo $side( $change, 'after' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by View::field_value. ?></div>
					</div>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
