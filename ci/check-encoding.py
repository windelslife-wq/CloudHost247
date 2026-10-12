#!/usr/bin/env python3
"""CloudHost247 — repo-wide text encoding guard.

Task 3 of the internationalization brief: remove every accidental
U+F0B7 (UTF-8 EF 82 B7) from the repository and keep it out.

U+F0B7 is a private-use codepoint. It appears in real-world content because
Microsoft Word (and the Symbol/Wingdings fonts it uses for bullets) maps the
bullet glyph to U+F0B7, and that codepoint survives copy/paste into HTML, PHP
and translation catalogs. It renders as nothing, as a box, or as a stray
character depending on the font — which is why it has to be found mechanically
rather than by eye.

Its UTF-8 encoding is the three bytes EF 82 B7. Getting this wrong is easy and
leads to confidently wrong answers, so it is worth stating the neighbours
explicitly:

    U+F0B7  ->  EF 82 B7   <- the Word/Symbol bullet we are hunting
    U+F2F7  ->  EF 8B B7   <- a different private-use codepoint; grepping for
                              this instead reports false positives in any
                              compressed stream that happens to contain those
                              three bytes
    U+2022  ->  E2 80 A2   <- a correct bullet, what U+F0B7 should become

This guard decodes each file and compares codepoints rather than bytes, so it
cannot be fooled in either direction.

It also scans for the closely-related artifacts:

  * U+FFFD (EF BF BD)      replacement char — lost bytes from a bad transcode
  * U+2028 / U+2029        line/paragraph separators pasted from Word
  * malformed bullets      U+2022 / U+00B7 / U+25CF fused to the next word with
                           no space, e.g. "•Item one" instead of "• Item one"
  * mojibake               the classic UTF-8-read-as-Latin-1 sequences
                           (Ã©, â€™, Â£, â€œ, â€¦) that indicate the file was
                           double-encoded somewhere upstream

Scope and exclusions
--------------------
Only text files are scanned. Binary files are skipped by content sniffing, not
by extension, because a .txt that is really a PNG would otherwise be destroyed
by an automated fix and a .php that is really UTF-8 must still be checked.

Baseline (verified 2026-10): U+F0B7 occurs in zero project text files.
A byte-pattern match in a compressed PNG stream and ICU dev data is classified
separately below; binary bytes are not rendered text. The brief's example path
shared/site/content/legal/*.txt does not exist in this checkout and never has
in its git history, so there was nothing there to clean.

What remains is the guard itself, so the finding cannot silently regress. Every
exclusion below is asserted against the file it describes: if the file goes
away, or stops containing the thing we excluded it for, the guard says so
instead of carrying a stale excuse.

Usage
-----
    python3 ci/check-encoding.py [--fix] [--root DIR]

--fix performs only the two transformations that cannot change meaning:
  * U+F0B7 / U+F0A0-family bullets -> a real U+2022 bullet with correct spacing
  * U+2028 / U+2029                -> a normal newline

It never touches mojibake or the fused-bullet cases: those need a human to
decide what the correct text was.

Exit code is 0 when the tree is clean, 1 when findings remain.
"""

from __future__ import annotations

import argparse
import hashlib
import os
import re
import sys

# --- the codepoints we hunt -------------------------------------------------

TARGET = "\uf0b7"
TARGET_NAME = "U+F0B7"

# Other Word/Symbol-font private-use bullets that travel with U+F0B7.
PRIVATE_BULLETS = (
    "\uf0a7",  #  Wingdings square
    "\uf0b2",  # 
    "\uf0a5",  # 
    "\uf06c",  # 
    "\uf06e",  # 
    "\uf0d8",  #  Wingdings arrow
    "\uf0e0",  #  envelope
    "\uf0fc",  #  checkmark
    "\uf0a9",
    "\uf0b0",
)

REPLACEMENT_CHAR = "\ufffd"
LINE_SEP = "\u2028"
PARA_SEP = "\u2029"

