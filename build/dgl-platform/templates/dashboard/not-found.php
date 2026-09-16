<?php
/**
 * Unknown route inside the member area.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;

defined( 'ABSPATH' ) || exit;
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'That page does not exist', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php esc_html_e( 'The link may be out of date.', 'dgl-platform' ); ?>
			<a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Go back to your dashboard', 'dgl-platform' ); ?></a>.
		</p>
	</div>
</header>
