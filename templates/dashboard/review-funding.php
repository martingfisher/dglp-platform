<?php
/**
 * Grants and Funding, for the review team: the funders' pages the weekly
 * import reads, how many funds each has on the site now, adding and deleting
 * them, and the switch that shows funding to the public.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\Wizard;

defined( 'ABSPATH' ) || exit;

$sources   = (array) ( $data['sources'] ?? [] );
$counts    = (array) ( $data['counts'] ?? [] );
$others    = (array) ( $data['others'] ?? [] );
$last      = $data['last'] ?? null;
$by_source = (array) ( $data['by_source'] ?? [] );
$notice    = (array) ( $data['notice'] ?? [] );
$is_admin  = ! empty( $data['is_admin'] );
$is_on     = ! empty( $data['is_on'] );
$here      = Router::url( 'review', 'funding' );
$total     = array_sum( array_map( 'intval', $counts ) );

/** What the last run said about one source, in words. */
$status = static function ( string $id ) use ( $by_source, $last ): array {
	if ( null === $last ) {
		return [ '', __( 'Not read yet', 'dgl-platform' ) ];
	}

	if ( ! isset( $by_source[ $id ] ) ) {
		return [ 'pending', __( 'Read at the next run', 'dgl-platform' ) ];
	}

	$row = (array) $by_source[ $id ];

	if ( empty( $row['ok'] ) ) {
		return [ 'refused', __( 'Could not be read', 'dgl-platform' ) ];
	}

	return 0 === (int) ( $row['funds'] ?? 0 )
		? [ '', __( 'Read: nothing open', 'dgl-platform' ) ]
		/* translators: %d: how many funds. */
		: [ 'approved', sprintf( _n( 'Read: %d open', 'Read: %d open', (int) $row['funds'], 'dgl-platform' ), (int) $row['funds'] ) ];
};
?>
<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span> <?php esc_html_e( 'Grants and Funding', 'dgl-platform' ); ?>
		</p>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Grants and Funding', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'Every Monday morning the funders\' pages below are read and the funding list brought up to date: new funds added, amounts and deadlines refreshed, and funds that have gone from a funder\'s page taken off. Add a funder here and it is read at the next run; delete one and its funds come off the site straight away.', 'dgl-platform' ); ?></p>
	</div>
</header>

<?php if ( '' !== (string) ( $notice['added'] ?? '' ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status"><p>
		<?php
		/* translators: %s: a funder's name. */
		echo esc_html( sprintf( __( '%s added. It will be read at the next weekly run.', 'dgl-platform' ), (string) $notice['added'] ) );
		?>
	</p></div>
<?php endif; ?>
<?php if ( '' !== (string) ( $notice['removed'] ?? '' ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status"><p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: a funder's name, 2: how many funds. */
				_n( '%1$s deleted, and %2$d of its funds taken off the site.', '%1$s deleted, and %2$d of its funds taken off the site.', (int) $notice['taken'], 'dgl-platform' ),
				(string) $notice['removed'],
				(int) $notice['taken']
			)
		);
		?>
	</p></div>
<?php endif; ?>
<?php if ( '' !== (string) ( $notice['error'] ?? '' ) ) : ?>
	<div class="dgl-alert" role="alert"><p><?php echo esc_html( (string) $notice['error'] ); ?></p></div>
<?php endif; ?>
<?php if ( '' !== (string) ( $notice['public'] ?? '' ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status"><p>
		<?php echo esc_html( 'on' === $notice['public'] ? __( 'Funding is now shown to the public.', 'dgl-platform' ) : __( 'Funding is now hidden from the public.', 'dgl-platform' ) ); ?>
		<?php esc_html_e( 'If a page still looks the same to a visitor, the page cache needs clearing.', 'dgl-platform' ); ?>
	</p></div>
<?php endif; ?>

<section class="dgl-card dgl-funding__switch" aria-labelledby="dgl-funding-public">
	<h2 class="dgl-section__title" id="dgl-funding-public"><?php echo esc_html( $is_on ? __( 'Shown to the public', 'dgl-platform' ) : __( 'Hidden from the public', 'dgl-platform' ) ); ?></h2>
	<p>
		<?php
		echo esc_html(
			$is_on
				? __( 'Everyone can see the funding list, each fund\'s page, the funding blocks on the home page and the funding support page, and funds in topic pages, search and the weekly round-up.', 'dgl-platform' )
				: __( 'Only signed-in administrators can see funding, marked as a preview. The weekly run carries on, so the list is up to date the moment it is switched on.', 'dgl-platform' )
		);
		?>
	</p>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: how many funds. */
				_n( '%d fund listed now.', '%d funds listed now.', $total, 'dgl-platform' ),
				$total
			)
		);
		?>
		<?php if ( is_array( $last ) ) : ?>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: a date and time, 2: pages read, 3: pages tried. */
					__( 'Last run %1$s: %2$d of %3$d pages read.', 'dgl-platform' ),
					wp_date( 'j M Y, H:i', (int) $last['at'] ),
					(int) $last['read'],
					(int) $last['sources']
				)
			);
			?>
		<?php endif; ?>
		<a href="<?php echo esc_url( (string) ( $data['list_url'] ?? '' ) ); ?>"><?php esc_html_e( 'Open the funding list', 'dgl-platform' ); ?></a>
	</p>
	<?php if ( $is_admin ) : ?>
		<form method="post" action="<?php echo esc_url( $here ); ?>">
			<?php wp_nonce_field( Wizard::NONCE ); ?>
			<input type="hidden" name="dgl_intent" value="public">
			<input type="hidden" name="dgl_public" value="<?php echo $is_on ? '0' : '1'; ?>">
			<button class="dgl-button<?php echo $is_on ? ' dgl-button--secondary' : ''; ?>" type="submit"><?php echo esc_html( $is_on ? __( 'Hide funding from the public', 'dgl-platform' ) : __( 'Show funding to the public', 'dgl-platform' ) ); ?></button>
		</form>
	<?php else : ?>
		<p class="dgl-help"><?php esc_html_e( 'Only a site administrator can show or hide funding.', 'dgl-platform' ); ?></p>
	<?php endif; ?>
