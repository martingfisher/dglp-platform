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
?>
<div class="dgl-dash">
	<div class="dgl-signin">
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Sign in', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'Post events, news, training, grants and volunteering opportunities to the site.', 'dgl-platform' ); ?></p>

		<?php
		wp_login_form(
			[
				'redirect'       => $data['redirect_to'] ?? home_url(),
				'label_username' => __( 'Email address', 'dgl-platform' ),
				'label_log_in'   => __( 'Sign in', 'dgl-platform' ),
			]
		);
		?>

		<p class="dgl-signin__foot">
			<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgotten your password?', 'dgl-platform' ); ?></a>
		</p>
	</div>
</div>
