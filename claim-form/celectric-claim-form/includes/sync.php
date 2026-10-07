<?php
/**
 * Sending each claim's totals to Excel (OneDrive via Microsoft Graph),
 * Google Sheets (Apps Script) and/or webhooks (Make, Zapier…), plus the
 * notification email. Runs in the background so staff don't wait.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'cel_claim_sync_event', 'cel_claim_sync' );

/**
 * Queues a claim for sending without making the staff member wait:
 *  1. with PHP-FPM, the work runs right after the page has been sent;
 *  2. otherwise a non-blocking background request to this site runs it
 *     (the same technique WordPress uses to start WP-Cron);
 *  3. WP-Cron is scheduled as a safety net in case both are blocked.
 * cel_claim_sync() makes sure a claim is only sent once.
 */
function cel_claim_queue_sync( $post_id ) {
	$post_id = (int) $post_id;
	wp_schedule_single_event( time() + 60, 'cel_claim_sync_event', array( $post_id ) );
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		add_action(
			'shutdown',
			function () use ( $post_id ) {
				fastcgi_finish_request();
				cel_claim_sync( $post_id );
			},
			1
		);
		return;
	}
	wp_remote_post(
		admin_url( 'admin-post.php' ),
		array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => array(
				'action' => 'cel_claim_bg_sync',
				'claim'  => $post_id,
				'key'    => cel_claim_bg_key( $post_id ),
			),
		)
	);
}

function cel_claim_bg_key( $post_id ) {
	return hash_hmac( 'sha256', 'cel_claim_bg_sync|' . (int) $post_id, wp_salt( 'nonce' ) );
}

add_action( 'admin_post_nopriv_cel_claim_bg_sync', 'cel_claim_bg_sync' );
add_action( 'admin_post_cel_claim_bg_sync', 'cel_claim_bg_sync' );
function cel_claim_bg_sync() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- authenticated by the HMAC key.
	$id  = isset( $_POST['claim'] ) ? absint( $_POST['claim'] ) : 0;
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	// phpcs:enable
	if ( $id && hash_equals( cel_claim_bg_key( $id ), $key ) ) {
		ignore_user_abort( true );
		cel_claim_sync( $id );
	}
	exit;
}

/** Sends one claim everywhere it should go. Safe to call twice. */
function cel_claim_sync( $post_id, $force = false ) {
	$post_id = (int) $post_id;
	if ( ! $force && get_post_meta( $post_id, '_cel_sync_done', true ) ) {
		return;
	}
	// Simple lock so the shutdown hook and WP-Cron don't both send.
	if ( ! $force && ! add_post_meta( $post_id, '_cel_sync_lock', time(), true ) ) {
		return;
	}
	$payload = cel_claim_payload( $post_id );
	if ( ! $payload ) {
		delete_post_meta( $post_id, '_cel_sync_lock' );
		return;
	}
	wp_clear_scheduled_hook( 'cel_claim_sync_event', array( $post_id ) );

	$results = cel_claim_send_everywhere( $payload );
	update_post_meta( $post_id, '_cel_sync', $results );
	update_post_meta( $post_id, '_cel_sync_at', current_time( 'mysql' ) );
	update_post_meta( $post_id, '_cel_sync_done', 1 );
	delete_post_meta( $post_id, '_cel_sync_lock' );

	if ( ! $force ) {
		cel_claim_send_email( $payload );
	}
}

/**
 * @return array<string,string> destination => "OK" or error text.
 */
function cel_claim_send_everywhere( $payload ) {
	$s       = cel_claim_settings();
	$results = array();

	if ( ! empty( $s['ms_enabled'] ) ) {
		$results['OneDrive Excel'] = cel_claim_send_graph( $payload );
	}
	if ( ! empty( $s['google_url'] ) ) {
		$results['Google Sheets'] = cel_claim_send_google( $payload );
	}
	foreach ( cel_claim_lines( $s['webhook_urls'] ) as $i => $url ) {
		$host                                   = wp_parse_url( $url, PHP_URL_HOST );
		$results[ 'Webhook ' . ( $i + 1 ) . ' (' . $host . ')' ] = cel_claim_send_webhook( $url, $payload );
	}
	if ( ! $results ) {
		$results['—'] = 'No destination set (Claims → Settings → Excel / Google Sheets)';
	}
	return $results;
}

function cel_claim_send_webhook( $url, $payload ) {
	$res = wp_remote_post(
		$url,
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		)
	);
	return cel_claim_http_result( $res );
}

function cel_claim_http_result( $res, $ok_codes = array() ) {
	if ( is_wp_error( $res ) ) {
		return 'Failed: ' . $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( ( $code >= 200 && $code < 300 ) || in_array( $code, $ok_codes, true ) ) {
		return 'OK';
	}
	$body = wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) );
	return 'Failed: HTTP ' . $code . ( $body ? ' – ' . mb_substr( $body, 0, 200 ) : '' );
}

/* ---------- Google Sheets via Apps Script web app ---------- */

function cel_claim_send_google( $payload ) {
	$s    = cel_claim_settings();
	$body = $payload + array(
		'secret'  => $s['google_secret'],
		'columns' => array_values( cel_claim_columns_for( $payload['form'] ) ),
		'values'  => cel_claim_row_values( $payload ),
	);
	// Apps Script answers a POST with a 302 to the result page; the row is
	// already written by then, so the redirect is not followed.
	$res = wp_remote_post(
		$s['google_url'],
		array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $body ),
		)
	);
	return cel_claim_http_result( $res, array( 302 ) );
}

