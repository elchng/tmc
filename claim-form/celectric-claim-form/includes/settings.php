<?php
/**
 * Settings: rates, dropdown lists, wording and where claims are sent.
 * Everything is stored in one option (CEL_CLAIM_OPTION) and edited on
 * Claims → Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cel_claim_default_settings() {
	return array(
		// General.
		'logo_url'           => '',
		'notify_email'       => '',
		'claim_page_url'     => '/claim-form/',
		'menu_button_menu'   => 0,
		'menu_button_text'   => 'Claim Form',

		// Travel & Expense Claim.
		'travel_title'       => 'TRAVEL & EXPENSE CLAIM FORM',
		'travel_subtitle'    => 'Internal Guideline Effective: September 2026',
		'travel_outstation'  => "None | 0\nFull Day | 80\nHalf Day | 40",
		'travel_meals'       => "None | 0\nBreakfast | 15\nLunch | 20\nDinner | 25\nBreakfast + Lunch | 35\nLunch + Dinner | 45\nFull Meals | 60",
		'travel_categories'  => "Hotel (Company-Approved)\nToll Expenses\nParking Fees\nPublic Transport\nGrab / Taxi\nFlight / Train\nEquipment Transport\nOther",
		'travel_rows_a'      => 9,
		'travel_rows_b'      => 7,
		'travel_guidelines'  => "Summary Guidelines & Compliance (Celectric Sept 2026):\n"
			. "• Meals & Incidentals: Max RM60/day (Breakfast: RM15, Lunch: RM20, Dinner: RM25,\n"
			. "  Breakfast+Lunch: RM35, Lunch+Dinner: RM45).\n"
			. "• Outstation Allowance: Full day = RM80; Half day = RM40 (compensates inconvenience).\n"
			. "• Receipts Requirement: Original invoices required for Hotel, Flight/Train, Grab, Toll & Parking.\n"
			. '• Strict Exclusions: Alcohol, traffic summons, personal expenses, and family costs are non-claimable.',

		// Mileage Claim.
		'mileage_title'      => 'MILEAGE CLAIM FORM',
		'mileage_vehicles'   => "Car | 0.70\nMotorcycle | 0.40",
		'mileage_purposes'   => "Site Visit\nMeeting\nSafety Briefing\nCommissioning\nCalibration\nDelivery\nOther",
		'mileage_rows'       => 16,

		// Where to send each claim's totals.
		'webhook_urls'       => '',
		'google_url'         => '',
		'google_secret'      => '',
		'ms_enabled'         => 0,
		'ms_tenant'          => '',
		'ms_client_id'       => '',
		'ms_client_secret'   => '',
		'ms_user'            => '',
		'ms_path'            => '',
		'ms_item_id'         => '',
		'ms_tables'          => '',
		'ms_table_travel'    => 'TravelClaims',
		'ms_table_mileage'   => 'MileageClaims',
	);
}

function cel_claim_settings() {
	$saved = get_option( CEL_CLAIM_OPTION, array() ); // Already cached by WordPress.
	$saved = is_array( $saved ) ? $saved : array();
	// v1 used different keys for these.
	foreach ( array( 'title' => 'travel_title', 'subtitle' => 'travel_subtitle', 'guidelines' => 'travel_guidelines', 'webhook_url' => 'webhook_urls' ) as $old => $new ) {
		if ( ! empty( $saved[ $old ] ) && ! isset( $saved[ $new ] ) ) {
			$saved[ $new ] = $saved[ $old ];
		}
	}
	return wp_parse_args( $saved, cel_claim_default_settings() );
}

function cel_claim_setting( $key ) {
	$s = cel_claim_settings();
	return $s[ $key ] ?? null;
}

/** Splits a textarea into trimmed, non-empty lines. */
function cel_claim_lines( $text ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $text ) ), 'strlen' ) );
}

/**
 * Parses "Label | amount" lines into [ display label => amount ].
 * The display label adds the amount the same way the Excel form does:
 * "Full Day | 80" → "Full Day (RM 80)". Zero-amount lines keep their label.
 */
function cel_claim_rate_list( $text ) {
	$out = array();
	foreach ( cel_claim_lines( $text ) as $line ) {
		$parts  = array_map( 'trim', explode( '|', $line, 2 ) );
		$label  = $parts[0];
		$amount = isset( $parts[1] ) ? (float) str_replace( array( 'RM', ',', ' ' ), '', $parts[1] ) : 0.0;
		if ( '' === $label ) {
			continue;
		}
		$display          = $amount > 0 ? $label . ' (RM ' . cel_claim_short_number( $amount ) . ')' : $label;
		$out[ $display ] = round( $amount, 2 );
	}
	return $out;
}