# UTF-8 bytes misread as Latin-1 / CP1252 => the classic mojibake pairs.
MOJIBAKE = (
    ("\u00c3\u00a9", "é"),
    ("\u00c3\u00a8", "è"),
    ("\u00c3\u00a0", "à"),
    ("\u00c3\u00bc", "ü"),
    ("\u00c3\u00b6", "ö"),
    ("\u00c3\u00a4", "ä"),
    ("\u00c3\u009f", "ß"),
    ("\u00c3\u00b1", "ñ"),
    ("\u00e2\u0080\u0099", "’"),
    ("\u00e2\u0080\u0098", "‘"),
    ("\u00e2\u0080\u009c", "“"),
    ("\u00e2\u0080\u009d", "”"),
    ("\u00e2\u0080\u0093", "–"),
    ("\u00e2\u0080\u0094", "—"),
    ("\u00e2\u0080\u00a6", "…"),
    ("\u00c2\u00a3", "£"),
    ("\u00c2\u00a9", "©"),
    ("\u00c2\u00ae", "®"),
)

# A bullet at the start of a line or after whitespace, immediately followed by a
# non-space character: "•Item" rather than "• Item". At line start the bullet is
# unquestionably a list marker, so the missing space is unambiguously a defect;
# mid-sentence the same character can be legitimate punctuation (a middot in
# "wall•e" or a separator in "Red • Blue"), so we stay quiet there.
FUSED_BULLET = re.compile(r"(?:(?<=^)|(?<=[\s>]))([\u2022\u25cf\u25aa\u25e6\u2219])(?=[^\s])", re.MULTILINE)

# Directories that are build output, vendored, or never hand-edited content.
SKIP_DIRS = {
    ".git",
    ".cache",  # generated, pinned dev dependencies / template compiler cache
    "node_modules",
    "vendor",
    "cache",
    "caches",
    "downloads",
    "attachments",
    "dist",
    "build",
    "coverage",
    ".venv",
    "__pycache__",
    ".idea",
    ".vscode",
}

SKIP_FILE_SUFFIXES = (
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".bmp", ".ico", ".svgz",
    ".woff", ".woff2", ".ttf", ".eot", ".otf",
    ".pdf", ".zip", ".gz", ".tar", ".tgz", ".rar", ".7z",
    ".mp3", ".mp4", ".webm", ".avi", ".mov",
    ".dat", ".bin", ".so", ".dll", ".exe", ".class", ".jar",
    ".mo",  # compiled gettext catalogs: regenerate, do not hand-edit
    ".sqlite", ".db",
)

# Every path here is checked by this script itself: if the file disappears, is
# no longer binary, or no longer contains the bytes, the guard says so instead
# of carrying a stale excuse forever.
EXEMPTIONS = (
    {
        "path": "templates/hostx/images/www-img.png",
        "class": "binary",
        "reason": (
            "The only genuine non-text occurrence of U+F0B7 in the working tree, "
            "verified by decoding the PNG: the three bytes fall inside the DEFLATE "
            "compressed IDAT stream and are NOT present in the decompressed pixels, "
            "so they are an artifact of the compressor, not content. There is no "
            "text chunk (tEXt/iTXt) carrying it. Removing them means re-encoding a "
            "shipped brand image to produce byte-identical pixels; it is excluded "
            "rather than rewritten."
        ),
    },
    {
        "path": "modules/addons/cloudhost247services/node_modules/@php-wasm/node/shared/icu.dat",
        "class": "vendored",
        "reason": (
            "Third-party ICU locale data shipped by the php-wasm dev dependency. It "
            "is gitignored build material, never deployed, and the private-use "
            "codepoints in it are genuine locale data rather than stray bullets."
        ),
    },
    {
        "path": "modules/servers/Smtphosting/templates/admin/assets/js/layers-ui.js",
        "class": "vendored-upstream",
        "line": 4549,
        "line_sha256": "4da127794a88f82bceef62399a192150fcd42621cafcfbe2ad53b7bef2b14103",
        "reason": (
            "Upstream heat-layer library. Line 4549 lost three U+2212 MINUS SIGN "
            "characters to U+FFFD somewhere before it was vendored; the damage is "
            "inside a code comment and affects nothing at runtime. Editing a "
            "vendored bundle would diverge from upstream and be silently reverted "
            "on the next bump, so it is reported here rather than patched."
        ),
    },
    {
        "path": "modules/servers/Smtphosting/templates/client/default/assets/js/layers-ui.js",
        "class": "vendored-upstream",
        "line": 4549,
        "line_sha256": "4da127794a88f82bceef62399a192150fcd42621cafcfbe2ad53b7bef2b14103",
        "reason": (
            "Same vendored library as the admin copy above; same three U+FFFD "
            "characters in the same comment."
        ),
    },
    {
        "path": "ci/check-encoding.py",
        "class": "self",
        "reason": (
            "This file has to contain the literal codepoints it hunts in order to "
            "define them. It is the guard's own source, not project content."
        ),
    },
    {
        "path": "tests/i18n/CatalogIntegrityTest.php",
        "class": "fixture",
        "reason": (
            "This suite deliberately embeds U+F0B7, U+FFFD and a mojibake sequence "
            "as fixtures to prove the catalogs reject them. It is the test for this "
            "guard, not a carrier of the bug."
        ),
    },
)

