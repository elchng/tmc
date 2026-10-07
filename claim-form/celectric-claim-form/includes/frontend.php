<?php
/**
 * Front end: the two forms, the claim history list, saving and the print view.
 * CSS/JS are only loaded on pages that contain one of the shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'cel_claim_register_assets' );
function cel_claim_register_assets() {
	if ( wp_style_is( 'cel-claim', 'registered' ) ) {
		return;
	}
	wp_register_style( 'cel-claim', CEL_CLAIM_URL . 'assets/claim.css', array(), cel_claim_asset_version( 'assets/claim.css' ) );
	wp_register_script( 'cel-claim', CEL_CLAIM_URL . 'assets/claim.js', array(), cel_claim_asset_version( 'assets/claim.js' ), true );
}

/** Version string that changes whenever the file changes, so browsers and caches never keep an old copy. */
function cel_claim_asset_version( $file ) {
	$time = @filemtime( CEL_CLAIM_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return CEL_CLAIM_VERSION . ( $time ? '.' . $time : '' );
}

/**
 * Ask speed/optimisation plugins (LiteSpeed, WP Rocket, Cloudflare Rocket Loader,
 * SiteGround, Autoptimize…) to leave the calculator script alone: combining or
 * delaying it can stop the live totals from working.
 */
add_filter( 'script_loader_tag', 'cel_claim_script_tag', 10, 2 );
function cel_claim_script_tag( $tag, $handle ) {
	if ( 'cel-claim' !== $handle || false !== strpos( $tag, 'data-no-optimize' ) ) {
		return $tag;
	}
	return str_replace( ' src=', ' data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-pagespeed-no-defer nowprocket src=', $tag );
}

/* WP Rocket: never delay or combine the calculator. */
add_filter( 'rocket_delay_js_exclusions', 'cel_claim_optimizer_exclusions' );
add_filter( 'rocket_exclude_js', 'cel_claim_optimizer_exclusions' );
add_filter( 'rocket_exclude_defer_js', 'cel_claim_optimizer_exclusions' );
/* LiteSpeed Cache. */
add_filter( 'litespeed_optm_js_defer_exc', 'cel_claim_optimizer_exclusions' );
add_filter( 'litespeed_optimize_js_excludes', 'cel_claim_optimizer_exclusions' );
/* Autoptimize. */
add_filter( 'autoptimize_filter_js_exclude', 'cel_claim_autoptimize_exclusions' );
function cel_claim_optimizer_exclusions( $list ) {
	$list   = is_array( $list ) ? $list : array();
	$list[] = 'celectric-claim-form/assets/claim.js';
	return $list;
}
function cel_claim_autoptimize_exclusions( $list ) {
	return trim( (string) $list . ', celectric-claim-form/assets/claim.js', ', ' );
}

/** Block themes and page builders can render shortcodes before wp_enqueue_scripts runs. */
function cel_claim_enqueue( $with_js = false ) {
	cel_claim_register_assets();
	wp_enqueue_style( 'cel-claim' );
	if ( $with_js ) {
		wp_enqueue_script( 'cel-claim' );
	}
}

function cel_claim_logo_url() {
	$logo = cel_claim_setting( 'logo_url' );
	return $logo ? $logo : CEL_CLAIM_URL . 'assets/logo.png';
}

add_shortcode( 'celectric_claim_form', 'cel_claim_travel_shortcode' );
function cel_claim_travel_shortcode( $atts = array() ) {
	$atts = shortcode_atts( array( 'form' => 'travel' ), $atts, 'celectric_claim_form' );
	return cel_claim_render_form( 'mileage' === $atts['form'] ? 'mileage' : 'travel' );
}

add_shortcode( 'celectric_mileage_form', 'cel_claim_mileage_shortcode' );
function cel_claim_mileage_shortcode() {
	return cel_claim_render_form( 'mileage' );
}

add_shortcode( 'celectric_my_claims', 'cel_claim_history_shortcode' );
function cel_claim_history_shortcode() {
	cel_claim_enqueue();
	if ( ! is_user_logged_in() ) {
		return cel_claim_login_box( 'view your claims' );
	}
	return '<div class="cel-claim-wrap">' . cel_claim_history( '' ) . '</div>';
}

function cel_claim_not_staff_box() {
	return '<div class="cel-claim-login"><h3>Staff only</h3><p>Claim forms are for Celectric staff. If you are staff, ask the administrator to set your account to <strong>Employee</strong>.</p></div>';
}

function cel_claim_login_box( $what ) {
	return '<div class="cel-claim-login"><h3>Staff login</h3><p>Please log in to ' . esc_html( $what ) . '.</p>'
		. wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ) ) . '</div>';
}

