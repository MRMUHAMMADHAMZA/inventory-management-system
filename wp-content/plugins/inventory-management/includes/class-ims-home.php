<?php
/**
 * Home (landing) page — [ims_home] — plus site-wide front-end assets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_Home {

	public function __construct() {
		add_shortcode( 'ims_home', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'maybe_create_page' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	public function maybe_create_page() {
		$id = (int) get_option( 'ims_home_page' );
		if ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Home',
				'post_name'    => 'home',
				'post_content' => '<!-- wp:shortcode -->[ims_home]<!-- /wp:shortcode -->',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			IMS_Auth::apply_fullwidth_layout( $id );
			update_option( 'ims_home_page', $id );
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}
	}

	public function assets() {
		wp_enqueue_style( 'ims-font', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'ims-front', IMS_URL . 'assets/ims-front.css', array(), IMS_VERSION );
	}

	public function body_class( $classes ) {
		if ( is_page() && (int) get_option( 'ims_home_page' ) === get_queried_object_id() ) {
			$classes[] = 'ims-fullbleed';
			$classes[] = 'ims-page-home';
		}
		return $classes;
	}

	private function icon( $name, $size = 20 ) {
		return IMS_Icons::get( $name, $size );
	}

	private function page_url( $slug ) {
		$ids = (array) get_option( 'ims_pages', array() );
		return ! empty( $ids[ $slug ] ) ? get_permalink( $ids[ $slug ] ) : home_url( '/' . $slug . '/' );
	}

	public function render() {
		$logged_in = is_user_logged_in();
		$inventory = get_page_by_path( 'inventory' );
		$inv_url   = $inventory ? get_permalink( $inventory ) : home_url( '/' );

		if ( $logged_in ) {
			$primary   = current_user_can( IMS_CAP ) ? array( admin_url( 'admin.php?page=ims' ), 'Open dashboard' ) : array( $inv_url, 'View stock list' );
			$secondary = array( $this->page_url( 'profile' ), 'My account' );
		} else {
			$primary   = array( $this->page_url( 'register' ), 'Create free account' );
			$secondary = array( $this->page_url( 'login' ), 'Log in' );
		}

		$features = array(
			array( 'box', 'Product catalogue', 'Keep every product, SKU, category, shelf location, cost and price in one organised list.' ),
			array( 'history', 'Stock in & out', 'Record goods received, items sold and stocktake adjustments. Stock can never go below zero.' ),
			array( 'bell', 'Low-stock alerts', 'Set a reorder level per product and see what needs restocking before you run out.' ),
			array( 'truck', 'Supplier management', 'Store supplier contacts and link them to products so you always know who to reorder from.' ),
			array( 'list', 'Complete audit trail', 'Every movement is logged with who made it, when, and why.' ),
			array( 'download', 'Reports & CSV export', 'See total stock value at a glance and export your inventory to Excel or Google Sheets.' ),
		);

		$steps = array(
			array( 'Add your products', 'Enter products with SKUs, prices and reorder levels, and link each one to a supplier.' ),
			array( 'Record every movement', 'Log stock coming in and going out. Quantities and history update instantly.' ),
			array( 'Reorder on time', 'The dashboard lists low and out-of-stock items so you restock at the right moment.' ),
		);

		// Sample rows for the product preview (illustrative only).
		$stock = array(
			array( 'Wireless Mouse', 'WM-204', 'Electronics', 128, 'ok', 'In stock' ),
			array( 'USB-C Cable 2m', 'UC-118', 'Accessories', 4, 'low', 'Low stock' ),
			array( 'Laptop Stand', 'LS-310', 'Accessories', 0, 'out', 'Out of stock' ),
			array( 'Desk Lamp', 'DL-422', 'Office', 56, 'ok', 'In stock' ),
			array( 'Notebook A5', 'NB-015', 'Stationery', 340, 'ok', 'In stock' ),
		);
		$activity = array(
			array( '09:42', 'Stock in', 'Wireless Mouse', '+120', 'in' ),
			array( '09:15', 'Stock out', 'Desk Lamp', '−6', 'out' ),
			array( 'Yesterday', 'Adjustment', 'Notebook A5', '340', 'adj' ),
			array( 'Yesterday', 'Stock out', 'USB-C Cable 2m', '−12', 'out' ),
		);

		ob_start();
		?>
		<div class="ims-home">

			<section class="ims-h-hero">
				<div class="ims-container ims-h-hero-grid">
					<div class="ims-h-hero-copy">
						<p class="ims-h-kicker">Inventory management software</p>
						<h1>Know exactly what&rsquo;s in stock, at every moment.</h1>
						<p class="ims-h-lead">Track products, record stock movements, manage suppliers and get low-stock alerts from one secure dashboard built for growing businesses.</p>
						<div class="ims-h-cta">
							<a class="ims-btn ims-btn-lg" href="<?php echo esc_url( $primary[0] ); ?>"><?php echo esc_html( $primary[1] ); ?></a>
							<a class="ims-btn ims-btn-lg ims-btn-ghost" href="<?php echo esc_url( $secondary[0] ); ?>"><?php echo esc_html( $secondary[1] ); ?></a>
						</div>
						<ul class="ims-h-points">
							<li><?php echo $this->icon( 'check', 16 ); // phpcs:ignore ?>Free to get started</li>
							<li><?php echo $this->icon( 'check', 16 ); // phpcs:ignore ?>Email-verified accounts</li>
							<li><?php echo $this->icon( 'check', 16 ); // phpcs:ignore ?>Export anytime</li>
						</ul>
					</div>

					<div class="ims-h-product" aria-hidden="true">
						<div class="ims-h-window">
							<div class="ims-h-window-bar">
								<span class="ims-h-window-title">Inventory</span>
								<span class="ims-h-window-tabs"><b>Products</b><span>Movements</span><span>Suppliers</span></span>
							</div>
							<div class="ims-h-kpis">
								<div><small>Products</small><strong>1,284</strong></div>
								<div><small>Units in stock</small><strong>18,930</strong></div>
								<div><small>Low stock</small><strong class="warn">7</strong></div>
								<div><small>Out of stock</small><strong class="bad">2</strong></div>
							</div>
							<table class="ims-h-table">
								<thead><tr><th>Product</th><th>SKU</th><th>Category</th><th class="num">Qty</th><th>Status</th></tr></thead>
								<tbody>
								<?php foreach ( $stock as $r ) : ?>
									<tr>
										<td><?php echo esc_html( $r[0] ); ?></td>
										<td class="mono"><?php echo esc_html( $r[1] ); ?></td>
										<td><?php echo esc_html( $r[2] ); ?></td>
										<td class="num"><?php echo (int) $r[3]; ?></td>
										<td><span class="ims-h-pill ims-h-pill-<?php echo esc_attr( $r[4] ); ?>"><?php echo esc_html( $r[5] ); ?></span></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</section>

			<section class="ims-h-highlights">
				<div class="ims-container ims-h-highlights-grid">
					<div><strong>Real-time</strong><span>stock levels across every product</span></div>
					<div><strong>Automatic</strong><span>low and out-of-stock alerts</span></div>
					<div><strong>Every change</strong><span>logged with user and time</span></div>
					<div><strong>One click</strong><span>CSV export of your inventory</span></div>
				</div>
			</section>

			<section class="ims-h-section" id="features">
				<div class="ims-container">
					<div class="ims-h-head">
						<p class="ims-h-kicker">Features</p>
						<h2>Everything you need to run your stock room</h2>
						<p>Replace spreadsheets with one reliable record of what you have, where it is, and when to reorder.</p>
					</div>
					<div class="ims-h-features">
						<?php foreach ( $features as $f ) : ?>
							<article class="ims-h-feature">
								<span class="ims-h-feature-icon"><?php echo $this->icon( $f[0], 20 ); // phpcs:ignore ?></span>
								<h3><?php echo esc_html( $f[1] ); ?></h3>
								<p><?php echo esc_html( $f[2] ); ?></p>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<section class="ims-h-section ims-h-section-alt">
				<div class="ims-container">
					<div class="ims-h-head">
						<p class="ims-h-kicker">How it works</p>
						<h2>Up and running in three steps</h2>
					</div>
					<ol class="ims-h-steps">
						<?php foreach ( $steps as $i => $s ) : ?>
							<li>
								<span class="ims-h-step-num"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
								<h3><?php echo esc_html( $s[0] ); ?></h3>
								<p><?php echo esc_html( $s[1] ); ?></p>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			</section>

			<section class="ims-h-section">
				<div class="ims-container ims-h-split">
					<div>
						<p class="ims-h-kicker">Accountability &amp; security</p>
						<h2>A full record of every change</h2>
						<p class="ims-h-text">Every stock movement is saved with the person who made it and the time it happened. Accounts are protected at every step.</p>
						<ul class="ims-h-checklist">
							<li><?php echo $this->icon( 'check', 16 ); // phpcs:ignore ?><span><b>Email verification</b> for every new account</span></li>
							<li><?php echo $this->icon( 'check', 16 ); // phpcs:ignore ?><span><b>Login protection</b> against repeated failed attempts</span></li>
							<li><?php echo $this->icon( 'check', 16 ); // phpcs:ignore ?><span><b>Secure password reset</b> with short-lived one-time codes</span></li>
							<li><?php echo $this->icon( 'check', 16 ); // phpcs:ignore ?><span><b>Role-based access</b>: only administrators change stock</span></li>
						</ul>
					</div>
					<div class="ims-h-window ims-h-activity" aria-hidden="true">
						<div class="ims-h-window-bar"><span class="ims-h-window-title">Recent activity</span></div>
						<table class="ims-h-table">
							<thead><tr><th>Time</th><th>Type</th><th>Product</th><th class="num">Qty</th></tr></thead>
							<tbody>
							<?php foreach ( $activity as $a ) : ?>
								<tr>
									<td class="muted"><?php echo esc_html( $a[0] ); ?></td>
									<td><span class="ims-h-type ims-h-type-<?php echo esc_attr( $a[4] ); ?>"><?php echo esc_html( $a[1] ); ?></span></td>
									<td><?php echo esc_html( $a[2] ); ?></td>
									<td class="num"><?php echo esc_html( $a[3] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</section>

			<section class="ims-h-cta-band">
				<div class="ims-container ims-h-cta-inner">
					<div>
						<h2>Ready to take control of your stock?</h2>
						<p>Create your account and add your first product in minutes.</p>
					</div>
					<div class="ims-h-cta">
						<a class="ims-btn ims-btn-lg" href="<?php echo esc_url( $primary[0] ); ?>"><?php echo esc_html( $primary[1] ); ?></a>
						<a class="ims-btn ims-btn-lg ims-btn-outline-light" href="<?php echo esc_url( $secondary[0] ); ?>"><?php echo esc_html( $secondary[1] ); ?></a>
					</div>
				</div>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}
}
