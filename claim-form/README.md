# Celectric Claim Forms (WordPress plugin) – v2.3

This plugin puts two Celectric claim forms online:

- **Travel & Expense Claim**, from `Claim_and_travel_*.xlsx`
- **Mileage Claim**, from `celectric_mileage_claim_calculator.xlsx`

Staff log in, fill in a form and submit it. Each claim is saved in WordPress, prints to a
PDF in the Excel layout, and has its totals sent to an Excel file on OneDrive and/or a
Google Sheet.

| File | What it is |
|---|---|
| `celectric-claim-form.zip` | The plugin. Upload it in WordPress (see step 1). |
| `celectric-claim-form/` | Source code of the plugin |
| `Claims_Register.xlsx` | The register file (also built into the plugin, which can create it in OneDrive for you). It has two tabs: **Travel Claims** (table `TravelClaims`) and **Mileage Claims** (table `MileageClaims`). |
| `celectric-claim-form/extras/google-apps-script.gs` | The script for the Google Sheets option |
| `sample-*.pdf` | Example printouts of both forms |

## Mileage claims: vehicle per trip (v2.2)

Staff choose the **Vehicle** on each trip row, so one claim can mix car and motorcycle
trips. Each row shows its **Amount (RM)** (distance × that vehicle's rate). Section 2
lists each vehicle used, e.g. *Car: 91.2 KM × RM 0.70/km = RM 63.84* and *Motorcycle:
28.3 KM × RM 0.40/km = RM 11.32*, then the total distance and the Net Payable Claim.
Payment is worked out per vehicle (total km × rate). A new or blank trip row starts with
the vehicle used on the row above, so someone who drives the car all month only picks it
once. Mileage claims submitted before v2.2 keep their single-vehicle layout and totals.

**If you already set up the OneDrive register before v2.2:** the Mileage table's columns
have changed (*Vehicle Type* and *Rate* became one *Vehicle Breakdown* column). Either
create a fresh register (Settings → **Browse OneDrive… → Create Claims_Register.xlsx in
this folder**), or in your current file change the Mileage Claims table headings to
match the list in section 5. The Make scenario and the Google Sheets script are already
updated.

## What staff get

- **Login**: anyone not logged in sees a staff login box instead of the form.
- **The form**: on a computer it looks like the Excel sheet. On a phone it switches to
  one field per line, with each row shown as a card. Only one blank row shows at a time;
  filling it in shows the next.
- **Live totals**: totals update as staff type. When a claim is saved, the server works
  them out again from the rates in Settings, so staff can't change the amounts.
- **Print / Save as PDF** opens the claim in the Excel layout on one A4 landscape page.
  Staff choose *Save as PDF* in the print dialog.
- **Claim history**: below each form is the list of that staff member's own claims,
  newest first, 15 per page, each with a **PDF** button. Nothing expires, so a claim from
  last year can still be downloaded. The shortcode `[celectric_my_claims]` shows all of a
  person's claims (both forms) on one page.

## Where claims are stored, and deleting them

- Claims are stored in the WordPress database on your hosting: one private record per
  claim, in `wp_posts` with type `cel_claim`, plus its details in `wp_postmeta`. They are
  not public and never appear on the website.
- Each claim keeps the rates it was submitted with. If you change a rate in Settings
  later, old claims and their PDFs stay unchanged.
- **Admins and Editors** see every claim in wp-admin → **Claims**. From there they can
  filter by form, open the PDF, export a CSV and **Trash** claims (hover over a row, or
  tick rows and use *Bulk actions → Move to Trash*). Trashed claims can be restored from
  the **Trash** view or removed with **Delete Permanently**.
- **Staff** (Subscribers) can't delete or edit claims. A trashed claim disappears from
  their history and its PDF link stops working.
- Deleting a claim in WordPress doesn't remove its row from the Excel or Google register.
  Delete that row by hand if needed.
- Your normal WordPress backups include the claims.

---

## 1. Install (5 minutes)

1. WordPress admin → **Plugins → Add New Plugin → Upload Plugin**. Choose
   `celectric-claim-form.zip`, then **Install Now** and **Activate**.
   (If v1 is installed, WordPress asks to replace it. Choose *Replace current with
   uploaded*. Existing claims are kept.)
2. A **Claims** menu appears in the admin sidebar.

## 2. Add the forms to pages with Elementor

Create a page for each form, open it with **Edit with Elementor**, add a **Shortcode**
widget and enter one of these:

| Page | Shortcode |
|---|---|
| Travel & Expense Claim | `[celectric_claim_form]` |
| Mileage Claim | `[celectric_mileage_form]` |
| My Claims (optional) | `[celectric_my_claims]` |
| Staff-only header button | `[celectric_claim_button]` |

Set the Elementor section/container to **Full Width**.

## 3. Staff logins

The plugin adds an **Employee** user role (v2.3). Go to **Users → Add New User** and
choose role **Employee**. The form fills in Claimant / Employee Name from the user's
**Display Name**. HR / Finance users who need to see all claims should be **Editor** or
**Administrator**.

- Only Employees, Editors and Administrators can open the claim forms. Other logged-in
  users (e.g. Subscribers or shop customers) see a "Staff only" message.
- After logging in, Employees go straight to the claim page. They don't see the WordPress
  toolbar or wp-admin, but they can still open their Profile page to change their
  password.
- Staff created earlier as **Subscriber** must be changed to **Employee**: tick them on
  the Users page, then *Change role to… → Employee → Change*.

### Staff-only "Claim Form" button in the header

The button only shows for logged-in staff. Visitors and other users see nothing. Set the
claim page address and button text under **Claims → Settings → General** (defaults:
`/claim-form/`, "Claim Form"). Then use either option:

- **Elementor Pro header (Theme Builder):** edit the header, drag a **Shortcode** widget
  where you want the button, and enter `[celectric_claim_button]`. Optional:
  `[celectric_claim_button text="Submit Claim" url="/claim-form/"]`.
- **Theme header / menu:** in **Claims → Settings → General → Add button to menu**,
  choose the menu shown in your header. The button is added at the end of that menu.

## 4. Rates and dropdown lists (Claims → Settings)

| Tab | What you can change |
|---|---|
| **Travel & Expense** | Outstation Allowance options and amounts, Meal Allowance options and amounts, Expense categories, Guidelines box text, title / subtitle, number of rows shown |
| **Mileage** | Vehicle types and RM per km (add more, e.g. `Van \| 0.90`), Purpose of Visit list, title, number of rows shown |
| **Excel / Google Sheets** | Where each claim's totals are sent (see 5) |
| **General** | Logo, email address(es) notified of every new claim |

Rates are typed one per line as `Label | amount`:

```
None | 0
Full Day | 80
Half Day | 40
```

Staff see these as "Full Day (RM 80)", the same as the Excel form. When you change the
travel rates, also update the **Guidelines box** text, because it is printed on the
PDF. The mileage "Rate Options" line updates itself.

## 5. Sending totals to Excel / Google Sheets

Only the header details and totals are sent, one row per claim:

| Form | Columns |
|---|---|
| Travel | Claim No, Submission Date, Claimant Name, Staff Email, Department, Purpose, Destination, Travel Period, Subtotal A, Subtotal B, Grand Total, PDF link |
| Mileage | Claim No, Submission Date, Employee Name, Staff Email, Claim Period, Vehicle Breakdown (e.g. “Car 91.2 km; Motorcycle 28.3 km”), Total Distance (KM), Net Payable, PDF link |

Sending happens in the background after the staff member submits, so they don't wait.
The **Excel / Sheets** column in **Claims** shows *Sent* or the error for each
destination, and has a **Send again** link. **Send test rows** on the settings tab
checks the connection.

You can use one, two or all three of these options.

### Option A: Microsoft 365 direct (no Make, no monthly cost)

The plugin writes straight into an Excel file in OneDrive through Microsoft Graph. You
pick the folder and file from inside WordPress. This needs a **Microsoft 365 business**
account (e.g. `info@mycelectric.com`). It does not work with a personal outlook.com
OneDrive.

**One-time app registration (about 10 minutes, done by a Microsoft 365 admin):**

1. Go to <https://entra.microsoft.com> → **App registrations → New registration**.
   - Name: `Celectric Claim Forms`. Account type: *Single tenant*. Click **Register**.
   - Copy the **Application (client) ID** and **Directory (tenant) ID**.
2. **API permissions → Add a permission → Microsoft Graph → Application permissions →
   `Files.ReadWrite.All`** → Add. Then click **Grant admin consent for Celectric**.
3. **Certificates & secrets → New client secret** (24 months) and copy the **Value**.
   Add a calendar reminder to renew it before it expires.

**In WordPress, go to Claims → Settings → Excel / Google Sheets, section A:**

4. Tick **Enable**. Paste the tenant ID, client ID and client secret. Enter the
   **OneDrive owner** (the Microsoft 365 user whose OneDrive should hold the file,
   e.g. `info@mycelectric.com`). Click **Save Changes**.
5. Next to **Excel file**, click **Browse OneDrive…**:
   - Click folders to open them, and **↑ Up** to go back.
   - Click an Excel file and **Use this file** to choose it, **or**
   - click **Create Claims_Register.xlsx in this folder**. The plugin uploads a
     ready-made register with the *Travel Claims* and *Mileage Claims* tables into the
     folder you're in. If a file with that name already exists, OneDrive names the new
     one "Claims_Register 1.xlsx".
6. The plugin reads the tables in the chosen file and picks the right ones. If you chose
   your own workbook, select which table receives travel claims and which receives
   mileage claims, then click **Save Changes**. Each table needs the same columns, in
   the same order, as the table in section 5 above.
7. Click **Send test rows**. Two test rows (`TEST-0000`, `TEST-0001`) should appear in
   the file. Delete them afterwards.

The file is remembered by its OneDrive ID, so it keeps working if someone renames it or
moves it to another folder. If you change the OneDrive owner, choose the file again.
Under **Type the path instead** you can enter a path such as
`Finance/Claims_Register.xlsx` instead of browsing.

`Files.ReadWrite.All` lets the app read and write files in any OneDrive in your
organisation. It needs that to show the folder browser. Keep the client secret private;
it is only stored in your WordPress database and never shown again after saving.

### Option B: Google Sheets (no Make, free)

1. Create a Google Sheet, e.g. "Claims Register".
2. **Extensions → Apps Script**. Replace the code with
   `celectric-claim-form/extras/google-apps-script.gs` and change
   `const SECRET = 'change-me';` to a random phrase.
3. **Deploy → New deployment → Web app**, with *Execute as: Me* and *Who has access:
   Anyone*. Authorise when asked and copy the **Web app URL** (ends in `/exec`).
4. WordPress → **Claims → Settings → Excel / Google Sheets**, section B: paste the URL,
   enter the same secret phrase, **Save Changes**, then **Send test rows**.

The script creates the **Travel Claims** and **Mileage Claims** tabs with headings, and
won't add the same claim number twice. If you need an Excel file, use *File → Download →
Microsoft Excel (.xlsx)* in Google Sheets.

### Option C: Make (already set up in your Make account)

These are already in your Make organisation "My Lab":
- Webhook **Celectric Claims (WordPress)**:
  `https://hook.us1.make.com/7xq2na49r7ejqi09mrgnjdrjffh50quc`
- Scenario **Celectric Claims → OneDrive Excel**: the webhook, then a router that sends
  travel claims to table `TravelClaims` and mileage claims to table `MileageClaims`.
  All columns are already mapped. The scenario is **off** until the connection below
  exists.

To finish it:
1. Upload `Claims_Register.xlsx` to your OneDrive.
2. Open the connection request and sign in with that Microsoft account:
   <https://us1.make.com/178648/credentials-requests/inbox?requestId=1f7b0bdc-3192-4012-8a15-d6fd6970c242>
3. In Make, open the scenario. In each of the two **Microsoft 365 Excel – Add a Table
   Row** modules, choose the new connection and the workbook `Claims_Register.xlsx`.
   Then switch the scenario **ON**. (Or tell Claude once the connection is done and it
   can finish this step.)
4. WordPress → **Claims → Settings → Excel / Google Sheets**, section C: paste the
   webhook URL above, **Save**, then **Send test rows**.

Make's Free plan allows 1,000 operations a month, and each claim uses about 2. It also
allows 2 scenarios; your account now uses both (*Weekly Sales Report to Slack* and this
one).

## 6. Speed and mobile

- CSS and JS (about 6 KB compressed, no jQuery) load only on pages that contain one of
  the shortcodes. The rest of your site is not affected.
- Sending to Excel / Google / Make runs after the page has been sent to the browser, so
  submitting is quick.
- The form, the claim history and the PDF view all fit a phone screen without sideways
  scrolling. On a phone the PDF view is scaled down to fit, and still prints full-size
  A4.

## Differences from the Excel files

- Amounts show 2 decimal places.
- On the travel form, the Section B subtotal label reads **SECTION B**; the Excel file
  says "SECTION C" by mistake.
- On the mileage form, the "Total Distance (KM):" label in section 2 spans two cells so
  it isn't cut off (in Excel it sits in the narrow No. column). The value moves one cell
  to the right.
- The Submission Date / Date is filled in automatically with the day the claim is
  submitted.
- A submitted claim can't be edited. HR deletes it and the staff member resubmits.
