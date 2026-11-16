#!/usr/bin/env python3
"""Compare selected WooCommerce test-fixture files to the official 9.0.0 tag.

Requires the GitHub CLI (`gh`) and network access. This verifies every PHP blob
under includes/ and templates/, plus woocommerce.php and the variation script.
It is NOT verification of vendor/, packages/, src/, or the entire release ZIP.
Usage: python3 tools/verify-wc-fixture.py /path/to/woocommerce
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import subprocess

OFFICIAL = "repos/woocommerce/woocommerce"
TAG = "9.0.0"


def gh_json(endpoint: str):
    return json.loads(subprocess.check_output(["gh", "api", endpoint], text=True))


def blob_sha(path: Path) -> str:
    contents = path.read_bytes()
    return hashlib.sha1(b"blob " + str(len(contents)).encode("ascii") + b"\0" + contents).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("woocommerce", type=Path)
    args = parser.parse_args()
    root = args.woocommerce.resolve()
    official_root = gh_json(f"{OFFICIAL}/contents/plugins/woocommerce?ref={TAG}")
    trees = {entry["name"]: entry["sha"] for entry in official_root if entry["type"] == "dir"}
    details = {}
    mismatches = []
    for directory in ("includes", "templates"):
        data = gh_json(f"{OFFICIAL}/git/trees/{trees[directory]}?recursive=1")
        if data.get("truncated"):
            raise RuntimeError(f"GitHub returned a truncated tree for {directory}")
        php_blobs = [entry for entry in data["tree"] if entry["type"] == "blob" and entry["path"].endswith(".php")]
        matched = 0
        for entry in php_blobs:
            local = root / directory / entry["path"]
            if local.is_file() and blob_sha(local) == entry["sha"]:
                matched += 1
            else:
                mismatches.append(f"{directory}/{entry['path']}")
        details[directory] = {"matched": matched, "total": len(php_blobs), "official_tree": trees[directory]}
    for official, local in (
        ("plugins/woocommerce/woocommerce.php", "woocommerce.php"),
        ("plugins/woocommerce/client/legacy/js/frontend/add-to-cart-variation.js", "assets/js/frontend/add-to-cart-variation.js"),
    ):
        metadata = gh_json(f"{OFFICIAL}/contents/{official}?ref={TAG}")
        file = root / local
        matched = file.is_file() and blob_sha(file) == metadata["sha"]
        details[local] = {"matched": matched, "official_blob": metadata["sha"]}
        if not matched:
            mismatches.append(local)
    print(json.dumps({
        "official_tag": TAG,
        "checked": details,
        "mismatches": mismatches,
        "scope": "Selected PHP and variation JS only; vendor/packages/src and full ZIP not verified.",
    }, ensure_ascii=False, indent=2))
    return 1 if mismatches else 0


if __name__ == "__main__":
    raise SystemExit(main())
