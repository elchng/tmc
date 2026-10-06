# Celectric Travel & Expense Claim Form (WordPress plugin)

This is an online version of the Excel file `Claim_and_travel_*.xlsx`. It works with
WordPress and Elementor.

- **Staff log in** with their WordPress account and fill in the form. The form uses the
  Excel layout and has the same dropdowns: Outstation Allowance, Meal Allowance and
  Expense Category.
- **Totals are calculated as staff type**: each row's allowance, Subtotal Section A,
  Subtotal Section B and TOTAL AMOUNT CLAIMED. The server calculates them again on save,
  using the fixed internal rates, so staff can't change the RM amounts.
- **On save, one row goes to an Excel file on OneDrive.** It holds only the header details
  and totals: Claim No, date, name, department, purpose, Subtotal A, Subtotal B, Grand
  Total, and a link to the PDF.
- **Print / Save as PDF** gives the same layout as the Excel sheet: logo, colours, column
  widths and merged cells, on one A4 landscape page.

| File | What it is |
|---|---|
| `celectric-claim-form.zip` | The plugin. Upload it in WordPress. |
| `celectric-claim-form/` | Source code of the plugin |
| `Claims_Register.xlsx` | The register file to put on OneDrive. It holds one row per claim, in a table named `Claims`. |

---

## 1. Install the plugin (5 minutes)

1. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**. Choose
   `celectric-claim-form.zip`, click **Install Now**, then **Activate**.
2. A new menu called **Expense Claims** appears in the admin sidebar.

## 2. Put the form on a page with Elementor

1. **Pages → Add New**, title it e.g. "Expense Claim", then click **Edit with Elementor**.
2. Drag the **Shortcode** widget onto the page and enter:

   ```
   [celectric_claim_form]
   ```
3. Set the section/container to **Full Width**, because the form is as wide as the Excel
   sheet. On phones it scrolls sideways.
4. Publish. If you want it hidden from your public site, leave the page out of your menu
   and send staff the link.

Visitors who aren't logged in see a **Staff login** box on that page. Logged-in staff see
the form and, below it, a list of their own submitted claims with **Print / PDF** links.

Optional: the shortcode `[celectric_my_claims]` shows only the "My submitted claims" list,
if you want it on a separate page.

## 3. Create staff logins

**Users → Add New User** for each staff member:
- Username, email and **Display Name** (the form fills in *Claimant Name* from it).
- Role: **Subscriber**. Subscribers can only submit and see their own claims.
- People who need to see **everyone's** claims (HR / Finance) should be **Editor** or
  **Administrator**.

## 4. Send totals to Excel on OneDrive

The plugin sends each new claim as JSON to a webhook URL. A free **Make.com** scenario
takes it and adds a row to `Claims_Register.xlsx` on your OneDrive.

### 4a. Put the register file on OneDrive
Upload `Claims_Register.xlsx` to OneDrive, e.g. `Documents/Finance/Claims_Register.xlsx`.
It already contains a table called **Claims**. Keep the table: the automation adds rows
to it. You can delete the `TEST-0000` row once real claims are coming in.

### 4b. Make.com scenario (free plan is enough)
1. Sign in at make.com, then **Create a new scenario**.
2. First module: **Webhooks → Custom webhook → Add**. Name it "Celectric claims" and
   **copy the URL** (looks like `https://hook.eu2.make.com/abc123…`).
3. In WordPress: **Expense Claims → Settings**. Paste the URL into **OneDrive webhook
   URL** and click **Save Changes**.
4. In Make, click **Run once**. Then in WordPress, click **Send test row**. Make now
   knows the fields.
5. Add a second module: **Microsoft 365 Excel → Add a Table Row**. Connect your Microsoft
   account, then pick:
   - Drive: *OneDrive*, File: `Claims_Register.xlsx`, Table: `Claims`
   - Map each column to the webhook field:

     | Excel column | Webhook field |
     |---|---|
     | Claim No | `claim_no` |
     | Submission Date | `submission_date` |
     | Claimant Name | `claimant_name` |
     | Staff Email | `staff_email` |
     | Department | `department` |
     | Purpose | `purpose` |
     | Destination | `destination` |
     | Travel Period | `travel_period` |
     | Subtotal A (RM) | `subtotal_a` |
     | Subtotal B (RM) | `subtotal_b` |
     | Grand Total (RM) | `grand_total` |
     | Print / PDF Link | `print_url` |
6. **Save** and switch the scenario **ON** (scheduling: *Immediately*).

Each new claim then appears as a new row in the OneDrive Excel file within a few seconds.

**Alternatives** use the same webhook URL and the same fields:
- **Power Automate**: trigger *When an HTTP request is received* (a Premium connector),
  then *Excel Online (Business) → Add a row into a table*.
- **Zapier**: *Webhooks by Zapier → Catch Hook*, then *Microsoft Excel → Add Row*.

### Checking it worked
In **Expense Claims → All Claims**, the **OneDrive** column shows `Sent – date/time` or
`Failed: …`. Each claim has a **Resend** link. **Export totals (CSV)** downloads all
claims with the same columns, as a backup.

Optional: under Settings, add a **Notify email** to receive an email with the totals for
every new claim.

## 5. Printing / PDF

After submitting, staff click **Print / Save as PDF**, either in the green message or in
"My submitted claims". It opens a print view with the Excel layout, and the browser's
print dialog appears:
- Printer / Destination: **Save as PDF**
- Layout: **Landscape**, Paper: **A4** (both are set automatically)

The whole form fits on one A4 landscape page. HR / Finance can open any claim's PDF from
**Expense Claims → All Claims → PDF**.

## Rules built in

These come from the Excel form and its guideline box:

| Item | Options |
|---|---|
| Outstation Allowance | None, Full Day (RM 80), Half Day (RM 40) |
| Meal Allowance | None, Breakfast (RM 15), Lunch (RM 20), Dinner (RM 25), Breakfast + Lunch (RM 35), Lunch + Dinner (RM 45), Full Meals (RM 60) |
| Expense Category | Hotel (Company-Approved), Toll Expenses, Parking Fees, Public Transport, Grab / Taxi, Flight / Train, Equipment Transport, Other |

- Required fields: Claimant Name, Purpose of Travel, Department / Project, and at least
  one Section A or Section B row. Every Section B row needs a date, a category and an
  amount.
- Each section shows 9 / 7 rows like the Excel file. The **+ Add row** buttons allow up
  to 40 rows per section.
- A submitted claim can't be edited. To correct one, an admin deletes it in **Expense
  Claims** and the staff member submits again.
- To change the rates or categories, edit `cel_claim_outstation_rates()`,
  `cel_claim_meal_rates()` and `cel_claim_expense_categories()` at the top of
  `celectric-claim-form.php`.
- The title, subtitle, guideline text and logo can be changed under **Expense Claims →
  Settings**.

### Differences from the Excel file
- Amounts show 2 decimal places (`100.00`, `23.30`) instead of `100` / `23.3`.
- The Section B subtotal label reads **SECTION B**. The Excel file says "SECTION C" by
  mistake.
- Submission Date is filled in automatically with the day the claim is submitted.