</section>

<section class="dgl-card" aria-labelledby="dgl-funding-add">
	<h2 class="dgl-section__title" id="dgl-funding-add"><?php esc_html_e( 'Add a funder', 'dgl-platform' ); ?></h2>
	<p class="dgl-help"><?php esc_html_e( 'Use the page that lists what the funder is offering now, such as its "apply" or "funding programmes" page, not its home page.', 'dgl-platform' ); ?></p>
	<form class="dgl-funding__add" method="post" action="<?php echo esc_url( $here ); ?>">
		<?php wp_nonce_field( Wizard::NONCE ); ?>
		<input type="hidden" name="dgl_intent" value="add_source">
		<p class="dgl-field-row">
			<label class="dgl-label" for="dgl-fund-name"><?php esc_html_e( 'Funder', 'dgl-platform' ); ?></label>
			<input class="dgl-field" id="dgl-fund-name" name="dgl_name" type="text" maxlength="120" required>
		</p>
		<p class="dgl-field-row">
			<label class="dgl-label" for="dgl-fund-url"><?php esc_html_e( 'Web address of its funding page', 'dgl-platform' ); ?></label>
			<input class="dgl-field" id="dgl-fund-url" name="dgl_url" type="url" inputmode="url" placeholder="https://" required>
		</p>
		<p class="dgl-field-row">
			<label class="dgl-label" for="dgl-fund-notes"><?php esc_html_e( 'Note for the team (optional)', 'dgl-platform' ); ?></label>
			<input class="dgl-field" id="dgl-fund-notes" name="dgl_notes" type="text" maxlength="300">
		</p>
		<p><button class="dgl-button" type="submit"><?php esc_html_e( 'Add funder', 'dgl-platform' ); ?></button></p>
	</form>
</section>

<section aria-labelledby="dgl-funding-list">
	<h2 class="dgl-section__title" id="dgl-funding-list">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: how many sources. */
				_n( '%d funder page read every week', '%d funder pages read every week', count( $sources ), 'dgl-platform' ),
				count( $sources )
			)
		);
		?>
	</h2>
	<?php if ( [] === $sources ) : ?>
		<p><?php esc_html_e( 'The list fills itself from the weekly import\'s own list the first time it runs after this page went live.', 'dgl-platform' ); ?></p>
	<?php else : ?>
		<table class="dgl-table dgl-funding__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Funder', 'dgl-platform' ); ?></th>
					<th scope="col" class="dgl-funding__num"><?php esc_html_e( 'On the site', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last run', 'dgl-platform' ); ?></th>
					<th scope="col"><span class="dgl-visually-hidden"><?php esc_html_e( 'Delete', 'dgl-platform' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sources as $source ) : ?>
					<?php
					$id         = (string) $source['id'];
					$n          = (int) ( $counts[ $id ] ?? 0 );
					[ $tone, $said ] = $status( $id );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $source['name'] ); ?></strong><br>
							<a class="dgl-funding__url" href="<?php echo esc_url( (string) $source['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) preg_replace( '#^https?://(www\.)?#', '', (string) $source['url'] ) ); ?></a>
							<?php if ( '' !== (string) $source['notes'] ) : ?>
								<br><span class="dgl-help"><?php echo esc_html( (string) $source['notes'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="dgl-funding__num">
							<?php if ( $n > 0 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'source', $id, (string) ( $data['list_url'] ?? '' ) ) ); ?>"><?php echo esc_html( number_format_i18n( $n ) ); ?></a>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td><span class="dgl-chip<?php echo '' !== $tone ? ' dgl-chip--' . esc_attr( $tone ) : ''; ?>"><?php echo esc_html( $said ); ?></span></td>
						<td class="dgl-table__action">
							<form method="post" action="<?php echo esc_url( $here ); ?>" onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( sprintf( /* translators: 1: a funder's name, 2: how many funds. */ __( 'Delete %1$s from the list? Its %2$d funds on the site will be taken off now.', 'dgl-platform' ), (string) $source['name'], $n ) ) ); ?>);">
								<?php wp_nonce_field( Wizard::NONCE ); ?>
								<input type="hidden" name="dgl_intent" value="remove_source">
								<input type="hidden" name="dgl_source" value="<?php echo esc_attr( $id ); ?>">
								<button class="dgl-button dgl-button--secondary dgl-button--small" type="submit">
									<?php esc_html_e( 'Delete', 'dgl-platform' ); ?><span class="dgl-visually-hidden"> <?php echo esc_html( (string) $source['name'] ); ?></span>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( [] !== $others ) : ?>
		<h3 class="dgl-section__title"><?php esc_html_e( 'Also on the site', 'dgl-platform' ); ?></h3>
		<p class="dgl-help"><?php esc_html_e( 'Funds that did not come from a page on the list above: written up by hand where a funder\'s site cannot be read automatically (Arts Council England), or left from a page deleted earlier until their deadlines pass.', 'dgl-platform' ); ?></p>
		<ul>
			<?php foreach ( $others as $id => $n ) : ?>
				<li><?php echo esc_html( sprintf( '%s: %d', (string) $id, (int) $n ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
