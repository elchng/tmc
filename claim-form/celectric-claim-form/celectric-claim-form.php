<?php
/**
 * Plugin Name: Celectric Claim Forms
 * Description: Online Travel & Expense Claim and Mileage Claim forms for Celectric staff. Staff log in, fill in the form and can reopen or download any past claim as a PDF that matches the Excel layout. Rates are set under Claims → Settings. Each claim's totals can be sent to an Excel file on OneDrive (directly or through Make) and/or a Google Sheet. Shortcodes: [celectric_claim_form], [celectric_mileage_form], [celectric_my_claims].
 * Version: 2.2.1
 * Author: Celectric Sdn Bhd
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: celectric-claim-form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEL_CLAIM_VERSION', '2.2.1' );
define( 'CEL_CLAIM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEL_CLAIM_URL', plugin_dir_url( __FILE__ ) );
define( 'CEL_CLAIM_CPT', 'cel_claim' );
define( 'CEL_CLAIM_OPTION', 'cel_claim_settings' );
/** Upper limit on rows per section, to stop runaway submissions. */
define( 'CEL_CLAIM_MAX_ROWS', 40 );

require_once CEL_CLAIM_DIR . 'includes/settings.php';
require_once CEL_CLAIM_DIR . 'includes/claims.php';
require_once CEL_CLAIM_DIR . 'includes/form-travel.php';
require_once CEL_CLAIM_DIR . 'includes/form-mileage.php';
require_once CEL_CLAIM_DIR . 'includes/sync.php';
require_once CEL_CLAIM_DIR . 'includes/onedrive.php';
require_once CEL_CLAIM_DIR . 'includes/frontend.php';

if ( is_admin() ) {
	require_once CEL_CLAIM_DIR . 'includes/admin.php';
}
