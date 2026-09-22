#!/usr/bin/env python3
"""Pre-flight audit for the Module Loadable Packages in this repository.

Why this exists
---------------
An MLP is a zip an administrator uploads through Admin -> Module Loader. On
install it writes files into a live instance, creates tables, registers logic
hooks that run on every save, and executes its own install scripts, all with
no sandbox. By the time a defect shows up there it has usually already taken
the instance with it: on 2026-09-11 ERP-Epicor 1.1.9's predecessor died
mid-install with "Cannot redeclare class ErpDashboardReconcile", was never
recorded in upgrade_history, and left the instance with its ERP modules
removed and the Quotes list throwing. Nothing in the repository would have
caught that.

The rules below are the build-time half of the Mango MLP review
(`.agents/skills/mango-mlp-review` in the Mango repository): that skill audits
a finished package on three axes, this catches the same defect classes from
source while they are still cheap to fix. Each rule cites the finding class it
came from.

What this is NOT
---------------
This is not ModuleScanner. The real upload gate is
`ModuleScanner::scanPackage()`, which tokenizes with nikic/php-parser and owns
the authoritative deny-lists; the only way to know whether an upload passes is
to run it. MLP002 below is a cheap pre-flight for the rejections this repo has
actually hit, not a reimplementation, and a clean run here is not a promise
that the loader will accept the package.

Detection is textual. There is no PHP parser in this repository's CI and no
third-party dependency is worth adding for one job, so every rule is a
line-oriented pattern over source. That trades some precision for being
runnable anywhere; see `--explain` for what each rule can and cannot see, and
use `# mlp-lint: ignore <RULE>` on the offending line when a rule is wrong.

Usage
-----
    python3 scripts/mlp_lint.py                     # every package in the repo
    python3 scripts/mlp_lint.py sugar-sell/ERP-Epicor
    python3 scripts/mlp_lint.py --zip path/to/pkg.zip
    python3 scripts/mlp_lint.py --format json
    python3 scripts/mlp_lint.py --explain MLP001

Exit codes: 0 clean, 1 blocker or required finding, 2 the run itself failed.
Advisory findings are reported and never fail the build.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import zipfile
from dataclasses import dataclass, field
from pathlib import Path

BLOCKER = "blocker"
REQUIRED = "required"
ADVISORY = "advisory"

SEVERITY_ORDER = {BLOCKER: 0, REQUIRED: 1, ADVISORY: 2}

# Deliberately NOT a rule
# ----------------------
# remove_tables => 'prompt' being honoured in the Module Loader UI but ignored
# on the REST uninstall path is a platform-wide quirk, identical for every
# package that sets it. The Mango review skill is explicit that it must never
# be attributed to a package or its author, and the one review that did flag
# it against this repo was wrong to. There is no rule for it here, and adding
# one would be a regression.


@dataclass
class Finding:
    rule: str
    severity: str
    path: str
    line: int
    message: str
    remedy: str

    def as_dict(self) -> dict:
        return {
            "rule": self.rule,
            "severity": self.severity,
            "path": self.path,
            "line": self.line,
            "message": self.message,
            "remedy": self.remedy,
        }


@dataclass
class Rule:
    ident: str
    severity: str
    title: str
    origin: str
    explanation: str


RULES: dict[str, Rule] = {}


def rule(ident: str, severity: str, title: str, origin: str, explanation: str) -> Rule:
    r = Rule(ident, severity, title, origin, explanation)
    RULES[ident] = r
    return r


rule(
    "MLP001", BLOCKER,
    "One class, two include paths, one install request",
    "The 1.1.9 install fatal, 2026-09-11",
    """require_once deduplicates by RESOLVED PATH, not by class name. A package
that ships a class inside its own zip AND installs a copy of it will load both
when one install script reaches for __DIR__ and another for the installed
path. PHP then dies with "Cannot redeclare class", the installer never records
the package, and Sugar's own failure handler calls a method that does not
exist, so nothing useful reaches the screen.

Guard the class, not the path: if (!class_exists('X', false)) { require_once
... }. Sees: include statements and class declarations in package PHP. Cannot
see: dynamic include paths built at runtime.""",
)

rule(
    "MLP002", BLOCKER,
    "Function the cloud package scanner rejects",
    "The 1.1.0 upload rejection, 2026-09-11",
    """SugarCloud instances run ModuleScanner with its deny-lists on. A single
call to one of these in any packaged PHP file rejects the whole upload before
anything installs, with a per-line "Code attempted to call denylisted
function" report. This repo has shipped that rejection once already, from a
filesystem cleanup script.

The authoritative list is the scanner's own effectiveDenyLists, which this
file cannot import. What is checked here is the set this repository has been
rejected for plus the classic code-execution sinks, as a cheap pre-flight.
Run the real scanner before sign-off.""",
)

rule(
    "MLP003", BLOCKER,
    "Server text rendered as HTML",
    "Mango audit finding 05, stored XSS",
    """jQuery parses a string containing markup as HTML, so .append('<b>' + msg
+ '</b>') executes whatever msg carries. In this repo those messages come back
from the ERP action APIs and embed quote-line fields any quote editor can set,
which makes it a stored cross-site scripting hole against whoever clicks the
button next. OWASP A03:2025.

Use .text(), or $('<b>').text(msg). Sees: jQuery HTML sinks whose argument
concatenates a markup literal with something that is not a literal.""",
)

rule(
    "MLP004", REQUIRED,
    "Logic hook with no Throwable guard",
    "Mango audit finding 08",
    """A logic hook runs inside somebody else's save. An uncaught exception in
before_save or after_save does not fail the hook, it fails the save that
triggered it, so a transient database blip during an unrelated cascade throws
away a rep's edit. This package already has the right pattern in
ErpOpportunityValuation::rollupHook.

Wrap the hook body in try { } catch (\\Throwable $e) { log->error(...) }.
Sees: methods named in a logic_hooks installdef, or in an Ext/LogicHooks
fragment, and whether their body contains a catch.""",
)

rule(
    "MLP005", REQUIRED,
    "SQL assembled by concatenation",
    "Mango audit finding 10",
    """Hand-quoted values spliced into a SQL string is the pattern this repo's
standards forbid, whether or not today's inputs happen to be package
constants. The review trigger that would catch a later widening of those
inputs does not exist, so the injection arrives unreviewed.

