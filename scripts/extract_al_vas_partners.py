#!/usr/bin/env python3
"""Extract Al VAS revenue partner master list (xlsx) → JSON for Laravel import."""

from __future__ import annotations

import json
import sys
from pathlib import Path

import openpyxl

DEFAULT_XLSX = (
    Path(__file__).resolve().parents[1]
    / "vaspartners"
    / "backend"
    / "database"
    / "data"
    / "Al vas data-august 31-2026.xlsx"
)


def norm(value) -> str | None:
    if value is None:
        return None
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    text = str(value).strip()
    return text if text else None


def main() -> None:
    xlsx = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_XLSX
    out = Path(sys.argv[2]) if len(sys.argv) > 2 else xlsx.with_suffix(".json")

    if not xlsx.is_file():
        raise SystemExit(f"Excel not found: {xlsx}")

    wb = openpyxl.load_workbook(xlsx, read_only=True, data_only=True)
    ws = wb.active
    partners: list[dict[str, str | None]] = []

    for index, row in enumerate(ws.iter_rows(values_only=True)):
        if index == 0:
            continue
        service_id = norm(row[0] if len(row) > 0 else None)
        if not service_id:
            continue
        partners.append(
            {
                "service_id": service_id,
                "partner_name": norm(row[1] if len(row) > 1 else None),
                "service_type": norm(row[2] if len(row) > 2 else None),
                "phone": norm(row[3] if len(row) > 3 else None),
                "account_manager": norm(row[4] if len(row) > 4 else None),
            }
        )

    wb.close()

    payload = {
        "meta": {
            "source_file": xlsx.name,
            "partner_count": len(partners),
        },
        "partners": partners,
    }

    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(payload, indent=2) + "\n")
    print(json.dumps(payload["meta"], indent=2))
    print(f"Wrote {out}")


if __name__ == "__main__":
    main()
