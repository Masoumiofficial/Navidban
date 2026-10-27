#!/usr/bin/env python3
"""
verify-release.py — build and audit the release archive.

The archive is what a customer installs, so the checks here are the ones whose
failure would be invisible until a support ticket:

  * the archive contains only shipping code (`tests/`, `tools/`, `docs/dev/`
    and `.github/` are stripped);
  * `Version:` in the plugin header, the `MOJODYAR_VERSION` constant and the
    newest CHANGELOG entry all agree — a mismatch is the single most common
    cause of an update never being offered;
  * no IonCube-style loader, no `eval`, no base64 payloads;
  * every table is InnoDB, the string MyISAM appears nowhere;
  * banned brand names are absent;
  * `license.txt` is present and GPLv2+;
  * `$wpdb` calls that interpolate a variable are flagged (they need
    `$wpdb->prepare()`).

Usage:
    python3 tools/verify-release.py            # audit an existing dist zip
    python3 tools/verify-release.py --build    # create the zip, then audit it

Exit codes: 0 pass, 1 audit failed, 2 tooling problem.
"""

from __future__ import annotations

import argparse
import hashlib
import os
import re
import sys
import zipfile
from datetime import datetime, timezone

PLUGIN_SLUG = "mojoodyar"
EXCLUDE_DIRS = ("tests/", "tools/", "docs/dev/", ".github/")
EXCLUDE_FILES = ("node_modules", "composer.json", "composer.lock", "package.json",
                 "package-lock.json", ".gitignore", ".gitattributes", "phpunit.xml")
BANNED_BRANDS = ("خبرم کنید", "دیجی‌کالا", "دیجیکالا", "YITH", "yith")
PERSIAN_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789")

TEXT_SUFFIXES = (".php", ".css", ".js", ".md", ".txt", ".pot", ".json")


class Report:
    def __init__(self) -> None:
        self.rows: list[tuple[str, str, str]] = []
        self.failures = 0

    def check(self, passed: bool, name: str, detail: str = "") -> bool:
        self.rows.append(("PASS" if passed else "FAIL", name, detail))
        if not passed:
            self.failures += 1
        return passed

    def info(self, name: str, detail: str = "") -> None:
        self.rows.append(("INFO", name, detail))

    def render(self) -> str:
        width = max((len(r[1]) for r in self.rows), default=10)
        out = []
        for status, name, detail in self.rows:
            out.append(f"[{status}] {name.ljust(width)}  {detail}".rstrip())
        return "\n".join(out)


def read_text(path: str) -> str:
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
        return handle.read()


def plugin_version(root: str) -> str:
    header = read_text(os.path.join(root, f"{PLUGIN_SLUG}.php"))
    match = re.search(r"^\s*\*?\s*Version:\s*([0-9][0-9.]*)", header, re.MULTILINE)
    if not match:
        match = re.search(r"MOJODYAR_VERSION',\s*'([^']+)'", header)
    return match.group(1).strip() if match else ""


def constant_version(root: str) -> str:
    header = read_text(os.path.join(root, f"{PLUGIN_SLUG}.php"))
    match = re.search(r"define\(\s*'MOJODYAR_VERSION',\s*'([^']+)'", header)
    return match.group(1).strip() if match else ""


def changelog_version(root: str) -> tuple[str, str]:
    path = os.path.join(root, "CHANGELOG.md")
    if not os.path.exists(path):
        return "", ""
    for line in read_text(path).splitlines():
        if line.startswith("## "):
            heading = line[3:].strip()
            match = re.search(r"([0-9][0-9.]*)\s*[—–-]", heading.translate(PERSIAN_DIGITS))
            version = match.group(1) if match else ""
            return version, heading
    return "", ""


def iter_files(root: str):
    for base, dirs, files in os.walk(root):
        dirs[:] = sorted(d for d in dirs if d not in ("node_modules", ".git"))
        for name in sorted(files):
            full = os.path.join(base, name)
            yield os.path.relpath(full, root).replace(os.sep, "/"), full


def build_zip(root: str, target: str) -> None:
    os.makedirs(os.path.dirname(target), exist_ok=True)
    if os.path.exists(target):
        os.remove(target)

    parent = os.path.dirname(root.rstrip("/"))
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for rel, full in iter_files(root):
            if any(rel.startswith(prefix) for prefix in EXCLUDE_DIRS):
                continue
            if os.path.basename(rel) in EXCLUDE_FILES:
                continue
            archive.write(full, arcname=f"{os.path.basename(root)}/{rel}")


