<?php
/**
 * Archive. Wireframe 1h.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Archive', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'Items no longer on the site. Anything with an end date comes off automatically when it passes. Anything else you archive yourself.', 'dgl-platform' ); ?></p>
	</div>
</header>

<?php
View::output(
	'dashboard/list-table',
	[
		'items' => $data['items'] ?? [],
		'empty' => __( 'Nothing archived yet. Items appear here once they expire or you archive them.', 'dgl-platform' ),
	]
);
