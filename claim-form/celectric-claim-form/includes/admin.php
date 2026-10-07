<?php
/**
 * wp-admin: claims list (filter by form, totals, sync status, PDF), CSV export,
 * resend, settings page. Claims can be trashed / deleted permanently here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'cel_claim_admin_menu' );
function cel_claim_admin_menu() {
	add_submenu_page( 'edit.php?post_type=' . CEL_CLAIM_CPT, 'Claim Form Settings', 'Settings', 'manage_options', 'cel-claim-settings', 'cel_claim_settings_page' );
}

add_action( 'admin_notices', 'cel_claim_admin_notices' );
function cel_claim_admin_notices() {
	$msg = get_transient( 'cel_claim_notice_' . get_current_user_id() );
	if ( $msg ) {
		delete_transient( 'cel_claim_notice_' . get_current_user_id() );
		echo '<div class="notice notice-info is-dismissible"><p>' . nl2br( esc_html( $msg ) ) . '</p></div>';
	}
}

/* ---------- list table ---------- */

add_filter( 'manage_' . CEL_CLAIM_CPT . '_posts_columns', 'cel_claim_columns' );
function cel_claim_columns( $cols ) {
	return array(
		'cb'        => $cols['cb'],
		'title'     => 'Claim',
		'cel_form'  => 'Form',
		'cel_total' => 'Total (RM)',
		'cel_sync'  => 'Excel / Sheets',
		'cel_print' => 'PDF',
		'author'    => 'Submitted by',
		'date'      => 'Submitted',
	);
}

add_action( 'manage_' . CEL_CLAIM_CPT . '_posts_custom_column', 'cel_claim_column_value', 10, 2 );
function cel_claim_column_value( $col, $post_id ) {
	$post = get_post( $post_id );
	$data = get_post_meta( $post_id, '_cel_claim', true );
	if ( ! is_array( $data ) ) {
		return;
	}
	$type = $data['type'] ?? 'travel';
	switch ( $col ) {
		case 'cel_form':
			echo esc_html( cel_claim_forms()[ $type ]['label'] ?? $type );
			break;
		case 'cel_total':
			$total = 'mileage' === $type ? ( $data['net_payable'] ?? 0 ) : ( $data['grand_total'] ?? 0 );
			echo '<strong>' . esc_html( cel_claim_money( $total ) ) . '</strong>';
			if ( 'travel' === $type ) {
				echo '<br><small>A ' . esc_html( cel_claim_money( $data['subtotal_a'] ?? 0 ) ) . ' + B ' . esc_html( cel_claim_money( $data['subtotal_b'] ?? 0 ) ) . '</small>';
			} else {
				$parts = array();
				foreach ( cel_claim_vehicle_breakdown( $data ) as $vname => $v ) {
					$parts[] = $vname . ' ' . number_format( (float) $v['km'], 1 ) . ' km × RM ' . number_format( (float) $v['rate'], 2 );
				}
				echo '<br><small>' . esc_html( implode( ' + ', $parts ) ) . '</small>';
			}
			break;
		case 'cel_sync':
			$res = get_post_meta( $post_id, '_cel_sync', true );
			if ( is_array( $res ) ) {
				foreach ( $res as $dest => $r ) {
					$ok = 'OK' === $r;
					echo '<div style="color:' . ( $ok ? '#1e7b34' : '#b32d2e' ) . '">' . esc_html( $dest . ': ' . ( $ok ? 'Sent' : $r ) ) . '</div>';
				}
			} elseif ( $res ) {
				echo esc_html( (string) $res ); // v1 status text.
			} else {
				echo '<em>Queued…</em>';
			}
			if ( 'publish' === $post->post_status ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=cel_claim_resend&claim=' . $post_id ), 'cel_claim_resend_' . $post_id );
				echo '<a href="' . esc_url( $url ) . '">Send again</a>';
			}
			break;
		case 'cel_print':
			if ( 'publish' === $post->post_status ) {
				echo '<a target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $post_id ) ) . '">Print / PDF</a>';
			}
			break;
	}
}

