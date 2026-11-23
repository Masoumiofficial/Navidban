#!/usr/bin/env python3
"""Exercise the release ZIP's literal-email privacy gate (stdlib only)."""

import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("release_audit", ROOT / "tools" / "verify-release.py")
AUDIT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(AUDIT)
GATE = "ایمیل ثابتِ بررسی‌نشده در متن بسته نیست"


class ReleaseEmailAuditTest(unittest.TestCase):
    def test_reserved_examples_and_pot_placeholder_pass(self):
        texts = {
            "mojoodyar/admin/example.php": (
                "one@example.com two@store.example.org three@shop.example four@example.test"
            ),
            "mojoodyar/languages/mojoodyar.pot": "Language-Team: LANGUAGE <LL@li.org>",
        }
        self.assertEqual([], AUDIT.unreviewed_email_literals(texts))

    def test_unreviewed_literal_fails_without_echoing_address(self):
        texts = {"mojoodyar/readme.txt": "line one\nowner@private-store.invalid\n"}
        self.assertEqual(["mojoodyar/readme.txt:2"], AUDIT.unreviewed_email_literals(texts))

    def test_pot_exception_does_not_allow_same_address_in_code(self):
        texts = {"mojoodyar/admin/example.php": "LL@li.org"}
        self.assertEqual(["mojoodyar/admin/example.php:1"], AUDIT.unreviewed_email_literals(texts))

    def test_zip_gate_rejects_and_then_accepts_replacement(self):
        with tempfile.TemporaryDirectory() as directory:
            zip_path = os.path.join(directory, f"mojoodyar-{AUDIT.plugin_version(str(ROOT))}.zip")
            for address, expected in (("owner@private-store.invalid", "FAIL"), ("owner@example.com", "PASS")):
                with zipfile.ZipFile(zip_path, "w") as archive:
                    archive.writestr("mojoodyar/readme.txt", f"Contact: {address}")
                report = AUDIT.Report()
                AUDIT.audit_zip(zip_path, report, str(ROOT))
                rows = [row for row in report.rows if row[1] == GATE]
                self.assertEqual(1, len(rows))
                self.assertEqual(expected, rows[0][0])
                self.assertNotIn(address, rows[0][2])


if __name__ == "__main__":
    unittest.main()
