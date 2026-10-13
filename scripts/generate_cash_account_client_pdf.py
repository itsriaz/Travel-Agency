r"""Create a client-facing cash account statement from the read-only audit JSON.

Usage (PowerShell):
  & C:\xampp\php\php.exe scripts/inspect_cash_account_discrepancies.php |
    & python scripts/generate_cash_account_client_pdf.py output/pdf/cash_account_movement_summary_2026-10-11.pdf
"""

from __future__ import annotations

import json
import sys
from decimal import Decimal
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.pdfgen import canvas


EXPECTED_MARKS = {
    "journal_entries": 7474,
    "customer_receipts": 1218,
    "supplier_payments": 270,
    "treasury_transactions": 41,
}
EXPECTED_BALANCES = {
    "Cash-SWT-PKR": Decimal("-2927350.99"),
    "Cash-UAE-AED": Decimal("946.00"),
}

NAVY = colors.HexColor("#17364A")
INK = colors.HexColor("#263C49")
MUTED = colors.HexColor("#5F707A")
LINE = colors.HexColor("#CED8DE")
PALE = colors.HexColor("#F3F7F8")
NEGATIVE = colors.HexColor("#9B3329")


def net(sources: dict, *names: str) -> Decimal:
    return sum(
        (Decimal(sources[name]["money_in"]) - Decimal(sources[name]["money_out"]) for name in names),
        Decimal("0"),
    )


def money(value: Decimal, currency: str, signed: bool = False) -> str:
    prefix = "+" if signed and value > 0 else ""
    return f"{prefix}{currency} {value:,.2f}"


def audit_rows(account: dict) -> list[tuple[str, Decimal]]:
    src = {row["source_type"]: row for row in account["source_breakdown"]}
    common = {
        "transfers": net(src, "treasury_transfer_posted"),
        "suppliers": net(src, "supplier_payment_recorded", "supplier_payment_void_reversed"),
        "expenses": net(src, "business_expense_recorded"),
        "refunds": net(src, "service_refund_posted", "service_refund_reversed"),
    }
    if account["name"] == "Cash-SWT-PKR":
        rows = [
            (
                "Customer receipts, net of voids and corrections",
                net(src, "customer_receipt_recorded", "customer_receipt_void_reversed",
                    "customer_advance_correction_reversal"),
            ),
            ("Treasury transfers, net", common["transfers"]),
            (
                "Supplier payments, net of reversals",
                common["suppliers"] + net(src, "supplier_payment_treasury_funded"),
            ),
            ("Operating expenses", common["expenses"]),
            ("Manual Money Out", net(src, "direct_treasury_entry_posted")),
            ("Customer refunds, net", common["refunds"]),
        ]
    else:
        # Two historical integrity reversals were traced to a receipt and a
        # supplier cash posting respectively; classify them with those sources.
        correction = src["data_integrity_reversal"]
        assert Decimal(correction["money_in"]) == Decimal("1188")
        assert Decimal(correction["money_out"]) == Decimal("1135")
        assert net(src, "customer_advance_refunded", "customer_advance_return_correction_reversal") == 0
        rows = [
            (
                "Customer receipts, net of voids and corrections",
                net(src, "customer_receipt_recorded", "customer_receipt_wrong_currency_reversed",
                    "customer_receipt_void_reversed", "customer_advance_correction_reversal")
                - Decimal(correction["money_out"]),
            ),
            ("Treasury transfers, net", common["transfers"]),
            (
                "Supplier payments, net of reversals",
                common["suppliers"] + Decimal(correction["money_in"]),
            ),
            (
                "Operating expenses, net of corrections",
                common["expenses"] + net(src, "business_expense_corrected_reversal"),
            ),
            ("Customer refunds, net", common["refunds"]),
        ]

    journal_net = Decimal(account["journal_totals"]["net"])
    if sum((value for _, value in rows), Decimal("0")) != journal_net:
        raise ValueError(f"Movement categories do not reconcile for {account['name']}")
    if Decimal(str(account["opening_balance"])) + journal_net != EXPECTED_BALANCES[account["name"]]:
        raise ValueError(f"Unexpected closing balance for {account['name']}")
    return rows


def text(c: canvas.Canvas, x: float, y: float, value: str, size: int = 10,
         bold: bool = False, color=INK) -> None:
    c.setFont("Helvetica-Bold" if bold else "Helvetica", size)
    c.setFillColor(color)
    c.drawString(x, y, value)


def right(c: canvas.Canvas, x: float, y: float, value: str, size: int = 10,
          bold: bool = False, color=INK) -> None:
    c.setFont("Helvetica-Bold" if bold else "Helvetica", size)
    c.setFillColor(color)
    c.drawRightString(x, y, value)


def wrap(c: canvas.Canvas, value: str, x: float, y: float, max_width: float,
         size: int = 9, leading: int = 14, color=MUTED) -> float:
    words = value.split()
    line = ""
    for word in words:
        candidate = f"{line} {word}".strip()
        if line and stringWidth(candidate, "Helvetica", size) > max_width:
            text(c, x, y, line, size=size, color=color)
            y -= leading
            line = word
        else:
            line = candidate
    if line:
        text(c, x, y, line, size=size, color=color)
        y -= leading
    return y