/* ---------- Microsoft 365: OneDrive Excel via Microsoft Graph ---------- */

function cel_claim_graph_urls() {
	return apply_filters(
		'cel_claim_graph_urls',
		array(
			'login' => 'https://login.microsoftonline.com',
			'graph' => 'https://graph.microsoft.com/v1.0',
		)
	);
}

/** App-only access token (client credentials), cached until shortly before it expires. */
function cel_claim_graph_token() {
	$cached = get_transient( 'cel_claim_ms_token' );
	if ( $cached ) {
		return $cached;
	}
	$s   = cel_claim_settings();
	$res = wp_remote_post(
		cel_claim_graph_urls()['login'] . '/' . rawurlencode( $s['ms_tenant'] ) . '/oauth2/v2.0/token',
		array(
			'timeout' => 15,
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $s['ms_client_id'],
				'client_secret' => $s['ms_client_secret'],
				'scope'         => 'https://graph.microsoft.com/.default',
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( empty( $data['access_token'] ) ) {
		$msg = $data['error_description'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
		return new WP_Error( 'cel_ms_token', 'Microsoft sign-in failed: ' . strtok( (string) $msg, "\r\n" ) );
	}
	set_transient( 'cel_claim_ms_token', $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );
	return $data['access_token'];
}

function cel_claim_send_graph( $payload ) {
	$s = cel_claim_settings();
	foreach ( array( 'ms_tenant', 'ms_client_id', 'ms_client_secret', 'ms_user', 'ms_path' ) as $k ) {
		if ( '' === trim( (string) $s[ $k ] ) ) {
			return 'Failed: Microsoft 365 settings are incomplete';
		}
	}
	$token = cel_claim_graph_token();
	if ( is_wp_error( $token ) ) {
		return 'Failed: ' . $token->get_error_message();
	}
	$table = 'mileage' === $payload['form'] ? $s['ms_table_mileage'] : $s['ms_table_travel'];
	$path  = implode( '/', array_map( 'rawurlencode', array_filter( explode( '/', trim( $s['ms_path'], '/' ) ), 'strlen' ) ) );
	$url   = cel_claim_graph_urls()['graph'] . '/users/' . rawurlencode( $s['ms_user'] ) . '/drive/root:/' . $path . ':/workbook/tables/' . rawurlencode( $table ) . '/rows';
	$res   = wp_remote_post(
		$url,
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'values' => array( cel_claim_row_values( $payload ) ) ) ),
		)
	);
	if ( 401 === (int) wp_remote_retrieve_response_code( $res ) ) {
		delete_transient( 'cel_claim_ms_token' );
	}
	return cel_claim_http_result( $res );
}

/* ---------- Notification email ---------- */

function cel_claim_send_email( $p ) {
	$to = trim( (string) cel_claim_setting( 'notify_email' ) );
	if ( '' === $to ) {
		return;
	}
	$lines = array( 'A new ' . cel_claim_forms()[ $p['form'] ]['label'] . ' claim has been submitted.', '' );
	foreach ( cel_claim_columns_for( $p['form'] ) as $k => $label ) {
		$v       = $p[ $k ];
		$lines[] = $label . ': ' . ( is_float( $v ) && 'total_km' !== $k && 'rate_per_km' !== $k ? 'RM ' . cel_claim_money( $v ) : $v );
	}
	wp_mail( $to, 'Claim ' . $p['claim_no'] . ' – ' . $p['claimant_name'], implode( "\n", $lines ) );
}

/* ---------- Admin: test & resend ---------- */

add_action( 'admin_post_cel_claim_test_sync', 'cel_claim_test_sync' );
function cel_claim_test_sync() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'cel_claim_test_sync' );
	$common  = array(
		'submission_date' => current_time( 'j/n/Y' ),
		'submitted_at'    => current_time( 'mysql' ),
		'claimant_name'   => 'Test Staff',
		'staff_email'     => 'test@example.com',
		'print_url'       => home_url( '/' ),
	);
	$travel  = array( 'form' => 'travel', 'claim_no' => 'TEST-0000' ) + $common + array(
		'department'    => 'Sales Department',
		'purpose'       => 'Connection test',
		'destination'   => 'Kuala Lumpur',
		'travel_period' => '1 Day',
		'subtotal_a'    => 100.0,
		'subtotal_b'    => 23.3,
		'grand_total'   => 123.3,
	);
	$mileage = array( 'form' => 'mileage', 'claim_no' => 'TEST-0001' ) + $common + array(
		'claim_period' => current_time( 'F Y' ),
		'vehicle'      => 'Car',
		'rate_per_km'  => 0.7,
		'total_km'     => 120.5,
		'net_payable'  => 84.35,
	);
	$msg = array();
	foreach ( array( 'Travel' => $travel, 'Mileage' => $mileage ) as $label => $p ) {
		foreach ( cel_claim_send_everywhere( $p ) as $dest => $result ) {
			$msg[] = $label . ' → ' . $dest . ': ' . $result;
		}
	}
	set_transient( 'cel_claim_notice_' . get_current_user_id(), implode( "\n", $msg ), 120 );
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . CEL_CLAIM_CPT . '&page=cel-claim-settings&tab=sync' ) );
	exit;
}
