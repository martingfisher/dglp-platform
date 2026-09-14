<?php
/**
 * Signed in, but not a member of the partnership.
 *
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="dgl-dash">
	<div class="dgl-notice-page">
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'This area is for partnership members', 'dgl-platform' ); ?></h1>
		<p><?php esc_html_e( 'Your account does not have access to the member area. If your organisation is a partnership member, ask whoever manages your account to invite you.', 'dgl-platform' ); ?></p>
	</div>
</div>
