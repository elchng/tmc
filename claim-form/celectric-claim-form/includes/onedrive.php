<?php
/**
 * Microsoft 365 direct: writes each claim as a row in an Excel table in
 * OneDrive through Microsoft Graph (app-only / client-credentials sign-in).
 *
 * The settings page has a OneDrive browser: the admin opens folders, picks
 * the Excel file (or creates Claims_Register.xlsx in the chosen folder) and
 * picks the tables. The file is remembered by its OneDrive ID, so it keeps
 * working if it is renamed or moved later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cel_claim_graph_urls() {
	return apply_filters(
		'cel_claim_graph_urls',
		array(
			'login' => 'https://login.microsoftonline.com',
			'graph' => 'https://graph.microsoft.com/v1.0',
		)
	);
}

/** True when the sign-in details have been filled in. */
function cel_claim_ms_ready() {
	$s = cel_claim_settings();
	foreach ( array( 'ms_tenant', 'ms_client_id', 'ms_client_secret', 'ms_user' ) as $k ) {
		if ( '' === trim( (string) $s[ $k ] ) ) {
			return false;
		}
	}
	return true;
}

/** App-only access token, cached until shortly before it expires. */
function cel_claim_graph_token() {
	$cached = get_transient( 'cel_claim_ms_token' );
	if ( $cached ) {
		return $cached;
	}
	$s   = cel_claim_settings();
	$res = wp_remote_post(
		cel_claim_graph_urls()['login'] . '/' . rawurlencode( trim( $s['ms_tenant'] ) ) . '/oauth2/v2.0/token',
		array(
			'timeout' => 15,
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => trim( $s['ms_client_id'] ),
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

/**
 * Calls Microsoft Graph. $path is relative to /v1.0 (e.g. "/users/x/drive/root").
 *
 * @return array|WP_Error Decoded JSON response.
 */
function cel_claim_graph( $method, $path, $body = null, $content_type = 'application/json' ) {
	$token = cel_claim_graph_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array(
		'method'  => $method,
		'timeout' => 25,
		'headers' => array( 'Authorization' => 'Bearer ' . $token ),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = $content_type;
		$args['body']                    = 'application/json' === $content_type ? wp_json_encode( $body ) : $body;
	}
	$res = wp_remote_request( cel_claim_graph_urls()['graph'] . $path, $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 ) {
		if ( 401 === $code ) {
			delete_transient( 'cel_claim_ms_token' );
		}
		$msg = $data['error']['message'] ?? wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) );
		$hint = '';
		if ( 403 === $code || 401 === $code ) {
			$hint = ' (check the app has the Files.ReadWrite.All application permission with admin consent)';
		} elseif ( 404 === $code ) {
			$hint = ' (not found – check the OneDrive owner email and that the file still exists)';
		}
		return new WP_Error( 'cel_graph', 'HTTP ' . $code . ': ' . mb_substr( (string) $msg, 0, 200 ) . $hint );
	}
	return is_array( $data ) ? $data : array();
}

/** Graph path of the owner's OneDrive. */
function cel_claim_ms_drive() {
	return '/users/' . rawurlencode( trim( (string) cel_claim_setting( 'ms_user' ) ) ) . '/drive';
}

/** Graph path of the chosen workbook (by ID, or by path for older settings). */
function cel_claim_ms_workbook() {
	$s = cel_claim_settings();
	if ( ! empty( $s['ms_item_id'] ) ) {
		return cel_claim_ms_drive() . '/items/' . rawurlencode( $s['ms_item_id'] );
	}
	$path = implode( '/', array_map( 'rawurlencode', array_filter( explode( '/', trim( (string) $s['ms_path'], '/' ) ), 'strlen' ) ) );
	return $path ? cel_claim_ms_drive() . '/root:/' . $path . ':' : '';
}

/** Readable folder path from a driveItem's parentReference ("/drive/root:/Finance" → "Finance"). */
function cel_claim_ms_folder_path( $item ) {
	$p = (string) ( $item['parentReference']['path'] ?? '' );
	$p = preg_replace( '#^/drive(s/[^/]+)?/root:?#', '', $p );
	return trim( rawurldecode( $p ), '/' );
}

/* ---------- writing a claim row ---------- */

function cel_claim_send_graph( $payload ) {
	if ( ! cel_claim_ms_ready() ) {
		return 'Failed: Microsoft 365 sign-in details are incomplete';
	}
	$book = cel_claim_ms_workbook();
	if ( '' === $book ) {
		return 'Failed: no Excel file chosen (Claims → Settings → Excel / Google Sheets → Browse OneDrive)';
	}
	$table = 'mileage' === $payload['form'] ? cel_claim_setting( 'ms_table_mileage' ) : cel_claim_setting( 'ms_table_travel' );
	$res   = cel_claim_graph( 'POST', $book . '/workbook/tables/' . rawurlencode( $table ) . '/rows', array( 'values' => array( cel_claim_row_values( $payload ) ) ) );
	return is_wp_error( $res ) ? 'Failed: ' . $res->get_error_message() : 'OK';
}

/* ---------- settings page: OneDrive browser (AJAX) ---------- */

add_action( 'admin_enqueue_scripts', 'cel_claim_onedrive_assets' );
function cel_claim_onedrive_assets( $hook ) {
	if ( CEL_CLAIM_CPT . '_page_cel-claim-settings' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'cel-claim-admin', CEL_CLAIM_URL . 'assets/admin.js', array(), CEL_CLAIM_VERSION, true );
	wp_localize_script(
		'cel-claim-admin',
		'CEL_OD',
		array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cel_claim_onedrive' ),
		)
	);
}