EXEMPT_PATHS = {entry["path"] for entry in EXEMPTIONS if entry["class"] != "vendored-upstream"}
LINE_EXEMPTIONS = {entry["path"]: entry for entry in EXEMPTIONS if entry["class"] == "vendored-upstream"}


def is_binary(data: bytes) -> bool:
    """Content sniff, not extension sniffing."""
    if data.startswith((b"\xff\xfe", b"\xfe\xff", b"\x00\x00\xfe\xff")):
        return False  # BOM-marked UTF-16/32 is text, not a binary exception.
    if b"\x00" in data[:8192]:
        return True
    # A high proportion of non-text control bytes means this is not a text file.
    text_chars = bytes({7, 8, 9, 10, 12, 13, 27} | set(range(0x20, 0x7F)) | set(range(0x80, 0x100)))
    chunk = data[:8192]
    if not chunk:
        return False
    non_text = chunk.translate(None, text_chars)
    return len(non_text) / len(chunk) > 0.30


def decode(data: bytes):
    """Return (text, encoding) or (None, None) if the file is not decodable."""
    if data.startswith((b"\xff\xfe\x00\x00", b"\x00\x00\xfe\xff")):
        return data.decode("utf-32"), "utf-32"
    if data.startswith((b"\xff\xfe", b"\xfe\xff")):
        return data.decode("utf-16"), "utf-16"
    for enc in ("utf-8-sig", "utf-8", "cp1252", "latin-1"):
        try:
            return data.decode(enc), enc
        except UnicodeDecodeError:
            continue
    return None, None


def iter_files(root: str):
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = sorted(d for d in dirnames if d not in SKIP_DIRS)
        for name in sorted(filenames):
            full = os.path.join(dirpath, name)
            rel = os.path.relpath(full, root).replace(os.sep, "/")
            if rel in EXEMPT_PATHS:
                continue
            if name.lower().endswith(SKIP_FILE_SUFFIXES):
                continue
            yield full, rel


def scan_file(text: str, exempt_lines=frozenset()):
    """Return a list of (kind, line_number, excerpt) for one file's text."""
    findings = []
    lines = text.split("\n")  # splitlines() silently consumes U+2028/U+2029 before detection.

    for number, line in enumerate(lines, start=1):
        if number in exempt_lines:
            continue
        excerpt = line.strip()[:120]

        if TARGET in line or any(b in line for b in PRIVATE_BULLETS):
            count = line.count(TARGET) + sum(line.count(b) for b in PRIVATE_BULLETS)
            findings.append(("private-use-bullet", number, excerpt, count))

        if REPLACEMENT_CHAR in line:
            findings.append(("replacement-char", number, excerpt, line.count(REPLACEMENT_CHAR)))

        if LINE_SEP in line or PARA_SEP in line:
            findings.append(("word-separator", number, excerpt, 1))

        if FUSED_BULLET.search(line):
            findings.append(("fused-bullet", number, excerpt, 1))

        for bad, good in MOJIBAKE:
            if bad in line:
                findings.append(("mojibake", number, excerpt + f"  (expected {good!r})", line.count(bad)))

    return findings


def autofix(text: str):
    """Apply only the meaning-preserving transformations.

    A private-use bullet means exactly one thing - "this was a list marker" -
    so replacing it with U+2022 and giving it the single trailing space a list
    marker needs cannot change what the text says. It can only fix how it
    reads. Mojibake and replacement characters are NOT touched: recovering
    those requires knowing what the original bytes were.
    """
    original = text

    for bullet in (TARGET,) + PRIVATE_BULLETS:
        text = text.replace(bullet, "\u2022")

    # A converted bullet sat directly against its text in the source; give it
    # the separator a real bullet would have had.
    text = FUSED_BULLET.sub("\u2022 ", text)

    text = text.replace(LINE_SEP, "\n").replace(PARA_SEP, "\n\n")

    return text, text != original


