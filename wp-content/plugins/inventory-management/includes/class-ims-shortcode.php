<?php
/**
 * Front-end shortcode: [ims_inventory]
 *
 * Attributes:
 *   public="no"        Only logged-in users see the list (default). Use "yes" to show it to everyone.
 *   show_prices="yes"  Show sale price column.
 *   category=""        Limit to one category.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_Shortcode {

	public function __construct() {
		add_shortcode( 'ims_inventory', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'public'      => 'no',
				'show_prices' => 'yes',
				'category'    => '',
			),
			$atts,
			'ims_inventory'
		);

		if ( 'yes' !== $atts['public'] ) {
			if ( ! is_user_logged_in() ) {
				return '<p>' . esc_html__( 'Please log in to view the inventory.', 'ims' ) . '</p>';
			}
			if ( ! current_user_can( 'ims_view_inventory' ) ) {
				return '<p>' . esc_html__( 'Your account does not have inventory access yet. Please ask an administrator to assign you an inventory role.', 'ims' ) . '</p>';
			}
		}


		$search   = isset( $_GET['ims_s'] ) ? sanitize_text_field( wp_unslash( $_GET['ims_s'] ) ) : '';
		$products = IMS_DB::get_products( array( 'search' => $search, 'category' => sanitize_text_field( $atts['category'] ) ) );
		$prices   = 'yes' === $atts['show_prices'];

		ob_start();
		?>
		<div class="ims-front">
			<form method="get" class="ims-front-search">
				<input type="search" name="ims_s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search products or SKU">
				<button type="submit">Search</button>
			</form>
			<table class="ims-front-table">
				<thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Available</th><?php echo $prices ? '<th>Price</th>' : ''; ?></tr></thead>
				<tbody>
				<?php if ( ! $products ) : ?>
					<tr><td colspan="<?php echo $prices ? 5 : 4; ?>">No products found.</td></tr>
				<?php endif; ?>
				<?php foreach ( $products as $p ) : ?>
					<?php
					if ( $p->quantity <= 0 ) {
						$cls = 'ims-out';
						$lbl = 'Out of stock';
					} elseif ( $p->quantity <= $p->reorder_level ) {
						$cls = 'ims-low';
						$lbl = 'Low (' . (int) $p->quantity . ')';
					} else {
						$cls = 'ims-ok';
						$lbl = (int) $p->quantity;
					}
					?>
					<tr>
						<td><?php echo esc_html( $p->name ); ?></td>
						<td><?php echo esc_html( $p->sku ); ?></td>
						<td><?php echo esc_html( $p->category ); ?></td>
						<td><span class="ims-badge <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $lbl ); ?></span></td>
						<?php if ( $prices ) : ?><td><?php echo esc_html( number_format_i18n( (float) $p->sale_price, 2 ) ); ?></td><?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}
}