function cel_claim_od_guard() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cel_claim_onedrive', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Not allowed – reload the page and try again.' ), 403 );
	}
	if ( ! cel_claim_ms_ready() ) {
		wp_send_json_error( array( 'message' => 'Fill in the tenant ID, client ID, client secret and OneDrive owner above, click Save Changes, then browse again.' ) );
	}
}

/** Lists the folders and Excel files in one OneDrive folder. */
add_action( 'wp_ajax_cel_claim_od_list', 'cel_claim_od_list' );
function cel_claim_od_list() {
	cel_claim_od_guard();
	$id     = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard.
	$drive  = cel_claim_ms_drive();
	$base   = '' === $id ? $drive . '/root' : $drive . '/items/' . rawurlencode( $id );
	$folder = cel_claim_graph( 'GET', $base . '?$select=id,name,root,parentReference' );
	if ( is_wp_error( $folder ) ) {
		wp_send_json_error( array( 'message' => $folder->get_error_message() ) );
	}
	$items = array();
	$next  = $base . '/children?$select=id,name,folder,file,parentReference&$top=200';
	for ( $page = 0; $next && $page < 10; $page++ ) {
		$res = cel_claim_graph( 'GET', $next );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		foreach ( (array) ( $res['value'] ?? array() ) as $it ) {
			if ( isset( $it['folder'] ) ) {
				$items[] = array( 'id' => $it['id'], 'name' => $it['name'], 'type' => 'folder' );
			} elseif ( preg_match( '/\.xlsx$/i', (string) $it['name'] ) ) {
				$items[] = array( 'id' => $it['id'], 'name' => $it['name'], 'type' => 'xlsx' );
			}
		}
		$next = isset( $res['@odata.nextLink'] ) ? str_replace( cel_claim_graph_urls()['graph'], '', $res['@odata.nextLink'] ) : '';
	}
	usort(
		$items,
		function ( $a, $b ) {
			return strcmp( $a['type'], $b['type'] ) ?: strnatcasecmp( $a['name'], $b['name'] ); // folders first.
		}
	);
	$is_root = isset( $folder['root'] );
	$path    = $is_root ? '' : trim( cel_claim_ms_folder_path( $folder ) . '/' . $folder['name'], '/' );
	wp_send_json_success(
		array(
			'id'     => $is_root ? '' : $folder['id'],
			'path'   => $path,
			'parent' => $is_root ? null : ( '' === cel_claim_ms_folder_path( $folder ) ? '' : ( $folder['parentReference']['id'] ?? '' ) ),
			'items'  => $items,
		)
	);
}

