<?php
/**
 * Plugin Name: قیمت لحظه‌ای (همراه کشف قیمت)
 * Description: وقتی مشتری وارد صفحه‌ی محصول می‌شود، محصول را به سبد اضافه می‌کند یا سفارش ثبت می‌کند، قیمت همان محصول را بی‌صدا و در پس‌زمینه از طریق افزونه‌ی «کشف قیمت ووکامرس» به‌روز می‌کند؛ درست مثل اینکه مدیر صفحه را رفرش کرده باشد.
 * Version:     1.0.2
 * Author:      Sina Sadeghiaram
 * Text Domain: pcyab-live-price
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCLP_VERSION', '1.0.2' );
define( 'PCLP_FILE', __FILE__ );
define( 'PCLP_URL', plugin_dir_url( __FILE__ ) );

final class PCLP_Live_Price {

	const OPT      = 'pclp_settings';
	const LOG_OPT  = 'pclp_log';
	const AJAX     = 'pclp_refresh';

	/** true while this plugin itself is sending a loopback request. */
	private static $in_call = false;

	/** Per-request cache for is_linked(). */
	private static $linked = array();

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	public static function init() {
		// Requests made by this plugin itself: do nothing, avoid loops.
		if ( ! empty( $_SERVER['HTTP_X_PCLP_LOOPBACK'] ) ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_post' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PCLP_FILE ), array( __CLASS__, 'action_links' ) );

		add_action( 'http_api_curl', array( __CLASS__, 'curl_resolve' ), 10, 3 );

		$s = self::settings();
		if ( 'yes' !== $s['enabled'] ) {
			return;
		}

		// 1) Product page view (works even with WP Rocket cache, because it is an AJAX call).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax_refresh' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX, array( __CLASS__, 'ajax_refresh' ) );

		// Keep our small script out of WP Rocket "Delay JavaScript execution".
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_exclusions' ) );
		add_filter( 'rocket_exclude_defer_js', array( __CLASS__, 'rocket_exclusions' ) );

		// 2) Add to cart.
		if ( (int) $s['cart_minutes'] > 0 ) {
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'on_add_to_cart' ), 20, 5 );
		}

		// 3) Checkout (classic + block checkout).
		if ( (int) $s['checkout_minutes'] > 0 ) {
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'on_checkout' ), 20, 2 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'on_block_checkout' ), 5, 2 );
		}
	}

	public static function defaults() {
		return array(
			'enabled'          => 'yes',
			'method'           => 'front',   // admin | front | both
			'repeat'           => 2,
			'view_minutes'     => 3,
			'cart_minutes'     => 5,
			'checkout_minutes' => 5,
			'only_linked'      => 'yes',
			'swap_price'       => 'yes',
			'keepalive'        => 'yes',
			'admin_user'       => 0,
			'selector'         => '.single-product .wd-single-price .price, .single-product div.product .summary-inner > p.price, .single-product div.product .summary p.price, .single-product div.product .entry-summary .price, .product-info .price-wrapper .price, .single-product div.product p.price, .single-product .wp-block-woocommerce-product-price',
			'resolve_ip'       => '',
		);
	}

	public static function settings() {
		$s = get_option( self::OPT, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
		if ( 0 === strpos( $s['selector'], '.single-product div.product .summary p.price' ) ) {
			$s['selector'] = self::defaults()['selector'];
		}
		return $s;
	}

	/* ------------------------------------------------------------------ *
	 * Front end: product page
	 * ------------------------------------------------------------------ */

	public static function enqueue() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$pid = (int) get_queried_object_id();
		if ( ! $pid || ! self::should_handle( $pid ) ) {
			return;
		}
		$s = self::settings();
		wp_enqueue_script( 'pclp-live-price', PCLP_URL . 'assets/pclp-live-price.js', array(), PCLP_VERSION, true );
		wp_localize_script(
			'pclp-live-price',
			'pclpLive',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX,
				'pid'       => $pid,
				'swap'      => 'yes' === $s['swap_price'] ? 1 : 0,
				'selector'  => $s['selector'],
				'keepalive' => 'yes' === $s['keepalive'] ? 1 : 0,
				'every'     => max( 60, (int) $s['view_minutes'] * 60 + 10 ),
				'maxPings'  => 5,
			)
		);
	}

	public static function rocket_exclusions( $list ) {
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[] = 'pclp-live-price';
		$list[] = 'pclpLive';
		return $list;
	}

	public static function ajax_refresh() {
		nocache_headers();
		$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		if ( ! $pid || 'product' !== get_post_type( $pid ) || 'publish' !== get_post_status( $pid ) ) {
			wp_send_json_error( array( 'status' => 'invalid' ) );
		}

		$s      = self::settings();
		$status = 'fresh';

		if ( self::should_handle( $pid ) && ! self::is_bot() && self::rate_ok() && self::is_stale( $pid, (int) $s['view_minutes'] ) ) {
			$r      = self::refresh_product( $pid, 'view' );
			$status = $r['status'];
		}

		self::flush_product_cache( $pid );
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			wp_send_json_error( array( 'status' => 'invalid' ) );
		}
		wp_send_json_success(
			array(
				'status'     => $status,
				'price_html' => $product->get_price_html(),
				'in_stock'   => $product->is_in_stock() ? 1 : 0,
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Add to cart / checkout
	 * ------------------------------------------------------------------ */

	public static function on_add_to_cart( $passed, $product_id = 0, $quantity = 1, $variation_id = 0, $variations = array() ) {
		if ( ! $passed || ! $product_id ) {
			return $passed;
		}
		$pid = self::parent_id( $product_id );
		$s   = self::settings();
		if ( ! self::should_handle( $pid ) || ! self::is_stale( $pid, (int) $s['cart_minutes'] ) ) {
			return $passed;
		}

		$before = self::db_state( $pid );
		self::refresh_product( $pid, 'cart', 40 );
		self::flush_product_cache( $pid );
		$after = self::db_state( $pid );

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return $passed;
		}
		if ( ! $product->is_in_stock() ) {
			wc_add_notice( sprintf( 'متأسفانه «%s» همین الان ناموجود شد.', $product->get_name() ), 'error' );
			return false;
		}
		if ( $before['price'] !== $after['price'] ) {
			wc_add_notice(
				sprintf( 'قیمت «%1$s» همین الان به‌روز شد. قیمت جدید: %2$s', $product->get_name(), wp_strip_all_tags( html_entity_decode( wc_price( $product->get_price() ) ) ) ),
				'notice'
			);
		}
		return $passed;
	}

	/**
	 * Re-checks stale cart items. Returns a list of messages for items whose price or stock changed.
	 */
	private static function recheck_cart() {
		$messages = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $messages;
		}
		$s    = self::settings();
		$cart = WC()->cart;
		$done = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$pid = self::parent_id( $item['product_id'] );
			if ( isset( $done[ $pid ] ) ) {
				continue;
			}
			$done[ $pid ] = true;
			if ( ! self::should_handle( $pid ) || ! self::is_stale( $pid, (int) $s['checkout_minutes'] ) ) {
				continue;
			}
			$before = self::db_state( $pid );
			self::refresh_product( $pid, 'checkout', 40 );
			self::flush_product_cache( $pid );
			$after = self::db_state( $pid );

			if ( $before === $after ) {
				continue;
			}
			// Replace product objects of all cart lines of this product with fresh ones.
			foreach ( $cart->cart_contents as $k => $line ) {
				if ( self::parent_id( $line['product_id'] ) !== $pid ) {
					continue;
				}
				$fresh = wc_get_product( ! empty( $line['variation_id'] ) ? $line['variation_id'] : $line['product_id'] );
				if ( ! $fresh ) {
					continue;
				}
				$cart->cart_contents[ $k ]['data'] = $fresh;
				if ( ! $fresh->is_in_stock() ) {
					$messages[] = sprintf( '«%s» همین الان ناموجود شد. لطفاً آن را از سبد خرید حذف کنید.', $fresh->get_name() );
				} else {
					$messages[] = sprintf( 'قیمت «%1$s» همین الان به‌روز شد (قیمت جدید: %2$s).', $fresh->get_name(), wp_strip_all_tags( html_entity_decode( wc_price( $fresh->get_price() ) ) ) );
				}
			}
		}
		if ( $messages ) {
			$cart->calculate_totals();
		}
		return $messages;
	}

	public static function on_checkout( $data, $errors ) {
		$messages = self::recheck_cart();
		if ( $messages && is_wp_error( $errors ) ) {
			$errors->add( 'pclp_price_changed', implode( '<br>', $messages ) . '<br>لطفاً مبلغ نهایی را بررسی کنید و دوباره روی «ثبت سفارش» بزنید.' );
		}
	}

	public static function on_block_checkout( $order = null, $request = null ) {
		$messages = self::recheck_cart();
		if ( $messages && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'pclp_price_changed',
				wp_strip_all_tags( implode( ' ', $messages ) ) . ' لطفاً مبلغ نهایی را بررسی کنید و دوباره سفارش را ثبت کنید.',
				409
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * The core: refresh one product
	 * ------------------------------------------------------------------ */

	/**
	 * Refreshes a product the same way an admin does by reloading the page.
	 *
	 * @param int    $pid     Product ID.
	 * @param string $context view | cart | checkout | test.
	 * @param int    $wait    Seconds to wait if another refresh of this product is running.
	 * @return array
	 */
	public static function refresh_product( $pid, $context, $wait = 0 ) {
		$pid = self::parent_id( $pid );
		$t0  = microtime( true );

		if ( ! self::lock( 'p' . $pid, 240 ) ) {
			if ( $wait > 0 ) {
				self::wait_unlocked( 'p' . $pid, $wait );
				return array( 'status' => 'waited', 'steps' => array() );
			}
			return array( 'status' => 'busy', 'steps' => array() );
		}

		try {
			// Only one refresh at a time on the whole site (protects your IP at the supplier sites).
			if ( ! self::wait_lock( 'global', 240, max( 30, (int) $wait ) ) ) {
				return array( 'status' => 'busy', 'steps' => array() );
			}
			try {
				return self::do_refresh( $pid, $context, $t0 );
			} finally {
				self::unlock( 'global' );
			}
		} finally {
			self::unlock( 'p' . $pid );
		}
	}

	private static function do_refresh( $pid, $context, $t0 ) {
		$s = self::settings();
		set_transient( 'pclp_last_' . $pid, time(), DAY_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true ); // phpcs:ignore
		}

		$old   = self::db_state( $pid );
		$steps = array();
		$ok    = false;

		$session = self::open_session();
		if ( is_wp_error( $session ) ) {
			$steps[] = array( 'ok' => false, 'what' => 'ورود مدیر', 'note' => $session->get_error_message() );
		} else {
			$edit_lock = self::raw_meta( $pid, '_edit_lock' );
			$repeat    = min( 3, max( 1, (int) $s['repeat'] ) );
			for ( $i = 0; $i < $repeat; $i++ ) {
				if ( in_array( $s['method'], array( 'admin', 'both' ), true ) ) {
					$r       = self::hit_admin_edit( $pid, $session['cookies'] );
					$steps[] = $r;
					$ok      = $ok || $r['ok'];
				}
				if ( in_array( $s['method'], array( 'front', 'both' ), true ) ) {
					$r       = self::hit_front( $pid, $session['cookies'] );
					$steps[] = $r;
					$ok      = $ok || $r['ok'];
				}
				if ( $i < $repeat - 1 ) {
					sleep( 1 );
				}
			}
			self::restore_edit_lock( $pid, $edit_lock );
			self::close_session( $session );
		}

		self::flush_product_cache( $pid );
		$new     = self::db_state( $pid );
		$changed = ( $old !== $new );
		if ( $changed ) {
			self::purge_page_cache( $pid );
		}

		$result = array(
			'status' => $ok ? ( $changed ? 'changed' : 'same' ) : 'error',
			'old'    => $old,
			'new'    => $new,
			'steps'  => $steps,
			'time'   => round( microtime( true ) - $t0, 1 ),
		);
		self::log( $pid, $context, $result );
		return $result;
	}

	/** Loads the product edit screen in wp-admin, exactly like refreshing it. */
	private static function hit_admin_edit( $pid, $cookies ) {
		$url = admin_url( 'post.php?post=' . $pid . '&action=edit' );
		$res = self::http( 'GET', $url, $cookies );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'what' => 'صفحه‌ی ویرایش محصول', 'note' => 'خطای اتصال: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( 200 === $code && false !== strpos( $body, 'post_ID' ) ) {
			return array( 'ok' => true, 'what' => 'صفحه‌ی ویرایش محصول', 'note' => 'باز شد (200)' );
		}
		$loc = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( false !== strpos( $loc, 'wp-login' ) ) {
			return array( 'ok' => false, 'what' => 'صفحه‌ی ویرایش محصول', 'note' => 'ورود مدیر پذیرفته نشد (به صفحه‌ی ورود هدایت شد). احتمالاً یک افزونه‌ی امنیتی مانع شده است.' );
		}
		return array( 'ok' => false, 'what' => 'صفحه‌ی ویرایش محصول', 'note' => 'کد پاسخ ' . $code . ( $loc ? ' → ' . $loc : '' ) );
	}

	/**
	 * Loads the product page as a logged-in admin (bypasses WP Rocket cache), then sends the same
	 * "pfinder_reader" request that the price finder plugin's own JavaScript sends in the browser.
	 */
	private static function hit_front( $pid, $cookies ) {
		$what = 'صفحه‌ی محصول در سایت';
		$url  = add_query_arg( 'pclp', time(), get_permalink( $pid ) );
		$res  = self::http( 'GET', $url, $cookies );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'what' => $what, 'note' => 'خطای اتصال: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return array( 'ok' => false, 'what' => $what, 'note' => 'کد پاسخ ' . $code );
		}
		$vars = self::extract_pfinder_vars( (string) wp_remote_retrieve_body( $res ) );
		if ( empty( $vars['sett'] ) || empty( $vars['data'] ) ) {
			return array( 'ok' => true, 'what' => $what, 'note' => 'صفحه باز شد (اطلاعات کشف قیمت در صفحه پیدا نشد)' );
		}

		$sett    = $vars['sett'];
		$api_url = isset( $sett->api_url ) ? (string) $sett->api_url : '';
		$nonce   = isset( $sett->api_nonce ) ? (string) $sett->api_nonce : '';
		if ( 0 === strpos( $api_url, '/' ) ) {
			$api_url = home_url( $api_url );
		}
		// Safety: only ever send the admin cookies to our own site.
		if ( ! $api_url || ! self::same_host( $api_url ) ) {
			return array( 'ok' => false, 'what' => $what, 'note' => 'آدرس API کشف قیمت نامعتبر است' );
		}

		$sent = 0;
		$good = 0;
		foreach ( $vars['data'] as $data ) {
			if ( empty( $data->pf_links ) || ! count( (array) $data->pf_links ) ) {
				continue;
			}
			if ( isset( $data->pid ) && (int) $data->pid !== (int) $pid ) {
				continue;
			}
			$links = wp_json_encode( $data->pf_links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$links = str_replace( '\\', '', (string) $links ); // same as the plugin's JS: .replace(/\\/g, '')
			$r     = self::http(
				'POST',
				$api_url . 'pfinder_reader',
				$cookies,
				array(
					'headers' => array( 'X-WP-Nonce' => $nonce ),
					'body'    => array(
						'api_nonce' => $nonce,
						'from'      => 'productpage',
						'pid'       => isset( $data->pid ) ? $data->pid : $pid,
						'arr_links' => $links,
					),
					'timeout' => 90,
				)
			);
			$sent++;
			if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
				$good++;
			}
		}
		if ( ! $sent ) {
			return array( 'ok' => true, 'what' => $what, 'note' => 'صفحه باز شد (لینکی برای بررسی نبود)' );
		}
		return array(
			'ok'   => $good > 0,
			'what' => $what,
			'note' => $good > 0 ? 'درخواست کشف قیمت انجام شد' : 'درخواست کشف قیمت ناموفق بود',
		);
	}

	private static function extract_pfinder_vars( $html ) {
		$out = array( 'sett' => null, 'data' => array() );
		if ( ! preg_match_all( '#<script\b[^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			return $out;
		}
		foreach ( $scripts[1] as $js ) {
			if ( false === strpos( $js, 'pfinder_' ) ) {
				continue;
			}
			if ( ! preg_match_all( '/\b(pfinder_sett|pfinder_data\d+)\s*=\s*(["\'])([A-Za-z0-9+\/=\\\\]+)\2/', $js, $m, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $m as $one ) {
				$raw  = base64_decode( str_replace( '\\/', '/', $one[3] ), true );
				$json = $raw ? json_decode( $raw ) : null;
				if ( ! is_object( $json ) ) {
					continue;
				}
				if ( 'pfinder_sett' === $one[1] ) {
					$out['sett'] = $json;
				} else {
					$out['data'][ $one[1] ] = $json;
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Admin session for loopback requests (never leaves the server)
	 * ------------------------------------------------------------------ */

	private static function admin_user_id() {
		$s   = self::settings();
		$uid = (int) $s['admin_user'];
		if ( $uid && user_can( $uid, 'edit_products' ) ) {
			return $uid;
		}
		$ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
		return $ids ? (int) $ids[0] : 0;
	}

	private static function open_session() {
		$uid = self::admin_user_id();
		if ( ! $uid ) {
			return new WP_Error( 'pclp_no_admin', 'هیچ کاربر مدیری پیدا نشد.' );
		}
		$exp     = time() + 10 * MINUTE_IN_SECONDS;
		$manager = WP_Session_Tokens::get_instance( $uid );
		$token   = $manager->create( $exp );
		$cookies = array(
			AUTH_COOKIE        => wp_generate_auth_cookie( $uid, $exp, 'auth', $token ),
			SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $uid, $exp, 'secure_auth', $token ),
			LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $uid, $exp, 'logged_in', $token ),
		);
		return array( 'uid' => $uid, 'token' => $token, 'cookies' => $cookies );
	}

	private static function close_session( $session ) {
		WP_Session_Tokens::get_instance( $session['uid'] )->destroy( $session['token'] );
	}

	private static function http( $method, $url, $cookies, $extra = array() ) {
		$headers = array(
			'X-PCLP-Loopback' => '1',
			'Cache-Control'   => 'no-cache',
			'Accept-Language' => 'fa-IR,fa;q=0.9',
		);
		if ( ! empty( $extra['headers'] ) ) {
			$headers = array_merge( $headers, $extra['headers'] );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => isset( $extra['timeout'] ) ? $extra['timeout'] : 60,
			'redirection' => 0,
			'sslverify'   => false, // request to our own server
			'cookies'     => $cookies,
			'headers'     => $headers,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 PCLP-Loopback',
		);
		if ( isset( $extra['body'] ) ) {
			$args['body'] = $extra['body'];
		}
		self::$in_call = true;
		$res           = wp_remote_request( $url, $args );
		self::$in_call = false;
		return $res;
	}

	/** Optional: send loopback requests straight to the server IP (useful behind ArvanCloud / Cloudflare). */
	public static function curl_resolve( $handle, $r, $url ) {
		if ( ! self::$in_call ) {
			return;
		}
		$ip = trim( (string) self::settings()['resolve_ip'] );
		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && self::same_host( $url ) ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $ip, $host . ':80:' . $ip ) ); // phpcs:ignore
		}
	}

	private static function same_host( $url ) {
		$a = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$b = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return $a && $a === $b;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function parent_id( $id ) {
		$id     = (int) $id;
		$parent = (int) wp_get_post_parent_id( $id );
		return ( $parent && 'product_variation' === get_post_type( $id ) ) ? $parent : $id;
	}

	private static function should_handle( $pid ) {
		$s = self::settings();
		if ( 'yes' !== $s['enabled'] ) {
			return false;
		}
		return 'yes' === $s['only_linked'] ? self::is_linked( $pid ) : true;
	}

	/** Has the product at least one supplier link in the price finder plugin? */
	private static function is_linked( $pid ) {
		if ( isset( self::$linked[ $pid ] ) ) {
			return self::$linked[ $pid ];
		}
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s AND meta_value LIKE %s LIMIT 1",
				$pid,
				$wpdb->esc_like( 'wp_pf_' ) . '%',
				'%http%'
			)
		);
		self::$linked[ $pid ] = ! empty( $found );
		return self::$linked[ $pid ];
	}

	/** Names (and short value hints) of the price finder meta fields of a product, for diagnostics. */
	private static function pf_meta_summary( $pid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s LIMIT 40", $pid, $wpdb->esc_like( 'wp_pf_' ) . '%' ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$v     = (string) $row->meta_value;
			$out[] = $row->meta_key . ( false !== stripos( $v, 'http' ) ? ' [لینک]' : '' ) . ' (' . strlen( $v ) . ')';
		}
		return $out;
	}

	private static function is_stale( $pid, $minutes ) {
		$last = (int) get_transient( 'pclp_last_' . $pid );
		return ! $last || ( time() - $last ) >= max( 1, $minutes ) * MINUTE_IN_SECONDS;
	}

	/** Price and stock read straight from the database (no cache). */
	private static function db_state( $pid ) {
		return array(
			'price' => (string) self::raw_meta( $pid, '_price' ),
			'stock' => (string) self::raw_meta( $pid, '_stock_status' ),
		);
	}

	private static function raw_meta( $pid, $key ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $pid, $key ) );
	}

	private static function restore_edit_lock( $pid, $before ) {
		if ( null === $before || '' === $before ) {
			delete_post_meta( $pid, '_edit_lock' );
		} else {
			update_post_meta( $pid, '_edit_lock', $before );
		}
	}

	private static function flush_product_cache( $pid ) {
		clean_post_cache( $pid );
		wp_cache_delete( $pid, 'post_meta' );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		$children = get_posts( array( 'post_parent' => $pid, 'post_type' => 'product_variation', 'fields' => 'ids', 'numberposts' => 200, 'post_status' => 'any' ) );
		foreach ( $children as $cid ) {
			clean_post_cache( $cid );
			wp_cache_delete( $cid, 'post_meta' );
		}
	}

	private static function purge_page_cache( $pid ) {
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $pid );
		}
		do_action( 'litespeed_purge_post', $pid );
	}

	private static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|lighthouse|pagespeed|headless|preview|facebookexternalhit|wget|curl|python/', $ua );
	}

	private static function rate_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'pclp_ip_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 30 ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/* --- Atomic database locks --- */

	private static function lock( $key, $ttl ) {
		global $wpdb;
		$name = 'pclp_lk_' . $key;
		$sql  = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
		if ( $wpdb->query( $wpdb->prepare( $sql, $name, (string) ( time() + $ttl ) ) ) ) {
			return true;
		}
		$exp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( $exp && $exp < time() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $exp ) );
			return (bool) $wpdb->query( $wpdb->prepare( $sql, $name, (string) ( time() + $ttl ) ) );
		}
		return false;
	}

	private static function unlock( $key ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'pclp_lk_' . $key ) );
	}

	private static function wait_lock( $key, $ttl, $seconds ) {
		$end = time() + $seconds;
		do {
			if ( self::lock( $key, $ttl ) ) {
				return true;
			}
			sleep( 1 );
		} while ( time() < $end );
		return false;
	}

	private static function wait_unlocked( $key, $seconds ) {
		$end = time() + $seconds;
		while ( time() < $end ) {
			if ( self::lock( $key, 5 ) ) {
				self::unlock( $key );
				return true;
			}
			sleep( 1 );
		}
		return false;
	}

	/* --- Log --- */

	private static function log( $pid, $context, $result ) {
		$log = get_option( self::LOG_OPT, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$notes = array();
		foreach ( $result['steps'] as $st ) {
			$notes[] = ( $st['ok'] ? '✔ ' : '✘ ' ) . $st['what'] . ': ' . $st['note'];
		}
		array_unshift(
			$log,
			array(
				't'      => time(),
				'pid'    => $pid,
				'ctx'    => $context,
				'status' => $result['status'],
				'old'    => $result['old'],
				'new'    => $result['new'],
				'time'   => $result['time'],
				'notes'  => implode( ' | ', array_unique( $notes ) ),
			)
		);
		update_option( self::LOG_OPT, array_slice( $log, 0, 80 ), false );
	}

	/* ------------------------------------------------------------------ *
	 * Admin page
	 * ------------------------------------------------------------------ */

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pclp-live-price' ) ) . '">تنظیمات</a>' );
		return $links;
	}

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', 'قیمت لحظه‌ای', 'قیمت لحظه‌ای', 'manage_woocommerce', 'pclp-live-price', array( __CLASS__, 'render_page' ) );
	}

	public static function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p>افزونه‌ی «قیمت لحظه‌ای» به ووکامرس نیاز دارد.</p></div>';
		} elseif ( ! class_exists( 'WC_Price_Finder' ) ) {
			echo '<div class="notice notice-warning"><p>افزونه‌ی «قیمت لحظه‌ای» برای کار کردن به افزونه‌ی «کشف قیمت ووکامرس» نیاز دارد. آن را فعال کنید.</p></div>';
		}
	}

	public static function handle_admin_post() {
		if ( empty( $_POST['pclp_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'pclp_admin' );
		$action = sanitize_key( wp_unslash( $_POST['pclp_action'] ) );

		if ( 'save' === $action ) {
			$d  = self::defaults();
			$in = wp_unslash( $_POST );
			$s  = array(
				'enabled'          => ! empty( $in['enabled'] ) ? 'yes' : 'no',
				'method'           => in_array( $in['method'] ?? '', array( 'admin', 'front', 'both' ), true ) ? $in['method'] : 'admin',
				'repeat'           => min( 3, max( 1, (int) ( $in['repeat'] ?? 2 ) ) ),
				'view_minutes'     => min( 120, max( 1, (int) ( $in['view_minutes'] ?? 3 ) ) ),
				'cart_minutes'     => min( 120, max( 0, (int) ( $in['cart_minutes'] ?? 5 ) ) ),
				'checkout_minutes' => min( 120, max( 0, (int) ( $in['checkout_minutes'] ?? 5 ) ) ),
				'only_linked'      => ! empty( $in['only_linked'] ) ? 'yes' : 'no',
				'swap_price'       => ! empty( $in['swap_price'] ) ? 'yes' : 'no',
				'keepalive'        => ! empty( $in['keepalive'] ) ? 'yes' : 'no',
				'admin_user'       => absint( $in['admin_user'] ?? 0 ),
				'selector'         => trim( sanitize_text_field( $in['selector'] ?? '' ) ) ?: $d['selector'],
				'resolve_ip'       => filter_var( trim( $in['resolve_ip'] ?? '' ), FILTER_VALIDATE_IP ) ? trim( $in['resolve_ip'] ) : '',
			);
			update_option( self::OPT, $s, false );
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			wp_safe_redirect( admin_url( 'admin.php?page=pclp-live-price&saved=1' ) );
			exit;
		}

		if ( 'test' === $action ) {
			$raw  = trim( (string) wp_unslash( $_POST['test_pid'] ?? '' ) );
			$pid  = ctype_digit( $raw ) ? (int) $raw : (int) url_to_postid( $raw );
			$type = $pid ? get_post_type( $pid ) : '';
			$out  = array( 'input' => $raw, 'pid' => $pid, 'type' => $type );
			if ( 'product_variation' === $type ) {
				$pid        = self::parent_id( $pid );
				$out['pid'] = $pid;
				$type       = get_post_type( $pid );
			}
			if ( 'product' !== $type ) {
				$out['error'] = ( $pid && $type ) ? 'این شناسه مربوط به محصول نیست (نوع: ' . ( $type ?: 'ناشناخته' ) . ').' : 'محصولی با این شناسه یا لینک پیدا نشد.';
			} else {
				self::$linked = array();
				$out['linked'] = self::is_linked( $pid );
				$out['keys']   = self::pf_meta_summary( $pid );
				$out['r']      = self::refresh_product( $pid, 'test', 60 );
			}
			set_transient( 'pclp_test_result_' . get_current_user_id(), $out, 300 );
			wp_safe_redirect( admin_url( 'admin.php?page=pclp-live-price&tested=1' ) );
			exit;
		}

		if ( 'clearlog' === $action ) {
			delete_option( self::LOG_OPT );
			wp_safe_redirect( admin_url( 'admin.php?page=pclp-live-price' ) );
			exit;
		}
	}

	private static function status_label( $st ) {
		$map = array(
			'changed' => '<b style="color:#1e8449">قیمت/موجودی تغییر کرد</b>',
			'same'    => 'بررسی شد، بدون تغییر',
			'error'   => '<b style="color:#b32d2e">خطا</b>',
			'busy'    => 'در حال انجام توسط درخواست دیگر',
			'waited'  => 'منتظر درخواست دیگر ماند',
		);
		return isset( $map[ $st ] ) ? $map[ $st ] : esc_html( $st );
	}

	private static function fmt_state( $st ) {
		if ( ! is_array( $st ) ) {
			return '-';
		}
		$price = '' !== $st['price'] && is_numeric( $st['price'] ) ? number_format_i18n( (float) $st['price'] ) : ( '' === $st['price'] ? '—' : esc_html( $st['price'] ) );
		$stock = 'instock' === $st['stock'] ? 'موجود' : ( 'outofstock' === $st['stock'] ? 'ناموجود' : esc_html( $st['stock'] ) );
		return $price . ' <small>(' . $stock . ')</small>';
	}

	public static function render_page() {
		$s      = self::settings();
		$ctxmap = array( 'view' => 'بازدید صفحه', 'cart' => 'افزودن به سبد', 'checkout' => 'ثبت سفارش', 'test' => 'آزمایش دستی' );
		$admins = get_users( array( 'role__in' => array( 'administrator', 'shop_manager' ), 'fields' => array( 'ID', 'display_name', 'user_login' ) ) );
		?>
		<div class="wrap" dir="rtl">
			<h1>قیمت لحظه‌ای (همراه کشف قیمت)</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>تنظیمات ذخیره شد و کش WP Rocket پاک شد.</p></div>
			<?php endif; ?>

			<?php
			$test = isset( $_GET['tested'] ) ? get_transient( 'pclp_test_result_' . get_current_user_id() ) : false;
			if ( isset( $_GET['tested'] ) && ! $test ) :
				?>
				<div class="notice notice-error"><p>نتیجه‌ی آزمایش پیدا نشد. احتمالاً آزمایش بیشتر از حد مجاز هاست طول کشیده و نیمه‌کاره ماند. دوباره امتحان کنید.</p></div>
				<?php
			elseif ( $test && ! empty( $test['error'] ) ) :
				?>
				<div class="notice notice-error"><p><b>آزمایش انجام نشد:</b> <?php echo esc_html( $test['error'] ); ?> (ورودی: <?php echo esc_html( $test['input'] ); ?>)</p></div>
				<?php
			elseif ( $test && ! empty( $test['r'] ) ) :
				$r = $test['r'];
				?>
				<div class="notice notice-info" style="padding:12px">
					<p><b>نتیجه‌ی آزمایش محصول #<?php echo (int) $test['pid']; ?> (<?php echo esc_html( get_the_title( $test['pid'] ) ); ?>):</b> <?php echo self::status_label( $r['status'] ); // phpcs:ignore ?></p>
					<p>وصل به کشف قیمت تشخیص داده شد؟ <?php echo ! empty( $test['linked'] ) ? '<b style="color:#1e8449">بله</b>' : '<b style="color:#b32d2e">خیر — به همین دلیل بازدید مشتری‌ها برای این محصول کاری انجام نمی‌دهد</b>'; ?></p>
					<p style="font-size:11px" dir="ltr"><?php echo esc_html( $test['keys'] ? implode( ' , ', $test['keys'] ) : 'no wp_pf_ meta' ); ?></p>
					<?php if ( isset( $r['old'] ) ) : ?>
						<p>قبل: <?php echo self::fmt_state( $r['old'] ); // phpcs:ignore ?> &nbsp; ← &nbsp; بعد: <?php echo self::fmt_state( $r['new'] ); // phpcs:ignore ?> &nbsp; (<?php echo esc_html( $r['time'] ); ?> ثانیه)</p>
					<?php endif; ?>
					<ul style="list-style:disc;margin-right:20px">
						<?php foreach ( $r['steps'] as $st ) : ?>
							<li><?php echo $st['ok'] ? '✔' : '✘'; ?> <?php echo esc_html( $st['what'] . ': ' . $st['note'] ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( 'same' === $r['status'] ) : ?>
						<p>اگر مطمئن هستید قیمت تأمین‌کننده عوض شده ولی اینجا «بدون تغییر» آمده، «روش به‌روزرسانی» را روی «هر دو» بگذارید و دوباره آزمایش کنید.</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'pclp_admin' ); ?>
				<input type="hidden" name="pclp_action" value="save">
				<table class="form-table" role="presentation">
					<tr><th>فعال باشد</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( 'yes', $s['enabled'] ); ?>> بله</label></td></tr>
					<tr><th>روش به‌روزرسانی</th><td>
						<select name="method">
							<option value="admin" <?php selected( 'admin', $s['method'] ); ?>>باز کردن صفحه‌ی ویرایش محصول در پیشخوان (پیشنهادی)</option>
							<option value="front" <?php selected( 'front', $s['method'] ); ?>>باز کردن صفحه‌ی محصول در سایت با حساب مدیر</option>
							<option value="both" <?php selected( 'both', $s['method'] ); ?>>هر دو</option>
						</select>
						<p class="description">همان کاری که خودتان با رفرش کردن انجام می‌دهید. اگر با روش اول قیمت عوض نشد، «هر دو» را امتحان کنید.</p>
					</td></tr>
					<tr><th>تعداد رفرش در هر بار</th><td><input type="number" min="1" max="3" name="repeat" value="<?php echo (int) $s['repeat']; ?>" class="small-text"> بار</td></tr>
					<tr><th>بازدید صفحه‌ی محصول</th><td>اگر آخرین به‌روزرسانی این محصول بیشتر از <input type="number" min="1" max="120" name="view_minutes" value="<?php echo (int) $s['view_minutes']; ?>" class="small-text"> دقیقه پیش بوده، دوباره به‌روز شود.
						<p class="description">عدد خیلی کم (مثلاً ۱) یعنی درخواست‌های بیشتر به سایت تأمین‌کننده و خطر مسدود شدن آی‌پی. ۲ تا ۵ دقیقه مناسب است.</p></td></tr>
					<tr><th>افزودن به سبد خرید</th><td>اگر آخرین به‌روزرسانی بیشتر از <input type="number" min="0" max="120" name="cart_minutes" value="<?php echo (int) $s['cart_minutes']; ?>" class="small-text"> دقیقه پیش بوده، قبل از افزودن دوباره بررسی شود. (۰ = خاموش)</td></tr>
					<tr><th>ثبت سفارش</th><td>اگر آخرین به‌روزرسانی بیشتر از <input type="number" min="0" max="120" name="checkout_minutes" value="<?php echo (int) $s['checkout_minutes']; ?>" class="small-text"> دقیقه پیش بوده، قبل از ثبت سفارش دوباره بررسی شود. (۰ = خاموش)
						<p class="description">اگر قیمت در این لحظه عوض شده باشد، سفارش ثبت نمی‌شود و به مشتری پیام داده می‌شود که قیمت جدید را ببیند و دوباره ثبت کند.</p></td></tr>
					<tr><th>فقط محصولات دارای لینک</th><td><label><input type="checkbox" name="only_linked" value="1" <?php checked( 'yes', $s['only_linked'] ); ?>> فقط محصولاتی که در کشف قیمت لینک تأمین‌کننده دارند (پیشنهادی)</label></td></tr>
					<tr><th>جایگزینی قیمت روی صفحه</th><td><label><input type="checkbox" name="swap_price" value="1" <?php checked( 'yes', $s['swap_price'] ); ?>> قیمت جدید بدون رفرش صفحه جلوی چشم مشتری جایگزین شود</label></td></tr>
					<tr><th>ادامه‌ی بررسی</th><td><label><input type="checkbox" name="keepalive" value="1" <?php checked( 'yes', $s['keepalive'] ); ?>> اگر مشتری صفحه را باز نگه داشت، هر چند دقیقه دوباره بررسی شود (حداکثر ۵ بار)</label></td></tr>
					<tr><th>حساب مدیر برای رفرش</th><td>
						<select name="admin_user">
							<option value="0">اولین مدیر سایت (خودکار)</option>
							<?php foreach ( $admins as $u ) : ?>
								<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $s['admin_user'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">رمز عبور لازم نیست و هیچ اطلاعاتی از سرور شما خارج نمی‌شود.</p></td></tr>
					<tr><th>انتخابگر قیمت در قالب</th><td><input type="text" name="selector" value="<?php echo esc_attr( $s['selector'] ); ?>" class="large-text" dir="ltr">
						<p class="description">معمولاً نیاز به تغییر ندارد. اگر قیمت روی صفحه عوض نمی‌شد، این را به من بدهید تا برای قالب شما تنظیمش کنم.</p></td></tr>
					<tr><th>آی‌پی سرور (اختیاری)</th><td><input type="text" name="resolve_ip" value="<?php echo esc_attr( $s['resolve_ip'] ); ?>" class="regular-text" dir="ltr" placeholder="مثلاً 185.12.34.56">
						<p class="description">فقط اگر سایت پشت ابر آروان یا کلادفلر است و آزمایش پایین خطای اتصال داد، آی‌پی اصلی هاست را اینجا بنویسید.</p></td></tr>
				</table>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<hr>
			<h2>آزمایش روی یک محصول</h2>
			<form method="post">
				<?php wp_nonce_field( 'pclp_admin' ); ?>
				<input type="hidden" name="pclp_action" value="test">
				<p>شناسه‌ی (ID) یا لینک صفحه‌ی محصول را وارد کنید:
					<input type="text" name="test_pid" class="regular-text" dir="ltr" required>
					<?php submit_button( 'آزمایش کن', 'secondary', 'submit', false ); ?>
				</p>
				<p class="description">این آزمایش ممکن است تا یک دقیقه طول بکشد.</p>
			</form>

			<hr>
			<h2>گزارش آخرین به‌روزرسانی‌ها</h2>
			<?php $log = get_option( self::LOG_OPT, array() ); ?>
			<?php if ( empty( $log ) ) : ?>
				<p>هنوز چیزی ثبت نشده است.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>زمان</th><th>محصول</th><th>علت</th><th>نتیجه</th><th>قبل</th><th>بعد</th><th>مدت</th><th>جزئیات</th></tr></thead>
					<tbody>
					<?php foreach ( $log as $e ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y/m/d H:i:s', $e['t'] ) ); ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $e['pid'] ) ); ?>"><?php echo esc_html( get_the_title( $e['pid'] ) ?: '#' . $e['pid'] ); ?></a></td>
							<td><?php echo esc_html( $ctxmap[ $e['ctx'] ] ?? $e['ctx'] ); ?></td>
							<td><?php echo self::status_label( $e['status'] ); // phpcs:ignore ?></td>
							<td><?php echo self::fmt_state( $e['old'] ); // phpcs:ignore ?></td>
							<td><?php echo self::fmt_state( $e['new'] ); // phpcs:ignore ?></td>
							<td><?php echo esc_html( $e['time'] ); ?>s</td>
							<td style="font-size:11px"><?php echo esc_html( $e['notes'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:10px">
					<?php wp_nonce_field( 'pclp_admin' ); ?>
					<input type="hidden" name="pclp_action" value="clearlog">
					<?php submit_button( 'پاک کردن گزارش', 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

PCLP_Live_Price::init();

register_uninstall_hook( __FILE__, 'pclp_uninstall' );
function pclp_uninstall() {
	delete_option( 'pclp_settings' );
	delete_option( 'pclp_log' );
}
