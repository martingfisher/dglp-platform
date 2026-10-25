<?php
/**
 * Settings, Wellbeing board: the Padlet board as this site shows it.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Padlet;

defined( 'ABSPATH' ) || exit;

/**
 * The block follows the board's own columns, so nothing here needs keeping
 * up. The screen says when the board was last read and what is left out
 * and why, fetches it on request, and lets the team change three things on
 * this site only: a column's heading, a column's introduction, and which
 * column one post is shown in. Every change can be undone by clearing it.
 */
final class Settings {

	public const SLUG   = 'dgl-padlet';
	public const ACTION = 'dgl_padlet_save';
	public const FETCH  = 'dgl_padlet_fetch';

	/** Seconds a fetch from this screen may spend on new pictures: a web request, so less than the command line. */
	private const FETCH_BUDGET = 25;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
		add_action( 'admin_post_' . self::FETCH, [ self::class, 'fetch' ] );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Wellbeing board', 'dgl-platform' ),
			__( 'Wellbeing board', 'dgl-platform' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'screen' ]
		);
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dgl-platform' ) );
		}

		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is cleaned by Board::clean_overrides().
		$names  = (array) wp_unslash( $_POST['column_name'] ?? [] );
		$intros = (array) wp_unslash( $_POST['column_intro'] ?? [] );
		$moves  = (array) wp_unslash( $_POST['assign'] ?? [] );
		// phpcs:enable

		$own    = [];
		$assign = [];

		foreach ( Board::items() as $item ) {
			$own[ (string) $item['key'] ] = '' !== (string) ( $item['section'] ?? '' ) ? (string) $item['section'] : Board::DEFAULT_GROUP;
		}

		// Only a post shown somewhere other than its own column is a change worth keeping.
		foreach ( $moves as $key => $group ) {
			if ( isset( $own[ (string) $key ] ) && $own[ (string) $key ] !== (string) $group ) {
				$assign[ (string) $key ] = (string) $group;
			}
		}

		update_option( Board::OPTION_GROUPS, Board::clean_overrides( [ 'names' => $names, 'intros' => $intros, 'assign' => $assign ] ), false );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'saved' => '1' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function fetch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dgl-platform' ) );
		}

		check_admin_referer( self::FETCH );

		$last = Board::refresh( self::FETCH_BUDGET );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'fetched' => $last['ok'] ? '1' : '0' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sections  = Board::sections();
		$overrides = Board::overrides();
		$items     = Board::items();
		$left_out  = Board::left_out();
		$last      = Board::last();
		$fetched   = Board::fetched();
		$names     = [ Board::DEFAULT_GROUP => __( 'More support', 'dgl-platform' ) ];
		$no_pic    = count( array_filter( $items, static fn( array $item ): bool => '' === Board::picture_url( $item ) ) );

		foreach ( $sections as $section ) {
			$names[ $section['id'] ] = $overrides['names'][ $section['id'] ] ?? $section['name'];
		}

		usort( $items, static fn( array $a, array $b ): int => strnatcasecmp( (string) $a['title'], (string) $b['title'] ) );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices after a redirect.
		$saved      = isset( $_GET['saved'] );
		$fetch_said = isset( $_GET['fetched'] ) ? '1' === $_GET['fetched'] : null;
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wellbeing board', 'dgl-platform' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. The board shows the changes straight away.', 'dgl-platform' ); ?></p></div>
			<?php endif; ?>
			<?php if ( null !== $fetch_said ) : ?>
				<div class="notice notice-<?php echo $fetch_said ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $fetch_said ? __( 'Fetched from Padlet.', 'dgl-platform' ) : sprintf( /* translators: %s: the error. */ __( 'The fetch failed: %s The last good board is still showing.', 'dgl-platform' ), (string) ( $last['error'] ?? '' ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( null !== $last && $last['ok'] && Board::FROM_FEEDS === $last['source'] ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Padlet\'s board data did not answer at the last fetch, so the board was read from its RSS feeds. Posts keep their last known column and picture; new posts show under More support until the board data answers again.', 'dgl-platform' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: the board's address, linked. */
						__( 'The [dgl_padlet] block shows the posts on Leeds Health and Care Academy\'s %s as cards on this site, in the board\'s own columns, with Padlet\'s pictures. It is read from Padlet every night, so new posts appear by themselves.', 'dgl-platform' ),
						'<a href="' . esc_url( Board::BOARD_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Health and Wellbeing Support board', 'dgl-platform' ) . '</a>'
					),
					[ 'a' => [ 'href' => true, 'target' => true, 'rel' => true ] ]
				);
				?>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: when, 2: how many shown, 3: how many left out, 4: how many still waiting for a picture. */
						__( 'Last read: %1$s. %2$d posts shown, %3$d left out, %4$d still waiting for their picture.', 'dgl-platform' ),
						$fetched > 0 ? wp_date( 'j F Y, H:i', $fetched ) : __( 'never', 'dgl-platform' ),
						count( $items ),
						count( $left_out ),
						$no_pic
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FETCH ); ?>">
				<?php wp_nonce_field( self::FETCH ); ?>
				<?php submit_button( __( 'Fetch from Padlet now', 'dgl-platform' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'Columns', 'dgl-platform' ); ?></h2>
				<p><?php esc_html_e( 'These are the board\'s columns, in its order. Leave a box empty to use the board\'s own wording, shown in grey. The id is what [dgl_padlet group="…"] takes to show one column on its own.', 'dgl-platform' ); ?></p>
				<?php if ( [] === $sections ) : ?>
					<p><?php esc_html_e( 'No columns yet. Use Fetch from Padlet now above.', 'dgl-platform' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:1000px">
						<thead><tr>
							<th scope="col" style="width:110px"><?php esc_html_e( 'Id', 'dgl-platform' ); ?></th>
							<th scope="col" style="width:280px"><?php esc_html_e( 'Heading on this site', 'dgl-platform' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Line under the heading', 'dgl-platform' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $sections as $section ) : ?>
								<?php $sid = $section['id']; ?>
								<tr>
									<td><code><?php echo esc_html( $sid ); ?></code></td>
									<td>
										<label class="screen-reader-text" for="dgl-c-n-<?php echo esc_attr( $sid ); ?>"><?php esc_html_e( 'Heading', 'dgl-platform' ); ?></label>
										<input type="text" id="dgl-c-n-<?php echo esc_attr( $sid ); ?>" name="column_name[<?php echo esc_attr( $sid ); ?>]" value="<?php echo esc_attr( $overrides['names'][ $sid ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $section['name'] ); ?>" style="width:100%">
									</td>
									<td>
										<label class="screen-reader-text" for="dgl-c-i-<?php echo esc_attr( $sid ); ?>"><?php esc_html_e( 'Line under the heading', 'dgl-platform' ); ?></label>
										<textarea id="dgl-c-i-<?php echo esc_attr( $sid ); ?>" name="column_intro[<?php echo esc_attr( $sid ); ?>]" rows="2" placeholder="<?php echo esc_attr( $section['intro'] ); ?>" style="width:100%"><?php echo esc_textarea( $overrides['intros'][ $sid ] ?? '' ); ?></textarea>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Posts', 'dgl-platform' ); ?></h2>
				<p><?php esc_html_e( 'Each post shows in its own column on the board. Choose another column to show it there on this site instead.', 'dgl-platform' ); ?></p>
				<?php if ( [] === $items ) : ?>
					<p><?php esc_html_e( 'Nothing fetched yet. Use Fetch from Padlet now above.', 'dgl-platform' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:1000px">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Post', 'dgl-platform' ); ?></th>
							<th scope="col" style="width:300px"><?php esc_html_e( 'Shown in', 'dgl-platform' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $items as $item ) : ?>
								<?php
								$key     = (string) $item['key'];
								$own     = '' !== (string) ( $item['section'] ?? '' ) && isset( $names[ (string) $item['section'] ] ) ? (string) $item['section'] : Board::DEFAULT_GROUP;
								$current = $overrides['assign'][ $key ] ?? $own;
								$current = isset( $names[ $current ] ) ? $current : $own;
								?>
								<tr>
									<td>
										<label for="dgl-a-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( (string) $item['title'] ); ?></strong></label>
										<br><a href="<?php echo esc_url( (string) $item['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $item['host'] ); ?></a>
										<?php if ( '' === Board::picture_url( $item ) ) : ?>
											<span class="description"> &middot; <?php esc_html_e( 'picture to follow', 'dgl-platform' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<select id="dgl-a-<?php echo esc_attr( $key ); ?>" name="assign[<?php echo esc_attr( $key ); ?>]">
											<?php foreach ( $names as $value => $label ) : ?>
												<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $current, (string) $value ); ?>><?php echo esc_html( $label . ( $own === (string) $value ? ' ' . __( '(its column on Padlet)', 'dgl-platform' ) : '' ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php submit_button( __( 'Save', 'dgl-platform' ) ); ?>
			</form>

			<?php if ( [] !== $left_out ) : ?>
				<h2><?php esc_html_e( 'Left out', 'dgl-platform' ); ?></h2>
				<p><?php esc_html_e( 'These posts are on the Padlet board but not shown here. A column\'s header post is not a card: its words are the line under the column\'s heading.', 'dgl-platform' ); ?></p>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Post', 'dgl-platform' ); ?></th>
						<th scope="col" style="width:300px"><?php esc_html_e( 'Why', 'dgl-platform' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $left_out as $post ) : ?>
							<tr>
								<td>
									<?php echo esc_html( '' !== (string) ( $post['title'] ?? '' ) ? (string) $post['title'] : __( '(no title)', 'dgl-platform' ) ); ?>
									<br><a href="<?php echo esc_url( (string) ( $post['link'] ?? '' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Board::host_label( (string) ( $post['link'] ?? '' ) ) ); ?></a>
								</td>
								<td><?php echo esc_html( Board::LEFT_SHOP === ( $post['why'] ?? '' ) ? __( 'Links to Amazon', 'dgl-platform' ) : __( 'A column header, or a post with no title', 'dgl-platform' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