def draw_page(c: canvas.Canvas, account: dict, page: int) -> None:
    width, height = A4
    left, right_edge = 46, width - 46
    currency = account["currency"]
    balance = EXPECTED_BALANCES[account["name"]]
    rows = audit_rows(account)

    text(c, left, height - 49, "CASH ACCOUNT MOVEMENT SUMMARY", 15, True, NAVY)
    right(c, right_edge, height - 48, "11 October 2026", 9, color=MUTED)
    c.setStrokeColor(NAVY)
    c.setLineWidth(1.2)
    c.line(left, height - 61, right_edge, height - 61)

    text(c, left, height - 89, account["name"], 14, True, INK)
    text(c, left, height - 107, "Recorded history through 11 October 2026", 9, color=MUTED)
    right(c, right_edge, height - 89, f"Currency: {currency}", 10, True, INK)

    y = height - 137
    c.setFillColor(PALE)
    c.rect(left, y - 24, right_edge - left, 30, stroke=0, fill=1)
    text(c, left + 9, y - 14, "Recorded movement", 9, True, NAVY)
    right(c, right_edge - 9, y - 14, "Net amount", 9, True, NAVY)
    y -= 42

    text(c, left + 9, y, "Opening balance recorded in the system", 10)
    right(c, right_edge - 9, y, money(Decimal(str(account["opening_balance"])), currency), 10)
    y -= 25
    c.setStrokeColor(LINE)
    c.line(left, y + 9, right_edge, y + 9)

    for label, amount in rows:
        text(c, left + 9, y, label, 9.5)
        right(c, right_edge - 9, y, money(amount, currency, signed=True), 9.5)
        y -= 25
        c.setStrokeColor(LINE)
        c.setLineWidth(0.35)
        c.line(left, y + 9, right_edge, y + 9)

    y -= 4
    c.setFillColor(PALE)
    c.rect(left, y - 19, right_edge - left, 33, stroke=0, fill=1)
    text(c, left + 9, y - 7, "Closing recorded balance", 10, True, NAVY)
    right(c, right_edge - 9, y - 7, money(balance, currency), 11, True,
          NEGATIVE if balance < 0 else NAVY)

    y -= 55
    text(c, left, y, "Reconciliation", 10, True, NAVY)
    y -= 20
    text(c, left, y, "Account balance", 9, color=MUTED)
    right(c, left + 257, y, money(Decimal(str(account["live_balance"])), currency), 9)
    text(c, left + 280, y, "Journal", 9, color=MUTED)
    right(c, right_edge, y, money(Decimal(str(account["journal_balance"])), currency), 9)
    y -= 18
    text(c, left, y, "Cash / bank ledger", 9, color=MUTED)
    right(c, left + 257, y, money(Decimal(str(account["cash_bank_ledger_balance"])), currency), 9)
    text(c, left + 280, y, "Difference", 9, color=MUTED)
    right(c, right_edge, y, money(Decimal(str(account["live_minus_journal"])), currency), 9)

    if not (account["live_balance"] == account["journal_balance"] == account["cash_bank_ledger_balance"]):
        raise ValueError(f"Reconciliation failed for {account['name']}")
    if (account["supplier_payment_cash_mismatches"] or account["customer_receipt_cash_mismatches"]
            or account["transfer_journal_failures"]):
        raise ValueError(f"Document checks failed for {account['name']}")

    y -= 38
    text(c, left, y, "Recent posting context", 10, True, NAVY)
    y -= 20
    if account["name"] == "Cash-SWT-PKR":
        note = (
            "Four supplier payments (SPAY-000255 to SPAY-000258), entered on 11 October "
            "with payment dates from 14 September to 6 October, total PKR 3,800,000. "
            "These entries took the recorded balance below zero. A subsequent PKR 20,000 "
            "receipt and PKR 64,000 refund are included in the closing balance."
        )
    else:
        note = (
            "The latest recorded activity includes an AED 130,000 transfer to Cash-SWT-PKR "
            "(TR-41), an AED 290 transfer to the bank, and three expenses entered on "
            "11 October totalling AED 15,320. These movements are included above."
        )
    y = wrap(c, note, left, y, right_edge - left, size=9, leading=14, color=INK)

    y -= 23
    text(c, left, y, "Basis of statement", 10, True, NAVY)
    y -= 19
    scope = (
        "Prepared from a local copy of the production database. The closing figures agree "
        "with the account balance, journal and cash / bank ledger in that copy. This statement "
        "does not verify physical cash held or establish whether any payment or receipt was "
        "never entered into the system."
    )
    y = wrap(c, scope, left, y, right_edge - left, size=8.5, leading=13)
    if y < 82:
        raise ValueError(f"Content would overlap the footer on page {page}")

    c.setStrokeColor(LINE)
    c.setLineWidth(0.6)
    c.line(left, 59, right_edge, 59)
    text(c, left, 44, "Source snapshot: journal #7474 | receipts #1218 | supplier payments #270 | transfers #41",
         7.5, color=MUTED)
    right(c, right_edge, 44, f"Page {page} of 2", 7.5, color=MUTED)
    c.showPage()


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: generate_cash_account_client_pdf.py OUTPUT.pdf < audit.json")
    audit = json.load(sys.stdin)
    if audit.get("inspection_only") is not True or audit.get("source_high_water_marks") != EXPECTED_MARKS:
        raise ValueError("The audit is not the verified production-copy snapshot")
    if audit.get("unassigned_cash_documents"):
        raise ValueError("Unassigned cash documents require review before issuing the statement")

    accounts = {row["name"]: row for row in audit["accounts"]}
    if set(accounts) != set(EXPECTED_BALANCES):
        raise ValueError("Expected cash accounts are missing")
    path = Path(sys.argv[1])
    path.parent.mkdir(parents=True, exist_ok=True)
    pdf = canvas.Canvas(str(path), pagesize=A4, pageCompression=1)
    pdf.setTitle("Cash account movement summary - 11 October 2026")
    pdf.setAuthor("Accounts review")
    for page, name in enumerate(("Cash-SWT-PKR", "Cash-UAE-AED"), start=1):
        draw_page(pdf, accounts[name], page)
    pdf.save()
    print(path.resolve())


if __name__ == "__main__":
    main()
