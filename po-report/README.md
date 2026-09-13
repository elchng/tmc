# Weekly Purchase Order (PO) Report

`weekly-po-report.html` is a standalone, shareable weekly PO report template for the
`#purchase-request-pr` team. Open it in any browser — no build step, no dependencies.
It shows the headline metrics requested: **how many POs were received** and the **total
amount in USD and in RM**, plus charts and a per-PO detail table.

> The numbers currently shown are **SAMPLE DATA** (see the amber badge at the top).
> Replace them with your real POs — see "Populating the report" below.

## What it shows
- **KPI tiles:** Total POs received (count), Total Amount (RM), Total Amount (USD).
- **Secondary stats:** Combined value in RM (with a stated FX assumption), number of
  suppliers, average PO value, and week-over-week change.
- **Charts:** POs received per weekday, USD vs RM split, and top suppliers by amount.
- **PO Detail table:** PO No., Date Received, Requester, Supplier, Currency, Amount,
  Amount (RM equiv), Status — with a totals row.

## Populating the report
Everything is computed in the browser from a single `ROWS` array near the bottom of
`weekly-po-report.html`. Each PO is one row with these fields:

| Field         | Meaning                                  | Example        |
|---------------|------------------------------------------|----------------|
| po_number     | PO reference                             | PO-2026-0142   |
| date_received | Date the PO was received (YYYY-MM-DD)    | 2026-09-09     |
| requester     | Who requested it                         | Zura           |
| supplier      | Supplier / vendor                        | Dwyer          |
| currency      | `USD` or `RM`                            | RM             |
| amount        | Amount in that currency                  | 12500          |
| status        | Received / Ordered / Pending             | Ordered        |

Edit the `ROWS` array (and `FX` for the USD→RM rate, `CFG` for the week label), reload,
and all tiles, charts, and totals recompute automatically.

For a repeatable feed, keep POs in a spreadsheet/CSV with the columns above
(`po_number, date_received, requester, supplier, currency, amount, status`) and export
into the `ROWS` array.

## Automating "every Monday 08:30"
This file is the report **template**. Turning it into an automatic Monday-08:30 delivery
still needs two decisions from the requester:
1. **Data source** — where the weekly POs live (a Google Sheet / Excel file, a database,
   or parsed from `#purchase-request-pr` messages).
2. **Schedule + delivery** — a scheduled trigger that regenerates this report each Monday
   at 08:30 and posts it (e.g. back into the Slack channel, or by email).

Once the data source is confirmed, the schedule can be wired up.
