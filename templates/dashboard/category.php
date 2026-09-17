<?php
/**
 * One content type's items. Wireframe 1e.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

$label    = $data['label'] ?? '';
$singular = $data['singular'] ?? $label;
$slug   = $data['slug'] ?? '';
$counts = $data['counts'] ?? [];
$active = $data['active'] ?? '';
?>
<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Dashboard', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span> <?php echo esc_html( $label ); ?>
		</p>
		<h1 class="dgl-page-head__title"><?php echo esc_html( $label ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'Editing something that is already live sends it back for approval. The published version stays up while the team reads your change.', 'dgl-platform' ); ?></p>
	</div>
	<a class="dgl-button" href="<?php echo esc_url( Router::url( 'new', $slug ) ); ?>">
		<?php
		printf(
			/* translators: %s: content type name. */
			esc_html__( 'New %s', 'dgl-platform' ),
			esc_html( strtolower( $singular ) )
		);
		?>
	</a>
</header>

<nav class="dgl-filters" aria-label="<?php esc_attr_e( 'Filter by status', 'dgl-platform' ); ?>">
	<a class="dgl-filter<?php echo '' === $active ? ' dgl-filter--on' : ''; ?>" href="<?php echo esc_url( Router::url( $slug ) ); ?>">
		<?php esc_html_e( 'All', 'dgl-platform' ); ?>
	</a>
	<?php foreach ( Statuses::all() as $status ) : ?>
		<?php if ( ( $counts[ $status ] ?? 0 ) < 1 ) { continue; } ?>
		<a
			class="dgl-filter<?php echo $active === $status ? ' dgl-filter--on' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'status', $status, Router::url( $slug ) ) ); ?>"
		>
			<?php echo esc_html( Statuses::label( $status ) ); ?>
			<span class="dgl-filter__count"><?php echo esc_html( (string) $counts[ $status ] ); ?></span>
		</a>
	<?php endforeach; ?>
</nav>

<?php
View::output(
	'dashboard/list-table',
	[
		'items' => $data['items'] ?? [],
		'empty' => __( 'Nothing of this type yet. Start one with the button above.', 'dgl-platform' ),
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
		'noun'  => strtolower( $label ),
	]
);
