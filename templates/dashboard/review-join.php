<?php
/**
 * A registered organisation and the person who registered it, with a decision.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\Wizard;
use DGL\Joining\Signup;

defined( 'ABSPATH' ) || exit;

$signup  = $data['signup'];
$waiting = $signup instanceof Signup && Signup::AWAITING === $signup->state;
?>
<?php if ( '' !== (string) ( $data['error'] ?? '' ) ) : ?>
	<div class="dgl-alert" role="alert"><p><?php echo esc_html( (string) $data['error'] ); ?></p></div>
<?php endif; ?>

<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs"><a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a></p>
		<h1 class="dgl-page-head__title"><?php echo esc_html( (string) $data['org'] ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php
			if ( $waiting ) {
				printf(
					/* translators: 1: person, 2: email, 3: date. */
					esc_html__( 'Registered by %1$s (%2$s), whose email address matched nothing on the list. Waiting since %3$s. They can draft but not submit until you decide.', 'dgl-platform' ),
					esc_html( (string) $data['who'] ),
					esc_html( $signup->email ),
					esc_html( (string) $data['since'] )
				);
			} else {
				printf(
					/* translators: %s: state. */
					esc_html__( 'This registration is %s. Nothing is waiting.', 'dgl-platform' ),
					esc_html( $signup instanceof Signup ? $signup->state : '' )
				);
			}
			?>
		</p>
	</div>
</header>

<section class="dgl-card">
	<h2 class="dgl-section__title"><?php esc_html_e( 'What they told us', 'dgl-platform' ); ?></h2>
	<dl class="dgl-review__list">
		<?php foreach ( (array) $data['details'] as $label => $value ) : ?>
			<dt><?php echo esc_html( (string) $label ); ?></dt>
			<dd><?php echo esc_html( (string) $value ); ?></dd>
		<?php endforeach; ?>
		<dt><?php esc_html_e( 'Email domain recorded', 'dgl-platform' ); ?></dt>
		<dd><?php echo [] === $data['domains'] ? esc_html__( 'None: a public email provider, so colleagues will need an invitation', 'dgl-platform' ) : esc_html( implode( ', ', (array) $data['domains'] ) ); ?></dd>
	</dl>
</section>

<?php if ( $waiting ) : ?>
	<section class="dgl-card dgl-decision">
		<h2 class="dgl-section__title"><?php esc_html_e( 'Decision', 'dgl-platform' ); ?></h2>
		<p class="dgl-help"><?php esc_html_e( 'Verifying makes the organisation a member and lets this person submit and invite colleagues. Refusing removes the organisation and closes the account; they are told why.', 'dgl-platform' ); ?></p>

		<form method="post" class="dgl-form dgl-form--bare">
			<?php wp_nonce_field( Wizard::NONCE ); ?>
			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl-note"><?php esc_html_e( 'Note to the person', 'dgl-platform' ); ?></label>
				<textarea class="dgl-field dgl-field--area" id="dgl-note" name="dgl_note" rows="4"></textarea>
				<p class="dgl-help"><?php esc_html_e( 'Required if you are refusing. They read it by email.', 'dgl-platform' ); ?></p>
			</div>
			<div class="dgl-decision__actions">
				<button class="dgl-button" type="submit" name="dgl_intent" value="approve"><?php esc_html_e( 'Verify the organisation', 'dgl-platform' ); ?></button>
				<button class="dgl-button dgl-button--danger" type="submit" name="dgl_intent" value="refuse"
					data-dgl-confirm="<?php esc_attr_e( 'Refuse this registration? The organisation is removed and the account closed.', 'dgl-platform' ); ?>"><?php esc_html_e( 'Refuse', 'dgl-platform' ); ?></button>
			</div>
		</form>
	</section>
<?php endif; ?>
