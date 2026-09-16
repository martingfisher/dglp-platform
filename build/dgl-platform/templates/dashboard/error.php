<?php
/**
 * Something the member cannot do, explained.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;

defined( 'ABSPATH' ) || exit;
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php echo esc_html( (string) ( $data['title'] ?? __( 'That did not work', 'dgl-platform' ) ) ); ?></h1>
		<p class="dgl-page-head__lede"><?php echo esc_html( (string) ( $data['message'] ?? '' ) ); ?></p>
		<p><a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Back to your dashboard', 'dgl-platform' ); ?></a></p>
	</div>
</header>
