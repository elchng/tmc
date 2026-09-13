#!/usr/bin/env python3
"""Weekly Purchase Order (PO) report for the #purchase-request-pr Slack channel.

Reads the previous week's messages from a Slack channel, identifies PO-related
messages, aggregates the PO count and the total amounts in USD and RM, and posts
a summary report back to the channel.

Intended to run every Monday 08:30 Asia/Kuala_Lumpur via GitHub Actions.

Environment variables:
  SLACK_BOT_TOKEN   (required) Slack bot token with scopes channels:history
                    (or groups:history for a private channel) and chat:write.
                    The bot must be a member of the target channel.
  SLACK_CHANNEL_ID  (optional) Channel id to read + post to. Default: C3QQYAFG8.
  USD_TO_RM         (optional) FX rate used for the combined figure. Default: 4.70.
  DRY_RUN           (optional) If "1"/"true"/"yes", print the report instead of
                    posting it to Slack (no token required when combined with
                    _SELFTEST).
"""
import os
import re
import json
import datetime as dt
from urllib import request
from zoneinfo import ZoneInfo

SLACK_API = "https://slack.com/api/"
TZ = ZoneInfo("Asia/Kuala_Lumpur")

TOKEN = os.environ.get("SLACK_BOT_TOKEN", "")
CHANNEL = os.environ.get("SLACK_CHANNEL_ID", "C3QQYAFG8")
USD_TO_RM = float(os.environ.get("USD_TO_RM", "4.70"))
DRY_RUN = os.environ.get("DRY_RUN", "").lower() in ("1", "true", "yes")

PO_KEYWORDS = re.compile(
    r"(purchase order|\bp\.?o\.?\b|place order|placed order|received po|order to)",
    re.I,
)
AMOUNT_RE = re.compile(
    r"(RM|MYR|USD|US\$)\s*([0-9][0-9,]*(?:\.[0-9]+)?)"
    r"|([0-9][0-9,]*(?:\.[0-9]+)?)\s*(RM|MYR|USD)\b",
    re.I,
)


