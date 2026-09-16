<?php
/**
 * A requested organisation name or logo change, side by side, with a decision.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Dashboard\Wizard;

defined( 'ABSPATH' ) || exit;

$changes = $data['changes'] ?? [];
?>
<?php if ( '' !== (string) ( $data['error'] ?? '' ) ) : ?>
	<div class="dgl-alert" role="alert"><p><?php echo esc_html( (string) $data['error'] ); ?></p></div>
<?php endif; ?>

<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a>
		</p>
		<h1 class="dgl-page-head__title"><?php echo esc_html( (string) $data['name'] ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php
			if ( [] === $changes ) {
				esc_html_e( 'Nothing is waiting on this organisation.', 'dgl-platform' );
			} else {
				printf(
					/* translators: %s: date. */
					esc_html__( 'Asked to change its details. Waiting since %s. Their listings carry the current details until you decide.', 'dgl-platform' ),
					esc_html( (string) $data['since'] )
				);
			}
			?>
		</p>
	</div>
</header>

<?php if ( [] !== $changes ) : ?>
	<?php
	View::output(
		'dashboard/changes',
		[
			'changes'       => $changes,
			'changes_title' => __( 'What they want to change', 'dgl-platform' ),
			'changes_lede'  => __( 'On the site now, and what they have asked for.', 'dgl-platform' ),
		]
	);
	?>

	<section class="dgl-card dgl-decision">
		<h2 class="dgl-section__title"><?php esc_html_e( 'Decision', 'dgl-platform' ); ?></h2>

		<form method="post" class="dgl-form dgl-form--bare">
			<?php wp_nonce_field( Wizard::NONCE ); ?>

			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl-note"><?php esc_html_e( 'Note to the member', 'dgl-platform' ); ?></label>
				<textarea class="dgl-field dgl-field--area" id="dgl-note" name="dgl_note" rows="4"></textarea>
				<p class="dgl-help"><?php esc_html_e( 'Required if you are refusing. They see it on their dashboard and by email.', 'dgl-platform' ); ?></p>
			</div>

			<div class="dgl-decision__actions">
				<button class="dgl-button" type="submit" name="dgl_intent" value="approve">
					<?php esc_html_e( 'Accept the change', 'dgl-platform' ); ?>
				</button>
				<button class="dgl-button dgl-button--danger" type="submit" name="dgl_intent" value="refuse">
					<?php esc_html_e( 'Refuse', 'dgl-platform' ); ?>
				</button>
			</div>
		</form>
	</section>
<?php else : ?>
	<p><a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Back to the queue', 'dgl-platform' ); ?></a></p>
<?php endif; ?>
