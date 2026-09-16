<?php
/**
 * Suspended account.
 *
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="dgl-notice-page">
	<h1 class="dgl-page-head__title"><?php esc_html_e( 'Your account is suspended', 'dgl-platform' ); ?></h1>
	<p><?php esc_html_e( 'You cannot use the member area at the moment. What your organisation has already published stays on the site. The team will have been in touch about why.', 'dgl-platform' ); ?></p>
	<?php /* A screen that says no still needs a door. */ ?>
	<p class="dgl-notice-page__ways">
		<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( home_url() ); ?>"><?php esc_html_e( 'Back to the website', 'dgl-platform' ); ?></a>
		<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'dgl-platform' ); ?></a>
	</p>
	<p class="dgl-help"><?php esc_html_e( 'Questions go to the DGLP team through the main website.', 'dgl-platform' ); ?></p>
</div>