/** 80 → "80", 0.7 → "0.70". */
function cel_claim_short_number( $n ) {
	return ( abs( $n - round( $n ) ) < 0.001 ) ? (string) (int) round( $n ) : number_format( $n, 2, '.', '' );
}

/** Mileage vehicle rates: [ "Car" => 0.70, … ]. */
function cel_claim_vehicle_rates() {
	$out = array();
	foreach ( cel_claim_lines( cel_claim_setting( 'mileage_vehicles' ) ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( '' !== $parts[0] ) {
			$out[ $parts[0] ] = round( isset( $parts[1] ) ? (float) str_replace( array( 'RM', ',', ' ', '/km' ), '', $parts[1] ) : 0, 2 );
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Settings page.
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', 'cel_claim_register_settings' );
function cel_claim_register_settings() {
	register_setting(
		'cel_claim',
		CEL_CLAIM_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'cel_claim_sanitize_settings',
		)
	);
}

function cel_claim_sanitize_settings( $in ) {
	$in   = is_array( $in ) ? $in : array();
	$d    = cel_claim_default_settings();
	// Each settings tab only posts its own fields; keep everything else as saved.
	$out  = array_intersect_key( cel_claim_settings(), $d );
	$has  = function ( $k ) use ( $in ) {
		return array_key_exists( $k, $in );
	};

	foreach ( array( 'travel_title', 'travel_subtitle', 'mileage_title', 'ms_tenant', 'ms_client_id', 'ms_user', 'ms_path', 'ms_item_id', 'ms_table_travel', 'ms_table_mileage', 'google_secret' ) as $k ) {
		if ( $has( $k ) ) {
			$out[ $k ] = sanitize_text_field( $in[ $k ] );
		}
	}
	foreach ( array( 'travel_outstation', 'travel_meals', 'travel_categories', 'travel_guidelines', 'mileage_vehicles', 'mileage_purposes', 'ms_tables' ) as $k ) {
		if ( $has( $k ) ) {
			$out[ $k ] = sanitize_textarea_field( $in[ $k ] );
		}
	}
	foreach ( array( 'travel_rows_a', 'travel_rows_b', 'mileage_rows' ) as $k ) {
		if ( $has( $k ) ) {
			$out[ $k ] = min( CEL_CLAIM_MAX_ROWS, max( 1, absint( $in[ $k ] ) ) );
		}
	}
	if ( $has( 'logo_url' ) ) {
		$out['logo_url'] = esc_url_raw( trim( $in['logo_url'] ), array( 'https', 'http' ) );
	}
	if ( $has( 'claim_page_url' ) ) {
		$u = trim( (string) $in['claim_page_url'] );
		if ( '' === $u ) {
			$u = '/claim-form/';
		} elseif ( 0 === strpos( $u, 'http' ) ) {
			$u = esc_url_raw( $u, array( 'https', 'http' ) );
		} else {
			$u = '/' . trim( sanitize_text_field( $u ), '/' ) . '/';
		}
		$out['claim_page_url'] = $u;
	}
	if ( $has( 'menu_button_menu' ) ) {
		$out['menu_button_menu'] = absint( $in['menu_button_menu'] );
	}
	if ( $has( 'menu_button_text' ) ) {
		$out['menu_button_text'] = sanitize_text_field( $in['menu_button_text'] ) ? sanitize_text_field( $in['menu_button_text'] ) : 'Claim Form';
	}
	if ( $has( 'notify_email' ) ) {
		$out['notify_email'] = implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', (string) $in['notify_email'] ) ) ) );
	}
	if ( $has( 'google_url' ) ) {
		$out['google_url'] = esc_url_raw( trim( $in['google_url'] ), array( 'https', 'http' ) );
	}
	if ( $has( 'webhook_urls' ) ) {
		$urls = array();
		foreach ( cel_claim_lines( $in['webhook_urls'] ) as $u ) {
			$u = esc_url_raw( $u, array( 'https', 'http' ) );
			if ( $u ) {
				$urls[] = $u;
			}
		}
		$out['webhook_urls'] = implode( "\n", $urls );
	}
	// Checkbox: only meaningful when the tab that shows it was submitted.
	if ( ( $in['_tab'] ?? '' ) === 'sync' || $has( 'ms_enabled' ) ) {
		$out['ms_enabled'] = empty( $in['ms_enabled'] ) ? 0 : 1;
	}
	// A path typed by hand replaces the file chosen with the OneDrive browser.
	if ( $has( 'ms_path_manual' ) && '' !== trim( (string) $in['ms_path_manual'] ) ) {
		$out['ms_path']    = trim( sanitize_text_field( $in['ms_path_manual'] ), '/' );
		$out['ms_item_id'] = '';
	}
	// A different OneDrive owner means the chosen file no longer applies.
	if ( $has( 'ms_user' ) && strtolower( trim( $in['ms_user'] ) ) !== strtolower( trim( (string) cel_claim_settings()['ms_user'] ) ) && ! $has( 'ms_item_id' ) ) {
		$out['ms_item_id'] = '';
		$out['ms_path']    = '';
		$out['ms_tables']  = '';
	}
	// The client secret is never printed back; an empty box keeps the saved one.
	if ( $has( 'ms_client_secret' ) && '' !== trim( (string) $in['ms_client_secret'] ) ) {
		$out['ms_client_secret'] = sanitize_text_field( trim( $in['ms_client_secret'] ) );
	}

	// Make sure each rate list still has at least one usable option.
	foreach ( array( 'travel_outstation', 'travel_meals', 'mileage_vehicles' ) as $k ) {
		$check = 'mileage_vehicles' === $k ? cel_claim_lines( $out[ $k ] ) : cel_claim_rate_list( $out[ $k ] );
		if ( ! $check ) {
			$out[ $k ] = $d[ $k ];
			add_settings_error( CEL_CLAIM_OPTION, 'cel_' . $k, 'A rate list was empty, so the default list was restored.' );
		}
	}

	delete_transient( 'cel_claim_ms_token' );
	return $out;
}

function cel_claim_settings_page() {
	$s = cel_claim_settings();
	$n = CEL_CLAIM_OPTION;

	$field = function ( $key, $label, $desc = '', $type = 'text', $class = 'regular-text' ) use ( $s, $n ) {
		echo '<tr><th scope="row"><label for="cel_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea id="cel_' . esc_attr( $key ) . '" class="large-text code" rows="7" name="' . esc_attr( $n . '[' . $key . ']' ) . '">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>';
		} elseif ( 'checkbox' === $type ) {
			echo '<label><input id="cel_' . esc_attr( $key ) . '" type="checkbox" value="1" name="' . esc_attr( $n . '[' . $key . ']' ) . '"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $desc ) . '</label>';
			$desc = '';
		} elseif ( 'secret' === $type ) {
			echo '<input id="cel_' . esc_attr( $key ) . '" type="password" autocomplete="new-password" class="' . esc_attr( $class ) . '" name="' . esc_attr( $n . '[' . $key . ']' ) . '" value="" placeholder="' . esc_attr( $s[ $key ] ? '•••••••• (saved – leave blank to keep)' : '' ) . '">';
		} else {
			echo '<input id="cel_' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . '" name="' . esc_attr( $n . '[' . $key . ']' ) . '" value="' . esc_attr( (string) $s[ $key ] ) . '">';
		}
		if ( $desc ) {
			echo '<p class="description">' . wp_kses( $desc, array( 'code' => array(), 'strong' => array(), 'br' => array(), 'a' => array( 'href' => array(), 'target' => array() ) ) ) . '</p>';
		}
		echo '</td></tr>';
	};
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'travel';
	$tabs = array(
		'travel'  => 'Travel & Expense',
		'mileage' => 'Mileage',
		'sync'    => 'Excel / Google Sheets',
		'general' => 'General',
	);
	$base = admin_url( 'edit.php?post_type=' . CEL_CLAIM_CPT . '&page=cel-claim-settings' );
	?>
	<div class="wrap">
		<h1>Claim Form Settings</h1>
		<p>Forms: <code>[celectric_claim_form]</code> (Travel &amp; Expense) · <code>[celectric_mileage_form]</code> (Mileage) · <code>[celectric_my_claims]</code> (claim history). In Elementor, use the <em>Shortcode</em> widget.</p>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $k => $label ) : ?>
				<a class="nav-tab<?php echo $tab === $k ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=' . $k ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php settings_errors( CEL_CLAIM_OPTION ); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'cel_claim' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>">
			<table class="form-table" role="presentation">
			<?php
			if ( 'travel' === $tab ) {
				$field( 'travel_outstation', 'Outstation Allowance options', 'One per line as <code>Label | RM amount</code>, e.g. <code>Full Day | 80</code>. Shown to staff as “Full Day (RM 80)”. Use <code>| 0</code> for “None”.', 'textarea' );
				$field( 'travel_meals', 'Meal Allowance options', 'One per line as <code>Label | RM amount</code>, e.g. <code>Lunch + Dinner | 45</code>.', 'textarea' );
				$field( 'travel_categories', 'Expense categories (Section B)', 'One per line.', 'textarea' );
				$field( 'travel_guidelines', 'Guidelines box', 'Printed in section 4. <strong>Update it when you change the rates above.</strong>', 'textarea' );
				$field( 'travel_title', 'Form title' );
				$field( 'travel_subtitle', 'Subtitle' );
				$field( 'travel_rows_a', 'Rows shown – Section A', 'Staff can add more rows (up to ' . CEL_CLAIM_MAX_ROWS . ').', 'number', 'small-text' );
				$field( 'travel_rows_b', 'Rows shown – Section B', '', 'number', 'small-text' );
			} elseif ( 'mileage' === $tab ) {
				$field( 'mileage_vehicles', 'Vehicle types & rate per km', 'One per line as <code>Vehicle | RM per km</code>, e.g. <code>Car | 0.70</code>.', 'textarea' );
				$field( 'mileage_purposes', 'Purpose of Visit options', 'One per line.', 'textarea' );
				$field( 'mileage_title', 'Form title' );
				$field( 'mileage_rows', 'Trip rows shown', 'Staff can add more rows (up to ' . CEL_CLAIM_MAX_ROWS . ').', 'number', 'small-text' );
			} elseif ( 'sync' === $tab ) {
				echo '<tr><td colspan="2" style="padding-left:0"><p>Each new claim’s totals can go to any or all of the destinations below. Sending happens in the background, so staff don’t wait for it.</p></td></tr>';
				echo '<tr><td colspan="2" style="padding-left:0"><h2 style="margin:0">A. Microsoft 365 – direct to OneDrive Excel (no Make)</h2></td></tr>';
				$field( 'ms_enabled', 'Enable', 'Add a row to the Excel tables in OneDrive / SharePoint directly via Microsoft Graph', 'checkbox' );
				$field( 'ms_tenant', 'Directory (tenant) ID', 'From your Entra ID app registration. See README section “Microsoft 365 direct”.', 'text', 'regular-text code' );
				$field( 'ms_client_id', 'Application (client) ID', '', 'text', 'regular-text code' );
				$field( 'ms_client_secret', 'Client secret', 'Stored in the WordPress database; never shown again.', 'secret', 'regular-text code' );
				$field( 'ms_user', 'OneDrive owner', 'Email of the Microsoft 365 user whose OneDrive holds the file, e.g. <code>info@mycelectric.com</code>.' );
				cel_claim_onedrive_fields( $s, $n );
				echo '<tr><td colspan="2" style="padding-left:0"><h2 style="margin:0">B. Google Sheets (no Make)</h2></td></tr>';
				$field( 'google_url', 'Apps Script web app URL', 'Deploy <code>google-apps-script.gs</code> from the plugin download as a web app and paste its <code>https://script.google.com/macros/s/…/exec</code> URL here.', 'url', 'large-text code' );
				$field( 'google_secret', 'Shared secret', 'Any random text. Put the same text in the script’s <code>SECRET</code> line so only this website can write to your sheet.', 'text', 'regular-text code' );
				echo '<tr><td colspan="2" style="padding-left:0"><h2 style="margin:0">C. Webhooks (Make, Zapier, Power Automate)</h2></td></tr>';
				$field( 'webhook_urls', 'Webhook URLs', 'One per line. Each claim is POSTed as JSON. The field <code>form</code> is <code>travel</code> or <code>mileage</code>.', 'textarea' );
			} else {
				$field( 'logo_url', 'Logo URL', 'Optional. Leave blank to use the Celectric logo from the Excel forms.', 'url', 'large-text' );
				$field( 'notify_email', 'Notify email(s)', 'Optional, comma-separated. Receives an email with the totals and PDF link for every new claim.', 'text', 'large-text' );
				echo '<tr><td colspan="2" style="padding-left:0"><h2 style="margin:0">Staff button &amp; access</h2><p>Claim forms and the button are only for users with the <strong>Employee</strong> role (and Editors / Administrators). Employees go straight to the claim page after logging in and do not see wp-admin.</p></td></tr>';
				$field( 'claim_page_url', 'Claim page', 'Where the button goes and where Employees land after logging in, e.g. <code>/claim-form/</code>.', 'text', 'regular-text code' );
				$field( 'menu_button_text', 'Button text' );
				echo '<tr><th scope="row"><label for="cel_menu_button_menu">Add button to menu</label></th><td><select id="cel_menu_button_menu" name="' . esc_attr( $n . '[menu_button_menu]' ) . '"><option value="0">— Don’t add (I’ll use the shortcode) —</option>';
				foreach ( wp_get_nav_menus() as $menu ) {
					echo '<option value="' . (int) $menu->term_id . '"' . selected( (int) $s['menu_button_menu'], (int) $menu->term_id, false ) . '>' . esc_html( $menu->name ) . '</option>';
				}
				echo '</select><p class="description">Choose the menu shown in your header. The button is added at the end of it, for logged-in staff only. Or leave this off and put the shortcode <code>[celectric_claim_button]</code> in your Elementor header.</p></td></tr>';
			}
			?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php if ( 'sync' === $tab ) : ?>
			<hr>
			<h2>Test</h2>
			<p>Sends a sample travel row (<code>TEST-0000</code>) and mileage row (<code>TEST-0001</code>) to every destination above. Save first.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cel_claim_test_sync">
				<?php wp_nonce_field( 'cel_claim_test_sync' ); ?>
				<?php submit_button( 'Send test rows', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/** "Excel file" picker and table choices for the Microsoft 365 section. */
function cel_claim_onedrive_fields( $s, $n ) {
	$tables = cel_claim_lines( $s['ms_tables'] );
	?>
	<tr>
		<th scope="row">Excel file</th>
		<td>
			<div id="cel-od">
				<p>
					<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
					<strong id="cel-od-current"><?php echo $s['ms_path'] ? esc_html( $s['ms_path'] ) : 'No file chosen yet'; ?></strong>
					<button type="button" class="button" id="cel-od-browse" style="margin-left:8px">Browse OneDrive…</button>
				</p>
				<div id="cel-od-panel" hidden style="max-width:640px;border:1px solid #c3c4c7;background:#fff;border-radius:4px">
					<div style="display:flex;gap:8px;align-items:center;padding:8px 10px;border-bottom:1px solid #dcdcde;background:#f6f7f7">
						<button type="button" class="button button-small" id="cel-od-up">↑ Up</button>
						<code id="cel-od-path" style="flex:1;background:none">OneDrive</code>
						<button type="button" class="button button-small" id="cel-od-close">Close</button>
					</div>
					<ul id="cel-od-list" style="margin:0;max-height:320px;overflow:auto"></ul>
					<div style="padding:8px 10px;border-top:1px solid #dcdcde;background:#f6f7f7">
						<button type="button" class="button" id="cel-od-create">Create Claims_Register.xlsx in this folder</button>
					</div>
				</div>
				<p id="cel-od-msg" class="description" aria-live="polite"></p>
				<p class="description">Save the sign-in details above first. Open folders, then click your Excel file to choose it – or use “Create Claims_Register.xlsx in this folder” to make a ready-made register there. The file is remembered even if it is later renamed or moved.</p>
				<details style="margin-top:6px"><summary>Type the path instead</summary>
					<p><input type="text" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[ms_path_manual]" value="" placeholder="Finance/Claims_Register.xlsx"> <span class="description">Relative to the OneDrive root. Leave blank to keep the file chosen above.</span></p>
				</details>
			</div>
		</td>
	</tr>
	<?php
	foreach ( array( 'ms_table_travel' => 'Table for travel claims', 'ms_table_mileage' => 'Table for mileage claims' ) as $k => $label ) {
		echo '<tr><th scope="row"><label for="cel_' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="cel_' . esc_attr( $k ) . '" name="' . esc_attr( $n . '[' . $k . ']' ) . '" data-cel-od-table>';
		$opts = $tables;
		if ( ! in_array( $s[ $k ], $opts, true ) ) {
			array_unshift( $opts, $s[ $k ] );
		}
		foreach ( $opts as $t ) {
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $s[ $k ], $t, false ) . '>' . esc_html( $t . ( $tables && ! in_array( $t, $tables, true ) ? ' (not in this file)' : '' ) ) . '</option>';
		}
		echo '</select></td></tr>';
	}
}