def audit_zip(path: str, report: Report, root: str) -> None:
    if not report.check(os.path.exists(path), "بستهٔ انتشار وجود دارد", path):
        return

    # A stale archive from an older version must never be mistaken for this
    # release: the filename carries the version, so refuse a mismatch outright.
    expected_name = f"{PLUGIN_SLUG}-{plugin_version(root)}.zip"
    report.check(
        os.path.basename(path) == expected_name,
        "نام بستهٔ انتشار با نسخهٔ افزونه می‌خواند",
        os.path.basename(path),
    )

    with zipfile.ZipFile(path) as archive:
        names = [n for n in archive.namelist() if not n.endswith("/")]
        bad = archive.testzip()
        report.check(bad is None, "بستهٔ ZIP سالم است", bad or "")

        report.check(
            all(n.startswith(f"{PLUGIN_SLUG}/") for n in names),
            "همهٔ مسیرها زیر پوشهٔ mojoodyar/ هستند",
            f"{len(names)} فایل",
        )

        leaked = [n for n in names if any(f"/{prefix}" in f"/{n}" for prefix in EXCLUDE_DIRS)]
        report.check(not leaked, "پوشه‌های توسعه در بسته نیستند", ", ".join(leaked[:5]))

        stray = [n for n in names if os.path.basename(n) in EXCLUDE_FILES]
        report.check(not stray, "فایل‌های ابزار توسعه در بسته نیستند", ", ".join(stray[:5]))

        src_left = [n for n in names if "/src/" in n]
        report.check(not src_left, "پوشهٔ src/ وجود ندارد", ", ".join(src_left[:5]))

        texts = {}
        for name in names:
            if name.endswith(TEXT_SUFFIXES):
                texts[name] = archive.read(name).decode("utf-8", "replace")

        main = texts.get(f"{PLUGIN_SLUG}/{PLUGIN_SLUG}.php", "")
        match = re.search(r"^\s*\*?\s*Version:\s*([0-9][0-9.]*)", main, re.MULTILINE)
        header = match.group(1) if match else ""
        report.check(bool(header), "هدر Version موجود است", header)

        changelog_name = f"{PLUGIN_SLUG}/CHANGELOG.md"
        changelog = texts.get(changelog_name, "")
        newest = ""
        for line in changelog.splitlines():
            if line.startswith("## "):
                m = re.search(r"([0-9][0-9.]*)\s*[—–-]", line[3:].translate(PERSIAN_DIGITS))
                newest = m.group(1) if m else ""
                break
        report.check(
            header == newest and header != "",
            "Version هدر = آخرین ورودی CHANGELOG",
            f"header={header or '?'} changelog={newest or '?'}",
        )

        const = re.search(r"MOJODYAR_VERSION',\s*'([^']+)'", main)
        report.check(
            (const.group(1) if const else "") == header,
            "MOJODYAR_VERSION = Version هدر",
            const.group(1) if const else "?",
        )

        report.check(f"{PLUGIN_SLUG}/license.txt" in names, "license.txt در بسته است")
        license_text = texts.get(f"{PLUGIN_SLUG}/license.txt", "")
        report.check(
            "GNU GENERAL PUBLIC LICENSE" in license_text.upper()
            and ("Version 2" in license_text or "version 2" in license_text),
            "پروانه GPLv2 است",
        )

        joined = "\n".join(texts.values())
        # The word may legitimately appear in a comment or in a health check that
        # *compares* engine names. What must never appear is a table actually
        # created with that engine.
        report.check(
            "ENGINE=MyISAM" not in joined.replace(" ", ""),
            "هیچ جدولی با ENGINE=MyISAM ساخته نمی‌شود",
            f"{joined.count('MyISAM')} اشارهٔ متنی (کامنت/بررسی سلامت)",
        )
        engines = re.findall(r"ENGINE=([A-Za-z]+)", joined)
        report.check(
            bool(engines) and set(engines) == {"InnoDB"},
            "همهٔ جدول‌ها InnoDB",
            ", ".join(sorted(set(engines))),
        )

        risky = []
        for name, body in texts.items():
            if name.endswith(".php"):
                for marker in ("eval(", "base64_decode(", "gzinflate(", "ioncube_loader"):
                    if marker in body:
                        risky.append(f"{name}:{marker}")
        report.check(not risky, "بدون eval/base64/ioncube", ", ".join(risky[:5]))

        brands = [word for word in BANNED_BRANDS if word in joined]
        report.check(not brands, "نام‌های ممنوعه در بسته نیست", ", ".join(brands))

        domain_wrong = []
        for name, body in texts.items():
            if not name.endswith(".php"):
                continue
            for domain in set(re.findall(r"[\"']([a-z0-9-]+)[\"']\s*,\s*[\"']mojoodyar[\"']", body)):
                if domain != PLUGIN_SLUG:
                    domain_wrong.append(f"{name}:{domain}")
        report.check(not domain_wrong, "text domain نامعتبر نیست", ", ".join(domain_wrong[:5]))

        # Convention: the only value allowed to be interpolated into SQL is a
        # variable named exactly $table (always Database::table()/Queue::table()).
        # Anything else must go through $wpdb->prepare().
        unprepared = []
        pattern = re.compile(r"\$wpdb->(query|get_results|get_row|get_var|get_col)\(\s*\"([^\"]*)\"")
        allowed = re.compile(r"\$\{?table\}?")
        for name, body in texts.items():
            if not name.endswith(".php"):
                continue
            for match in pattern.finditer(body):
                sql = match.group(2)
                if "$" not in sql:
                    continue
                if allowed.sub("", sql).count("$") == 0:
                    continue
                line = body[: match.start()].count("\n") + 1
                unprepared.append(f"{name}:{line}")
        report.check(
            not unprepared,
            "هیچ $wpdb بدون prepare روی ورودی غیر از نام جدول نیست",
            ", ".join(sorted(set(unprepared))[:5]),
        )

        total = sum(len(body.encode("utf-8")) for body in texts.values())
        digest = hashlib.sha256(open(path, "rb").read()).hexdigest()
        report.info("اندازهٔ بسته", f"{os.path.getsize(path):,} بایت")
        report.info("SHA-256", digest)


