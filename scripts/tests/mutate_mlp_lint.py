"""Mutation harness for MLP002 and MLP014 (G222).

A suite that passes against a deliberately broken linter proves nothing. This
applies each mutation to the REAL scripts/mlp_lint.py on disk, runs
test_mlp_lint.py, and restores the file. A mutation is KILLED if the suite
fails. Every one must be killed.

Run:  python3 scripts/tests/mutate_mlp_lint.py
      MLP_LINT_SUGAR_ROOT=/nonexistent python3 scripts/tests/mutate_mlp_lint.py
The second form is what CI would see: no Sugar tree, so only the pinned
fixtures and sentinels stand between a mutant and a green build.
"""
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LINT = ROOT / "scripts/mlp_lint.py"
SUITE = ROOT / "scripts/tests/test_mlp_lint.py"

# (name, find, replace) - each `find` must occur exactly once.
MUTANTS = [
    # --- the G222 defect: the second list -----------------------------------
    ("M1  drop $unsafeHttpClientFunctions from the merged denylist",
     "DENIED_FUNCTIONS = SCANNER_BLACKLIST | SCANNER_UNSAFE_HTTP_CLIENT_FUNCTIONS",
     "DENIED_FUNCTIONS = SCANNER_BLACKLIST"),
    ("M2  forget stream_resolve_include_path alone",
     '"stream_resolve_include_path", "stream_select",',
     '"stream_select",'),
    # --- what the scanner does NOT see --------------------------------------
    ("M3  line comments become code",
     'if c == "#" and not s.startswith("#[", i) or s.startswith("//", i):',
     'if False:'),
    ("M4  block comments become code",
     'if s.startswith("/*", i):\n                j = s.find("*/", i + 2)',
     'if False:\n                j = s.find("*/", i + 2)'),
    ("M5  single-quoted strings become code",
     "            if c == \"'\":\n                self._single_quoted()\n                continue\n",
     ""),
    ("M6  double-quoted strings become code",
     "            if c == '\"' or c == \"`\":",
     "            if c == \"`\":"),
    ("M7  heredoc bodies become code",
     "                if m:\n                    self._heredoc(m)\n                    continue\n",
     ""),
    ("M8  a method call counts as a call to the global",
     '    "->", "?->", "::", "new", "function", "fn", "const", "class", "interface",',
     '    "::", "new", "function", "fn", "const", "class", "interface",'),
    ("M9  a namespaced name counts as the global",
     "        if len(parts) != 1:\n            k = j\n            continue\n",
     ""),
    ("M10 an attribute's name counts as a call",
     'if brackets and brackets[-1] == "#[" and prev[1] in ("#[", ","):',
     'if False:'),
    ("M11 use-function aliases are ignored",
     "resolved = name if fq else function_aliases.get(name.lower(), name)",
     "resolved = name"),
    # --- what the scanner DOES see ------------------------------------------
    ("M12 match case-sensitively",
     'if resolved.lstrip("\\\\").lower() in DENIED_FUNCTIONS:',
     'if resolved.lstrip("\\\\") in DENIED_FUNCTIONS:'),
    ("M13 code inside {$...} is not lexed",
     "                self._code(interp=True)\n                j = self.i\n                continue\n",
     "                j = self.i + 1\n                continue\n"),
    ("M14 report a split call at its ( line, not its name",
     "            hits.append((start_line, _function_kind(resolved.lstrip(\"\\\\\")), name))",
     "            hits.append((nxt[2], _function_kind(resolved.lstrip(\"\\\\\")), name))"),
    ("M15 skip Studio-generated files again",
     "        text = read(f)\n        hits = scanner_denylist_hits(text)",
     "        text = read(f)\n        if is_generated(text):\n            continue\n"
     "        hits = scanner_denylist_hits(text)"),
    ("M16 inline HTML is lexed as code",
     "            self._to(m.end() if m else self.n)\n            self._code(interp=False)",
     "            self._code(interp=False)"),
    # --- classes and methods -------------------------------------------------
    ("M17 class names ignore the namespace",
     '        return "\\\\".join(([namespace] if namespace else []) + parts)',
     '        return "\\\\".join(parts)'),
    ("M18 drop the SecureSmarty classes",
     "DENIED_CLASSES = SCANNER_CLASS_BLACKLIST | SCANNER_SECURE_SMARTY_CLASSES",
     "DENIED_CLASSES = SCANNER_CLASS_BLACKLIST"),
    ("M19 nullsafe ?-> counts as a method call",
     'if value == "->" and k + 2 < len(toks)',
     'if value in ("->", "?->") and k + 2 < len(toks)'),
    ("M20 stop checking `extends`",
     'if parts and resolve_class(parts, fq).lower() in DENIED_CLASSES:\n                hits.append((line, "class_extends"',
     'if False:\n                hits.append((line, "class_extends"'),
    # --- MLP014 over $validExt ----------------------------------------------
    ("M21 accept every file name",
     "    return bool(ext) and ext in SCANNER_VALID_EXTENSIONS",
     "    return True"),
    ("M22 LICENSE is no longer special",
     '    if base == "license":\n        return True\n',
     ""),
]


def main() -> int:
    original = LINT.read_text()
    survivors = []
    try:
        for name, find, replace in MUTANTS:
            count = original.count(find)
            if count != 1:
                print(f"SETUP ERROR {name}: pattern found {count} times")
                survivors.append(name)
                continue
            LINT.write_text(original.replace(find, replace, 1))
            r = subprocess.run(
                [sys.executable, str(SUITE)], capture_output=True, text=True
            )
            killed = r.returncode != 0
            print(f"{'KILLED  ' if killed else 'SURVIVED'} {name}")
            if not killed:
                survivors.append(name)
    finally:
        LINT.write_text(original)
    r = subprocess.run([sys.executable, str(SUITE)], capture_output=True, text=True)
    print(f"restored; suite on the real file: {'green' if r.returncode == 0 else 'RED'}")
    print(f"{len(MUTANTS) - len(survivors)}/{len(MUTANTS)} killed")
    return 1 if survivors or r.returncode != 0 else 0


if __name__ == "__main__":
    sys.exit(main())