function cel_claim_render_form( $type ) {
	cel_claim_enqueue( true );
	if ( ! is_user_logged_in() ) {
		return cel_claim_login_box( 'submit a claim' );
	}
	if ( ! cel_claim_is_staff() ) {
		return cel_claim_not_staff_box();
	}

	$user   = wp_get_current_user();
	$notice = '';
	$draft  = null;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display flags.
	if ( isset( $_GET['cel_claim_saved'] ) ) {
		$saved = cel_claim_get( absint( $_GET['cel_claim_saved'] ) );
		if ( $saved && $saved['type'] === $type && cel_claim_user_can_view( $saved ) ) {
			$notice = '<div class="cel-notice cel-notice-ok" role="status"><strong>Claim ' . esc_html( $saved['claim_no'] ) . ' submitted.</strong> '
				. 'Total: RM ' . esc_html( cel_claim_money( cel_claim_total( $saved ) ) ) . '. '
				. '<a class="cel-btn" target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $saved['id'] ) ) . '">Print / Save as PDF</a></div>';
		}
	}
	if ( isset( $_GET['cel_claim_error'] ) ) {
		$draft = get_transient( 'cel_claim_draft_' . $type . '_' . $user->ID );
		if ( is_array( $draft ) && ! empty( $draft['errors'] ) ) {
			$notice = '<div class="cel-notice cel-notice-err" role="alert"><strong>Please fix the following and submit again:</strong><ul><li>'
				. implode( '</li><li>', array_map( 'esc_html', $draft['errors'] ) ) . '</li></ul></div>';
		}
	}
	// phpcs:enable

	$raw   = is_array( $draft ) ? $draft['raw'] : array();
	$mode  = 'edit';
	$claim = 'mileage' === $type ? cel_claim_mileage_edit_values( $raw, $user ) : cel_claim_travel_edit_values( $raw, $user );
	$label = cel_claim_forms()[ $type ]['label'];

	ob_start();
	echo '<div id="cel-claim" class="cel-claim-wrap">';
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cel-claim-form" novalidate>
		<input type="hidden" name="action" value="cel_claim_save">
		<input type="hidden" name="form" value="<?php echo esc_attr( $type ); ?>">
		<input type="hidden" name="_cel_back" value="<?php echo esc_url( get_permalink() ); ?>">
		<?php wp_nonce_field( 'cel_claim_save', '_cel_nonce' ); ?>
		<div class="cel-sheet-scroll">
			<?php include CEL_CLAIM_DIR . 'templates/' . $type . '-sheet.php'; ?>
		</div>
		<div class="cel-actions">
			<?php if ( 'mileage' === $type ) : ?>
				<button type="button" class="cel-btn cel-btn-light" data-cel-add="t">+ Add trip row</button>
			<?php else : ?>
				<button type="button" class="cel-btn cel-btn-light" data-cel-add="a">+ Add allowance row</button>
				<button type="button" class="cel-btn cel-btn-light" data-cel-add="b">+ Add expense row</button>
			<?php endif; ?>
			<span class="cel-spacer"></span>
			<button type="submit" class="cel-btn cel-btn-primary">Submit <?php echo esc_html( $label ); ?> Claim</button>
		</div>
		<p class="cel-hint">Rates are fixed by Celectric’s internal schedule. A submitted claim can’t be changed — ask HR/Admin to delete it if you need to resubmit. Your submitted claims stay listed below and can be downloaded as PDF at any time.</p>
	</form>
	<?php
	echo cel_claim_history( $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	echo '</div>';
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Claim history ("My submitted claims"): every claim the user ever
 * submitted, newest first, 15 per page, each with a PDF link.
 * ---------------------------------------------------------------------- */

function cel_claim_history( $type ) {
	$per_page = 15;
	$page     = isset( $_GET['cel_page'] ) ? max( 1, absint( $_GET['cel_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$args     = array(
		'post_type'      => CEL_CLAIM_CPT,
		'post_status'    => 'publish',
		'author'         => get_current_user_id(),
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( 'mileage' === $type ) {
		$args['meta_query'] = array( array( 'key' => '_cel_type', 'value' => 'mileage' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	} elseif ( 'travel' === $type ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array( 'key' => '_cel_type', 'value' => 'travel' ),
			array( 'key' => '_cel_type', 'compare' => 'NOT EXISTS' ),
		);
	}
	$q     = new WP_Query( $args );
	$title = $type ? 'My submitted ' . cel_claim_forms()[ $type ]['label'] . ' claims' : 'My submitted claims';

	$html = '<section class="cel-history"><h3 class="cel-my-title">' . esc_html( $title ) . '</h3>';
	if ( ! $q->have_posts() ) {
		return $html . '<p class="cel-empty">No claims yet.</p></section>';
	}
	$html .= '<table class="cel-my-claims"><thead><tr><th>Claim No.</th><th>Form</th><th>Submitted</th><th>Details</th><th class="r">Total (RM)</th><th></th></tr></thead><tbody>';
	foreach ( $q->posts as $p ) {
		$c = cel_claim_get( $p->ID );
		if ( ! $c ) {
			continue;
		}
		$html .= '<tr>'
			. '<td data-label="Claim No."><strong>' . esc_html( $c['claim_no'] ) . '</strong></td>'
			. '<td data-label="Form">' . esc_html( cel_claim_forms()[ $c['type'] ]['label'] ) . '</td>'
			. '<td data-label="Submitted">' . esc_html( cel_claim_display_date( $c['submission_date'] ) ) . '</td>'
			. '<td data-label="Details">' . esc_html( cel_claim_summary( $c ) ) . '</td>'
			. '<td data-label="Total (RM)" class="r"><strong>' . esc_html( cel_claim_money( cel_claim_total( $c ) ) ) . '</strong></td>'
			. '<td class="cel-pdf"><a class="cel-btn" target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $p->ID ) ) . '">PDF</a></td>'
			. '</tr>';
	}
	$html .= '</tbody></table>';
	if ( $q->max_num_pages > 1 ) {
		$html .= '<nav class="cel-pager">';
		if ( $page > 1 ) {
			$html .= '<a class="cel-btn cel-btn-light" href="' . esc_url( add_query_arg( 'cel_page', $page - 1 ) ) . '#cel-claim">‹ Newer</a>';
		}
		$html .= '<span>Page ' . (int) $page . ' of ' . (int) $q->max_num_pages . '</span>';
		if ( $page < $q->max_num_pages ) {
			$html .= '<a class="cel-btn cel-btn-light" href="' . esc_url( add_query_arg( 'cel_page', $page + 1 ) ) . '#cel-claim">Older ›</a>';
		}
		$html .= '</nav>';
	}
	return $html . '</section>';
}

/* -------------------------------------------------------------------------
 * Saving a submission.
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_cel_claim_save', 'cel_claim_handle_save' );
add_action( 'admin_post_nopriv_cel_claim_save', 'cel_claim_handle_save' );
function cel_claim_handle_save() {
	$back = isset( $_POST['_cel_back'] ) ? esc_url_raw( wp_unslash( $_POST['_cel_back'] ) ) : home_url( '/' );
	$back = wp_validate_redirect( $back, home_url( '/' ) );
	$back = remove_query_arg( array( 'cel_claim_saved', 'cel_claim_error', 'cel_page' ), $back );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( $back ) );
		exit;
	}
	if ( ! cel_claim_is_staff() ) {
		wp_die( 'Claim forms are for Celectric staff only.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
	}
	if ( ! isset( $_POST['_cel_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_cel_nonce'] ), 'cel_claim_save' ) ) {
		wp_die( 'Your session has expired. Please go back, refresh the page and submit again.', 'Claim not saved', array( 'back_link' => true ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification -- verified above.
	$type = isset( $_POST['form'] ) && 'mileage' === $_POST['form'] ? 'mileage' : 'travel';
	list( $claim, $errors ) = 'mileage' === $type ? cel_claim_mileage_from_request( $_POST ) : cel_claim_travel_from_request( $_POST );
	$user = wp_get_current_user();

	if ( $errors ) {
		// Keep what the staff member typed so they don't lose it.
		set_transient( 'cel_claim_draft_' . $type . '_' . $user->ID, array( 'raw' => wp_unslash( $_POST ), 'errors' => $errors ), 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'cel_claim_error', 1, $back ) . '#cel-claim' );
		exit;
	}
	// phpcs:enable

	$claim['submission_date'] = current_time( 'Y-m-d' );
	$claim['submitted_at']    = current_time( 'mysql' );
	$claim['staff_email']     = $user->user_email;
	$claim['staff_login']     = $user->user_login;

	$post_id = wp_insert_post(
		array(
			'post_type'   => CEL_CLAIM_CPT,
			'post_status' => 'publish',
			'post_author' => $user->ID,
			'post_title'  => $claim['claimant_name'],
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		wp_die( 'The claim could not be saved: ' . esc_html( $post_id->get_error_message() ), 'Claim not saved', array( 'back_link' => true ) );
	}
	$claim['claim_no'] = cel_claim_number( $post_id, $type );
	wp_update_post(
		array(
			'ID'         => $post_id,
			'post_title' => $claim['claim_no'] . ' – ' . $claim['claimant_name'] . ' – ' . ( 'mileage' === $type ? 'Mileage ' . cel_claim_display_month( $claim['claim_period'] ) : $claim['purpose'] ),
		)
	);
	update_post_meta( $post_id, '_cel_claim', $claim );
	update_post_meta( $post_id, '_cel_type', $type );
	update_post_meta( $post_id, '_cel_total', 'mileage' === $type ? $claim['net_payable'] : $claim['grand_total'] );
	delete_transient( 'cel_claim_draft_' . $type . '_' . $user->ID );

	cel_claim_queue_sync( $post_id );

	wp_safe_redirect( add_query_arg( 'cel_claim_saved', $post_id, $back ) . '#cel-claim' );
	exit;
}

/* -------------------------------------------------------------------------
 * Print view: ?cel_claim_print=ID → the Excel layout, A4 landscape,
 * opens the browser's Print dialog ("Save as PDF").
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'cel_claim_print_view', 1 );
function cel_claim_print_view() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, access checked below.
	if ( ! isset( $_GET['cel_claim_print'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$claim = cel_claim_get( absint( $_GET['cel_claim_print'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! cel_claim_user_can_view( $claim ) ) {
		wp_die( 'You do not have permission to view this claim.', 'Not allowed', array( 'response' => 403 ) );
	}
	nocache_headers();
	$mode = 'print';
	include CEL_CLAIM_DIR . 'templates/print.php';
	exit;
}