Use the query builder with bound parameters. Sees: a SQL verb in a PHP string
on a line that also concatenates a variable or interpolates one.""",
)

rule(
    "MLP006", BLOCKER,
    "Config read with no admin gate",
    "Mango audit finding 01, broken access control",
    """The write half of a config endpoint is usually gated and the read half
forgotten. In this repo that exposed the live Epicor orchestrator bearer token
to any authenticated user of any licence type, which is enough to write orders
into the ERP. OWASP A01:2025.

Gate the read on isAdmin and mask secrets in the response even for admins.
Sees: a method whose name reads as a config getter in an api/ file, and
whether isAdmin appears in it.""",
)

rule(
    "MLP007", REQUIRED,
    "Credential passed to the logger",
    "Mango audit finding 02",
    """A token short enough to escape a truncation guard lands in sugarcrm.log
verbatim, and from there in every log aggregator and support bundle. This
package masks the Authorization header a few lines away, after an earlier
leak, so the intent is already established.

Exclude the secret before logging. Sees: a log call on a line naming a
token-like key or variable.""",
)

rule(
    "MLP008", REQUIRED,
    "Manifest claims a module the package does not ship",
    "Mango audit findings 03 and 06",
    """The manifest is a contract. A description listing ten modules for a
package that installs seven sends a reviewer looking for code that was never
written, and a beans entry pointing at an absent directory fatals whatever
touches it. Both happened here at once: syncQuoteTiers required an ERP_Quotes
module no build ever contained.

Sees: beans module/path entries in the manifest against the files present, and
module-shaped names in the description that appear nowhere in the package.""",
)

rule(
    "MLP009", REQUIRED,
    "Undeclared dependency on a sibling package",
    "Mango audit finding 07",
    """Requiring a file the package does not ship works on the author's
instance and fatals on a clean one. Either declare the sibling in the
manifest's dependencies, so the loader refuses the install with a clear
message, or degrade the way ErpDemoDashboards already does by checking first.

Sees: require/include of an installed path that no file in this package
provides, with no dependencies key in the manifest.""",
)

rule(
    "MLP010", ADVISORY,
    "Reference to a test file that does not exist",
    "Mango audit finding 11",
    """A docblock citing tests/XTest.php that was never written reads as
coverage to the next person. Either write it or drop the reference and say the
gap is a gap.""",
)

rule(
    "MLP011", ADVISORY,
    "Unescaped Handlebars with no justification",
    "Mango audit finding 17",
    """Triple-mustache disables escaping. Every use in this repo traces to a
fixed lookup table rather than record data, which is fine, but the standard is
that the reasoning sits next to the code. Add a {{! ... }} comment saying what
makes this one safe.""",
)

rule(
    "MLP012", ADVISORY,
    "Personal data logged at info",
    "Mango audit finding 12",
    """Billing address, email and phone logged on every write-back call is a
retention problem, and inconsistent with the header masking in the same file.
Move it to debug or strip the fields first.""",
)

rule(
    "MLP013", REQUIRED,
    "Two install mechanisms own one destination",
    "Mango audit finding 09",
    """The same destination reached by a copy entry and by an installdefs
section means a future edit to either can silently stop applying, or register
twice. Keep the idiomatic section and drop the copy.""",
)

rule(
    "MLP017", BLOCKER,
    "Dynamic dispatch in packaged PHP",
    "ErpQuoteHooks' own docblock, confirmed live",
    """ModuleScanner rejects a dynamically-named function call, a dynamic
method call and dynamic class instantiation in any file shipped inside a
package zip. One occurrence refuses the whole upload. ErpQuoteHooks records
that an earlier draft of that class was rejected for exactly this, and
ErpDemoDashboards notes the same restriction ruling out array_map.

This is the constraint that makes some ordinary refactors unavailable here. A
trait whose method takes a callable and invokes it - the natural way to share
a try/catch wrapper across classes - cannot ship, because invoking the
callable is a dynamically-named call. The shape that works is an abstract base
class with a template method, where the call is a literal method name.

Sees: new $var(), $var->$method(), $$var, and a bare $var(...) call. Write the
name out longhand instead, even when that means repetition.""",
)

rule(
    "MLP015", REQUIRED,
    "Installer class that nothing reverses",
    "Uninstall protocol",
    """An MLP that cannot be cleanly removed is an MLP nobody can safely trial.
Every installer class in this repo pairs install() with uninstall() for that
reason: BaseErpLayout declares both abstract, so a layout that adds a panel,
a button or a field also knows how to take it away again. A class with an
install() and no uninstall() leaves whatever it wrote behind forever, and the
next install of the same package sees a half-configured view it did not expect.

Sees: a class defining install() without a matching uninstall(). Add the
uninstall, even if its body is a single removal call.""",
)

rule(
    "MLP016", REQUIRED,
    "Package declares itself permanently uninstallable",
    "Uninstall protocol",
    """is_uninstallable => false locks a package into the instance with no way
back from the Module Loader UI. On a demo or trial instance that is
unrecoverable short of a restore, and nothing in this repository needs it.
Set it true, or leave it out and take the default, which is true.""",
)

rule(
    "MLP014", BLOCKER,
    "Built package ships a files.md5, which SugarCloud refuses to install",
    "ossugarcube2, 2026-09-11",
    """ModuleScanner validates every shipped file's extension against an
allow-list, and `md5` is not on it. A package containing files.md5 is rejected
at install with "File Issues / files.md5 / Invalid file extension" and cannot
be installed on SugarCloud at all.

This rule used to say the opposite: that the HealthCheck pass wants files.md5
and its absence was worth reporting. Acting on that advice added one to all
thirteen archives upstream and made every one of them unloadable, which is how
the truth was found. files.md5 belongs to Sugar's own upgrade and patch
packages, not to module packages, and nothing in this repository should ever
produce one.""",
)


IGNORE_RE = re.compile(r"mlp-lint:\s*ignore\s+([A-Z0-9, ]+)")

GENERATED_MARKER = re.compile(r"^\s*//\s*created:\s", re.MULTILINE)

# MLP002. The set this repository has actually been rejected for, plus the
# classic code-execution sinks. Not the scanner's real list; see the rule text.
DENIED_FUNCTIONS = {
    "is_file", "is_dir", "unlink", "rmdir", "mkdir", "rename", "copy",
    "file_get_contents", "file_put_contents", "fopen", "fwrite", "fputs",
    "glob", "scandir", "opendir", "readdir", "touch", "chmod",
    "eval", "exec", "shell_exec", "system", "passthru", "popen", "proc_open",
    "create_function", "assert", "extract",
    "call_user_func", "call_user_func_array", "array_map",
    "unserialize", "curl_exec",
}

SQL_VERB_RE = re.compile(
    r"""['"][^'"]*\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|DROP\s+TABLE|ALTER\s+TABLE)\b""",
    re.IGNORECASE,
)

