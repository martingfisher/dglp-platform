<?php
/**
 * Placeholder for a screen that is designed but not yet built.
 *
 * Says so plainly rather than rendering an empty page that looks broken.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php echo esc_html( $data['title'] ?? '' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'This screen is designed but not built yet.', 'dgl-platform' ); ?></p>
	</div>
</header>