def slack_get(method, params):
    query = "&".join(f"{k}={request.quote(str(v))}" for k, v in params.items())
    req = request.Request(
        SLACK_API + method + "?" + query,
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    with request.urlopen(req) as resp:
        return json.loads(resp.read())


def slack_post(method, payload):
    data = json.dumps(payload).encode()
    req = request.Request(
        SLACK_API + method,
        data=data,
        headers={
            "Authorization": f"Bearer {TOKEN}",
            "Content-Type": "application/json; charset=utf-8",
        },
    )
    with request.urlopen(req) as resp:
        return json.loads(resp.read())


def week_window(now=None):
    """Return (start, end) datetimes for the previous ISO week (Mon 00:00 .. next Mon 00:00), MYT."""
    now = now or dt.datetime.now(TZ)
    today = now.date()
    this_monday = today - dt.timedelta(days=today.weekday())
    last_monday = this_monday - dt.timedelta(days=7)
    start = dt.datetime.combine(last_monday, dt.time.min, TZ)
    end = dt.datetime.combine(this_monday, dt.time.min, TZ)  # exclusive
    return start, end


def normalize_ccy(tok):
    tok = tok.upper().replace("US$", "USD")
    if tok in ("RM", "MYR"):
        return "RM"
    if tok == "USD":
        return "USD"
    return None


def parse_amounts(text):
    out = []
    for m in AMOUNT_RE.finditer(text):
        if m.group(1):
            ccy, amt = normalize_ccy(m.group(1)), m.group(2)
        else:
            ccy, amt = normalize_ccy(m.group(4)), m.group(3)
        if ccy and amt:
            out.append((ccy, float(amt.replace(",", ""))))
    return out


def fetch_messages(start, end):
    msgs, cursor = [], None
    while True:
        params = {
            "channel": CHANNEL,
            "oldest": f"{start.timestamp():.6f}",
            "latest": f"{end.timestamp():.6f}",
            "inclusive": "false",
            "limit": 200,
        }
        if cursor:
            params["cursor"] = cursor
        resp = slack_get("conversations.history", params)
        if not resp.get("ok"):
            raise SystemExit(f"Slack API error (conversations.history): {resp.get('error')}")
        msgs.extend(resp.get("messages", []))
        cursor = resp.get("response_metadata", {}).get("next_cursor")
        if not cursor:
            break
    return msgs


def aggregate(messages):
    po_count = 0
    totals = {"RM": 0.0, "USD": 0.0}
    per_day = {i: 0 for i in range(7)}
    missing_amount = 0
    for m in messages:
        if m.get("subtype"):
            continue
        text = m.get("text", "")
        if not PO_KEYWORDS.search(text):
            continue
        po_count += 1
        ts = dt.datetime.fromtimestamp(float(m["ts"]), TZ)
        per_day[ts.weekday()] += 1
        amts = parse_amounts(text)
        if not amts:
            missing_amount += 1
        for ccy, val in amts:
            totals[ccy] += val
    return {
        "po_count": po_count,
        "totals": totals,
        "combined_rm": totals["RM"] + totals["USD"] * USD_TO_RM,
        "per_day": per_day,
        "missing_amount": missing_amount,
        "n_messages": len(messages),
    }


def fmt_money(v):
    return f"{v:,.2f}"


def render_blocks(start, end, r):
    days = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]
    per_day_line = "  ".join(f"{days[i]} {r['per_day'][i]}" for i in range(7))
    label = f"{start.strftime('%d %b')} – {(end - dt.timedelta(days=1)).strftime('%d %b %Y')}"
    note = ""
    if r["missing_amount"]:
        note = (
            f"\n:warning: {r['missing_amount']} PO message(s) had no detectable amount"
            " — totals may be understated. Include e.g. `RM 12,500` or `USD 9,200`"
            " in the PO message so it can be counted."
        )
    text = (
        "*:page_facing_up: Weekly Purchase Order Report*\n"
        f"_Week of {label} (Mon–Sun)_\n\n"
        f"*POs received:* {r['po_count']}\n"
        f"*Total (RM):* RM {fmt_money(r['totals']['RM'])}\n"
        f"*Total (USD):* USD {fmt_money(r['totals']['USD'])}\n"
        f"*Combined (RM equiv, USD 1 = RM {USD_TO_RM}):* RM {fmt_money(r['combined_rm'])}\n\n"
        f"*By day:* {per_day_line}{note}"
    )
    return [
        {"type": "section", "text": {"type": "mrkdwn", "text": text}},
        {
            "type": "context",
            "elements": [
                {
                    "type": "mrkdwn",
                    "text": (
                        f"Auto-generated Monday 08:30 (MYT) · scanned "
                        f"{r['n_messages']} messages in <#{CHANNEL}>"
                    ),
                }
            ],
        },
    ]


def main():
    start, end = week_window()
    if os.environ.get("_SELFTEST"):
        sample = [
            {"ts": str(start.timestamp() + 3600), "text": "received PO from hyperwave. Pls place order to dwyer. RM 12,500"},
            {"ts": str(start.timestamp() + 90000), "text": "please place order to Dwyer. Quotation: 260909774 USD 3,200"},
            {"ts": str(start.timestamp() + 200000), "text": "pls assist the PO"},
            {"ts": str(start.timestamp() + 300000), "text": "good morning everyone"},
            {"ts": str(start.timestamp() + 400000), "text": "PO received, amount 8,000 RM", "subtype": None},
        ]
        r = aggregate(sample)
    else:
        if not TOKEN:
            raise SystemExit("SLACK_BOT_TOKEN is not set.")
        r = aggregate(fetch_messages(start, end))
    blocks = render_blocks(start, end, r)
    fallback = (
        f"Weekly PO Report: {r['po_count']} POs, "
        f"RM {fmt_money(r['totals']['RM'])}, USD {fmt_money(r['totals']['USD'])}"
    )
    if DRY_RUN or os.environ.get("_SELFTEST"):
        print(json.dumps({"fallback": fallback, "blocks": blocks}, indent=2, ensure_ascii=False))
        return
    resp = slack_post("chat.postMessage", {"channel": CHANNEL, "text": fallback, "blocks": blocks})
    if not resp.get("ok"):
        raise SystemExit(f"Slack API error (chat.postMessage): {resp.get('error')}")
    print("Posted report:", resp.get("ts"))


if __name__ == "__main__":
    main()