SECRET_KEY_RE = re.compile(
    r"\b\w*(token|secret|password|passwd|api_?key|credential|authorization|bearer)\w*\b",
    re.IGNORECASE,
)

PII_KEY_RE = re.compile(
    r"\b\w*(billing_address|email1|email_address|\bphone\w*|address_street|primary_address)\w*\b",
    re.IGNORECASE,
)

LOG_CALL_RE = re.compile(r"->\s*(info|warn|warning|error|fatal|debug|deprecated)\s*\(")

JQUERY_SINK_RE = re.compile(r"\.\s*(append|prepend|html|after|before|replaceWith|wrap)\s*\(")

CLASS_DEF_RE = re.compile(r"^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_]\w*)", re.MULTILINE)

INCLUDE_RE = re.compile(
    r"\b(require_once|require|include_once|include)\b\s*\(?\s*(.+?)\s*\)?\s*;"
)

CLASS_EXISTS_RE = re.compile(r"class_exists\s*\(\s*['\"]([A-Za-z_]\w*)['\"]")

CONFIG_GETTER_RE = re.compile(
    r"function\s+(get\w*(?:Config|Settings|Credentials)\w*)\s*\(", re.IGNORECASE
)

TEST_REF_RE = re.compile(r"((?:[\w./-]*)tests?/[\w./-]*Test\.php)")

MODULE_NAME_RE = re.compile(r"\b((?:ERP|CORE|PS)_[A-Z]\w+)\b")


# What lives in a package directory but never reaches an instance.
#
# This distinction is the difference between a usable linter and 178 findings
# nobody reads. pack.php is the build script: it legitimately calls mkdir,
# glob and file_get_contents, none of which the cloud scanner ever sees
# because pack.php is not in the zip it produces. tests/ is the same story,
# and it also never runs during an install, so a class it includes can never
# collide with one an install script includes.
NOT_SHIPPED = (
    "pack.php",
    "version",
    "buildPackages.sh",
)
NOT_SHIPPED_DIRS = (
    "releases",
    "tests",
    "docs",
    "node_modules",
    ".git",
)

# Build topology this repo's buildPackages.sh implements: ERP-Core has no
# pack.php of its own and is overlaid into ERP-Epicor's zip (step 3 of the
# merge, documented in sugar-sell/AGENTS.md). A file ERP-Core provides is
# therefore a file ERP-Epicor ships, and rules that ask "does this package
# provide X" have to know that or they report every shared helper as a
# missing dependency.
OVERLAID: dict[str, tuple[str, ...]] = {
    "ERP-Epicor": ("ERP-Core/src", "ERP-Core/scripts"),
}


def _excluded(path: Path, root: Path) -> bool:
    try:
        parts = path.relative_to(root).parts
    except ValueError:
        return False
    if parts and parts[-1] in NOT_SHIPPED:
        return True
    return any(p in NOT_SHIPPED_DIRS for p in parts)


@dataclass
class Package:
    name: str
    root: Path
    files: list[Path] = field(default_factory=list)
    manifest_text: str = ""
    # Files present in the package directory but not in the zip, kept only so
    # existence questions (MLP010) can tell "not written" from "not shipped".
    unshipped: list[Path] = field(default_factory=list)

    @property
    def php_files(self) -> list[Path]:
        return [f for f in self.files if f.suffix == ".php"]

    @property
    def js_files(self) -> list[Path]:
        return [f for f in self.files if f.suffix == ".js"]

    @property
    def hbs_files(self) -> list[Path]:
        return [f for f in self.files if f.suffix == ".hbs"]

    @property
    def all_basenames(self) -> set[str]:
        return {p.name for p in self.files} | {p.name for p in self.unshipped}