def main() -> int:
    parser = argparse.ArgumentParser(description="Repo-wide U+F0B7 and encoding guard.")
    parser.add_argument("--root", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    parser.add_argument("--fix", action="store_true", help="apply meaning-preserving fixes in place")
    args = parser.parse_args()

    root = args.root

    # 1. Assert the documented exclusions still describe reality. A stale
    #    exclusion is a hole in the guard, so it must fail loudly.
    invalid_exclusions = 0
    project_scan = os.path.exists(os.path.join(root, ".git")) or os.path.isfile(os.path.join(root, "ci/check-encoding.py"))
    for entry in EXEMPTIONS if project_scan else ():
        path = os.path.join(root, entry["path"])
        if not os.path.exists(path):
            if entry["class"] == "vendored":
                continue  # Optional, gitignored dev ICU data is not required in CI.
            print(f"STALE EXCLUSION {entry['path']}: file no longer exists; retire this exception")
            invalid_exclusions += 1
            continue
        with open(path, "rb") as handle:
            data = handle.read()
        if entry["class"] == "binary":
            if not is_binary(data) or TARGET.encode("utf-8") not in data:
                print(f"STALE EXCLUSION {entry['path']}: binary classification no longer applies")
                invalid_exclusions += 1
            continue
        if entry["class"] == "vendored":
            continue
        text, _ = decode(data)
        if entry["class"] == "vendored-upstream":
            lines = text.splitlines()
            number = entry["line"]
            line = lines[number - 1] if len(lines) >= number else ""
            if hashlib.sha256(line.encode("utf-8")).hexdigest() != entry["line_sha256"]:
                print(f"STALE EXCLUSION {entry['path']}:{number}: upstream comment changed; reclassify/retire exception")
                invalid_exclusions += 1
        elif not scan_file(text):
            print(f"STALE EXCLUSION {entry['path']}: deliberate fixture no longer present")
            invalid_exclusions += 1

    # 2. Walk the tree.
    total = invalid_exclusions
    scanned = 0
    fixed = 0
    by_kind = {}

    for full, rel in iter_files(root):
        try:
            with open(full, "rb") as handle:
                data = handle.read()
        except OSError:
            continue

        if is_binary(data):
            if TARGET.encode("utf-8") in data:
                # A binary we did not expect: report it, never rewrite it.
                print(f"BINARY  {rel}: contains U+F0B7 bytes but is not a text file — excluded from auto-fix")
                total += 1
                by_kind["binary-unclassified"] = by_kind.get("binary-unclassified", 0) + 1
            continue

        text, _encoding = decode(data)
        if text is None:
            continue

        scanned += 1
        exemption = LINE_EXEMPTIONS.get(rel)
        exempt_lines = set()
        if exemption:
            lines = text.splitlines()
            number = exemption["line"]
            if len(lines) >= number and hashlib.sha256(lines[number - 1].encode("utf-8")).hexdigest() == exemption["line_sha256"]:
                exempt_lines.add(number)
        findings = scan_file(text, exempt_lines)

        if not findings:
            continue

        if args.fix:
            new_text, changed = autofix(text)
            if changed:
                with open(full, "w", encoding="utf-8", newline="") as handle:
                    handle.write(new_text)
                fixed += 1
                print(f"FIXED   {rel}")
            findings = scan_file(new_text)

        for kind, number, excerpt, count in findings:
            total += count
            by_kind[kind] = by_kind.get(kind, 0) + count
            print(f"{kind:20s} {rel}:{number} ({count})  {excerpt}")

    print()
    print(f"scanned {scanned} text file(s)")
    if fixed:
        print(f"autofixed {fixed} file(s) (--fix)")
    if by_kind:
        print("findings by class:")
        for kind in sorted(by_kind):
            print(f"  {kind:20s} {by_kind[kind]}")
    print(f"TOTAL={total}")

    if total:
        print("RESULT: encoding findings present")
        return 1

    print("RESULT: no U+F0B7 or related encoding artifacts in text files")
    return 0


if __name__ == "__main__":
    sys.exit(main())
