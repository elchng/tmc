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

## Automated weekly Slack report (every Monday 08:30 MYT)

`slack_weekly_report.py` + `.github/workflows/po-weekly-report.yml` read the previous
week's messages from `#purchase-request-pr`, count the PO messages, total the amounts in
USD and RM, and post a summary back into the channel. The GitHub Actions cron is set to
`30 0 * * 1` = **00:30 UTC = 08:30 Asia/Kuala_Lumpur, every Monday**.

### One-time setup (required before it can run)
1. **Create a Slack app** (https://api.slack.com/apps) in the workspace and add a **bot
   token** with scopes `channels:history` (or `groups:history` if the channel is private)
   and `chat:write`. Install the app to the workspace.
2. **Invite the bot** into `#purchase-request-pr` (`/invite @your-bot`) so it can read and
   post there.
3. In the GitHub repo, add the bot token as a secret **`SLACK_BOT_TOKEN`**
   (Settings → Secrets and variables → Actions → Secrets). Optionally add repo
   **Variables** `SLACK_CHANNEL_ID` (defaults to `C3QQYAFG8`) and `USD_TO_RM`
   (defaults to `4.70`).
4. **Merge this workflow to the default branch (`main`).** GitHub only runs scheduled
   workflows from the default branch, so the cron will not fire while it lives only on a
   feature branch. You can test it any time before merging via **Actions → Weekly PO
   Report → Run workflow** (`workflow_dispatch`).

### How PO messages are detected
A message counts as a PO if it mentions any of: `purchase order`, `PO`, `place order`,
`received PO`, or `order to`. Amounts are read from patterns like `RM 12,500`,
`USD 9,200`, `US$1,200`, or `8,000 RM`. **For accurate totals, include the amount and
currency in the PO message.** Recommended one-line format:

```
PO received | Supplier: Dwyer | Amount: RM 12,500 | Ref: 260909774
```

Messages with no detectable amount are still counted, and the Slack report notes how many
were missing an amount so figures aren't silently understated.

### Limitations
- Only top-level channel messages are scanned (replies inside threads are not).
- Detection is keyword/regex based; a consistent PO message format gives the most accurate
  counts and totals.
- The bot posts the summary as a Slack message. The richer HTML dashboard
  (`weekly-po-report.html`) remains a manual/visual template.