def read(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return ""


# Third-party code vendored into a package's zip. PS-Impex-Seed-Loader
# carries the ImpexApi library, whose whole job is generating SQL, so the
# standards rules reported twenty of its files on the first run. Vendored code
# is reviewed the way generated code is: sampled for correctness, never
# style-reviewed. The hard gates still apply to it — a denied function in a
# vendored file rejects the upload just the same, and a config endpoint that
# leaks a token leaks it whoever wrote the file.
VENDORED_MARKERS = ("/wsystems/", "/vendor/", "/third_party/", "/node_modules/")


def is_vendored(path: Path) -> bool:
    p = str(path).replace(os.sep, "/")
    return any(m in p for m in VENDORED_MARKERS)


def is_generated(text: str) -> bool:
    """Studio and ModuleBuilder output carries a `// created: <date>` header.

    On a real package this is the large majority of files. It is sampled for
    correctness by a human reviewer and never style-reviewed, so every rule
    that is about hand-written intent skips it.
    """
    return bool(GENERATED_MARKER.search(text[:2000]))


def ignored(window: str, ident: str) -> bool:
    """Is this rule waived here?

    `window` is usually the flagged line, but callers may pass a few lines of
    context. Accepting a window matters: a rule fires on the line holding the
    pattern, and in real code that line is often the middle of a statement
    that opened three lines earlier. Requiring the comment on exactly the
    flagged line sent two genuine waivers in this repo to the wrong line, so
    the comment is honoured anywhere in the statement around it.
    """
    for m in IGNORE_RE.finditer(window):
        if ident in {p.strip() for p in m.group(1).split(",")}:
            return True
    return False


def numbered(text: str):
    for i, line in enumerate(text.splitlines(), start=1):
        yield i, line


def code_lines(text: str):
    """Yield (line number, original line, code with comments removed).

    Every rule here looks for a pattern in *code*, and this repository's PHP is
    heavily commented — including, in several places, prose that names the very
    functions the scanner denies in order to explain why they are not used. The
    first run of this linter reported ten such comments as blockers. Stripping
    comments is therefore not a refinement, it is the difference between the
    tool being right and being deleted.

    Block-comment state is tracked across lines so a docblock spanning twenty
    lines is skipped in full. Quotes are not parsed, so a `//` inside a string
    truncates the line; that direction only loses findings, never invents them.
    """
    all_lines = text.splitlines()
    in_block = False
    for i, line in enumerate(all_lines, start=1):
        # The statement this line probably belongs to, for ignore comments.
        window = "\n".join(all_lines[max(0, i - 4):i])
        code = line
        if in_block:
            end = code.find("*/")
            if end == -1:
                yield i, line, "", window
                continue
            code = code[end + 2:]
            in_block = False
        # Strip any complete /* ... */ regions on this line.
        while True:
            start = code.find("/*")
            if start == -1:
                break
            end = code.find("*/", start + 2)
            if end == -1:
                code = code[:start]
                in_block = True
                break
            code = code[:start] + " " + code[end + 2:]
        code = re.sub(r"//.*$", "", code)
        code = re.sub(r"(?<!\$)#.*$", "", code)
        yield i, line, code, window


def dedupe(findings: list[Finding]) -> list[Finding]:
    """One finding per rule per line, whatever matched inside it."""
    seen: set[tuple[str, str, int]] = set()
    out: list[Finding] = []
    for f in findings:
        key = (f.rule, f.path, f.line)
        if key in seen:
            continue
        seen.add(key)
        out.append(f)
    return out


def rel(path: Path, root: Path) -> str:
    """Path as a reader can use it.

    Relative to the package when it lives there, and relative to the
    repository otherwise — a file overlaid from ERP-Core is outside the
    package root, and printing its absolute path made the first reports
    unreadable.
    """
    try:
        return str(path.relative_to(root))
    except ValueError:
        pass
    for parent in path.parents:
        if (parent / ".git").exists() or (parent / "sugar-sell").is_dir():
            try:
                return str(path.relative_to(parent))
            except ValueError:
                break
    return str(path)


# --------------------------------------------------------------------------
# Rules
# --------------------------------------------------------------------------

def check_class_redeclare(pkg: Package) -> list[Finding]:
    """MLP001. A class reachable at two different include paths in one request."""
    findings: list[Finding] = []

    # Which classes does this package declare, and in which file basenames?
    class_to_basename: dict[str, str] = {}
    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        for m in CLASS_DEF_RE.finditer(text):
            class_to_basename[m.group(1)] = f.name

    # Where is each basename included from, and is that site class-guarded?
    sites: dict[str, list[tuple[Path, int, str, bool]]] = {}
    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        lines = text.splitlines()
        for m in INCLUDE_RE.finditer(text):
            expr = m.group(2)
            line_no = text[: m.start()].count("\n") + 1
            base = expr.rstrip("'\"").split("/")[-1].strip("'\" ")
            if not base.endswith(".php"):
                continue
            # Guarded if a class_exists naming this class sits just above.
            window = "\n".join(lines[max(0, line_no - 6):line_no])
            guarded_names = set(CLASS_EXISTS_RE.findall(window))
            sites.setdefault(base, []).append((f, line_no, expr, bool(guarded_names)))

    for cls, base in sorted(class_to_basename.items()):
        entries = sites.get(base, [])
        # Normalise the include expression: what matters is whether two
        # textually different paths resolve to two different files on disk.
        shapes = {e[2].replace(" ", "") for e in entries}
        if len(shapes) < 2:
            continue
        for f, line_no, expr, guarded in entries:
            text_lines = read(f).splitlines()
            line = text_lines[line_no - 1] if line_no <= len(text_lines) else ""
            if guarded or ignored(line, "MLP001"):
                continue
            findings.append(Finding(
                "MLP001", BLOCKER, rel(f, pkg.root), line_no,
                f"{cls} is included here and from a different path elsewhere in "
                f"this package ({len(shapes)} distinct paths for {base}); "
                f"require_once dedupes by path, so both copies load and PHP "
                f"fatals with \"Cannot redeclare class {cls}\"",
                f"Wrap this include in if (!class_exists('{cls}', false)) {{ ... }}",
            ))
    return findings


def check_denied_functions(pkg: Package) -> list[Finding]:
    """MLP002. Functions that get the whole upload rejected on SugarCloud."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        for line_no, line, code, window in code_lines(text):
            for fn in sorted(DENIED_FUNCTIONS):
                if not re.search(r"(?<![\w$>:])" + re.escape(fn) + r"\s*\(", code):
                    continue
                # A method of your own that happens to share the name is not a
                # call to the global. ImpexApi declares `private function
                # eval(...)`, which the first run reported as a call to PHP's
                # eval and would have had someone chasing a non-existent
                # blocker through vendored code.
                if re.search(r"\bfunction\s+" + re.escape(fn) + r"\s*\(", code):
                    continue
                if ignored(window, "MLP002"):
                    continue
                findings.append(Finding(
                    "MLP002", BLOCKER, rel(f, pkg.root), line_no,
                    f"calls {fn}(), which the cloud package scanner denies; a "
                    f"single occurrence rejects the entire upload before "
                    f"anything installs",
                    "Remove the call, or move the work out of the package and "
                    "into the orchestrator; confirm against the real scanner",
                ))
    return findings


def check_html_sinks(pkg: Package) -> list[Finding]:
    """MLP003. Server text concatenated into markup and handed to jQuery."""
    findings: list[Finding] = []
    for f in pkg.js_files:
        text = read(f)
        for line_no, line, code, window in code_lines(text):
            if not JQUERY_SINK_RE.search(code):
                continue
            # A markup literal concatenated with anything non-literal.
            if not re.search(r"""['"]\s*<[^'"]*['"]\s*\+""", code):
                continue
            if ignored(window, "MLP003"):
                continue
            findings.append(Finding(
                "MLP003", BLOCKER, rel(f, pkg.root), line_no,
                "builds HTML by concatenation and hands it to a jQuery sink, so "
                "any markup in the interpolated value executes in the viewer's "
                "session",
                "Use .text(), or $('<tag>').text(value)",
            ))
    return findings


def _hook_methods(pkg: Package) -> set[tuple[str, str]]:
    """(class, method) pairs this package registers as logic hooks."""
    pairs: set[tuple[str, str]] = set()
    sources = [pkg.manifest_text]
    for f in pkg.php_files:
        if "LogicHooks" in str(f) or "logic_hooks" in f.name:
            sources.append(read(f))
    for text in sources:
        if not text:
            continue
        # Manifest form: 'class' => 'X', 'function' => 'y'
        for m in re.finditer(
            r"['\"]class['\"]\s*=>\s*['\"](\w+)['\"].*?['\"]function['\"]\s*=>\s*['\"](\w+)['\"]",
            text, re.DOTALL,
        ):
            pairs.add((m.group(1), m.group(2)))
        # Ext fragment form:
        #   $hook_array['after_save'][] = array(
        #       1, 'description', 'path/To/Class.php', 'Class', 'method',
        #   );
        # Taken as "the last two quoted strings in the array", because the
        # earlier pattern required the closing paren to follow the method
        # immediately and every fragment in this repo writes a trailing comma.
        # That one character hid all three of the unguarded Quotes hooks a
        # human review had already found.
        for m in re.finditer(
            r"\$hook_array\s*\[[^\]]*\]\s*\[\s*\]\s*=\s*array\s*\((.*?)\)\s*;",
            text, re.DOTALL,
        ):
            strings = re.findall(r"['\"]([^'\"]+)['\"]", m.group(1))
            if len(strings) >= 2:
                pairs.add((strings[-2], strings[-1]))
    return pairs


def check_hook_guards(pkg: Package) -> list[Finding]:
    """MLP004. A registered hook body with no catch in it."""
    findings: list[Finding] = []
    pairs = _hook_methods(pkg)
    if not pairs:
        return findings
    wanted = {m for _, m in pairs}
    for f in pkg.php_files:
        if is_vendored(f):
            continue
        text = read(f)
        if is_generated(text):
            continue
        for m in re.finditer(r"function\s+(\w+)\s*\(", text):
            method = m.group(1)
            if method not in wanted:
                continue
            # Body is from here to the next function at the same nesting, or EOF.
            nxt = text.find("\n    function ", m.end())
            if nxt == -1:
                nxt = text.find("\n    public function ", m.end())
            body = text[m.end(): nxt if nxt != -1 else len(text)]
            if "catch" in body:
                continue
            line_no = text[: m.start()].count("\n") + 1
            line = text.splitlines()[line_no - 1]
            if ignored(line, "MLP004"):
                continue
            findings.append(Finding(
                "MLP004", REQUIRED, rel(f, pkg.root), line_no,
                f"{method}() is registered as a logic hook but its body has no "
                f"catch; an exception here fails the unrelated save that "
                f"triggered it",
                "Wrap the body in try { } catch (\\Throwable $e) and log the error",
            ))
    return findings


def check_sql_concat(pkg: Package) -> list[Finding]:
    """MLP005. SQL text spliced together rather than parameter-bound."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        if is_vendored(f):
            continue
        text = read(f)
        if is_generated(text):
            continue
        for line_no, line, code, window in code_lines(text):
            if not SQL_VERB_RE.search(code):
                continue
            interpolated = "{$" in code or re.search(r"['\"]\s*\.\s*\$", code)
            if not interpolated:
                continue
            if ignored(window, "MLP005"):
                continue
            findings.append(Finding(
                "MLP005", REQUIRED, rel(f, pkg.root), line_no,
                "assembles SQL by concatenation or interpolation instead of "
                "binding parameters",
                "Use the connection's query builder with setParameter()",
            ))
    return findings


def check_config_admin_gate(pkg: Package) -> list[Finding]:
    """MLP006. A config getter in an API class with no isAdmin check."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        if "api" not in str(f).lower():
            continue
        text = read(f)
        if is_generated(text):
            continue
        for m in CONFIG_GETTER_RE.finditer(text):
            method = m.group(1)
            nxt = text.find("\n    function ", m.end())
            if nxt == -1:
                nxt = text.find("\n    public function ", m.end())
            body = text[m.end(): nxt if nxt != -1 else len(text)]
            if "isAdmin" in body:
                continue
            line_no = text[: m.start()].count("\n") + 1
            line = text.splitlines()[line_no - 1]
            if ignored(line, "MLP006"):
                continue
            findings.append(Finding(
                "MLP006", BLOCKER, rel(f, pkg.root), line_no,
                f"{method}() returns configuration without an isAdmin check, so "
                f"any authenticated user can read it, secrets included",
                "Require $api->user->isAdmin() and mask secret values in the "
                "response even for admins",
            ))
    return findings


def check_secret_logging(pkg: Package) -> list[Finding]:
    """MLP007. A log call on a line that names a credential."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        for line_no, line, code, window in code_lines(text):
            if not LOG_CALL_RE.search(code) or not SECRET_KEY_RE.search(code):
                continue
            if re.search(r"mask|redact|substr|str_repeat|\*{3}", code, re.IGNORECASE):
                continue
            if ignored(window, "MLP007"):
                continue
            findings.append(Finding(
                "MLP007", REQUIRED, rel(f, pkg.root), line_no,
                "passes a credential-shaped value to the logger, which puts it "
                "in sugarcrm.log and every support bundle taken afterwards",
                "Remove the secret from the logged payload, or log only its "
                "last few characters",
            ))
    return findings


def check_pii_logging(pkg: Package) -> list[Finding]:
    """MLP012. Personal data logged at info."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        for line_no, line, code, window in code_lines(text):
            if not re.search(r"->\s*(info|warn|warning|error|fatal)\s*\(", code):
                continue
            if not PII_KEY_RE.search(code):
                continue
            if ignored(window, "MLP012"):
                continue
            findings.append(Finding(
                "MLP012", ADVISORY, rel(f, pkg.root), line_no,
                "logs personal data above debug level",
                "Move to debug, or strip the personal fields before logging",
            ))
    return findings


def check_manifest_claims(pkg: Package) -> list[Finding]:
    """MLP008. Modules the manifest promises against the files present."""
    findings: list[Finding] = []
    text = pkg.manifest_text
    if not text:
        return findings

    shipped = {p.name for p in pkg.files} | {p.parent.name for p in pkg.files}
    present_text = " ".join(str(p) for p in pkg.files)

    for m in re.finditer(
        r"['\"]module['\"]\s*=>\s*['\"](\w+)['\"]\s*,\s*['\"]class['\"]\s*=>\s*['\"](\w+)['\"]",
        text,
    ):
        module, cls = m.group(1), m.group(2)
        if module in present_text or cls in shipped:
            continue
        line_no = text[: m.start()].count("\n") + 1
        findings.append(Finding(
            "MLP008", REQUIRED, "manifest.php", line_no,
            f"beans declares module {module} (class {cls}) but no file in the "
            f"package provides it",
            "Ship the module, or remove the beans entry",
        ))

    desc = re.search(r"['\"]description['\"]\s*=>\s*['\"](.+?)['\"]\s*,", text, re.DOTALL)
    if desc:
        claimed = set(MODULE_NAME_RE.findall(desc.group(1)))
        for name in sorted(claimed):
            if name in present_text:
                continue
            line_no = text[: desc.start()].count("\n") + 1
            findings.append(Finding(
                "MLP008", REQUIRED, "manifest.php", line_no,
                f"description names {name}, which does not exist anywhere in "
                f"this package",
                "Correct the description to the modules actually installed",
            ))
    return findings


def check_undeclared_dependencies(pkg: Package) -> list[Finding]:
    """MLP009. Requiring a file no file in this package provides."""
    findings: list[Finding] = []
    has_dependencies = bool(
        re.search(r"\$manifest\s*\[.*?\]|['\"]dependencies['\"]\s*=>", pkg.manifest_text)
        and "dependencies" in pkg.manifest_text
    )
    own_basenames = {p.name for p in pkg.files}

    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        lines = text.splitlines()
        for m in INCLUDE_RE.finditer(text):
            expr = m.group(2)
            if "__DIR__" in expr or "$" in expr:
                continue
            mm = re.search(r"['\"]([^'\"]+\.php)['\"]", expr)
            if not mm:
                continue
            target = mm.group(1)
            # custom/ only. modules/ and include/ are Sugar's own tree, always
            # present on any instance, and requiring DeployedMetaDataImplementation
            # or SugarQuery is how a package is supposed to reach the platform.
            # Treating those as missing dependencies reported every install
            # script in the repo on the first run.
            if not target.startswith("custom/"):
                continue
            if os.path.basename(target) in own_basenames:
                continue
            line_no = text[: m.start()].count("\n") + 1
            line = lines[line_no - 1] if line_no <= len(lines) else ""
            if ignored(line, "MLP009") or has_dependencies:
                continue

            # An existence guard is the other correct answer, and the one
            # ERP-Epicor-BAQ-Dashlet and PartialFulfillmentQuotesApi already
            # use: check file_exists() first and degrade, or throw a sentence
            # naming what is missing. Without this, the rule fired on exactly
            # the code that handles the case properly, which is the worst kind
            # of false positive - it penalises the pattern it wants.
            window = "\n".join(lines[max(0, line_no - 9):line_no])
            guarded = re.search(
                r"(file_exists|is_readable|class_exists)\s*\(", window
            )
            if guarded:
                continue
            findings.append(Finding(
                "MLP009", REQUIRED, rel(f, pkg.root), line_no,
                f"requires {target}, which this package does not ship, and the "
                f"manifest declares no dependencies",
                "Declare the sibling package in $manifest['dependencies'], or "
                "check the file exists before requiring it",
            ))
    return findings


def check_dangling_tests(pkg: Package) -> list[Finding]:
    """MLP010. A cited test file that is not there."""
    findings: list[Finding] = []
    # Existence, not shipping: a test that exists but is excluded from the zip
    # is still a test that exists, so this is the one rule that looks at both.
    own = pkg.all_basenames
    for f in pkg.php_files + pkg.js_files:
        if is_vendored(f):
            continue
        text = read(f)
        if is_generated(text):
            continue
        for line_no, line, _code, window in code_lines(text):
            for ref in TEST_REF_RE.findall(line):
                if os.path.basename(ref) in own:
                    continue
                if ignored(window, "MLP010"):
                    continue
                findings.append(Finding(
                    "MLP010", ADVISORY, rel(f, pkg.root), line_no,
                    f"cites {ref}, which does not exist in this package",
                    "Write the test, or drop the reference and state the gap",
                ))
    return findings


def check_triple_mustache(pkg: Package) -> list[Finding]:
    """MLP011. Unescaped Handlebars with no justification comment."""
    findings: list[Finding] = []
    for f in pkg.hbs_files:
        text = read(f)
        if "{{{" not in text:
            continue
        if "{{!" in text:
            continue
        line_no = next(
            (i for i, line in numbered(text) if "{{{" in line), 1
        )
        findings.append(Finding(
            "MLP011", ADVISORY, rel(f, pkg.root), line_no,
            "uses triple-mustache with no {{! }} comment saying why the value "
            "is safe unescaped",
            "Add a {{! ... }} comment naming the source of the value",
        ))
    return findings


def check_duplicate_destinations(pkg: Package) -> list[Finding]:
    """MLP013. One destination written by two mechanisms."""
    findings: list[Finding] = []
    text = pkg.manifest_text
    if not text:
        return findings
    # Counted per key, not per occurrence, and both keys are needed.
    #
    # An earlier version counted every mention of a path string, which missed
    # the defect it was written for: the platforms file is installed by a copy
    # entry and again by the platforms section, and those two entries share a
    # `from` source while only one carries a `to`. Counting mentions saw two
    # (the copy's own from and to, which every copy entry has) and said
    # nothing. An earlier version still inferred a collision from a shared
    # directory and reported 129 findings on one manifest.
    #
    # So: a destination written twice is a conflict, and a source installed by
    # two entries is a conflict. A single copy entry naming its own from and
    # to is neither.
    def normalise(path: str) -> str:
        idx = path.find("custom/")
        return path[idx:] if idx != -1 else path

    for key, label in (("to", "destination"), ("from", "source file")):
        occurrences: dict[str, list[int]] = {}
        for m in re.finditer(
            r"['\"]" + key + r"['\"]\s*=>\s*['\"]([^'\"]+\.\w+)['\"]", text
        ):
            occurrences.setdefault(normalise(m.group(1)), []).append(
                text[: m.start()].count("\n") + 1
            )
        for path, lines in sorted(occurrences.items()):
            if len(lines) < 2:
                continue
            findings.append(Finding(
                "MLP013", REQUIRED, "manifest.php", lines[0],
                f"{path} appears as the {label} of {len(lines)} installdefs "
                f"entries (lines {', '.join(str(n) for n in lines)}); two "
                f"mechanisms owning one file means a future edit to either can "
                f"silently stop applying, or register it twice",
                "Keep the idiomatic installdefs section and drop the copy entry",
            ))
    return findings


DYNAMIC_DISPATCH = (
    (re.compile(r"\bnew\s+\$\w+\s*\("), "dynamic class instantiation (new $var())"),
    (re.compile(r"->\s*\$\w+\s*\("), "dynamic method call ($obj->$name())"),
    # The brace form is the same call to ModuleScanner; it rejected a Bench
    # Dogs release for `$GLOBALS['log']->{$level}($message)`. A brace
    # PROPERTY read (`$bean->{$field}`) has no call and stays allowed.
    (re.compile(r"->\s*\{[^{}]*\}\s*\("), "dynamic method call ($obj->{$name}())"),
    (re.compile(r"::\s*(?:\$\w+|\{[^{}]*\})\s*\("), "dynamic static method call (Class::$name())"),
    (re.compile(r"(?<![\w>$])\$\w+\s*\(\s*(?:\$|\)|['\"])"), "call through a variable ($fn())"),
    (re.compile(r"\$\$\w+"), "variable variable ($$name)"),
)


def check_dynamic_dispatch(pkg: Package) -> list[Finding]:
    """MLP017. What the scanner calls a dynamically-named call."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        for line_no, line, code, window in code_lines(text):
            for pattern, what in DYNAMIC_DISPATCH:
                if not pattern.search(code):
                    continue
                if ignored(window, "MLP017"):
                    continue
                findings.append(Finding(
                    "MLP017", BLOCKER, rel(f, pkg.root), line_no,
                    f"uses {what}, which ModuleScanner refuses in a packaged "
                    f"file; one occurrence rejects the whole upload",
                    "Write the class or method name out longhand, even if that "
                    "means repeating yourself",
                ))
                break
    return findings


def check_uninstall_symmetry(pkg: Package) -> list[Finding]:
    """MLP015. An installer class with no way back."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        if is_vendored(f):
            continue
        text = read(f)
        if is_generated(text):
            continue
        if not re.search(r"function\s+install\s*\(", text):
            continue
        # An abstract declaration counts: the base class names the contract.
        if re.search(r"function\s+uninstall\s*\(", text):
            continue
        m = re.search(r"function\s+install\s*\(", text)
        line_no = text[: m.start()].count("\n") + 1
        line = text.splitlines()[line_no - 1]
        if ignored(line, "MLP015"):
            continue
        findings.append(Finding(
            "MLP015", REQUIRED, rel(f, pkg.root), line_no,
            "defines install() with no uninstall(), so whatever it writes to "
            "the instance is never removed",
            "Add uninstall() that reverses what install() does",
        ))
    return findings


def check_is_uninstallable(pkg: Package) -> list[Finding]:
    """MLP016. A package that cannot be removed."""
    findings: list[Finding] = []
    text = pkg.manifest_text
    if not text:
        return findings
    m = re.search(r"['\"]is_uninstallable['\"]\s*=>\s*(false|0|['\"]false['\"])", text)
    if not m:
        return findings
    line_no = text[: m.start()].count("\n") + 1
    findings.append(Finding(
        "MLP016", REQUIRED, "manifest.php", line_no,
        "declares is_uninstallable => false, which locks the package into any "
        "instance it reaches with no way back from the Module Loader UI",
        "Set it true, or omit it and take the default",
    ))
    return findings


SOURCE_RULES = [
    check_class_redeclare,
    check_dynamic_dispatch,
    check_uninstall_symmetry,
    check_is_uninstallable,
    check_denied_functions,
    check_html_sinks,
    check_hook_guards,
    check_sql_concat,
    check_config_admin_gate,
    check_secret_logging,
    check_pii_logging,
    check_manifest_claims,
    check_undeclared_dependencies,
    check_dangling_tests,
    check_triple_mustache,
    check_duplicate_destinations,
]


# --------------------------------------------------------------------------
# Discovery and drivers
# --------------------------------------------------------------------------

def load_package(root: Path, name: str | None = None) -> Package:
    present = [p for p in root.rglob("*") if p.is_file()]
    files = [p for p in present if not _excluded(p, root)]
    unshipped = [p for p in present if _excluded(p, root)]

    # Anything overlaid into this package's zip at build time counts as shipped.
    for extra in OVERLAID.get(root.name, ()):
        base = root.parent / extra
        if base.is_dir():
            files.extend(p for p in base.rglob("*") if p.is_file() and not _excluded(p, base))

    manifest = ""
    for candidate in ("manifest.php", "pack.php"):
        f = root / candidate
        if f.is_file():
            manifest += read(f)
    return Package(name or root.name, root, files, manifest, unshipped)


def discover(repo: Path) -> list[Package]:
    """A package is a directory with its own pack.php and version file."""
    out = []
    for area in ("sugar-sell", "sugar-predict", "sugar-market", "sugar-discover"):
        base = repo / area
        if not base.is_dir():
            continue
        for child in sorted(base.iterdir()):
            if child.is_dir() and (child / "pack.php").is_file():
                out.append(load_package(child, f"{area}/{child.name}"))
    return out


def lint_package(pkg: Package) -> list[Finding]:
    findings: list[Finding] = []
    for fn in SOURCE_RULES:
        findings.extend(fn(pkg))
    findings = dedupe(findings)
    findings.sort(key=lambda f: (SEVERITY_ORDER[f.severity], f.path, f.line))
    return findings


def lint_zip(path: Path) -> tuple[Package, list[Finding]]:
    import tempfile
    with tempfile.TemporaryDirectory() as tmp:
        dest = Path(tmp) / "pkg"
        with zipfile.ZipFile(path) as zf:
            names = zf.namelist()
            zf.extractall(dest)
        pkg = load_package(dest, path.name)
        findings = lint_package(pkg)
        if any(n.strip("/").lower().endswith(".md5") for n in names):
            findings.append(Finding(
                "MLP014", BLOCKER, path.name, 0,
                "ships a .md5 file, an extension ModuleScanner does not allow; "
                "SugarCloud rejects the package with \"Invalid file extension\"",
                "Remove it from the archive. files.md5 belongs to Sugar's own "
                "upgrade packages, never to a module package",
            ))
        findings.sort(key=lambda f: (SEVERITY_ORDER[f.severity], f.path, f.line))
        return pkg, findings


BASELINE_PATH = Path(__file__).resolve().parent / "mlp_lint_baseline.json"

# Why a baseline rather than a clean slate
# ---------------------------------------
# These rules were written after the packages they audit, and they find real
# defects that predate them — three of which are security issues with their own
# blast radius and their own testing needs. Landing the gate red would mean
# either blocking every unrelated pull request or, far more likely, somebody
# switching the job off within a week.
#
# So the gate is a ratchet: everything known on the day it landed is recorded
# here, and CI fails on anything NEW. The baseline is keyed by rule and path,
# not by line, so moving code around does not fake a new finding. It is a
# to-do list, not an amnesty — the entries marked blocker should be burned
# down first, and shrinking this file is the point of having it.


def baseline_key(f: Finding) -> str:
    return f"{f.rule} {f.path}"


def load_baseline() -> dict:
    if not BASELINE_PATH.is_file():
        return {"accepted": {}}
    try:
        return json.loads(BASELINE_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {"accepted": {}}


def write_baseline(results: list[tuple[str, list[Finding]]]) -> int:
    accepted: dict[str, dict] = {}
    for _, findings in results:
        for f in findings:
            accepted[baseline_key(f)] = {
                "severity": f.severity,
                "message": f.message,
            }
    BASELINE_PATH.write_text(
        json.dumps(
            {
                "note": (
                    "Findings that predate scripts/mlp_lint.py. CI fails on "
                    "anything not listed here. Shrinking this file is the "
                    "point of it; start with the blockers."
                ),
                "accepted": dict(sorted(accepted.items())),
            },
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    return len(accepted)


def render_text(results: list[tuple[str, list[Finding]]]) -> str:
    lines: list[str] = []
    totals = {BLOCKER: 0, REQUIRED: 0, ADVISORY: 0}
    for name, findings in results:
        if not findings:
            lines.append(f"  {name}: clean")
            continue
        lines.append(f"  {name}: {len(findings)} finding(s)")
        for f in findings:
            totals[f.severity] += 1
            where = f"{f.path}:{f.line}" if f.line else f.path
            lines.append(f"    [{f.severity.upper():8}] {f.rule} {where}")
            lines.append(f"               {f.message}")
            lines.append(f"               fix: {f.remedy}")
    head = (
        f"MLP pre-flight: {totals[BLOCKER]} blocker, "
        f"{totals[REQUIRED]} required, {totals[ADVISORY]} advisory"
    )
    return head + "\n" + "\n".join(lines)


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("targets", nargs="*", help="package directories; default is every package")
    ap.add_argument("--zip", action="append", default=[], help="also audit a built zip")
    ap.add_argument(
        "--zips-from", nargs="+", default=[], metavar="AREA",
        help="audit every zip under <area>/*/releases/ (what CI runs after a build)",
    )
    ap.add_argument("--format", choices=["text", "json"], default="text")
    ap.add_argument("--explain", metavar="RULE", help="print one rule's reasoning and exit")
    ap.add_argument(
        "--fail-on", choices=[BLOCKER, REQUIRED, ADVISORY], default=REQUIRED,
        help="lowest severity that fails the run (default: required)",
    )
    ap.add_argument(
        "--baseline", action="store_true",
        help="fail only on findings absent from scripts/mlp_lint_baseline.json",
    )
    ap.add_argument(
        "--update-baseline", action="store_true",
        help="record every current finding as accepted, then exit",
    )
    args = ap.parse_args(argv)

    if args.explain:
        r = RULES.get(args.explain.upper())
        if not r:
            print(f"no such rule: {args.explain}", file=sys.stderr)
            print("known rules: " + ", ".join(sorted(RULES)), file=sys.stderr)
            return 2
        print(f"{r.ident}  [{r.severity}]  {r.title}")
        print(f"origin: {r.origin}\n")
        print(r.explanation)
        return 0

    repo = Path(__file__).resolve().parent.parent

    # Every zip already built under the named areas. This is what CI audits
    # after its build step: the rules that read a manifest (a duplicated
    # install destination, a beans entry naming a module nobody shipped, a
    # shipped files.md5) can only be evaluated against the generated one, and
    # reading pack.php instead is guesswork.
    zips = list(args.zip)
    for area in args.zips_from:
        base = repo / area
        if not base.is_dir():
            print(f"no such area: {area}", file=sys.stderr)
            return 2
        zips.extend(str(z) for z in sorted(base.glob("*/releases/*.zip")))
    if args.zips_from and not zips:
        print(
            "no built zips found under " + ", ".join(args.zips_from)
            + " - did the build step run?",
            file=sys.stderr,
        )
        return 2

    packages: list[Package] = []
    if args.targets:
        for t in args.targets:
            p = Path(t)
            if not p.is_dir():
                print(f"not a directory: {t}", file=sys.stderr)
                return 2
            packages.append(load_package(p))
    elif not zips:
        packages = discover(repo)
        if not packages:
            print("no packages found", file=sys.stderr)
            return 2

    results: list[tuple[str, list[Finding]]] = []
    for pkg in packages:
        results.append((pkg.name, lint_package(pkg)))
    for z in zips:
        zp = Path(z)
        if not zp.is_file():
            print(f"not a file: {z}", file=sys.stderr)
            return 2
        pkg, findings = lint_zip(zp)
        results.append((f"zip:{pkg.name}", findings))

    if args.update_baseline:
        n = write_baseline(results)
        print(f"recorded {n} accepted finding(s) in {BASELINE_PATH.name}")
        return 0

    if args.format == "json":
        print(json.dumps(
            {name: [f.as_dict() for f in fs] for name, fs in results}, indent=2
        ))
    else:
        print(render_text(results))

    threshold = SEVERITY_ORDER[args.fail_on]
    gating = [
        f for _, fs in results for f in fs
        if SEVERITY_ORDER[f.severity] <= threshold
    ]

    if args.baseline:
        accepted = load_baseline().get("accepted", {})
        new = [f for f in gating if baseline_key(f) not in accepted]
        if not new:
            print(
                f"\nNo new findings. {len(accepted)} known finding(s) are "
                f"recorded in {BASELINE_PATH.name} and still waiting to be fixed."
            )
            return 0
        print(f"\n{len(new)} finding(s) not in the baseline:")
        for f in new:
            where = f"{f.path}:{f.line}" if f.line else f.path
            print(f"  [{f.severity.upper()}] {f.rule} {where}")
            print(f"      {f.message}")
            print(f"      fix: {f.remedy}")
        print(
            "\nFix them, or add `# mlp-lint: ignore <RULE>` on the line with a "
            "reason if the rule is wrong here. Do not run --update-baseline to "
            "silence a finding you introduced."
        )
        return 1

    return 1 if gating else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