/* Filter the list by form. */
add_action( 'restrict_manage_posts', 'cel_claim_list_filters' );
function cel_claim_list_filters( $post_type ) {
	if ( CEL_CLAIM_CPT !== $post_type ) {
		return;
	}
	$cur = isset( $_GET['cel_form'] ) ? sanitize_key( $_GET['cel_form'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo '<select name="cel_form"><option value="">All forms</option>';
	foreach ( cel_claim_forms() as $k => $f ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $f['label'] ) . '</option>';
	}
	echo '</select>';
	foreach ( cel_claim_forms() as $k => $f ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=cel_claim_export&form=' . $k ), 'cel_claim_export' );
		echo '<a class="button" style="margin:0 0 0 6px" href="' . esc_url( $url ) . '">Export ' . esc_html( $f['label'] ) . ' (CSV)</a>';
	}
}

add_action( 'pre_get_posts', 'cel_claim_filter_query' );
function cel_claim_filter_query( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || CEL_CLAIM_CPT !== $q->get( 'post_type' ) ) {
		return;
	}
	$form = isset( $_GET['cel_form'] ) ? sanitize_key( $_GET['cel_form'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'mileage' === $form ) {
		$q->set( 'meta_query', array( array( 'key' => '_cel_type', 'value' => 'mileage' ) ) );
	} elseif ( 'travel' === $form ) {
		$q->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array( 'key' => '_cel_type', 'value' => 'travel' ),
				array( 'key' => '_cel_type', 'compare' => 'NOT EXISTS' ),
			)
		);
	}
}

/* Claims are read-only records: "edit" opens the print view instead. */
add_action( 'load-post.php', 'cel_claim_redirect_edit_screen' );
function cel_claim_redirect_edit_screen() {
	$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $id && CEL_CLAIM_CPT === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
		wp_safe_redirect( cel_claim_print_url( $id ) . '&noprint=1' );
		exit;
	}
}

add_filter( 'post_row_actions', 'cel_claim_row_actions', 10, 2 );
function cel_claim_row_actions( $actions, $post ) {
	if ( CEL_CLAIM_CPT === $post->post_type ) {
		unset( $actions['inline hide-if-no-js'], $actions['edit'] );
		if ( 'publish' === $post->post_status ) {
			$actions = array( 'view' => '<a target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $post->ID ) . '&noprint=1' ) . '">View</a>' ) + $actions;
		}
	}
	return $actions;
}

/* Remove queued background work when a claim is deleted. */
add_action( 'before_delete_post', 'cel_claim_on_delete' );
function cel_claim_on_delete( $post_id ) {
	if ( CEL_CLAIM_CPT === get_post_type( $post_id ) ) {
		wp_clear_scheduled_hook( 'cel_claim_sync_event', array( (int) $post_id ) );
	}
}

add_action( 'admin_post_cel_claim_resend', 'cel_claim_resend' );
function cel_claim_resend() {
	$id = isset( $_GET['claim'] ) ? absint( $_GET['claim'] ) : 0;
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'cel_claim_resend_' . $id );
	if ( cel_claim_get( $id ) ) {
		cel_claim_sync( $id, true );
		$res = get_post_meta( $id, '_cel_sync', true );
		$msg = array();
		foreach ( (array) $res as $dest => $r ) {
			$msg[] = $dest . ': ' . $r;
		}
		set_transient( 'cel_claim_notice_' . get_current_user_id(), 'Sent ' . cel_claim_get( $id )['claim_no'] . " again\n" . implode( "\n", $msg ), 120 );
	}
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . CEL_CLAIM_CPT ) );
	exit;
}

/* CSV export of the totals – one file per form, same columns as the Excel register. */
add_action( 'admin_post_cel_claim_export', 'cel_claim_export_csv' );
function cel_claim_export_csv() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'cel_claim_export' );
	$type = isset( $_GET['form'] ) && 'mileage' === $_GET['form'] ? 'mileage' : 'travel';
	$ids  = get_posts(
		array(
			'post_type'      => CEL_CLAIM_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	);
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $type . '-claims-' . current_time( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads it correctly.
	fputcsv( $out, array_values( cel_claim_columns_for( $type ) ) );
	foreach ( $ids as $id ) {
		$p = cel_claim_payload( $id );
		if ( ! $p || $p['form'] !== $type ) {
			continue;
		}
		// Stop Excel treating text that starts with = + - @ as a formula.
		$row = array_map(
			function ( $v ) {
				return is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
			},
			cel_claim_row_values( $p )
		);
		fputcsv( $out, $row );
	}
	fclose( $out );
	exit;
}