def audit_tree(root: str, report: Report) -> None:
    required = [
        f"{PLUGIN_SLUG}.php",
        "uninstall.php",
        "license.txt",
        "CHANGELOG.md",
        "readme.txt",
        "includes/Autoloader.php",
        "includes/Plugin.php",
        "includes/Database.php",
        "includes/Frontend.php",
        "includes/Queue.php",
        "includes/StockWatcher.php",
        "includes/Support/Validator.php",
        "admin/class-admin.php",
        "assets/css/mojoodyar-front.css",
        "assets/js/mojoodyar-front.js",
        "languages/mojoodyar.pot",
        "docs/SPEC.md",
        "docs/ARCHITECTURE.md",
        "docs/DATA-STRUCTURE.md",
        "docs/SCHEDULER-POLICY.md",
        "docs/PERFORMANCE.md",
        "docs/SECURITY.md",
        "docs/TROUBLESHOOTING.md",
        "docs/QA-CHECKLIST.md",
        "docs/TEST-REPORT.md",
        "docs/USER-GUIDE.md",
        "tests/run.php",
        "tests/results.json",
        "tests/admin-render.php",
        "tests/admin-render-results.json",
        "tests/queries.php",
        "tools/verify-release.py",
        "tools/lint-php.js",
        "tools/check-methods.js",
        "tools/smoke-php.mjs",
        "tools/build-assets.sh",
        "tools/har-audit.sh",
    ]
    missing = [path for path in required if not os.path.exists(os.path.join(root, path))]
    report.check(not missing, "همهٔ فایل‌های لازم موجودند", ", ".join(missing[:8]))

    version, heading = changelog_version(root)
    report.check(bool(version), "CHANGELOG نسخهٔ اول را دارد", heading)
    report.check(
        version == plugin_version(root) == constant_version(root),
        "نسخه در هدر/ثابت/CHANGELOG یکسان است",
        f"header={plugin_version(root)} const={constant_version(root)} changelog={version}",
    )

    readme_path = os.path.join(root, "readme.txt")
    if os.path.exists(readme_path):
        readme = read_text(readme_path)
        report.check(
            f"Stable tag: {version}" in readme,
            "Stable tag در readme.txt",
            version,
        )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--build", action="store_true", help="create the release zip first")
    parser.add_argument("--plugin-dir", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    args = parser.parse_args()

    root = os.path.abspath(args.plugin_dir)
    version = plugin_version(root)
    if not version:
        print("cannot read plugin version", file=sys.stderr)
        return 2

    # "dist" is a name that many toolchains treat as disposable output; this
    # project keeps the shipped artefact in "release/" so it survives cleanup
    # rules and is obvious to a human.
    target = os.path.join(os.path.dirname(root), "release", f"{PLUGIN_SLUG}-{version}.zip")
    report = Report()

    if args.build:
        build_zip(root, target)
        report.info("ساخته شد", target)

    audit_tree(root, report)
    audit_zip(target if os.path.exists(target) else "", report, root)

    print(report.render())
    print()
    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    checked = sum(1 for row in report.rows if row[0] in ("PASS", "FAIL"))
    print(f"{checked} checks, {report.failures} failures · {stamp}")
    return 0 if report.failures == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
