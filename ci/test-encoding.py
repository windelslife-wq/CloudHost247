#!/usr/bin/env python3
"""Regression tests for the guard; negative fixtures live only in temp files."""
import importlib.util
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location("encoding_guard", Path(__file__).with_name("check-encoding.py"))
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

class EncodingGuardTests(unittest.TestCase):
    def test_target_encoding_is_exact(self):
        self.assertEqual(guard.TARGET.encode("utf-8"), bytes.fromhex("ef82b7"))
        self.assertNotEqual(guard.TARGET.encode("utf-8"), bytes.fromhex("ef8bb7"))

    def test_stray_bullet_is_found(self):
        self.assertEqual(guard.scan_file("Heading\n\uf0b7 item")[0][:2], ("private-use-bullet", 2))

    def test_legitimate_spaced_bullet_is_clean(self):
        self.assertEqual(guard.scan_file("\u2022 Item\n<ul><li>Item</li></ul>"), [])

    def test_fused_bullet_is_found(self):
        self.assertEqual(guard.scan_file("\u2022Item")[0][0], "fused-bullet")

    def test_mojibake_is_found(self):
        self.assertTrue(any(hit[0] == "mojibake" for hit in guard.scan_file("\u00c3\u00a9")))

    def test_replacement_character_is_found(self):
        self.assertEqual(guard.scan_file("lost \ufffd")[0][0], "replacement-char")

    def test_auto_fix_preserves_boundaries(self):
        fixed, changed = guard.autofix("first\uf0b7second")
        self.assertTrue(changed)
        self.assertIn("first", fixed)
        self.assertIn("second", fixed)
        self.assertNotIn("firstsecond", fixed)
        self.assertEqual(guard.scan_file(fixed), [])

    def test_newline_separators_are_found(self):
        self.assertTrue(any(hit[0] == "word-separator" for hit in guard.scan_file("first\u2028second")))

    def test_bom_text_is_not_binary(self):
        for encoding in ("utf-16", "utf-32"):
            with self.subTest(encoding=encoding):
                data = "\uf0b7 item".encode(encoding)
                self.assertFalse(guard.is_binary(data))
                text, detected = guard.decode(data)
                self.assertEqual(detected, encoding)
                self.assertEqual(guard.scan_file(text)[0][0], "private-use-bullet")

    def test_png_is_binary(self):
        self.assertTrue(guard.is_binary(b"\x89PNG\r\n\x1a\n\x00binary"))

    def test_dotfiles_and_ci_are_scanned(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / ".github").mkdir()
            (root / ".github" / "workflow.yml").write_text("name: test")
            (root / ".htaccess").write_text("# public config")
            found = {relative for _, relative in guard.iter_files(tmp)}
            self.assertIn(".htaccess", found)
            self.assertIn(".github/workflow.yml", found)

    def test_generated_dependencies_are_excluded(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for directory in (".git", ".cache", "node_modules"):
                (root / directory).mkdir()
                (root / directory / "fixture.txt").write_text("\uf0b7 fixture")
            self.assertEqual(list(guard.iter_files(tmp)), [])

    def test_upstream_exception_is_only_one_pinned_comment_line(self):
        self.assertEqual(guard.scan_file("\ufffd upstream\n\uf0b7 regression", {1})[0][:2], ("private-use-bullet", 2))
        self.assertTrue(all(entry["path"] not in guard.EXEMPT_PATHS for entry in guard.EXEMPTIONS if entry["class"] == "vendored-upstream"))

if __name__ == "__main__":
    unittest.main()
