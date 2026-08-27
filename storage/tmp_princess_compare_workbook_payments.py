from __future__ import annotations

import json
import re
from collections import Counter
from pathlib import Path

import pandas as pd


BASE = Path(r"C:\xampp\htdocs\Travel-Agency")
WORKBOOK = Path(r"C:\Users\Laptop Valley\Downloads\ledgers_NOBLE_ROUTE_TRAVEL_&_TOURS_L.L.C_2026-08-12.xlsx")
SYSTEM_JSON = BASE / "storage" / "tmp_princess_system_payments.json"


def normalize_text(value: object) -> str:
    if value is None:
        return ""
    return " ".join(str(value).strip().split())


def normalize_money(value: object) -> float:
    if value is None:
        return 0.0
    if isinstance(value, (int, float)):
        return round(float(value), 2)
    text = normalize_text(value)
    if not text:
        return 0.0
    cleaned = re.sub(r"[^0-9.\-]", "", text)
    if cleaned in {"", "-", ".", "-."}:
        return 0.0
    return round(float(cleaned), 2)


def main() -> None:
    workbook_df = pd.read_excel(WORKBOOK)
    workbook_df["Created At"] = pd.to_datetime(workbook_df["Created At"], errors="coerce")
    workbook_df["Description"] = workbook_df["Description"].map(normalize_text)
    workbook_df["CreditAmount"] = workbook_df["Credit"].map(normalize_money)

    payment_rows = workbook_df[
        (workbook_df["Description"] == "Balance request accepted")
        & (workbook_df["CreditAmount"] > 0)
    ].copy()
    payment_rows["date"] = payment_rows["Created At"].dt.strftime("%Y-%m-%d")
    payment_rows["amount"] = payment_rows["CreditAmount"].round(2)
    payment_rows["remarks_norm"] = payment_rows["Remarks"].map(normalize_text)
    payment_rows["ref_norm"] = payment_rows["Ref Number"].map(normalize_text)

    system_payload = json.loads(SYSTEM_JSON.read_text(encoding="utf-8"))
    system_rows = system_payload["rows"]

    system_counter = Counter()
    system_detailed: dict[tuple[str, float], list[dict[str, object]]] = {}
    for row in system_rows:
        key = (str(row.get("payment_date") or ""), round(float(row.get("paid_amount") or 0), 2))
        system_counter[key] += 1
        system_detailed.setdefault(key, []).append(row)

    workbook_counter = Counter()
    workbook_detailed: dict[tuple[str, float], list[dict[str, object]]] = {}
    for _, row in payment_rows.iterrows():
        key = (str(row["date"]), round(float(row["amount"]), 2))
        workbook_counter[key] += 1
        workbook_detailed.setdefault(key, []).append(
            {
                "created_at": row["Created At"].isoformat() if pd.notna(row["Created At"]) else "",
                "date": row["date"],
                "amount": round(float(row["amount"]), 2),
                "remarks": normalize_text(row.get("Remarks")),
                "ref_number": normalize_text(row.get("Ref Number")),
                "book_id": normalize_text(row.get("Book Id")),
            }
        )

    unmatched_workbook = []
    for key, count in sorted(workbook_counter.items()):
        system_count = system_counter.get(key, 0)
        if count > system_count:
            unmatched_workbook.append(
                {
                    "key": {"date": key[0], "amount": key[1]},
                    "workbook_count": count,
                    "system_count": system_count,
                    "missing_count": count - system_count,
                    "workbook_rows": workbook_detailed.get(key, []),
                    "system_rows": system_detailed.get(key, []),
                }
            )

    unmatched_system = []
    for key, count in sorted(system_counter.items()):
        workbook_count = workbook_counter.get(key, 0)
        if count > workbook_count:
            unmatched_system.append(
                {
                    "key": {"date": key[0], "amount": key[1]},
                    "system_count": count,
                    "workbook_count": workbook_count,
                    "extra_count": count - workbook_count,
                    "system_rows": system_detailed.get(key, []),
                    "workbook_rows": workbook_detailed.get(key, []),
                }
            )

    print("WORKBOOK_PAYMENT_TOTAL", round(payment_rows["amount"].sum(), 2))
    print("SYSTEM_PAYMENT_TOTAL", round(sum(float(row.get("paid_amount") or 0) for row in system_rows), 2))
    print("UNMATCHED_WORKBOOK_KEYS", len(unmatched_workbook))
    for item in unmatched_workbook:
        print(json.dumps({"missing_in_system": item}, ensure_ascii=False))
    print("UNMATCHED_SYSTEM_KEYS", len(unmatched_system))
    for item in unmatched_system:
        print(json.dumps({"extra_in_system": item}, ensure_ascii=False))


if __name__ == "__main__":
    main()
