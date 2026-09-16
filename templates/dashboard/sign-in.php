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
		<p class="dgl-page-head__lede">
			<?php
			printf(
				/* translators: %s: list of content types, e.g. "events, news and training". */
				esc_html__( 'Post %s to the site.', 'dgl-platform' ),
				esc_html( \DGL\Invites\Invites::can_post_sentence() )
			);
			?>
		</p>

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
