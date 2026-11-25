<?php
/**
 * Admin screens (wp-admin → Inventory) and form handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );

		foreach ( array( 'save_product', 'delete_product', 'save_supplier', 'delete_supplier', 'stock_movement', 'export_csv', 'unlock' ) as $action ) {
			add_action( 'admin_post_ims_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	public function menu() {
		if ( ! current_user_can( 'ims_view_inventory' ) ) {
			return;
		}
		$low   = IMS_DB::stats();
		$alert = $low['low_stock'] + $low['out_stock'];
		$badge = $alert ? ' <span class="awaiting-mod">' . (int) $alert . '</span>' : '';

		add_menu_page( 'Inventory', 'Inventory' . $badge, 'ims_view_inventory', 'ims', array( $this, 'page_dashboard' ), 'dashicons-archive', 26 );
		add_submenu_page( 'ims', 'Dashboard', 'Dashboard', 'ims_view_inventory', 'ims', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'ims', 'Products', 'Products', 'ims_view_inventory', 'ims-products', array( $this, 'page_products' ) );
		add_submenu_page( 'ims', 'Stock In / Out', 'Stock In / Out', 'ims_manage_stock', 'ims-stock', array( $this, 'page_stock' ) );
		add_submenu_page( 'ims', 'Suppliers', 'Suppliers', 'ims_view_inventory', 'ims-suppliers', array( $this, 'page_suppliers' ) );
		add_submenu_page( 'ims', 'Security log', 'Security log', 'ims_view_security', 'ims-security', array( $this, 'page_security' ) );
	}

	private function is_ims_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		return 0 === strpos( $page, 'ims' );
	}

	public function assets( $hook ) {
		if ( $this->is_ims_screen() ) {
			wp_enqueue_style( 'ims-font', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null );
			wp_enqueue_style( 'ims-admin', IMS_URL . 'assets/ims.css', array(), IMS_VERSION );
			wp_enqueue_script( 'ims-admin', IMS_URL . 'assets/ims-admin.js', array(), IMS_VERSION, true );
		}
	}

	public function body_class( $classes ) {
		return $this->is_ims_screen() ? $classes . ' ims-screen' : $classes;
	}

	/* ===================================================================
	 * Helpers
	 * ================================================================= */

	private function guard( $nonce_action, $cap ) {
		if ( ! is_user_logged_in() || ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ims' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect( $page, $msg, $type = 'success', $extra = array() ) {
		$args = array_merge( array( 'page' => $page, 'ims_msg' => rawurlencode( $msg ), 'ims_type' => $type ), $extra );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private function icon( $name, $size = 16 ) {
		return IMS_Icons::get( $name, $size );
	}

	private function notice() {
		if ( empty( $_GET['ims_msg'] ) ) {
			return;
		}
		$error = isset( $_GET['ims_type'] ) && 'error' === $_GET['ims_type'];
		printf(
			'<div class="ims-flash %s" role="%s">%s<span>%s</span></div>',
			$error ? 'ims-flash-error' : 'ims-flash-success',
			$error ? 'alert' : 'status',
			$error ? IMS_Icons::get( 'alert', 18 ) : IMS_Icons::get( 'check', 18 ),
			esc_html( sanitize_text_field( wp_unslash( $_GET['ims_msg'] ) ) )
		);
	}

	/** Page header with title, optional subtitle and action buttons. */
	private function head( $title, $subtitle = '', $actions = array(), $back = null ) {
		echo '<div class="wrap ims-app"><hr class="wp-header-end">';
		echo '<header class="ims-head"><div class="ims-head-text">';
		if ( $back ) {
			printf( '<a class="ims-back" href="%s">%s%s</a>', esc_url( $back[0] ), $this->icon( 'back', 14 ), esc_html( $back[1] ) );
		}
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p>' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		if ( $actions ) {
			echo '<div class="ims-head-actions">';
			foreach ( $actions as $a ) {
				printf( '<a class="ims-button %s" href="%s">%s%s</a>', esc_attr( $a[2] ?? 'ims-button-secondary' ), esc_url( $a[0] ), isset( $a[3] ) ? $this->icon( $a[3] ) : '', esc_html( $a[1] ) );
			}
			echo '</div>';
		}
		echo '</header>';
		$this->notice();
	}

	private function foot() {
		echo '</div>';
	}

	private function money( $n ) {
		return number_format_i18n( (float) $n, 2 );
	}

	private function status( $p ) {
		if ( (int) $p->quantity <= 0 ) {
			return array( 'out', 'Out of stock' );
		}
		if ( (int) $p->quantity <= (int) $p->reorder_level ) {
			return array( 'low', 'Low stock' );
		}
		return array( 'ok', 'In stock' );
	}

	private function pill( $p ) {
		list( $cls, $label ) = $this->status( $p );
		return '<span class="ims-pill ims-pill-' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}

	private function ago( $mysql_local ) {
		$ts = strtotime( get_gmt_from_date( $mysql_local ) . ' UTC' );
		return ( time() - $ts < 60 ) ? 'Just now' : human_time_diff( $ts ) . ' ago';
	}

	private function empty_state( $icon, $title, $text, $action = null ) {
		echo '<div class="ims-empty"><span class="ims-empty-icon">' . $this->icon( $icon, 22 ) . '</span><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $text ) . '</p>';
		if ( $action ) {
			printf( '<a class="ims-button ims-button-primary" href="%s">%s</a>', esc_url( $action[0] ), esc_html( $action[1] ) );
		}
		echo '</div>';
	}

	/* ===================================================================
	 * Dashboard
	 * ================================================================= */

	public function page_dashboard() {
		$s         = IMS_DB::stats();
		$attention = IMS_DB::get_products( array( 'low_stock' => true ) );
		$mov       = IMS_DB::get_movements( 8 );
		$actions   = array();
		if ( current_user_can( 'ims_manage_products' ) ) {
			$actions[] = array( $this->url( 'ims-products', array( 'action' => 'new' ) ), 'Add product', 'ims-button-secondary', 'box' );
		}
		if ( current_user_can( 'ims_manage_stock' ) ) {
			$actions[] = array( $this->url( 'ims-stock' ), 'Record movement', 'ims-button-primary', 'history' );
		}
		$this->head( 'Dashboard', 'Overview of your stock as of ' . wp_date( 'M j, Y \a\t g:i a' ) . '.', $actions );

		$need = $s['low_stock'] + $s['out_stock'];
		?>
		<div class="ims-kpis">
			<div class="ims-kpi">
				<span class="ims-kpi-label">Products</span>
				<strong><?php echo esc_html( number_format_i18n( $s['products'] ) ); ?></strong>
				<span class="ims-kpi-sub">from <?php echo esc_html( number_format_i18n( $s['suppliers'] ) ); ?> supplier<?php echo 1 === (int) $s['suppliers'] ? '' : 's'; ?></span>
			</div>
			<div class="ims-kpi">
				<span class="ims-kpi-label">Units in stock</span>
				<strong><?php echo esc_html( number_format_i18n( $s['units'] ) ); ?></strong>
				<span class="ims-kpi-sub">across all locations</span>
			</div>
			<div class="ims-kpi">
				<span class="ims-kpi-label">Stock value</span>
				<strong><?php echo esc_html( $this->money( $s['stock_value'] ) ); ?></strong>
				<span class="ims-kpi-sub">at cost price</span>
			</div>
			<a class="ims-kpi ims-kpi-link <?php echo $need ? 'is-alert' : ''; ?>" href="<?php echo esc_url( $this->url( 'ims-products', array( 'low' => 1 ) ) ); ?>">
				<span class="ims-kpi-label">Needs attention</span>
				<strong><?php echo (int) $need; ?></strong>
				<span class="ims-kpi-sub"><span class="ims-dot ims-dot-low"></span><?php echo (int) $s['low_stock']; ?> low &nbsp; <span class="ims-dot ims-dot-out"></span><?php echo (int) $s['out_stock']; ?> out</span>
			</a>
		</div>

		<div class="ims-cols">
			<section class="ims-card">
				<header class="ims-card-head">
					<div><h2>Needs reordering</h2><p>Products at or below their reorder level.</p></div>
					<?php if ( $attention ) : ?><a class="ims-link" href="<?php echo esc_url( $this->url( 'ims-products', array( 'low' => 1 ) ) ); ?>">View all</a><?php endif; ?>
				</header>
				<?php if ( ! $attention ) : ?>
					<?php $this->empty_state( 'check', 'Everything is well stocked', 'No products are at or below their reorder level.' ); ?>
				<?php else : ?>
					<div class="ims-table-wrap">
						<table class="ims-table">
							<thead><tr><th>Product</th><th class="num">In stock</th><th class="num">Reorder at</th><th>Status</th><th>Supplier</th><th></th></tr></thead>
							<tbody>
							<?php foreach ( $attention as $p ) : ?>
								<tr>
									<td><span class="ims-prod"><strong><?php echo esc_html( $p->name ); ?></strong><code><?php echo esc_html( $p->sku ); ?></code></span></td>
									<td class="num"><?php echo (int) $p->quantity; ?></td>
									<td class="num ims-muted"><?php echo (int) $p->reorder_level; ?></td>
									<td><?php echo $this->pill( $p ); // phpcs:ignore ?></td>
									<td class="ims-muted"><?php echo esc_html( $p->supplier_name ? $p->supplier_name : '—' ); ?></td>
									<td class="ims-row-actions">
										<?php if ( current_user_can( 'ims_manage_stock' ) ) : ?>
											<a class="ims-button ims-button-small ims-button-secondary" href="<?php echo esc_url( $this->url( 'ims-stock', array( 'product_id' => $p->id, 'type' => 'in' ) ) ); ?>">Restock</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>

			<section class="ims-card">
				<header class="ims-card-head">
					<div><h2>Recent activity</h2><p>Latest stock movements.</p></div>
					<?php if ( $mov && current_user_can( 'ims_manage_stock' ) ) : ?><a class="ims-link" href="<?php echo esc_url( $this->url( 'ims-stock' ) ); ?>">View all</a><?php endif; ?>
				</header>
				<?php if ( ! $mov ) : ?>
					<?php $this->empty_state( 'history', 'No activity yet', 'Stock movements will appear here as your team records them.' ); ?>
				<?php else : ?>
					<ul class="ims-feed">
						<?php foreach ( $mov as $m ) : ?>
							<?php
							$change = 'out' === $m->type ? -(int) $m->quantity : (int) $m->quantity;
							$label  = array( 'in' => 'Stock in', 'out' => 'Stock out', 'adjust' => 'Adjustment' )[ $m->type ] ?? $m->type;
							?>
							<li>
								<span class="ims-feed-type ims-type-<?php echo esc_attr( $m->type ); ?>"><?php echo $this->icon( 'out' === $m->type ? 'download' : ( 'in' === $m->type ? 'box' : 'history' ), 14 ); // phpcs:ignore ?></span>
								<div class="ims-feed-body">
									<div class="ims-feed-top"><strong><?php echo esc_html( $m->product_name ); ?></strong><span class="ims-change <?php echo $change < 0 ? 'neg' : 'pos'; ?>"><?php echo esc_html( ( $change > 0 ? '+' : '' ) . $change ); ?></span></div>
									<div class="ims-feed-meta"><?php echo esc_html( $label . ( $m->note ? ' · ' . $m->note : '' ) ); ?></div>
									<div class="ims-feed-meta"><?php echo esc_html( ( $m->user_name ? $m->user_name : 'System' ) . ' · ' . $this->ago( $m->created_at ) ); ?></div>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</div>
		<?php
		$this->foot();
	}

	/* ===================================================================
	 * Products
	 * ================================================================= */

	public function page_products() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			if ( ! current_user_can( 'ims_manage_products' ) ) {
				wp_die( esc_html__( 'You do not have permission to edit products.', 'ims' ), 403 );
			}
			$this->product_form( 'edit' === $action ? absint( $_GET['id'] ?? 0 ) : 0 );
			return;
		}

		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$category = isset( $_GET['cat'] ) ? sanitize_text_field( wp_unslash( $_GET['cat'] ) ) : '';
		$low      = ! empty( $_GET['low'] );
		$products = IMS_DB::get_products( array( 'search' => $search, 'category' => $category, 'low_stock' => $low ) );
		$stats    = IMS_DB::stats();
		$can_edit = current_user_can( 'ims_manage_products' );
		$can_stk  = current_user_can( 'ims_manage_stock' );

		$actions = array();
		if ( current_user_can( 'ims_export' ) ) {
			$actions[] = array( wp_nonce_url( admin_url( 'admin-post.php?action=ims_export_csv' ), 'ims_export_csv' ), 'Export CSV', 'ims-button-secondary', 'download' );
		}
		if ( $can_edit ) {
			$actions[] = array( $this->url( 'ims-products', array( 'action' => 'new' ) ), 'Add product', 'ims-button-primary', 'box' );
		}
		$this->head( 'Products', 'Everything you stock, with live quantities.', $actions );
		$attention = $stats['low_stock'] + $stats['out_stock'];
		?>
		<section class="ims-card ims-card-flush">
			<div class="ims-toolbar">
				<nav class="ims-tabs" aria-label="Filter products">
					<a class="<?php echo $low ? '' : 'is-active'; ?>" href="<?php echo esc_url( $this->url( 'ims-products', array_filter( array( 's' => $search, 'cat' => $category ) ) ) ); ?>">All <span><?php echo (int) $stats['products']; ?></span></a>
					<a class="<?php echo $low ? 'is-active' : ''; ?>" href="<?php echo esc_url( $this->url( 'ims-products', array_filter( array( 's' => $search, 'cat' => $category, 'low' => 1 ) ) ) ); ?>">Needs attention <span class="<?php echo $attention ? 'is-alert' : ''; ?>"><?php echo (int) $attention; ?></span></a>
				</nav>
				<form method="get" class="ims-search">
					<input type="hidden" name="page" value="ims-products">
					<?php if ( $low ) : ?><input type="hidden" name="low" value="1"><?php endif; ?>
					<label class="ims-search-field"><?php echo $this->icon( 'search', 16 ); // phpcs:ignore ?><span class="screen-reader-text">Search products</span><input type="search" id="ims-product-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name, SKU or category"></label>
					<label class="screen-reader-text" for="ims-cat">Category</label>
					<select id="ims-cat" name="cat" onchange="this.form.submit()">
						<option value="">All categories</option>
						<?php foreach ( IMS_DB::get_categories() as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $category, $c ); ?>><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="ims-button ims-button-secondary" type="submit">Search</button>
				</form>
			</div>

			<?php if ( ! $products ) : ?>
				<?php
				if ( $search || $category || $low ) {
					$this->empty_state( 'list', 'No matching products', 'Try a different search or clear the filters.', array( $this->url( 'ims-products' ), 'Clear filters' ) );
				} else {
					$this->empty_state( 'box', 'No products yet', 'Add your first product to start tracking stock.', $can_edit ? array( $this->url( 'ims-products', array( 'action' => 'new' ) ), 'Add product' ) : null );
				}
				?>
			<?php else : ?>
			<div class="ims-table-wrap">
				<table class="ims-table ims-table-products">
					<thead><tr><th>Product</th><th>Category</th><th>Location</th><th>Supplier</th><th class="num">In stock</th><th>Status</th><th class="num">Cost</th><th class="num">Price</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>
					<tbody>
					<?php foreach ( $products as $p ) : ?>
						<?php
						$pct = $p->reorder_level > 0 ? min( 100, round( $p->quantity / max( 1, $p->reorder_level * 3 ) * 100 ) ) : 100;
						list( $st ) = $this->status( $p );
						?>
						<tr>
							<td>
								<span class="ims-prod">
									<?php if ( $can_edit ) : ?>
										<a href="<?php echo esc_url( $this->url( 'ims-products', array( 'action' => 'edit', 'id' => $p->id ) ) ); ?>"><?php echo esc_html( $p->name ); ?></a>
									<?php else : ?>
										<strong><?php echo esc_html( $p->name ); ?></strong>
									<?php endif; ?>
									<code><?php echo esc_html( $p->sku ); ?></code>
								</span>
							</td>
							<td><?php echo $p->category ? '<span class="ims-chip">' . esc_html( $p->category ) . '</span>' : '<span class="ims-muted">—</span>'; ?></td>
							<td class="ims-muted"><?php echo esc_html( $p->location ? $p->location : '—' ); ?></td>
							<td class="ims-muted"><?php echo esc_html( $p->supplier_name ? $p->supplier_name : '—' ); ?></td>
							<td class="num">
								<span class="ims-stock"><strong><?php echo esc_html( number_format_i18n( $p->quantity ) ); ?></strong><span class="ims-meter ims-meter-<?php echo esc_attr( $st ); ?>"><i style="width:<?php echo (int) $pct; ?>%"></i></span><small>reorder at <?php echo (int) $p->reorder_level; ?></small></span>
							</td>
							<td><?php echo $this->pill( $p ); // phpcs:ignore ?></td>
							<td class="num ims-muted"><?php echo esc_html( $this->money( $p->cost_price ) ); ?></td>
							<td class="num"><?php echo esc_html( $this->money( $p->sale_price ) ); ?></td>
							<td class="ims-row-actions">
								<?php if ( $can_stk ) : ?>
									<a class="ims-button ims-button-small ims-button-secondary" href="<?php echo esc_url( $this->url( 'ims-stock', array( 'product_id' => $p->id ) ) ); ?>">Stock</a>
								<?php endif; ?>
								<?php if ( $can_edit ) : ?>
									<a class="ims-button ims-button-small ims-button-ghost" href="<?php echo esc_url( $this->url( 'ims-products', array( 'action' => 'edit', 'id' => $p->id ) ) ); ?>">Edit</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="ims-table-foot"><?php echo esc_html( sprintf( 'Showing %d of %d products', count( $products ), $stats['products'] ) ); ?></div>
			<?php endif; ?>
		</section>
		<?php
		$this->foot();
	}

	private function product_form( $id ) {
		$p = $id ? IMS_DB::get_product( $id ) : null;
		if ( $id && ! $p ) {
			$this->head( 'Product not found', '', array(), array( $this->url( 'ims-products' ), 'Products' ) );
			$this->empty_state( 'box', 'This product doesn’t exist', 'It may have been deleted.', array( $this->url( 'ims-products' ), 'Back to products' ) );
			$this->foot();
			return;
		}
		$v = function ( $field, $default = '' ) use ( $p ) {
			return $p ? $p->$field : $default;
		};
		$this->head(
			$p ? $p->name : 'Add product',
			$p ? 'SKU ' . $p->sku . ' · last updated ' . $this->ago( $p->updated_at ) : 'Create a product and set its opening stock.',
			array(),
			array( $this->url( 'ims-products' ), 'Products' )
		);
		?>
		<div class="<?php echo $p ? 'ims-cols ims-cols-main' : ''; ?>">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ims-card ims-card-flush ims-form">
				<input type="hidden" name="action" value="ims_save_product">
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'ims_save_product' ); ?>

				<div class="ims-section">
					<div class="ims-section-info"><h2>Basic details</h2><p>How the product is identified across the system.</p></div>
					<div class="ims-section-fields">
						<div class="ims-field ims-span-2"><label for="name">Product name <em>*</em></label><input required id="name" name="name" value="<?php echo esc_attr( $v( 'name' ) ); ?>" placeholder="e.g. Wireless Mouse"></div>
						<div class="ims-field"><label for="sku">SKU <em>*</em></label><input required id="sku" name="sku" class="ims-mono" value="<?php echo esc_attr( $v( 'sku' ) ); ?>" placeholder="e.g. WM-204"><small>Must be unique.</small></div>
						<div class="ims-field"><label for="category">Category</label><input id="category" name="category" list="ims-cats" value="<?php echo esc_attr( $v( 'category' ) ); ?>" placeholder="e.g. Electronics">
							<datalist id="ims-cats"><?php foreach ( IMS_DB::get_categories() as $c ) { echo '<option value="' . esc_attr( $c ) . '">'; } ?></datalist></div>
						<div class="ims-field ims-span-2"><label for="supplier_id">Supplier</label>
							<select id="supplier_id" name="supplier_id">
								<option value="0">No supplier</option>
								<?php foreach ( IMS_DB::get_suppliers() as $s ) : ?>
									<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( (int) $v( 'supplier_id', 0 ), (int) $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
								<?php endforeach; ?>
							</select></div>
					</div>
				</div>

				<div class="ims-section">
					<div class="ims-section-info"><h2>Stock</h2><p>You get a low-stock alert when the quantity falls to the reorder level.</p></div>
					<div class="ims-section-fields">
						<?php if ( ! $p ) : ?>
							<div class="ims-field"><label for="quantity">Opening quantity</label><input type="number" min="0" id="quantity" name="quantity" value="0"><small>Recorded as the first “stock in”.</small></div>
						<?php else : ?>
							<div class="ims-field"><label>Current quantity</label><div class="ims-readonly"><?php echo (int) $p->quantity; ?> <?php echo $this->pill( $p ); // phpcs:ignore ?></div><small>Change it from <a href="<?php echo esc_url( $this->url( 'ims-stock', array( 'product_id' => $p->id ) ) ); ?>">Stock In / Out</a> so it’s logged.</small></div>
						<?php endif; ?>
						<div class="ims-field"><label for="reorder_level">Reorder level</label><input type="number" min="0" id="reorder_level" name="reorder_level" value="<?php echo esc_attr( $v( 'reorder_level', 5 ) ); ?>"></div>
						<div class="ims-field ims-span-2"><label for="location">Location / shelf</label><input id="location" name="location" value="<?php echo esc_attr( $v( 'location' ) ); ?>" placeholder="e.g. Aisle 3, Shelf B"></div>
					</div>
				</div>

				<div class="ims-section">
					<div class="ims-section-info"><h2>Pricing</h2><p>Cost price is used to calculate your stock value.</p></div>
					<div class="ims-section-fields" data-ims-margin>
						<div class="ims-field"><label for="cost_price">Cost price</label><input type="number" step="0.01" min="0" id="cost_price" name="cost_price" value="<?php echo esc_attr( $v( 'cost_price', '0.00' ) ); ?>"></div>
						<div class="ims-field"><label for="sale_price">Sale price</label><input type="number" step="0.01" min="0" id="sale_price" name="sale_price" value="<?php echo esc_attr( $v( 'sale_price', '0.00' ) ); ?>"></div>
						<p class="ims-span-2 ims-margin" aria-live="polite"></p>
					</div>
				</div>

				<footer class="ims-form-foot">
					<a class="ims-button ims-button-secondary" href="<?php echo esc_url( $this->url( 'ims-products' ) ); ?>">Cancel</a>
					<button type="submit" class="ims-button ims-button-primary"><?php echo $p ? 'Save changes' : 'Add product'; ?></button>
				</footer>
			</form>

			<?php if ( $p ) : ?>
				<aside class="ims-side">
					<section class="ims-card">
						<header class="ims-card-head"><div><h2>At a glance</h2></div></header>
						<dl class="ims-facts">
							<div><dt>In stock</dt><dd><?php echo (int) $p->quantity; ?></dd></div>
							<div><dt>Stock value</dt><dd><?php echo esc_html( $this->money( $p->quantity * $p->cost_price ) ); ?></dd></div>
							<div><dt>Added</dt><dd><?php echo esc_html( mysql2date( 'M j, Y', $p->created_at ) ); ?></dd></div>
						</dl>
						<?php if ( current_user_can( 'ims_manage_stock' ) ) : ?>
							<a class="ims-button ims-button-primary ims-button-block" href="<?php echo esc_url( $this->url( 'ims-stock', array( 'product_id' => $p->id ) ) ); ?>">Record movement</a>
						<?php endif; ?>
					</section>
					<section class="ims-card ims-danger">
						<header class="ims-card-head"><div><h2>Delete product</h2><p>Removes the product and its stock history. This can’t be undone.</p></div></header>
						<a class="ims-button ims-button-danger ims-button-block" data-ims-confirm="Delete <?php echo esc_attr( $p->name ); ?> and its stock history? This can’t be undone." href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ims_delete_product&id=' . $p->id ), 'ims_delete_product_' . $p->id ) ); ?>">Delete product</a>
					</section>
				</aside>
			<?php endif; ?>
		</div>

		<?php if ( $p ) : ?>
			<section class="ims-card ims-card-flush ims-mt">
				<header class="ims-card-head ims-pad"><div><h2>Stock history</h2><p>Every change to this product’s quantity.</p></div></header>
				<?php $this->movements_table( IMS_DB::get_movements( 50, $p->id ), false ); ?>
			</section>
		<?php endif; ?>
		<?php
		$this->foot();
	}

	public function handle_save_product() {
		$this->guard( 'ims_save_product', 'ims_manage_products' );
		$id  = absint( $_POST['id'] ?? 0 );
		$sku = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );

		$data = array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'sku'           => $sku,
			'category'      => sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) ),
			'supplier_id'   => absint( $_POST['supplier_id'] ?? 0 ),
			'reorder_level' => absint( $_POST['reorder_level'] ?? 0 ),
			'cost_price'    => round( max( 0, (float) ( $_POST['cost_price'] ?? 0 ) ), 2 ),
			'sale_price'    => round( max( 0, (float) ( $_POST['sale_price'] ?? 0 ) ), 2 ),
			'location'      => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
		);

		$back = $id ? array( 'action' => 'edit', 'id' => $id ) : array( 'action' => 'new' );
		if ( '' === $data['name'] || '' === $sku ) {
			$this->redirect( 'ims-products', 'Name and SKU are required.', 'error', $back );
		}
		if ( IMS_DB::sku_exists( $sku, $id ) ) {
			$this->redirect( 'ims-products', 'That SKU is already used by another product.', 'error', $back );
		}

		if ( $id ) {
			IMS_DB::save_product( $data, $id );
			$this->redirect( 'ims-products', 'Product updated.' );
		}

		$data['quantity'] = 0;
		$new_id           = IMS_DB::save_product( $data );
		$opening          = absint( $_POST['quantity'] ?? 0 );
		if ( $new_id && $opening > 0 ) {
			IMS_DB::record_movement( $new_id, 'in', $opening, 'Opening stock' );
		}
		$this->redirect( 'ims-products', 'Product added.' );
	}

	public function handle_delete_product() {
		$id = absint( $_GET['id'] ?? 0 );
		$this->guard( 'ims_delete_product_' . $id, 'ims_manage_products' );
		IMS_DB::delete_product( $id );
		$this->redirect( 'ims-products', 'Product deleted.' );
	}

	public function handle_export_csv() {
		$this->guard( 'ims_export_csv', 'ims_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=inventory-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Name', 'SKU', 'Category', 'Supplier', 'Location', 'Quantity', 'Reorder level', 'Cost price', 'Sale price', 'Stock value' ) );
		foreach ( IMS_DB::get_products() as $p ) {
			$row = array( $p->name, $p->sku, $p->category, $p->supplier_name, $p->location, $p->quantity, $p->reorder_level, $p->cost_price, $p->sale_price, round( $p->quantity * $p->cost_price, 2 ) );
			// Prevent spreadsheet formula injection.
			$row = array_map( function ( $cell ) {
				return ( is_string( $cell ) && preg_match( '/^[=+\-@]/', $cell ) ) ? "'" . $cell : $cell;
			}, $row );
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	/* ===================================================================
	 * Stock in / out
	 * ================================================================= */

	public function page_stock() {
		$selected = absint( $_GET['product_id'] ?? 0 );
		$type     = isset( $_GET['type'] ) && in_array( $_GET['type'], array( 'in', 'out', 'adjust' ), true ) ? sanitize_key( $_GET['type'] ) : 'in';
		$products = IMS_DB::get_products();
		$this->head( 'Stock In / Out', 'Record deliveries, sales and stock counts. Every change is logged with your name.' );

		if ( ! $products ) {
			$this->empty_state( 'box', 'Add a product first', 'You need at least one product before you can record stock.', current_user_can( 'ims_manage_products' ) ? array( $this->url( 'ims-products', array( 'action' => 'new' ) ), 'Add product' ) : null );
			$this->foot();
			return;
		}
		$types = array(
			'in'     => array( 'Stock in', 'Goods received or returned', 'box' ),
			'out'    => array( 'Stock out', 'Sold, used or sent out', 'download' ),
			'adjust' => array( 'Adjust', 'Set the exact count after a stocktake', 'history' ),
		);
		?>
		<div class="ims-cols ims-cols-stock">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ims-card ims-card-flush ims-form" data-ims-movement>
				<input type="hidden" name="action" value="ims_stock_movement">
				<?php wp_nonce_field( 'ims_stock_movement' ); ?>
				<header class="ims-card-head ims-pad"><div><h2>Record a movement</h2></div></header>
				<div class="ims-pad ims-stack">
					<div class="ims-field"><label for="product_id">Product</label>
						<select id="product_id" name="product_id" required>
							<?php foreach ( $products as $p ) : ?>
								<option value="<?php echo esc_attr( $p->id ); ?>" data-qty="<?php echo (int) $p->quantity; ?>" <?php selected( $selected, (int) $p->id ); ?>><?php echo esc_html( sprintf( '%s · %s', $p->name, $p->sku ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<fieldset class="ims-field ims-choice">
						<legend>Type of movement</legend>
						<div class="ims-choice-grid">
							<?php foreach ( $types as $key => $t ) : ?>
								<label class="ims-choice-item">
									<input type="radio" name="type" value="<?php echo esc_attr( $key ); ?>" <?php checked( $type, $key ); ?>>
									<span class="ims-choice-card"><span class="ims-choice-icon ims-type-<?php echo esc_attr( $key ); ?>"><?php echo $this->icon( $t[2], 16 ); // phpcs:ignore ?></span><strong><?php echo esc_html( $t[0] ); ?></strong><small><?php echo esc_html( $t[1] ); ?></small></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<div class="ims-grid-2">
						<div class="ims-field"><label for="quantity" data-ims-qty-label>Quantity</label><input type="number" id="quantity" name="quantity" min="0" required placeholder="0"></div>
						<div class="ims-field"><label for="note">Note</label><input type="text" id="note" name="note" placeholder="e.g. PO #1042, Invoice 553"></div>
					</div>

					<div class="ims-preview" aria-live="polite">
						<div><span>Current stock</span><strong data-ims-current>0</strong></div>
						<span class="ims-preview-arrow"><?php echo $this->icon( 'arrow', 16 ); // phpcs:ignore ?></span>
						<div><span>After this movement</span><strong data-ims-after>0</strong></div>
					</div>
				</div>
				<footer class="ims-form-foot">
					<button type="submit" class="ims-button ims-button-primary">Record movement</button>
				</footer>
			</form>

			<section class="ims-card ims-card-flush">
				<header class="ims-card-head ims-pad">
					<div><h2>Movement history</h2><p><?php echo $selected ? 'Showing one product. ' : 'Latest 200 movements across all products.'; ?><?php if ( $selected ) : ?><a class="ims-link" href="<?php echo esc_url( $this->url( 'ims-stock' ) ); ?>">Show all</a><?php endif; ?></p></div>
				</header>
				<?php $this->movements_table( IMS_DB::get_movements( 200, $selected ), ! $selected ); ?>
			</section>
		</div>
		<?php
		$this->foot();
	}

	public function handle_stock_movement() {
		$this->guard( 'ims_stock_movement', 'ims_manage_stock' );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$type       = sanitize_key( $_POST['type'] ?? '' );
		$qty        = absint( $_POST['quantity'] ?? 0 );
		$note       = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		$back       = array( 'product_id' => $product_id );

		if ( 'adjust' !== $type && $qty < 1 ) {
			$this->redirect( 'ims-stock', 'Quantity must be at least 1.', 'error', $back );
		}
		$result = IMS_DB::record_movement( $product_id, $type, $qty, $note );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'ims-stock', $result->get_error_message(), 'error', $back );
		}
		$this->redirect( 'ims-stock', 'Stock movement recorded.', 'success', $back );
	}

	private function movements_table( $rows, $show_product = true ) {
		if ( ! $rows ) {
			$this->empty_state( 'history', 'No movements yet', 'Recorded stock movements will appear here.' );
			return;
		}
		$labels = array( 'in' => 'Stock in', 'out' => 'Stock out', 'adjust' => 'Adjustment' );
		echo '<div class="ims-table-wrap"><table class="ims-table"><thead><tr><th>Date</th>' . ( $show_product ? '<th>Product</th>' : '' ) . '<th>Type</th><th class="num">Change</th><th class="num">Balance</th><th>Note</th><th>By</th></tr></thead><tbody>';
		foreach ( $rows as $m ) {
			$change = 'out' === $m->type ? -(int) $m->quantity : (int) $m->quantity;
			printf(
				'<tr><td class="ims-nowrap">%s<small class="ims-block ims-muted">%s</small></td>%s<td><span class="ims-pill ims-type-pill-%s">%s</span></td><td class="num"><span class="ims-change %s">%s</span></td><td class="num">%d</td><td class="ims-muted">%s</td><td class="ims-muted">%s</td></tr>',
				esc_html( mysql2date( 'M j, Y', $m->created_at ) ),
				esc_html( mysql2date( 'g:i a', $m->created_at ) ),
				$show_product ? '<td><span class="ims-prod"><strong>' . esc_html( $m->product_name ) . '</strong><code>' . esc_html( $m->sku ) . '</code></span></td>' : '',
				esc_attr( $m->type ),
				esc_html( $labels[ $m->type ] ?? $m->type ),
				$change < 0 ? 'neg' : 'pos',
				esc_html( ( $change > 0 ? '+' : '' ) . $change ),
				(int) $m->balance_after,
				esc_html( $m->note ? $m->note : '—' ),
				esc_html( $m->user_name ? $m->user_name : '—' )
			);
		}
		echo '</tbody></table></div>';
	}

	/* ===================================================================
	 * Suppliers
	 * ================================================================= */

	public function page_suppliers() {
		$can       = current_user_can( 'ims_manage_suppliers' );
		$edit_id   = $can ? absint( $_GET['id'] ?? 0 ) : 0;
		$edit      = $edit_id ? IMS_DB::get_supplier( $edit_id ) : null;
		$suppliers = IMS_DB::get_suppliers();
		$v         = function ( $f ) use ( $edit ) {
			return $edit ? $edit->$f : '';
		};
		$this->head( 'Suppliers', 'The companies you buy stock from.' );
		?>
		<div class="<?php echo $can ? 'ims-cols ims-cols-main' : ''; ?>">
			<section class="ims-card ims-card-flush">
				<header class="ims-card-head ims-pad"><div><h2>All suppliers</h2><p><?php echo esc_html( sprintf( '%d supplier%s', count( $suppliers ), 1 === count( $suppliers ) ? '' : 's' ) ); ?></p></div></header>
				<?php if ( ! $suppliers ) : ?>
					<?php $this->empty_state( 'truck', 'No suppliers yet', 'Add the companies you reorder from, then link them to products.' ); ?>
				<?php else : ?>
				<div class="ims-table-wrap">
					<table class="ims-table">
						<thead><tr><th>Supplier</th><th>Email</th><th>Phone</th><th class="num">Products</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>
						<tbody>
						<?php foreach ( $suppliers as $s ) : ?>
							<tr class="<?php echo $edit_id === (int) $s->id ? 'is-selected' : ''; ?>">
								<td><span class="ims-prod"><strong><?php echo esc_html( $s->name ); ?></strong><span class="ims-muted"><?php echo esc_html( $s->contact_person ? $s->contact_person : 'No contact person' ); ?></span></span></td>
								<td class="ims-muted"><?php echo esc_html( $s->email ? $s->email : '—' ); ?></td>
								<td class="ims-muted ims-nowrap"><?php echo esc_html( $s->phone ? $s->phone : '—' ); ?></td>
								<td class="num"><?php echo (int) $s->product_count; ?></td>
								<td class="ims-row-actions">
									<?php if ( $can ) : ?>
										<a class="ims-button ims-button-small ims-button-ghost" href="<?php echo esc_url( $this->url( 'ims-suppliers', array( 'id' => $s->id ) ) ); ?>">Edit</a>
										<a class="ims-button ims-button-small ims-button-ghost ims-text-danger" data-ims-confirm="Delete <?php echo esc_attr( $s->name ); ?>? Its products will be kept." href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ims_delete_supplier&id=' . $s->id ), 'ims_delete_supplier_' . $s->id ) ); ?>">Delete</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

			<?php if ( $can ) : ?>
			<aside class="ims-side">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ims-card ims-card-flush ims-form">
					<input type="hidden" name="action" value="ims_save_supplier">
					<input type="hidden" name="id" value="<?php echo esc_attr( $edit_id ); ?>">
					<?php wp_nonce_field( 'ims_save_supplier' ); ?>
					<header class="ims-card-head ims-pad"><div><h2><?php echo $edit ? 'Edit supplier' : 'Add a supplier'; ?></h2></div></header>
					<div class="ims-pad ims-stack">
						<div class="ims-field"><label for="s_name">Company name <em>*</em></label><input required id="s_name" name="name" value="<?php echo esc_attr( $v( 'name' ) ); ?>"></div>
						<div class="ims-field"><label for="s_contact">Contact person</label><input id="s_contact" name="contact_person" value="<?php echo esc_attr( $v( 'contact_person' ) ); ?>"></div>
						<div class="ims-field"><label for="s_email">Email</label><input type="email" id="s_email" name="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>"></div>
						<div class="ims-field"><label for="s_phone">Phone</label><input id="s_phone" name="phone" value="<?php echo esc_attr( $v( 'phone' ) ); ?>"></div>
						<div class="ims-field"><label for="s_address">Address</label><textarea id="s_address" rows="3" name="address"><?php echo esc_textarea( $v( 'address' ) ); ?></textarea></div>
					</div>
					<footer class="ims-form-foot">
						<?php if ( $edit ) : ?><a class="ims-button ims-button-secondary" href="<?php echo esc_url( $this->url( 'ims-suppliers' ) ); ?>">Cancel</a><?php endif; ?>
						<button type="submit" class="ims-button ims-button-primary"><?php echo $edit ? 'Save changes' : 'Add supplier'; ?></button>
					</footer>
				</form>
			</aside>
			<?php endif; ?>
		</div>
		<?php
		$this->foot();
	}

	public function handle_save_supplier() {
		$this->guard( 'ims_save_supplier', 'ims_manage_suppliers' );
		$id   = absint( $_POST['id'] ?? 0 );
		$data = array(
			'name'           => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'contact_person' => sanitize_text_field( wp_unslash( $_POST['contact_person'] ?? '' ) ),
			'email'          => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'          => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'address'        => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
		);
		if ( '' === $data['name'] ) {
			$this->redirect( 'ims-suppliers', 'Supplier name is required.', 'error' );
		}
		IMS_DB::save_supplier( $data, $id );
		$this->redirect( 'ims-suppliers', $id ? 'Supplier updated.' : 'Supplier added.' );
	}

	public function handle_delete_supplier() {
		$id = absint( $_GET['id'] ?? 0 );
		$this->guard( 'ims_delete_supplier_' . $id, 'ims_manage_suppliers' );
		IMS_DB::delete_supplier( $id );
		$this->redirect( 'ims-suppliers', 'Supplier deleted.' );
	}

	/* ===================================================================
	 * Security log
	 * ================================================================= */

	const EVENT_LABELS = array(
		'login_success'            => array( 'Login', 'ok' ),
		'login_success_remembered' => array( 'Login (remember me)', 'ok' ),
		'login_failed'             => array( 'Failed login', 'warn' ),
		'login_blocked'            => array( 'Login blocked', 'bad' ),
		'account_locked'           => array( 'Account locked', 'bad' ),
		'login_unverified'         => array( 'Login, email not verified', 'warn' ),
		'logout'                   => array( 'Logout', 'info' ),
		'register'                 => array( 'Registered', 'info' ),
		'email_verified'           => array( 'Email verified', 'ok' ),
		'otp_failed'               => array( 'Wrong code', 'warn' ),
		'password_reset_requested' => array( 'Reset requested', 'info' ),
		'reset_code_verified'      => array( 'Reset code verified', 'info' ),
		'password_reset'           => array( 'Password reset', 'ok' ),
		'password_changed'         => array( 'Password changed', 'ok' ),
		'reauth_failed'            => array( 'Wrong current password', 'warn' ),
		'email_changed'            => array( 'Email changed', 'info' ),
		'profile_updated'          => array( 'Profile updated', 'info' ),
		'sessions_revoked'         => array( 'Other devices logged out', 'info' ),
	);

	const BUCKET_LABELS = array(
		'login_ip'      => 'Login (IP address)',
		'login_account' => 'Login (account)',
		'register_ip'   => 'Registration (IP address)',
		'forgot_ip'     => 'Password reset (IP address)',
		'otp_ip'        => 'Code verification (IP address)',
		'reauth'        => 'Password confirmation (user)',
	);

	public function page_security() {
		if ( ! current_user_can( 'ims_view_security' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ims' ), 403 );
		}
		$event = isset( $_GET['event'] ) ? sanitize_key( $_GET['event'] ) : '';
		$event = isset( self::EVENT_LABELS[ $event ] ) ? $event : '';
		$rows  = IMS_Security::get_log( 200, $event );
		$locks = IMS_Security::active_lockouts();
		$this->head( 'Security log', 'Sign-ins, failed attempts and account changes from the last 90 days.' );
		?>
		<section class="ims-card ims-card-flush">
			<header class="ims-card-head ims-pad"><div><h2>Active lockouts</h2><p>Logins paused after too many failed attempts. Unlock to let someone back in now.</p></div></header>
			<?php if ( ! $locks ) : ?>
				<?php $this->empty_state( 'shield', 'No active lockouts', 'Nobody is currently blocked from signing in.' ); ?>
			<?php else : ?>
				<div class="ims-table-wrap">
					<table class="ims-table">
						<thead><tr><th>Type</th><th class="num">Attempts</th><th>Locked until</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>
						<tbody>
						<?php foreach ( $locks as $l ) : ?>
							<tr>
								<td><?php echo esc_html( self::BUCKET_LABELS[ $l->bucket ] ?? $l->bucket ); ?></td>
								<td class="num"><?php echo (int) $l->hits; ?></td>
								<td class="ims-muted"><?php echo esc_html( wp_date( 'M j, g:i a', (int) $l->locked_until ) ); ?></td>
								<td class="ims-row-actions"><a class="ims-button ims-button-small ims-button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ims_unlock&id=' . (int) $l->id ), 'ims_unlock_' . (int) $l->id ) ); ?>">Unlock</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="ims-table-foot">Emails and IP addresses are stored as salted hashes here. Match them with the events below.</div>
			<?php endif; ?>
		</section>

		<section class="ims-card ims-card-flush ims-mt">
			<div class="ims-toolbar">
				<div><h2 class="ims-toolbar-title">Events</h2></div>
				<form method="get" class="ims-search">
					<input type="hidden" name="page" value="ims-security">
					<label class="screen-reader-text" for="ims-event">Event type</label>
					<select id="ims-event" name="event" onchange="this.form.submit()">
						<option value="">All events</option>
						<?php foreach ( self::EVENT_LABELS as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $event, $key ); ?>><?php echo esc_html( $label[0] ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
			<?php if ( ! $rows ) : ?>
				<?php $this->empty_state( 'history', 'No events', $event ? 'No events of this type yet.' : 'Sign-in activity will appear here.' ); ?>
			<?php else : ?>
			<div class="ims-table-wrap">
				<table class="ims-table">
					<thead><tr><th>Time</th><th>Event</th><th>User</th><th>IP address</th><th>Device</th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$label = self::EVENT_LABELS[ $row->event ] ?? array( $row->event, 'info' );
						$user  = $row->user_id ? get_userdata( $row->user_id ) : null;
						?>
						<tr>
							<td class="ims-nowrap"><?php echo esc_html( get_date_from_gmt( $row->created_at, 'M j, Y' ) ); ?><small class="ims-block ims-muted"><?php echo esc_html( get_date_from_gmt( $row->created_at, 'g:i:s a' ) ); ?></small></td>
							<td><span class="ims-pill ims-ev-<?php echo esc_attr( $label[1] ); ?>"><?php echo esc_html( $label[0] ); ?></span></td>
							<td><?php echo $user ? '<span class="ims-prod"><a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a><span class="ims-muted">' . esc_html( $user->user_email ) . '</span></span>' : '<span class="ims-muted">' . esc_html( $row->email ? $row->email : '—' ) . '</span>'; ?></td>
							<td><code class="ims-code"><?php echo esc_html( $row->ip ); ?></code></td>
							<td class="ims-muted"><?php echo esc_html( IMS_Security::describe_ua( $row->user_agent ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="ims-table-foot">Showing the latest <?php echo count( $rows ); ?> events. Events older than 90 days are deleted automatically.</div>
			<?php endif; ?>
		</section>
		<?php
		$this->foot();
	}

	public function handle_unlock() {
		$id = absint( $_GET['id'] ?? 0 );
		$this->guard( 'ims_unlock_' . $id, 'ims_view_security' );
		IMS_Security::unlock_by_id( $id );
		$this->redirect( 'ims-security', 'Lockout removed.' );
	}
}
