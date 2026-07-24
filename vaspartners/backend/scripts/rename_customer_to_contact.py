#!/usr/bin/env python3
"""Rename Customer → Contact across the vaspartners codebase (not DB data)."""

from __future__ import annotations

import re
import shutil
from pathlib import Path

ROOT = Path("/data-disk/applications/vaspartners/vaspartners")

SKIP_DIR_NAMES = {
    "vendor",
    "node_modules",
    ".git",
    "storage",
    ".next",
    "bootstrap",
}

SKIP_FILES = {
    "mvas_catalog.json",
    "rename_customer_to_contact.py",
    "2026_07_24_223000_rename_customers_to_contacts.php",
}

REPLACEMENTS: list[tuple[str, str]] = [
    ("CustomerResource", "ContactResource"),
    ("ListCustomers", "ListContacts"),
    ("ViewCustomer", "ViewContact"),
    ("CreateCustomer", "CreateContact"),
    ("EditCustomer", "EditContact"),
    ("targetCustomer", "targetContact"),
    ("TargetCustomer", "TargetContact"),
    ("customerReviewer", "contactReviewer"),
    ("CustomerReviewer", "ContactReviewer"),
    ("uploadedByCustomer", "uploadedByContact"),
    ("UploadedByCustomer", "UploadedByContact"),
    ("ownerCustomer", "ownerContact"),
    ("OwnerCustomer", "OwnerContact"),
    ("serializeCustomer", "serializeContact"),
    ("createCompanyForCustomer", "createCompanyForContact"),
    ("storeForCustomer", "storeForContact"),
    ("deleteForCustomer", "deleteForContact"),
    ("allowsCustomerEdits", "allowsContactEdits"),
    ("locksCustomerDocuments", "locksContactDocuments"),
    ("locksCustomerChat", "locksContactChat"),
    ("customerDocumentsAreLocked", "contactDocumentsAreLocked"),
    ("linkCustomer", "linkContact"),
    ("unlinkCustomer", "unlinkContact"),
    ("useCustomer", "useContact"),
    ("created_by_customer_id", "created_by_contact_id"),
    ("reviewed_by_customer_id", "reviewed_by_contact_id"),
    ("uploaded_by_customer_id", "uploaded_by_contact_id"),
    ("target_customer_id", "target_contact_id"),
    ("target_customer", "target_contact"),
    ("customer_note", "contact_note"),
    ("customer_can_edit", "contact_can_edit"),
    ("customer_id", "contact_id"),
    ("App\\Models\\Customer", "App\\Models\\Contact"),
    ("App\\\\Models\\\\Customer", "App\\\\Models\\\\Contact"),
    ("Filament\\Resources\\Customers", "Filament\\Resources\\Contacts"),
    ("Resources\\Customers\\", "Resources\\Contacts\\"),
    ("Resources/Customers/", "Resources/Contacts/"),
    ("hooks/use-customer", "hooks/use-contact"),
    ("@/hooks/use-customer", "@/hooks/use-contact"),
    ("class Customer", "class Contact"),
    ("Customer::", "Contact::"),
    ("Customer $", "Contact $"),
    ("Customer|", "Contact|"),
    ("|Customer", "|Contact"),
    ("(Customer", "(Contact"),
    (" Customer ", " Contact "),
    ("'Customer'", "'Contact'"),
    ('"Customer"', '"Contact"'),
    ("customers", "contacts"),
    ("Customers", "Contacts"),
]

WORD_CUSTOMER = re.compile(r"\bcustomer\b")
WORD_CUSTOMER_CAP = re.compile(r"\bCustomer\b")


def should_skip(path: Path) -> bool:
    for part in path.parts:
        if part in SKIP_DIR_NAMES:
            return True
    if path.name in SKIP_FILES:
        return True
    if path.suffix not in {".php", ".ts", ".tsx", ".js", ".jsx", ".json", ".md", ".yml", ".yaml", ".conf", ".example"}:
        return True
    return False


def transform(text: str) -> str:
    for old, new in REPLACEMENTS:
        text = text.replace(old, new)
    text = WORD_CUSTOMER_CAP.sub("Contact", text)
    text = WORD_CUSTOMER.sub("contact", text)
    return text


def main() -> None:
    changed = 0
    for path in sorted(ROOT.rglob("*")):
        if not path.is_file() or should_skip(path):
            continue
        try:
            original = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, IsADirectoryError):
            continue
        updated = transform(original)
        if updated != original:
            path.write_text(updated, encoding="utf-8")
            changed += 1
            print(f"updated: {path.relative_to(ROOT)}")

    backend = ROOT / "backend"
    frontend = ROOT / "frontend"

    # Rename model file
    model_src = backend / "app/Models/Customer.php"
    model_dst = backend / "app/Models/Contact.php"
    if model_src.exists():
        model_src.rename(model_dst)
        print(f"renamed: {model_src.name} -> {model_dst.name}")

    # Rename Filament tree
    cust_dir = backend / "app/Filament/Resources/Customers"
    cont_dir = backend / "app/Filament/Resources/Contacts"
    if cust_dir.exists():
        if cont_dir.exists():
            shutil.rmtree(cont_dir)
        cust_dir.rename(cont_dir)
        for path in sorted(cont_dir.rglob("*"), reverse=True):
            if path.is_file():
                new_name = path.name.replace("Customer", "Contact").replace("Customers", "Contacts")
                if new_name != path.name:
                    path.rename(path.with_name(new_name))
                    print(f"renamed: {new_name}")

    # Rename frontend hook
    hook_src = frontend / "src/hooks/use-customer.ts"
    hook_dst = frontend / "src/hooks/use-contact.ts"
    if hook_src.exists():
        hook_src.rename(hook_dst)
        print(f"renamed: use-customer.ts -> use-contact.ts")

    # Rename migration filenames
    mig = backend / "database/migrations"
    for path in list(mig.glob("*customer*")) + list(mig.glob("*customers*")):
        new = path.with_name(
            path.name.replace("customers", "contacts").replace("customer", "contact")
        )
        if new != path and not new.exists():
            path.rename(new)
            print(f"renamed migration: {new.name}")

    print(f"done. content-updated={changed}")


if __name__ == "__main__":
    main()
