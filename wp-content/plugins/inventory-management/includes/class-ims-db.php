<?php
/**
 * Database layer: table creation and queries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'ims_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$products  = self::table( 'products' );
		$suppliers = self::table( 'suppliers' );
		$movements = self::table( 'movements' );

		dbDelta( "CREATE TABLE $suppliers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			contact_person VARCHAR(191) DEFAULT '' NOT NULL,
			email VARCHAR(191) DEFAULT '' NOT NULL,
			phone VARCHAR(50) DEFAULT '' NOT NULL,
			address TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) $charset;" );

		dbDelta( "CREATE TABLE $products (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			sku VARCHAR(100) NOT NULL,
			category VARCHAR(100) DEFAULT '' NOT NULL,
			supplier_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			quantity INT NOT NULL DEFAULT 0,
			reorder_level INT NOT NULL DEFAULT 0,
			cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
			sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
			location VARCHAR(100) DEFAULT '' NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sku (sku),
			KEY supplier_id (supplier_id)
		) $charset;" );

		dbDelta( "CREATE TABLE $movements (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(10) NOT NULL,
			quantity INT NOT NULL,
			balance_after INT NOT NULL,
			note VARCHAR(255) DEFAULT '' NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id)
		) $charset;" );

		$rate = self::table( 'rate_limits' );
		dbDelta( "CREATE TABLE $rate (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket VARCHAR(40) NOT NULL,
			ident CHAR(64) NOT NULL,
			hits INT UNSIGNED NOT NULL DEFAULT 0,
			window_start INT UNSIGNED NOT NULL DEFAULT 0,
			locked_until INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_ident (bucket,ident),
			KEY locked_until (locked_until)
		) $charset;" );

		$log = self::table( 'auth_log' );
		dbDelta( "CREATE TABLE $log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(191) NOT NULL DEFAULT '',
			event VARCHAR(40) NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY event (event),
			KEY created_at (created_at)
		) $charset;" );

		update_option( 'ims_db_version', IMS_DB_VERSION );
	}

	/* ---------- Products ---------- */

	public static function get_products( $args = array() ) {
		global $wpdb;
		$p = self::table( 'products' );
		$s = self::table( 'suppliers' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(p.name LIKE %s OR p.sku LIKE %s OR p.category LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'p.category = %s';
			$params[] = $args['category'];
		}
		if ( ! empty( $args['low_stock'] ) ) {
			$where[] = 'p.quantity <= p.reorder_level';
		}

		$sql = "SELECT p.*, s.name AS supplier_name FROM $p p LEFT JOIN $s s ON s.id = p.supplier_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.name ASC';
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function get_product( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'products' ) . ' WHERE id = %d', $id ) );
	}

	/** One product including its supplier name (for API responses). */
	public static function get_products_by_id( $id ) {
		global $wpdb;
		$p = self::table( 'products' );
		$s = self::table( 'suppliers' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT p.*, s.name AS supplier_name FROM $p p LEFT JOIN $s s ON s.id = p.supplier_id WHERE p.id = %d", $id ) );
	}

	public static function sku_exists( $sku, $exclude_id = 0 ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'products' ) . ' WHERE sku = %s AND id <> %d', $sku, $exclude_id ) );
	}

	public static function save_product( $data, $id = 0 ) {
		global $wpdb;
		$now                = current_time( 'mysql' );
		$data['updated_at'] = $now;
		if ( $id ) {
			$wpdb->update( self::table( 'products' ), $data, array( 'id' => $id ) );
			return $id;
		}
		$data['created_at'] = $now;
		$wpdb->insert( self::table( 'products' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function delete_product( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'movements' ), array( 'product_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::table( 'products' ), array( 'id' => $id ), array( '%d' ) );
	}

	public static function get_categories() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT DISTINCT category FROM ' . self::table( 'products' ) . " WHERE category <> '' ORDER BY category" );
	}

	/* ---------- Suppliers ---------- */

	public static function get_suppliers() {
		global $wpdb;
		$s = self::table( 'suppliers' );
		$p = self::table( 'products' );
		return $wpdb->get_results( "SELECT s.*, (SELECT COUNT(*) FROM $p p WHERE p.supplier_id = s.id) AS product_count FROM $s s ORDER BY s.name ASC" );
	}

	public static function get_supplier( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'suppliers' ) . ' WHERE id = %d', $id ) );
	}

	public static function save_supplier( $data, $id = 0 ) {
		global $wpdb;
		if ( $id ) {
			$wpdb->update( self::table( 'suppliers' ), $data, array( 'id' => $id ) );
			return $id;
		}
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table( 'suppliers' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function delete_supplier( $id ) {
		global $wpdb;
		$wpdb->update( self::table( 'products' ), array( 'supplier_id' => 0 ), array( 'supplier_id' => $id ) );
		$wpdb->delete( self::table( 'suppliers' ), array( 'id' => $id ), array( '%d' ) );
	}

	/* ---------- Stock movements ---------- */

	/**
	 * Record a stock movement and update the product quantity atomically.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $type       in | out | adjust (adjust sets the quantity to $qty).
	 * @param int    $qty        Quantity (positive).
	 * @param string $note       Optional note.
	 * @return true|WP_Error
	 */
	public static function record_movement( $product_id, $type, $qty, $note = '' ) {
		global $wpdb;
		$p = self::table( 'products' );

		$wpdb->query( 'START TRANSACTION' );
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT quantity FROM $p WHERE id = %d FOR UPDATE", $product_id ) );

		if ( null === $current ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ims_no_product', __( 'Product not found.', 'ims' ) );
		}
		$current = (int) $current;

		switch ( $type ) {
			case 'in':
				$new = $current + $qty;
				break;
			case 'out':
				if ( $qty > $current ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'ims_insufficient', sprintf( __( 'Not enough stock. Only %d available.', 'ims' ), $current ) );
				}
				$new = $current - $qty;
				break;
			case 'adjust':
				$new = $qty;
				$qty = $new - $current;
				break;
			default:
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'ims_bad_type', __( 'Invalid movement type.', 'ims' ) );
		}

		$wpdb->update( $p, array( 'quantity' => $new, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $product_id ) );
		$wpdb->insert(
			self::table( 'movements' ),
			array(
				'product_id'    => $product_id,
				'type'          => $type,
				'quantity'      => $qty,
				'balance_after' => $new,
				'note'          => $note,
				'user_id'       => get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
			)
		);
		$wpdb->query( 'COMMIT' );
		return true;
	}

	public static function get_movements( $limit = 100, $product_id = 0 ) {
		global $wpdb;
		$m     = self::table( 'movements' );
		$p     = self::table( 'products' );
		$where = $product_id ? $wpdb->prepare( 'WHERE m.product_id = %d', $product_id ) : '';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, p.name AS product_name, p.sku, u.display_name AS user_name
				FROM $m m
				LEFT JOIN $p p ON p.id = m.product_id
				LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
				$where
				ORDER BY m.id DESC LIMIT %d",
				$limit
			)
		);
	}

	/* ---------- Dashboard stats ---------- */

	public static function stats() {
		global $wpdb;
		$p = self::table( 'products' );
		return array(
			'products'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $p" ),
			'units'       => (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM $p" ),
			'stock_value' => (float) $wpdb->get_var( "SELECT COALESCE(SUM(quantity * cost_price),0) FROM $p" ),
			'low_stock'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $p WHERE quantity <= reorder_level AND quantity > 0" ),
			'out_stock'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $p WHERE quantity <= 0" ),
			'suppliers'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'suppliers' ) ),
		);
	}
}
