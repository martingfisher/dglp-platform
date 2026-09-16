<?php
/**
 * Sign in. Wireframe 1a.
 *
 * Single sign-on is not built yet, so this offers what actually works rather
 * than showing buttons that do nothing.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag set by our own redirect, read only.
$flag    = isset( $_GET[ \DGL\Dashboard\SignIn::FLAG ] ) ? sanitize_key( wp_unslash( $_GET[ \DGL\Dashboard\SignIn::FLAG ] ) ) : '';
$message = \DGL\Dashboard\SignIn::message( $flag );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a destination, read only and validated by wp_login_form.
$wanted  = isset( $_GET['redirect_to'] ) ? (string) wp_unslash( $_GET['redirect_to'] ) : (string) ( $data['redirect_to'] ?? home_url() );
?>
<?php /* No wrapper of its own: the shell already provides .dgl-dash, and nesting a second one nested the whole layout. */ ?>
	<div class="dgl-signin">
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Sign in', 'dgl-platform' ); ?></h1>

		<?php if ( '' !== $message ) : ?>
			<div class="dgl-alert" role="alert"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'Sign in to your admin dashboard.', 'dgl-platform' ); ?></p>

		<?php
		wp_login_form(
			[
				'redirect'       => $wanted,
				'label_username' => __( 'Email address', 'dgl-platform' ),
				'label_log_in'   => __( 'Sign in', 'dgl-platform' ),
			]
		);
		?>

		<p class="dgl-signin__foot">
			<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgotten your password?', 'dgl-platform' ); ?></a>
		</p>
	</div>
