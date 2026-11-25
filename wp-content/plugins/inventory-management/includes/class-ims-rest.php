<?php
/**
 * REST API: /wp-json/ims/v1/...
 *
 * Authentication: a logged-in browser session (cookie + X-WP-Nonce header) or
 * WordPress Application Passwords (HTTP Basic auth; HTTPS required outside local dev).
 * Authorization: every route checks a specific inventory capability.
 * Rate limiting: 120 requests per minute per user (or per IP when anonymous),
 * reported with X-RateLimit-* headers and 429 + Retry-After when exceeded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_REST {

	const NS = 'ims/v1';

	/** @var array|null Rate-limit info for the current request (for response headers). */
	private $rate = null;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'rate_limit' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'rate_headers' ), 10, 3 );
	}

	/* ===================================================================
	 * Rate limiting
	 * ================================================================= */

	private function is_ours( $request ) {
		return 0 === strpos( $request->get_route(), '/' . self::NS );
	}

	public function rate_limit( $result, $server, $request ) {
		if ( null !== $result || ! $this->is_ours( $request ) ) {
			return $result;
		}
		$who  = is_user_logged_in() ? 'user:' . get_current_user_id() : 'ip:' . IMS_Security::client_ip();
		$wait = IMS_Security::blocked_for( 'api', $who );
		list( $max ) = IMS_Security::POLICIES['api'];

		if ( $wait ) {
			$this->rate = array( $max, 0, $wait );
			return new WP_Error( 'ims_rate_limited', 'Too many requests. Please slow down.', array( 'status' => 429, 'retry_after' => $wait ) );
		}
		list( , $remaining, $blocked ) = IMS_Security::hit( 'api', $who );
		$this->rate = array( $max, $remaining, $blocked );
		return $result;
	}

	public function rate_headers( $response, $server, $request ) {
		if ( $this->rate && $this->is_ours( $request ) && $response instanceof WP_REST_Response ) {
			list( $max, $remaining, $wait ) = $this->rate;
			$response->header( 'X-RateLimit-Limit', (string) $max );
			$response->header( 'X-RateLimit-Remaining', (string) $remaining );
			if ( 429 === $response->get_status() ) {
				$response->header( 'Retry-After', (string) max( 1, (int) $wait ) );
			}
		}
		return $response;
	}

	/* ===================================================================
	 * Authorization helpers
	 * ================================================================= */

	private function can( $cap ) {
		return function () use ( $cap ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'ims_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
			}
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'ims_forbidden', 'You do not have permission to do this.', array( 'status' => 403 ) );
			}
			return true;
		};
	}

	/* ===================================================================
	 * Routes
	 * ================================================================= */

	public function routes() {
		$id = array(
			'id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'me' ),
				'permission_callback' => function () {
					return is_user_logged_in() ? true : new WP_Error( 'ims_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function () {
					return rest_ensure_response( IMS_DB::stats() );
				},
				'permission_callback' => $this->can( 'ims_view_inventory' ),
			)
		);

		register_rest_route(
			self::NS,
			'/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_products' ),
					'permission_callback' => $this->can( 'ims_view_inventory' ),
					'args'                => array(
						'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'category'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'low_stock' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_product' ),
					'permission_callback' => $this->can( 'ims_manage_products' ),
					'args'                => $this->product_args( true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product' ),
					'permission_callback' => $this->can( 'ims_view_inventory' ),
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_product' ),
					'permission_callback' => $this->can( 'ims_manage_products' ),
					'args'                => array_merge( $id, $this->product_args( false ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_product' ),
					'permission_callback' => $this->can( 'ims_manage_products' ),
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/movements',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_movements' ),
					'permission_callback' => $this->can( 'ims_view_inventory' ),
					'args'                => array(
						'product_id' => array( 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ),
						'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_movement' ),
					'permission_callback' => $this->can( 'ims_manage_stock' ),
					'args'                => array(
						'product_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true, 'sanitize_callback' => 'absint' ),
						'type'       => array( 'type' => 'string', 'enum' => array( 'in', 'out', 'adjust' ), 'required' => true ),
						'quantity'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'required' => true ),
						'note'       => array( 'type' => 'string', 'maxLength' => 255, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/suppliers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function () {
						return rest_ensure_response( array_map( array( $this, 'format_supplier' ), IMS_DB::get_suppliers() ) );
					},
					'permission_callback' => $this->can( 'ims_view_inventory' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_supplier' ),
					'permission_callback' => $this->can( 'ims_manage_suppliers' ),
					'args'                => $this->supplier_args( true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/suppliers/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_supplier' ),
					'permission_callback' => $this->can( 'ims_view_inventory' ),
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_supplier' ),
					'permission_callback' => $this->can( 'ims_manage_suppliers' ),
					'args'                => array_merge( $id, $this->supplier_args( false ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_supplier' ),
					'permission_callback' => $this->can( 'ims_manage_suppliers' ),
					'args'                => $id,
				),
			)
		);
	}

	private function product_args( $creating ) {
		return array(
			'name'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'required' => $creating, 'sanitize_callback' => 'sanitize_text_field' ),
			'sku'           => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'required' => $creating, 'sanitize_callback' => 'sanitize_text_field' ),
			'category'      => array( 'type' => 'string', 'maxLength' => 100, 'sanitize_callback' => 'sanitize_text_field' ),
			'supplier_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
			'reorder_level' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000000 ),
			'cost_price'    => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 9999999999 ),
			'sale_price'    => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 9999999999 ),
			'location'      => array( 'type' => 'string', 'maxLength' => 100, 'sanitize_callback' => 'sanitize_text_field' ),
			'quantity'      => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'description' => 'Opening stock (create only). Use /movements to change stock later.' ),
		);
	}

	private function supplier_args( $creating ) {
		return array(
			'name'           => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'required' => $creating, 'sanitize_callback' => 'sanitize_text_field' ),
			'contact_person' => array( 'type' => 'string', 'maxLength' => 191, 'sanitize_callback' => 'sanitize_text_field' ),
			'email'          => array( 'type' => 'string', 'maxLength' => 191, 'format' => 'email' ),
			'phone'          => array( 'type' => 'string', 'maxLength' => 50, 'sanitize_callback' => 'sanitize_text_field' ),
			'address'        => array( 'type' => 'string', 'maxLength' => 1000, 'sanitize_callback' => 'sanitize_textarea_field' ),
		);
	}

	/* ===================================================================
	 * Handlers
	 * ================================================================= */

	public function me() {
		$u    = wp_get_current_user();
		$caps = array();
		foreach ( array_keys( IMS_Security::CAPS ) as $cap ) {
			$caps[ $cap ] = current_user_can( $cap );
		}
		return rest_ensure_response(
			array(
				'id'           => $u->ID,
				'email'        => $u->user_email,
				'name'         => $u->display_name,
				'roles'        => array_values( $u->roles ),
				'capabilities' => $caps,
			)
		);
	}

	private function format_product( $p ) {
		$qty = (int) $p->quantity;
		return array(
			'id'            => (int) $p->id,
			'name'          => $p->name,
			'sku'           => $p->sku,
			'category'      => $p->category,
			'supplier_id'   => (int) $p->supplier_id,
			'supplier_name' => isset( $p->supplier_name ) ? $p->supplier_name : null,
			'quantity'      => $qty,
			'reorder_level' => (int) $p->reorder_level,
			'status'        => $qty <= 0 ? 'out_of_stock' : ( $qty <= (int) $p->reorder_level ? 'low_stock' : 'in_stock' ),
			'cost_price'    => (float) $p->cost_price,
			'sale_price'    => (float) $p->sale_price,
			'location'      => $p->location,
			'created_at'    => $p->created_at,
			'updated_at'    => $p->updated_at,
		);
	}

	public function format_supplier( $s ) {
		return array(
			'id'             => (int) $s->id,
			'name'           => $s->name,
			'contact_person' => $s->contact_person,
			'email'          => $s->email,
			'phone'          => $s->phone,
			'address'        => $s->address,
			'product_count'  => isset( $s->product_count ) ? (int) $s->product_count : null,
		);
	}

	public function list_products( WP_REST_Request $req ) {
		$rows = IMS_DB::get_products(
			array(
				'search'    => (string) $req['search'],
				'category'  => (string) $req['category'],
				'low_stock' => (bool) $req['low_stock'],
			)
		);
		return rest_ensure_response( array_map( array( $this, 'format_product' ), $rows ) );
	}

	private function find_product( $id ) {
		$p = IMS_DB::get_product( $id );
		return $p ? $p : new WP_Error( 'ims_not_found', 'Product not found.', array( 'status' => 404 ) );
	}

	public function get_product( WP_REST_Request $req ) {
		$p = $this->find_product( (int) $req['id'] );
		return is_wp_error( $p ) ? $p : rest_ensure_response( $this->format_product( $p ) );
	}

	/** Collect provided product fields from the request. */
	private function product_data( WP_REST_Request $req ) {
		$data = array();
		foreach ( array( 'name', 'sku', 'category', 'location' ) as $f ) {
			if ( $req->has_param( $f ) ) {
				$data[ $f ] = trim( (string) $req[ $f ] );
			}
		}
		foreach ( array( 'supplier_id', 'reorder_level' ) as $f ) {
			if ( $req->has_param( $f ) ) {
				$data[ $f ] = absint( $req[ $f ] );
			}
		}
		foreach ( array( 'cost_price', 'sale_price' ) as $f ) {
			if ( $req->has_param( $f ) ) {
				$data[ $f ] = round( (float) $req[ $f ], 2 );
			}
		}
		if ( isset( $data['name'] ) && '' === $data['name'] ) {
			return new WP_Error( 'ims_invalid', 'Name cannot be empty.', array( 'status' => 400 ) );
		}
		if ( isset( $data['sku'] ) && '' === $data['sku'] ) {
			return new WP_Error( 'ims_invalid', 'SKU cannot be empty.', array( 'status' => 400 ) );
		}
		if ( ! empty( $data['supplier_id'] ) && ! IMS_DB::get_supplier( $data['supplier_id'] ) ) {
			return new WP_Error( 'ims_invalid', 'Supplier not found.', array( 'status' => 400 ) );
		}
		return $data;
	}

	public function create_product( WP_REST_Request $req ) {
		$data = $this->product_data( $req );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( IMS_DB::sku_exists( $data['sku'] ) ) {
			return new WP_Error( 'ims_conflict', 'That SKU is already used by another product.', array( 'status' => 409 ) );
		}
		$data['quantity'] = 0;
		$id               = IMS_DB::save_product( $data );
		$opening          = absint( $req['quantity'] );
		if ( $id && $opening > 0 ) {
			IMS_DB::record_movement( $id, 'in', $opening, 'Opening stock (API)' );
		}
		$res = rest_ensure_response( $this->format_product( IMS_DB::get_products_by_id( $id ) ) );
		$res->set_status( 201 );
		return $res;
	}

	public function update_product( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$p  = $this->find_product( $id );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( $req->has_param( 'quantity' ) ) {
			return new WP_Error( 'ims_invalid', 'Quantity cannot be edited directly. Record a stock movement via POST /movements.', array( 'status' => 400 ) );
		}
		$data = $this->product_data( $req );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['sku'] ) && IMS_DB::sku_exists( $data['sku'], $id ) ) {
			return new WP_Error( 'ims_conflict', 'That SKU is already used by another product.', array( 'status' => 409 ) );
		}
		if ( $data ) {
			IMS_DB::save_product( $data, $id );
		}
		return rest_ensure_response( $this->format_product( IMS_DB::get_products_by_id( $id ) ) );
	}

	public function delete_product( WP_REST_Request $req ) {
		$p = $this->find_product( (int) $req['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		IMS_DB::delete_product( (int) $p->id );
		return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $p->id ) );
	}

	public function list_movements( WP_REST_Request $req ) {
		$rows = IMS_DB::get_movements( (int) $req['limit'], (int) $req['product_id'] );
		return rest_ensure_response(
			array_map(
				function ( $m ) {
					return array(
						'id'            => (int) $m->id,
						'product_id'    => (int) $m->product_id,
						'product_name'  => $m->product_name,
						'sku'           => $m->sku,
						'type'          => $m->type,
						'quantity'      => (int) $m->quantity,
						'balance_after' => (int) $m->balance_after,
						'note'          => $m->note,
						'user'          => $m->user_name,
						'created_at'    => $m->created_at,
					);
				},
				$rows
			)
		);
	}

	public function create_movement( WP_REST_Request $req ) {
		$type = (string) $req['type'];
		$qty  = (int) $req['quantity'];
		if ( 'adjust' !== $type && $qty < 1 ) {
			return new WP_Error( 'ims_invalid', 'Quantity must be at least 1.', array( 'status' => 400 ) );
		}
		$result = IMS_DB::record_movement( (int) $req['product_id'], $type, $qty, (string) $req['note'] );
		if ( is_wp_error( $result ) ) {
			$status = 'ims_no_product' === $result->get_error_code() ? 404 : 409;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		$res = rest_ensure_response( $this->format_product( IMS_DB::get_products_by_id( (int) $req['product_id'] ) ) );
		$res->set_status( 201 );
		return $res;
	}

	public function get_supplier( WP_REST_Request $req ) {
		$s = IMS_DB::get_supplier( (int) $req['id'] );
		return $s ? rest_ensure_response( $this->format_supplier( $s ) ) : new WP_Error( 'ims_not_found', 'Supplier not found.', array( 'status' => 404 ) );
	}

	public function save_supplier( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( $id && ! IMS_DB::get_supplier( $id ) ) {
			return new WP_Error( 'ims_not_found', 'Supplier not found.', array( 'status' => 404 ) );
		}
		$data = array();
		foreach ( array( 'name', 'contact_person', 'phone', 'address' ) as $f ) {
			if ( $req->has_param( $f ) ) {
				$data[ $f ] = trim( (string) $req[ $f ] );
			}
		}
		if ( $req->has_param( 'email' ) ) {
			$data['email'] = sanitize_email( $req['email'] );
		}
		if ( isset( $data['name'] ) && '' === $data['name'] ) {
			return new WP_Error( 'ims_invalid', 'Supplier name cannot be empty.', array( 'status' => 400 ) );
		}
		$new_id = $data ? IMS_DB::save_supplier( $data, $id ) : $id;
		$res    = rest_ensure_response( $this->format_supplier( IMS_DB::get_supplier( $new_id ) ) );
		if ( ! $id ) {
			$res->set_status( 201 );
		}
		return $res;
	}

	public function delete_supplier( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! IMS_DB::get_supplier( $id ) ) {
			return new WP_Error( 'ims_not_found', 'Supplier not found.', array( 'status' => 404 ) );
		}
		IMS_DB::delete_supplier( $id );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}
}