/** Chooses an Excel file, reads its tables and saves the choice. */
add_action( 'wp_ajax_cel_claim_od_select', 'cel_claim_od_select' );
function cel_claim_od_select() {
	cel_claim_od_guard();
	$id = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard.
	cel_claim_od_choose( $id );
}

/** Creates Claims_Register.xlsx (with both tables) in a folder and chooses it. */
add_action( 'wp_ajax_cel_claim_od_create', 'cel_claim_od_create' );
function cel_claim_od_create() {
	cel_claim_od_guard();
	$folder = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard.
	$parent = '' === $folder ? cel_claim_ms_drive() . '/root' : cel_claim_ms_drive() . '/items/' . rawurlencode( $folder );
	$body   = file_get_contents( CEL_CLAIM_DIR . 'extras/Claims_Register.xlsx' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
	$item   = cel_claim_graph(
		'PUT',
		$parent . ':/Claims_Register.xlsx:/content?@microsoft.graph.conflictBehavior=rename',
		$body,
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
	);
	if ( is_wp_error( $item ) ) {
		wp_send_json_error( array( 'message' => $item->get_error_message() ) );
	}
	cel_claim_od_choose( $item['id'] );
}

function cel_claim_od_choose( $id ) {
	if ( '' === $id ) {
		wp_send_json_error( array( 'message' => 'No file chosen.' ) );
	}
	$item = cel_claim_graph( 'GET', cel_claim_ms_drive() . '/items/' . rawurlencode( $id ) . '?$select=id,name,file,parentReference' );
	if ( is_wp_error( $item ) ) {
		wp_send_json_error( array( 'message' => $item->get_error_message() ) );
	}
	if ( ! isset( $item['file'] ) || ! preg_match( '/\.xlsx$/i', (string) $item['name'] ) ) {
		wp_send_json_error( array( 'message' => 'Please choose an Excel (.xlsx) file.' ) );
	}
	$tables = cel_claim_graph( 'GET', cel_claim_ms_drive() . '/items/' . rawurlencode( $id ) . '/workbook/tables?$select=name' );
	if ( is_wp_error( $tables ) ) {
		wp_send_json_error( array( 'message' => 'Could not open the workbook: ' . $tables->get_error_message() ) );
	}
	$names = array_values( array_filter( array_map( function ( $t ) {
		return (string) ( $t['name'] ?? '' );
	}, (array) ( $tables['value'] ?? array() ) ), 'strlen' ) );

	$s      = cel_claim_settings();
	$travel = cel_claim_od_guess( $names, $s['ms_table_travel'], array( 'TravelClaims', 'travel' ) );
	$mile   = cel_claim_od_guess( $names, $s['ms_table_mileage'], array( 'MileageClaims', 'mileage' ) );
	$path   = trim( cel_claim_ms_folder_path( $item ) . '/' . $item['name'], '/' );

	update_option(
		CEL_CLAIM_OPTION,
		array(
			'ms_item_id'       => $item['id'],
			'ms_path'          => $path,
			'ms_tables'        => implode( "\n", $names ),
			'ms_table_travel'  => $travel,
			'ms_table_mileage' => $mile,
		)
	);
	$warning = '';
	if ( ! $names ) {
		$warning = 'This workbook has no tables. Use “Create Claims_Register.xlsx here”, or in Excel select your heading row and press Ctrl+T to make a table.';
	} elseif ( ! in_array( $travel, $names, true ) || ! in_array( $mile, $names, true ) ) {
		$warning = 'Choose which table receives travel claims and which receives mileage claims below, then click Save Changes.';
	}
	wp_send_json_success(
		array(
			'path'    => $path,
			'tables'  => $names,
			'travel'  => $travel,
			'mileage' => $mile,
			'warning' => $warning,
		)
	);
}

/** Keeps the current table name if the workbook has it, else matches a likely name. */
function cel_claim_od_guess( $names, $current, $hints ) {
	if ( in_array( $current, $names, true ) ) {
		return $current;
	}
	foreach ( $hints as $hint ) {
		foreach ( $names as $n ) {
			if ( 0 === strcasecmp( $n, $hint ) || false !== stripos( $n, $hint ) ) {
				return $n;
			}
		}
	}
	return $current;
}
