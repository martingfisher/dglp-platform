<?php
/**
 * What the team have already decided, for looking over and undoing.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

$counts = $data['counts'] ?? [];
$active = (string) ( $data['active'] ?? '' );
$order  = [ Statuses::LIVE, Statuses::REJECTED, Statuses::EXPIRED, Statuses::ARCHIVED ];
?>
<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span> <?php esc_html_e( 'Approved', 'dgl-platform' ); ?>
		</p>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Approved', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'What is on the site, newest first. Open one to check it again or take it off the site. The filters show refusals, expired and archived items, which can be reopened or restored.', 'dgl-platform' ); ?></p>
	</div>
</header>

<nav class="dgl-filters" aria-label="<?php esc_attr_e( 'Filter by outcome', 'dgl-platform' ); ?>">
	<a class="dgl-filter<?php echo 'all' === $active ? ' dgl-filter--on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'status', 'all', Router::url( 'review', 'decided' ) ) ); ?>">
		<?php esc_html_e( 'All', 'dgl-platform' ); ?>
	</a>
	<?php foreach ( $order as $status ) : ?>
		<a
			class="dgl-filter<?php echo $active === $status ? ' dgl-filter--on' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'status', $status, Router::url( 'review', 'decided' ) ) ); ?>"
		>
			<?php echo esc_html( Statuses::label( $status ) ); ?>
			<span class="dgl-filter__count"><?php echo esc_html( (string) ( $counts[ $status ] ?? 0 ) ); ?></span>
		</a>
	<?php endforeach; ?>
</nav>

<?php
View::output(
	'dashboard/list-table',
	[
		'items'  => $data['items'] ?? [],
		'action' => __( 'Open', 'dgl-platform' ),
		'empty'  => __( 'Nothing decided yet.', 'dgl-platform' ),
	]
);

View::output(
	'dashboard/pager',
	[
		'total' => (int) ( $data['total'] ?? 0 ),
		'page'  => (int) ( $data['page'] ?? 1 ),
		'pages' => (int) ( $data['pages'] ?? 1 ),
		'first' => (int) ( $data['first'] ?? 0 ),
		'last'  => (int) ( $data['last'] ?? 0 ),
		'base'  => (string) ( $data['base'] ?? '' ),
		'noun'  => __( 'decided', 'dgl-platform' ),
		'label' => __( 'Decided pages', 'dgl-platform' ),
	]
);
