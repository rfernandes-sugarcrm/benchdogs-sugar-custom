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
to run it. MLP002 below carries those deny-lists transcribed from the scanner's
source, with line references, and matches them with a PHP lexer that agrees
with the real scanner on every file it has been compared against
(scripts/tests/scanner_oracle.php runs the real one). It is still not the
scanner: the scanner also runs Rector, HealthCheck and syntax checks, and a
tenant can widen its lists, so a clean run here is not a promise that the
loader will accept the package.

Detection elsewhere is textual. There is no PHP parser in this repository's CI
and no third-party dependency is worth adding for one job, so every other rule
is a line-oriented pattern over source. That trades some precision for being
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
    "Function, class or method the cloud package scanner denies",
    "The 1.1.0 upload rejection, 2026-09-11; ERP-Epicor 1.1.100 (G222), 2026-09-21",
    """SugarCloud runs ModuleScanner over every file in an uploaded package. A
single call to a denied function, a single `new` of a denied class, or a single
call to a denied method rejects the whole upload before anything installs,
with a per-line "Code attempted to call denylisted function" report.

The lists are TRANSCRIBED from SugarEnt 26.1.0 ModuleInstall/ModuleScanner.php
(identical in 25.2.0), with the line range of each array recorded next to it:
$blackList, plus $unsafeHttpClientFunctions (curl_*, socket_*, fsockopen,
pfsockopen and 46 stream_* functions), which EnhancedModuleChecks merges into
it and SugarCloud always has on; $classBlackList plus the three Smarty classes
SecureSmarty adds; and $methodsBlackList. ERP-Epicor 1.1.100 was refused for
stream_resolve_include_path after this rule, knowing only $blackList, passed
it. `python3 scripts/regen_denylist.py <SugarEnt root> --check` says whether
the transcription still matches the source.

Matching follows the scanner, which parses PHP rather than searching it: a
name in a comment, a string or a heredoc body is not a hit, `function_exists(
'curl_init')` is not a hit, `$obj->stream_get_contents()` is not a hit, and
`Sub\\curl_init()` is not a hit because it names a function in a namespace.
`\\curl_init()`, `CURL_INIT()`, a call whose `(` is on the next line and a
call inside a string's {$...} all are. Checked against the real scanner
(scripts/tests/scanner_oracle.php) over every PHP file in this repository and
the 14,000 in Sugar's own include/, src/ and modules/: no disagreement.

Cannot see: a list extended by a tenant's sugar_config['moduleInstaller'],
which nobody outside Sugar can read. An `mlp-lint: ignore MLP002` comment
silences this rule and never the scanner.""",
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
    "MLP018", REQUIRED,
    "Short Handlebars comment that closes early and leaks onto the page",
    "erp-credit-badge on ossugarcube2, 2026-09-11",
    """A short comment, {{! ... }}, is terminated by the FIRST }} the parser
sees. So a comment whose own text contains {{ or }} closes early, and the rest
of the prose - including the author's intended closing braces - is rendered as
literal text in the UI.

Six templates in this repository did exactly that. Each carried a comment
explaining that badgeStyle is deliberately unescaped "...every value rendered
from the record below it uses escaped {{ }}", and that mention of {{ }} ended
the comment four words early. Every account record drew a stray ". }}" to the
left of its credit, status or source badge, in ERP-Core and ERP-Epicor alike.

The long form, {{!-- ... --}}, is closed by --}} and may contain braces
safely. It is the only correct form for a comment that mentions them, which a
comment about escaping almost always does.

Sees: {{! comments whose body contains {{, or whose text continues past the
parser's closing point. Cannot see: a comment that closes early but happens to
leave only whitespace behind.""",
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
    "Built package ships a file whose name or extension SugarCloud refuses",
    "ossugarcube2, 2026-09-11",
    """ModuleScanner validates every shipped file's name against an allow-list
of extensions, $validExt (ModuleScanner.php:74, checked by isValidExtension at
:680-696). A file named files.md5 is refused by name even though `md5` is on
the list, and so is a file with no extension or an extension off the list: a
.svg icon, a .map, a .DS_Store, a .gitkeep. One such file rejects the package
at install with "File Issues / <file> / Invalid file extension", and it cannot
be installed on SugarCloud at all. Sees: every entry in a built zip, so it runs
in --zip mode only. The one name the scanner lets through without an
extension is LICENSE.

This rule used to say the opposite: that the HealthCheck pass wants files.md5
and its absence was worth reporting. Acting on that advice added one to all
thirteen archives in this repository and made every one of them unloadable,
which is how the truth was found. files.md5 belongs to Sugar's own upgrade and
patch packages, not to module packages, and nothing in this repository should
ever produce one.""",
)


IGNORE_RE = re.compile(r"mlp-lint:\s*ignore\s+([A-Z0-9, ]+)")

GENERATED_MARKER = re.compile(r"^\s*//\s*created:\s", re.MULTILINE)

# MLP018. Any Handlebars comment opener, long form included. The long form
# is NOT excluded: on this platform it closes at the first }} inside it just
# as the short form does, which is how the first fix for this made the page
# read ". --}}" instead of ". }}".
HBS_COMMENT_OPEN = re.compile(r"\{\{!(?:--)?")

# ---------------------------------------------------------------------------
# MLP002: the cloud scanner's deny-lists, TRANSCRIBED FROM THE SCANNER ITSELF
# ---------------------------------------------------------------------------
#
# Source: SugarEnt-Full-26.1.0/ModuleInstall/ModuleScanner.php. It is
# byte-identical in 25.2.0 (sha1 88c9183b2d0a1f97fe547ab2ed31191755aaa352 in
# both). The enforcement is in
# src/Security/ModuleScanner/BlacklistVisitor.php, which is also identical
# between the two versions.
#
# TO RE-SYNC: `python3 scripts/regen_denylist.py <SugarEnt root> --check` exits
# non-zero if any set below has drifted from the scanner, or if a line range
# recorded in SCANNER_SOURCE no longer holds the array it names. Without
# `--check` it prints fresh literals to paste in here.
#
# WHAT THE SCANNER ENFORCES, AND UNDER WHICH FEATURE FLAG. Every flag below is
# on for SugarCloud (read from src/FeatureToggle/Features/<Flag>.php):
#
#   $blackList                  :106-427  always
#   $unsafeHttpClientFunctions  :428-532  merged INTO $blackList at :610-611 when
#                                         EnhancedModuleChecks is on: on by
#                                         default from 12.2.0, not toggleable
#                                         from 13.0.0, and "enforced as true"
#                                         on Sugar's cloud (the flag's own
#                                         description)
#   $classBlackList             :75-105   always
#   smarty, sugar_smarty,       :614-616  added to the class list when
#   sugarpdfsmarty                        SecureSmarty is on (from 14.2.0)
#   $methodsBlackList           :534-542  always
#   $validExt                   :74       always (isValidExtension, :680-696)
#   $blackListExempt,           :70-71    both EMPTY in the shipped source. A
#   $classBlackListExempt                 tenant's sugar_config['moduleInstaller']
#                                         can extend any list (:591-607), which
#                                         nobody outside Sugar can read, so a
#                                         clean result here is a floor, not a
#                                         ceiling.
#
# G222, WHY THE SECOND LIST IS HERE. Until 2026-09-22 this file carried
# $blackList only. ERP-Epicor 1.1.100 linted 0/0/0 and SugarCloud refused it:
#   src/custom/clients/base/api/QuotesErpActionsApi.php
#   Code attempted to call denylisted function "stream_resolve_include_path"
#   on line 2870 (and 3165)
# stream_resolve_include_path is on $unsafeHttpClientFunctions, which the
# scanner merges into $blackList before it looks at a single file. The two are
# ONE list as far as an upload is concerned. They are kept apart here only so
# that each still reads as a transcription of one array in the source.
#
# The pattern behind most of $blackList: ANY FUNCTION THAT TAKES A CALLABLE is
# denied, because it is an indirect-dispatch vector - array_filter, array_map,
# array_reduce, array_walk, usort, uasort, uksort, preg_replace_callback,
# iterator_apply, is_callable, set_error_handler, register_shutdown_function,
# spl_autoload_register. A developer has no reason to suspect any of these,
# which is exactly why the lists must be READ FROM THE SCANNER, not recalled:
# a 33-name list written from memory let ERP-Epicor 1.1.24-rc29 through with
# array_filter, and the hosted scan refused it.
#
# (line ranges in ModuleScanner.php; checked by regen_denylist.py --check)
SCANNER_SOURCE = {
    "file": "ModuleInstall/ModuleScanner.php",
    "sha1": "88c9183b2d0a1f97fe547ab2ed31191755aaa352",
    "validExt": (74, 74),
    "classBlackList": (75, 105),
    "blackList": (106, 427),
    "unsafeHttpClientFunctions": (428, 532),
    "methodsBlackList": (534, 542),
    "enhancedModuleChecksMerge": (610, 611),
    "secureSmartyMerge": (614, 616),
}

# $blackList, ModuleScanner.php:106-427. 253 entries, 250 distinct (passthru,
# call_user_func and call_user_func_array each appear twice).
SCANNER_BLACKLIST = frozenset({
    "addfunction", "addserver", "array_diff_uassoc", "array_diff_ukey",
    "array_filter", "array_intersect_uassoc", "array_intersect_ukey", "array_map",
    "array_reduce", "array_udiff", "array_udiff_assoc", "array_udiff_uassoc",
    "array_uintersect", "array_uintersect_assoc", "array_uintersect_uassoc", "array_walk",
    "array_walk_recursive", "call_user_func", "call_user_func_array", "chdir",
    "chgrp", "chmod", "chown", "chroot",
    "class_alias", "clearstatcache", "construct", "consume",
    "consumerhandler", "copy", "copy_recursive", "create_cache_directory",
    "create_custom_directory", "create_function", "dir", "disk_free_space",
    "disk_total_space", "diskfreespace", "eio_busy", "eio_chmod",
    "eio_chown", "eio_close", "eio_custom", "eio_dup2",
    "eio_fallocate", "eio_fchmod", "eio_fchown", "eio_fdatasync",
    "eio_fstat", "eio_fstatvfs", "eio_fsync", "eio_ftruncate",
    "eio_futime", "eio_grp", "eio_link", "eio_lstat",
    "eio_mkdir", "eio_mknod", "eio_nop", "eio_open",
    "eio_read", "eio_readahead", "eio_readdir", "eio_readlink",
    "eio_realpath", "eio_rename", "eio_rmdir", "eio_sendfile",
    "eio_stat", "eio_statvfs", "eio_symlink", "eio_sync",
    "eio_sync_file_range", "eio_syncfs", "eio_truncate", "eio_unlink",
    "eio_utime", "eio_write", "error_log", "escapeshellarg",
    "escapeshellcmd", "eval", "exec", "fclose",
    "fdf_enum_values", "feof", "fflush", "fgetc",
    "fgetcsv", "fgets", "fgetss", "file",
    "file_get_contents", "file_put_contents", "fileatime", "filectime",
    "filegroup", "fileinode", "filemtime", "fileowner",
    "fileperms", "filesize", "filetype", "flock",
    "fnmatch", "fopen", "forward_static_call", "forward_static_call_array",
    "fpassthru", "fputcsv", "fputs", "fread",
    "fscanf", "fseek", "fstat", "ftell",
    "ftruncate", "fwrite", "get", "getbykey",
    "getdelayed", "getdelayedbykey", "getfunctionvalue", "getimagesize",
    "glob", "gzwrite", "header_register_callback", "highlight_file",
    "ibase_set_event_handler", "ini_set", "is_callable", "is_dir",
    "is_executable", "is_file", "is_link", "is_readable",
    "is_uploaded_file", "is_writable", "is_writeable", "iterator_apply",
    "lchgrp", "lchown", "ldap_set_rebind_proc", "libxml_set_external_entity_loader",
    "link", "linkinfo", "lstat", "mailparse_msg_extract_part",
    "mailparse_msg_extract_part_file", "mailparse_msg_extract_whole_part_file", "mk_temp_dir", "mkdir",
    "mkdir_recursive", "move_uploaded_file", "newt_entry_set_filter", "newt_set_suspend_callback",
    "ob_start", "open", "opendir", "parse_ini_file",
    "parse_ini_string", "passthru", "pathinfo", "pclose",
    "pcntl_signal", "php_strip_whitespace", "popen", "preg_replace_callback",
    "proc_close", "proc_get_status", "proc_nice", "proc_open",
    "readdir", "readfile", "readline_add_history", "readline_callback_handler_install",
    "readline_completion_function", "readline_write_history", "readlink", "realpath",
    "realpath_cache_get", "realpath_cache_size", "register_shutdown_function", "register_tick_function",
    "rename", "rewind", "rmdir", "rmdir_recursive",
    "save_custom_app_list_strings_contents", "session_save_path", "session_set_save_handler", "set_error_handler",
    "set_exception_handler", "set_file_buffer", "set_local_infile_handler", "set_time_limit",
    "setclientcallback", "setcompletecallback", "setdatacallback", "setexceptioncallback",
    "setfailcallback", "setserverparams", "setstatuscallback", "setwarningcallback",
    "setworkloadcallback", "shell_exec", "show_source", "simplexml_load_file",
    "simplexml_load_string", "spl_autoload_register", "sqlite_create_aggregate", "sqlite_create_function",
    "sqlitecreateaggregate", "sqlitecreatefunction", "stat", "sugar_chgrp",
    "sugar_chmod", "sugar_chown", "sugar_file_put_contents", "sugar_file_put_contents_atomic",
    "sugar_fopen", "sugar_mkdir", "sugar_rename", "sugar_touch",
    "sybase_set_message_handler", "symlink", "system", "tempnam",
    "timestampnoncehandler", "tmpfile", "tokenhandler", "touch",
    "uasort", "uksort", "umask", "unlink",
    "unzip", "unzip_file", "usort", "write_array_to_file",
    "write_array_to_file_as_key_value_pair", "xml_set_character_data_handler", "xml_set_default_handler", "xml_set_element_handler",
    "xml_set_end_namespace_decl_handler", "xml_set_external_entity_ref_handler", "xml_set_notation_decl_handler", "xml_set_processing_instruction_handler",
    "xml_set_start_namespace_decl_handler", "xml_set_unparsed_entity_decl_handler",
})

# $unsafeHttpClientFunctions, ModuleScanner.php:428-532. 100 names: 17 curl_*,
# 36 socket_*, fsockopen, pfsockopen, 46 stream_*. Merged into $blackList by
# EnhancedModuleChecks (:610-611). THIS is the list G222 was missing.
SCANNER_UNSAFE_HTTP_CLIENT_FUNCTIONS = frozenset({
    "curl_copy_handle", "curl_exec", "curl_file_create", "curl_init",
    "curl_multi_add_handle", "curl_multi_exec", "curl_multi_getcontent", "curl_multi_info_read",
    "curl_multi_init", "curl_multi_remove_handle", "curl_multi_select", "curl_multi_setopt",
    "curl_setopt", "curl_setopt_array", "curl_share_init", "curl_share_setopt",
    "curl_share_strerror", "fsockopen", "pfsockopen", "socket_accept",
    "socket_addrinfo_bind", "socket_addrinfo_connect", "socket_addrinfo_explain", "socket_addrinfo_lookup",
    "socket_bind", "socket_clear_error", "socket_close", "socket_cmsg_space",
    "socket_connect", "socket_create", "socket_create_listen", "socket_create_pair",
    "socket_export_stream", "socket_get_option", "socket_getopt", "socket_getpeername",
    "socket_getsockname", "socket_import_stream", "socket_last_error", "socket_listen",
    "socket_read", "socket_recv", "socket_recvfrom", "socket_recvmsg",
    "socket_select", "socket_send", "socket_sendmsg", "socket_sendto",
    "socket_set_block", "socket_set_nonblock", "socket_set_option", "socket_setopt",
    "socket_shutdown", "socket_write", "stream_bucket_append", "stream_bucket_make_writeable",
    "stream_bucket_new", "stream_bucket_prepend", "stream_context_create", "stream_context_get_default",
    "stream_context_get_options", "stream_context_get_params", "stream_context_set_default", "stream_context_set_option",
    "stream_context_set_params", "stream_copy_to_stream", "stream_filter_append", "stream_filter_prepend",
    "stream_filter_register", "stream_filter_remove", "stream_get_contents", "stream_get_filters",
    "stream_get_line", "stream_get_meta_data", "stream_get_transports", "stream_get_wrappers",
    "stream_is_local", "stream_isatty", "stream_notification_callback", "stream_register_wrapper",
    "stream_resolve_include_path", "stream_select", "stream_set_blocking", "stream_set_chunk_size",
    "stream_set_read_buffer", "stream_set_timeout", "stream_set_write_buffer", "stream_socket_accept",
    "stream_socket_client", "stream_socket_enable_crypto", "stream_socket_get_name", "stream_socket_pair",
    "stream_socket_recvfrom", "stream_socket_sendto", "stream_socket_server", "stream_socket_shutdown",
    "stream_supports_lock", "stream_wrapper_register", "stream_wrapper_restore", "stream_wrapper_unregister",
})

# What the scanner actually checks every function call against. Kept as the
# name the rest of this file (and its tests) already use.
DENIED_FUNCTIONS = SCANNER_BLACKLIST | SCANNER_UNSAFE_HTTP_CLIENT_FUNCTIONS

# $classBlackList, ModuleScanner.php:75-105, 27 names; lower case because the
# visitor lower-cases before comparing. Checked against `new X` and against
# `class Y extends X`, after resolving X through the file's namespace and `use`
# imports - so `new ZipArchive()` inside a namespace with no import is NOT a
# hit (it names a class in that namespace), while `new \ZipArchive()` is.
SCANNER_CLASS_BLACKLIST = frozenset({
    "lua", "pdo", "reflection",
    "reflectionclass", "reflectionexception", "reflectionextension",
    "reflectionfunction", "reflectionfunctionabstract", "reflectionmethod",
    "reflectionobject", "reflectionparameter", "reflectionproperty",
    "reflectionzendextension", "reflector", "splfileinfo",
    "splfileobject", "sugarautoloader", "sugarcrm\\sugarcrm\\packagebuilder\\filehandlertrait",
    "sugarcrm\\sugarcrm\\packagebuilder\\filehandlertrait_uploadstream", "sugarcronjobs", "sugarcronparalleljobs",
    "sugarmin", "symfony\\component\\expressionlanguage\\serializedparsedexpression",
    "symfony\\component\\filesystem\\filesystem",
    "symfony\\component\\security\\core\\authentication\\token\\abstracttoken",
    "symfony\\component\\security\\core\\authentication\\token\\remembermetoken",
    "ziparchive",
})

# ModuleScanner.php:614-616, SecureSmarty (on from 14.2.0).
SCANNER_SECURE_SMARTY_CLASSES = frozenset({"smarty", "sugar_smarty", "sugarpdfsmarty"})

DENIED_CLASSES = SCANNER_CLASS_BLACKLIST | SCANNER_SECURE_SMARTY_CLASSES

# $methodsBlackList, ModuleScanner.php:534-542, as BlacklistVisitor reads it.
# The four plain VALUES are denied as `$obj->name()` and as `X::name()` for any
# X. The three KEYED entries are denied only as a static call on the named
# class (`SugarAutoLoader::put()`), and `$obj->put()` is fine. A nullsafe call
# (`$obj?->setLevel()`) is a different AST node the visitor never inspects.
SCANNER_METHOD_BLACKLIST = frozenset({"setlevel", "openuri", "unserialize", "extractto"})
SCANNER_CLASS_METHOD_BLACKLIST = {
    "put": frozenset({"sugarautoloader"}),
    "unlink": frozenset({"sugarautoloader"}),
    "minify": frozenset({"sugarmin"}),
}

# $validExt, ModuleScanner.php:74. isValidExtension (:680-696) also refuses a
# file with no extension at all and any file named files.md5, and lets a
# file named LICENSE through. See MLP014.
SCANNER_VALID_EXTENSIONS = frozenset({
    "css", "gif", "hbs", "htm",
    "html", "jpg", "js", "json",
    "less", "md5", "pdf", "php",
    "png", "tpl", "txt", "wsdl",
    "xml",
})

# In the old hand-written set but on NO scanner list. Kept deliberately: worth
# flagging on their own merits, but they do NOT refuse an upload, so they must
# never be reported as though they do. curl_exec used to be in this set; it is
# on $unsafeHttpClientFunctions and DOES refuse the upload. `unserialize` is
# denied only as a METHOD name (SCANNER_METHOD_BLACKLIST), never as a function.
DENIED_ADVISORY_ONLY = {
    "assert", "extract", "scandir", "unserialize",
}

# ---------------------------------------------------------------------------
# MLP002: a PHP lexer, so the deny-lists are matched the way the scanner
# matches them
# ---------------------------------------------------------------------------
#
# ModuleScanner does not search text. It parses every file with nikic
# PhpParser (CodeScanner.php:52-60), runs NameResolver, and BlacklistVisitor
# then inspects FuncCall, MethodCall, StaticCall, New_ and Class_ nodes. So a
# name inside a comment, a string or a heredoc is never a hit, a call split
# across two lines is, and `Sub\curl_init()` is not (it names a function in a
# namespace, not the global).
#
# This used to be a per-line regex over code_lines() output, and measured
# against the real scanner over the 1,645 PHP files in this repository it was
# wrong 49 times (14 invented, 35 missed). The misses came from things that
# are ordinary here: a glob pattern '/*.php' read as the start of a block
# comment, which hid the REST OF THE FILE from the rule; a '#' or '//' inside
# a string, which cut the line short; a call whose `(` sits on the next line.
# The inventions came from JavaScript inside PHP heredocs (`this.model.get(`).
# With the lexer below, the same corpus agrees with the scanner on every hit.
#
# Known limits, all rare in Sugar packages: short open tags (`<?` without
# `php`) are treated as HTML; a `use` import inside a braced namespace block
# leaks into the next block; and a method call on a multi-line chain may be
# reported a line or two below where the scanner reports it.

_PHP_OPEN_TAG = re.compile(r"<\?php(?=[\s]|$)|<\?=", re.IGNORECASE)
_PHP_IDENT = re.compile(r"[A-Za-z_\x80-\U0010ffff][A-Za-z0-9_\x80-\U0010ffff]*")
_PHP_NUMBER = re.compile(
    r"0[xX][0-9a-fA-F_]+|0[bB][01_]+|0[oO][0-7_]+"
    r"|(?:\d[\d_]*(?:\.[\d_]*)?|\.\d[\d_]*)(?:[eE][+-]?\d[\d_]*)?"
)
_PHP_HEREDOC = re.compile(
    r"<<<[ \t]*(['\"]?)([A-Za-z_\x80-\U0010ffff][A-Za-z0-9_\x80-\U0010ffff]*)\1\r?\n"
)
_PHP_OPS = ("?->", "...", "->", "::", "=>", "#[", "\\")


class _PhpLexer:
    """Tokens of the PHP code in a file, as (kind, value, line).

    kind is one of: "id" (identifier or keyword), "var" ($name), "str" (a
    string with no interpolation; value is its text), "estr" (a string with
    interpolation, or a heredoc), "op" (punctuation). Comments, whitespace and
    inline HTML produce nothing. Code inside a string's {$...} is lexed as
    code, because the parser sees it as code too.
    """

    def __init__(self, text: str) -> None:
        self.s = text
        self.n = len(text)
        self.i = 0
        self.line = 1
        self.toks: list[tuple[str, str, int]] = []

    def run(self) -> list[tuple[str, str, int]]:
        while self.i < self.n:
            m = _PHP_OPEN_TAG.search(self.s, self.i)
            self._to(m.end() if m else self.n)
            self._code(interp=False)
        return self.toks

    def _to(self, j: int) -> None:
        self.line += self.s.count("\n", self.i, j)
        self.i = j

    def _emit(self, kind: str, value: str, line: int | None = None) -> None:
        self.toks.append((kind, value, self.line if line is None else line))

    def _code(self, interp: bool) -> None:
        s, n = self.s, self.n
        depth = 0
        while self.i < n:
            i = self.i
            c = s[i]
            if c in " \t\r\n\f\v":
                j = i + 1
                while j < n and s[j] in " \t\r\n\f\v":
                    j += 1
                self._to(j)
                continue
            if c == "?" and s.startswith("?>", i) and not interp:
                self._to(i + 2)
                return
            if c == "#" and not s.startswith("#[", i) or s.startswith("//", i):
                j = i
                while j < n and s[j] != "\n" and not s.startswith("?>", j):
                    j += 1
                self._to(j)
                continue
            if s.startswith("/*", i):
                j = s.find("*/", i + 2)
                self._to(n if j == -1 else j + 2)
                continue
            if c == "'":
                self._single_quoted()
                continue
            if c == '"' or c == "`":
                if c == "`":
                    self._emit("op", "`")
                self._interpolated(c)
                continue
            if c == "<" and s.startswith("<<<", i):
                m = _PHP_HEREDOC.match(s, i)
                if m:
                    self._heredoc(m)
                    continue
            if c == "$":
                m = _PHP_IDENT.match(s, i + 1)
                if m:
                    self._emit("var", m.group(0))
                    self._to(m.end())
                    continue
            m = _PHP_IDENT.match(s, i)
            if m:
                self._emit("id", m.group(0))
                self._to(m.end())
                if m.group(0).lower() == "__halt_compiler":
                    # Everything after it is raw data, not PHP.
                    self._to(n)
                    return
                continue
            if c.isdigit() or (c == "." and i + 1 < n and s[i + 1].isdigit()):
                m = _PHP_NUMBER.match(s, i)
                if m and m.end() > i:
                    self._to(m.end())
                    continue
            for op in _PHP_OPS:
                if s.startswith(op, i):
                    self._emit("op", op)
                    self._to(i + len(op))
                    break
            else:
                if interp:
                    if c == "{":
                        depth += 1
                    elif c == "}":
                        if depth == 0:
                            self._to(i + 1)
                            return
                        depth -= 1
                self._emit("op", c)
                self._to(i + 1)

    def _single_quoted(self) -> None:
        s, n = self.s, self.n
        start, line = self.i, self.line
        j = start + 1
        while j < n and s[j] != "'":
            j += 2 if s[j] == "\\" else 1
        body = s[start + 1:min(j, n)].replace("\\\\", "\\").replace("\\'", "'")
        self._emit("str", body, line)
        self._to(min(j + 1, n))

    def _interpolated(self, quote: str | None, end: int | None = None) -> None:
        """A double-quoted string, a backtick string, or a heredoc body.

        For a heredoc, `quote` is None and `end` is where the body stops.
        """
        s = self.s
        limit = self.n if end is None else end
        line = self.line
        j = self.i + (1 if quote else 0)
        body_start = j
        interpolated = False
        while j < limit:
            ch = s[j]
            if ch == "\\":
                j += 2
                continue
            if quote and ch == quote:
                break
            if ch == "$" and j + 1 < limit and (
                s[j + 1] == "{" or _PHP_IDENT.match(s, j + 1)
            ):
                interpolated = True
            if (ch == "{" and s.startswith("{$", j)) or (ch == "$" and s.startswith("${", j)):
                interpolated = True
                self._to(j + (1 if ch == "{" else 2))
                self._code(interp=True)
                j = self.i
                continue
            j += 1
        if quote == '"' and not interpolated:
            self._emit("str", s[body_start:j], line)
        elif quote != "`":
            self._emit("estr", "", line)
        self._to(min(j + 1, self.n) if quote else limit)

    def _heredoc(self, m: re.Match) -> None:
        nowdoc = m.group(1) == "'"
        label = m.group(2)
        body = m.end()
        close = re.compile(
            r"^[ \t]*" + re.escape(label) + r"(?![A-Za-z0-9_\x80-\U0010ffff])",
            re.MULTILINE,
        ).search(self.s, body)
        stop = close.start() if close else self.n
        after = close.end() if close else self.n
        line = self.line
        self._to(body)
        if nowdoc:
            self._emit("estr", "", line)
            self._to(after)
            return
        self._interpolated(None, stop)
        self._to(after)


# Keywords that look like `name(` but are language constructs. `eval` is the
# one that matters: the scanner reports it as its own node (EvalUsed), and so
# do we, rather than as a call to a function called eval.
_PHP_CONSTRUCTS = frozenset({
    "array", "list", "isset", "unset", "empty", "exit", "die", "eval",
    "include", "include_once", "require", "require_once", "print", "echo",
    "if", "elseif", "while", "for", "foreach", "switch", "match", "declare",
    "catch", "return", "function", "fn", "new", "clone", "yield", "static",
    "self", "parent", "and", "or", "xor", "use", "namespace", "class",
    "interface", "trait", "enum", "instanceof", "case", "throw",
})

# A name straight after one of these is being declared or dereferenced, not
# called as a global function.
_PHP_NOT_A_FUNCTION_AFTER = frozenset({
    "->", "?->", "::", "new", "function", "fn", "const", "class", "interface",
    "trait", "enum", "extends", "implements", "instanceof", "insteadof", "goto",
    "use", "namespace",
})


def _php_read_name(toks, k):
    """(parts, fully_qualified, next_index) for a name starting at toks[k].

    `parts` is None when toks[k] does not start a name.
    """
    fq = False
    if toks[k] == ("op", "\\", toks[k][2]):
        if k + 1 < len(toks) and toks[k + 1][0] == "id":
            fq = True
            k += 1
        else:
            return None, False, k + 1
    if toks[k][0] != "id":
        return None, False, k + 1
    parts = [toks[k][1]]
    k += 1
    while (
        k + 1 < len(toks)
        and toks[k][0] == "op" and toks[k][1] == "\\"
        and toks[k + 1][0] == "id"
    ):
        parts.append(toks[k + 1][1])
        k += 2
    return parts, fq, k


def _php_use_imports(toks):
    """The file's `use` imports: (class aliases, function aliases).

    Both map a lower-cased alias to the fully-qualified name it stands for.
    Only top-level `use` statements count; a closure's `use (...)` and a
    trait `use` inside a class body are not imports.
    """
    classes: dict[str, str] = {}
    functions: dict[str, str] = {}
    depth = 0
    namespace_braced = False
    k = 0
    while k < len(toks):
        kind, value, _ = toks[k]
        if kind == "op" and value == "{":
            depth += 1
        elif kind == "op" and value == "}":
            depth -= 1
        elif kind == "id" and value.lower() == "namespace":
            nxt = toks[k + 1] if k + 1 < len(toks) else None
            if nxt and not (nxt[0] == "op" and nxt[1] == "\\"):
                j = k + 1
                while j < len(toks) and not (toks[j][0] == "op" and toks[j][1] in ";{"):
                    j += 1
                namespace_braced = j < len(toks) and toks[j][1] == "{"
        elif (
            kind == "id" and value.lower() == "use"
            and depth == (1 if namespace_braced else 0)
            and not (k + 1 < len(toks) and toks[k + 1][1] == "(")
        ):
            k = _php_parse_use(toks, k + 1, classes, functions)
            continue
        k += 1
    return classes, functions


def _php_parse_use(toks, k, classes, functions):
    """Parse one `use` statement from toks[k]; return the index after it."""
    default = "class"
    if k < len(toks) and toks[k][0] == "id" and toks[k][1].lower() in ("function", "const"):
        default = toks[k][1].lower()
        k += 1

    def add(kind, full, alias):
        table = functions if kind == "function" else classes if kind == "class" else None
        if table is not None:
            table[alias.lower()] = full

    while k < len(toks) and toks[k][1] != ";":
        parts, _fq, k = _php_read_name(toks, k)
        if parts is None:
            continue
        prefix = "\\".join(parts)
        # Group form: use A\{B, function c, D as E};
        if k + 1 < len(toks) and toks[k][1] == "\\" and toks[k + 1][1] == "{":
            k += 2
            while k < len(toks) and toks[k][1] != "}":
                kind = default
                if toks[k][0] == "id" and toks[k][1].lower() in ("function", "const"):
                    kind = toks[k][1].lower()
                    k += 1
                sub, _fq, k = _php_read_name(toks, k)
                if sub is None:
                    continue
                alias = sub[-1]
                if k + 1 < len(toks) and toks[k][0] == "id" and toks[k][1].lower() == "as":
                    alias = toks[k + 1][1]
                    k += 2
                add(kind, prefix + "\\" + "\\".join(sub), alias)
                if k < len(toks) and toks[k][1] == ",":
                    k += 1
            k += 1
            continue
        alias = parts[-1]
        if k + 1 < len(toks) and toks[k][0] == "id" and toks[k][1].lower() == "as":
            alias = toks[k + 1][1]
            k += 2
        add(default, prefix, alias)
        if k < len(toks) and toks[k][1] == ",":
            k += 1
    return k + 1


# Words that end a receiver expression when walking backwards from `->`.
_PHP_EXPR_STOP = frozenset({
    "return", "echo", "print", "throw", "yield", "case", "else", "elseif",
    "do", "if", "while", "for", "foreach", "switch", "match", "and", "or",
    "xor", "instanceof", "as", "include", "include_once", "require",
    "require_once", "clone", "fn", "function", "use", "global", "const",
})


def _php_expr_start(toks, p):
    """Index of the first token of the receiver ending just before toks[p].

    Used to put a method-call finding on the line the scanner reports, which
    is where the WHOLE call expression starts (`$logger` in a chain that puts
    `->setLevel()` three lines lower), not where the method name is. Walks
    back one postfix element at a time: a bracketed group, then a name or
    variable, joined by `->`, `?->`, `::` or `\\`.
    """
    closers = {")": "(", "]": "["}
    k = p - 1
    while k >= 0:
        kind, value, _ = toks[k]
        if kind == "op" and value in closers:
            opener, depth = closers[value], 1
            while k > 0 and depth:
                k -= 1
                if toks[k][0] == "op" and toks[k][1] == value:
                    depth += 1
                elif toks[k][0] == "op" and toks[k][1] == opener:
                    depth -= 1
            before = toks[k - 1] if k else None
            if before is None or not (
                before[0] in ("var", "str", "estr")
                or (before[0] == "id" and before[1].lower() not in _PHP_EXPR_STOP)
                or (before[0] == "op" and before[1] in (")", "]"))
            ):
                return k
            k -= 1
            continue
        if kind in ("var", "str", "estr") or (kind == "id" and value.lower() not in _PHP_EXPR_STOP):
            j = k - 1
            if j >= 0 and toks[j][0] == "op" and toks[j][1] in ("->", "?->", "::", "\\"):
                k = j - 1
                continue
            if j >= 0 and toks[j][0] == "id" and toks[j][1].lower() == "new":
                return j
            return k
        return k + 1
    return 0


def scanner_denylist_hits(text: str) -> list[tuple[int, str, str]]:
    """Every place ModuleScanner's BlacklistVisitor would report in this file.

    Returns (line, kind, name). `kind` is one of:
      function        a call to a name on SCANNER_BLACKLIST
      unsafe_function a call to a name on SCANNER_UNSAFE_HTTP_CLIENT_FUNCTIONS
      eval            eval(...), which the scanner always reports
      class_new       `new X` with X on DENIED_CLASSES
      class_extends   `class Y extends X` with X on DENIED_CLASSES
      method          `$o->m()` or `X::m()` with m on SCANNER_METHOD_BLACKLIST
      class_method    `X::m()` on SCANNER_CLASS_METHOD_BLACKLIST
      shell_exec      a backtick string, which the scanner always reports
      halt_compiler   __halt_compiler(), which the scanner always reports
    """
    toks = _PhpLexer(text).run()
    class_aliases, function_aliases = _php_use_imports(toks)
    namespace = ""
    hits: list[tuple[int, str, str]] = []
    # Brackets we are inside, so an attribute's class name (`#[Get('/x')]`) is
    # not read as a call to get().
    brackets: list[str] = []
    in_class_header = False

    def resolve_class(parts, fq):
        if fq:
            return "\\".join(parts)
        first = parts[0].lower()
        if len(parts) == 1 and first in ("self", "static", "parent"):
            return parts[0]
        if first in class_aliases:
            return "\\".join([class_aliases[first]] + parts[1:])
        return "\\".join(([namespace] if namespace else []) + parts)

    k = 0
    while k < len(toks):
        kind, value, line = toks[k]
        prev = toks[k - 1] if k else ("op", "", 0)
        # PHP keywords are case-insensitive: `NEW Foo`, `Function get(`.
        if prev[0] == "id":
            prev = ("id", prev[1].lower(), prev[2])

        starts_fq_name = (
            kind == "op" and value == "\\"
            and k + 1 < len(toks) and toks[k + 1][0] == "id"
        )
        if kind == "op" and not starts_fq_name:
            if value == "`":
                hits.append((line, "shell_exec", "`"))
            if value in ("(", "[", "#["):
                brackets.append(value)
            elif value in (")", "]") and brackets:
                brackets.pop()
            elif value in ("{", ";"):
                in_class_header = False
            if value == "->" and k + 2 < len(toks) and toks[k + 1][0] == "id" and toks[k + 2][1] == "(":
                name = toks[k + 1][1]
                if name.lower() in SCANNER_METHOD_BLACKLIST:
                    hits.append((toks[_php_expr_start(toks, k)][2], "method", name))
            # `$class::setLevel()`: a static call whose class is not a name.
            # (A named class is handled below, with the name.)
            if value == "::" and k + 2 < len(toks) and toks[k + 1][0] == "id" and toks[k + 2][1] == "(":
                name = toks[k + 1][1]
                if name.lower() in SCANNER_METHOD_BLACKLIST:
                    hits.append((toks[_php_expr_start(toks, k)][2], "method", name))
            k += 1
            continue

        if kind == "str":
            # 'curl_init'(...): a FuncCall whose name is a string. The scanner
            # checks the string against the list, and so do we.
            if (
                k + 1 < len(toks) and toks[k + 1][1] == "("
                and prev[1] not in _PHP_NOT_A_FUNCTION_AFTER
                and value.lstrip("\\").lower() in DENIED_FUNCTIONS
            ):
                name = value.lstrip("\\")
                hits.append((line, _function_kind(name), name))
            k += 1
            continue

        if kind != "id" and not starts_fq_name:
            k += 1
            continue

        lower = value.lower()
        if kind == "id" and lower == "namespace" and not (
            k + 1 < len(toks) and toks[k + 1][1] == "\\"
        ):
            parts, _fq, j = _php_read_name(toks, k + 1) if k + 1 < len(toks) else (None, False, k + 1)
            namespace = "\\".join(parts) if parts else ""
            k = j
            continue
        if kind == "id" and lower in ("class", "trait", "interface", "enum") and prev[1] != "::":
            in_class_header = lower == "class"
            k += 1
            continue
        if kind == "id" and lower == "extends" and in_class_header:
            parts, fq, j = _php_read_name(toks, k + 1)
            if parts and resolve_class(parts, fq).lower() in DENIED_CLASSES:
                hits.append((line, "class_extends", "\\".join(parts)))
            k = j
            continue
        if kind == "id" and lower == "new":
            nxt = toks[k + 1] if k + 1 < len(toks) else None
            if nxt and nxt[0] == "id" and nxt[1].lower() == "class":
                k += 1
                continue
            if not nxt or not (nxt[0] == "id" or nxt[1] == "\\"):
                k += 1
                continue
            parts, fq, j = _php_read_name(toks, k + 1)
            if parts and resolve_class(parts, fq).lower() in DENIED_CLASSES:
                hits.append((line, "class_new", "\\".join(parts)))
            k = j if parts else k + 1
            continue
        if kind == "id" and lower == "__halt_compiler":
            hits.append((line, "halt_compiler", value))
            break
        if kind == "id" and lower == "eval" and k + 1 < len(toks) and toks[k + 1][1] == "(":
            if prev[1] not in _PHP_NOT_A_FUNCTION_AFTER:
                hits.append((line, "eval", value))
            k += 1
            continue

        start_line = line
        parts, fq, j = _php_read_name(toks, k)
        if parts is None:
            k = j
            continue
        nxt = toks[j] if j < len(toks) else ("op", "", 0)

        if nxt[1] == "::" and j + 2 < len(toks) and toks[j + 1][0] == "id" and toks[j + 2][1] == "(":
            method = toks[j + 1][1]
            if prev[1] not in ("->", "?->", "::"):
                if method.lower() in SCANNER_METHOD_BLACKLIST:
                    hits.append((start_line, "method", method))
                owners = SCANNER_CLASS_METHOD_BLACKLIST.get(method.lower())
                if owners and resolve_class(parts, fq).lower() in owners:
                    hits.append((start_line, "class_method", "\\".join(parts) + "::" + method))
            k = j + 2
            continue

        if nxt[1] != "(" or prev[1] in _PHP_NOT_A_FUNCTION_AFTER:
            k = j
            continue
        if prev[1] == "&" and k >= 2 and toks[k - 2][1].lower() == "function":
            k = j
            continue
        if brackets and brackets[-1] == "#[" and prev[1] in ("#[", ","):
            k = j
            continue
        if len(parts) != 1:
            k = j
            continue
        name = parts[0]
        if not fq and name.lower() in _PHP_CONSTRUCTS:
            k = j
            continue
        resolved = name if fq else function_aliases.get(name.lower(), name)
        if resolved.lstrip("\\").lower() in DENIED_FUNCTIONS:
            hits.append((start_line, _function_kind(resolved.lstrip("\\")), name))
        k = j
    return hits


def _function_kind(name: str) -> str:
    return (
        "unsafe_function"
        if name.lower() in SCANNER_UNSAFE_HTTP_CLIENT_FUNCTIONS
        else "function"
    )


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
    # (?<![\w/]) rather than a bare \b. With \b alone the word `include`
    # matches INSIDE a path such as 'custom/include/scripts/Modules/X.php', so
    # a plain string assignment of that path is read as an include site and the
    # capture group returns '/scripts/Modules/X.php' as a phantom second path.
    # MLP001 then reports "2 distinct paths" for a class that is required once,
    # and names a "Cannot redeclare class" fatal that cannot happen. That is
    # how it blocked Partial Fulfillment 1.0.20 and 1.0.21, whose
    # scripts/pre_uninstall.php assigns exactly such a path in order to
    # file_exists-check it before use. custom/include/ is this estate's
    # standard destination for packaged scripts, so every package here is
    # exposed to it. A real include keyword is never preceded by a slash or a
    # word character, so excluding those loses no genuine site.
    r"(?<![\w/])(require_once|require|include_once|include)\b\s*\(?\s*(.+?)\s*\)?\s*;"
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
    # True when this Package was reconstructed from a built archive rather than
    # read from the source tree. A zip has no `unshipped` list to consult - the
    # source directory it was built from is not there to look at - so rules that
    # ask whether a file EXISTS cannot be answered from one, and must sit out.
    from_zip: bool = False

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


_DENYLIST_MESSAGES = {
    "function": "calls {name}(), which is on ModuleScanner's $blackList",
    "unsafe_function": (
        "calls {name}(), which is on ModuleScanner's $unsafeHttpClientFunctions; "
        "EnhancedModuleChecks merges that list into the denylist and SugarCloud "
        "always runs with it on"
    ),
    "eval": "uses eval(), which ModuleScanner refuses unconditionally",
    "class_new": "instantiates {name}, which is on ModuleScanner's class denylist",
    "class_extends": "extends {name}, which is on ModuleScanner's class denylist",
    "method": "calls a method named {name}(), which ModuleScanner's $methodsBlackList denies on any object or class",
    "class_method": "calls {name}(), which ModuleScanner's $methodsBlackList denies on that class",
    "shell_exec": "uses a backtick string (shell execution), which ModuleScanner refuses unconditionally",
    "halt_compiler": "uses __halt_compiler(), which ModuleScanner refuses unconditionally",
}


def check_denied_functions(pkg: Package) -> list[Finding]:
    """MLP002. What gets the whole upload rejected on SugarCloud.

    Every file, generated or not: the scanner skips nothing, so neither may
    this. The lexer in scanner_denylist_hits() is what keeps a comment, a
    string or a heredoc that merely NAMES a denied function from firing, and it
    is also what catches a call the old per-line regex could not see.
    """
    findings: list[Finding] = []
    for f in pkg.php_files:
        text = read(f)
        hits = scanner_denylist_hits(text)
        if not hits:
            continue
        lines = text.splitlines()
        for line_no, kind, name in hits:
            window = "\n".join(lines[max(0, line_no - 4):line_no])
            if ignored(window, "MLP002"):
                continue
            findings.append(Finding(
                "MLP002", BLOCKER, rel(f, pkg.root), line_no,
                _DENYLIST_MESSAGES[kind].format(name=name)
                + "; a single occurrence rejects the entire upload before "
                "anything installs",
                "Remove the call, or move the work out of the package and "
                "into the orchestrator",
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
    # 🛑 MATCH THE (CLASS, METHOD) PAIR, NOT THE BARE METHOD NAME.
    # _hook_methods() builds pairs for a reason and this check used to drop the
    # class: `wanted = {m for _, m in pairs}`. Any method whose NAME collided
    # with a registered one was then reported as an unguarded hook, in whatever
    # class it happened to live in. G128 made that visible by giving ERP-Epicor
    # a hook registered as `apply`, which flagged ERP-Core's PRIVATE
    # ErpEstimatingStamps::apply and ErpNativeShippingMirror::apply -- neither
    # of them registered, and both already guarded by their public callers
    # (stamp() and mirror(), which are what the fragments actually name, and
    # which both catch Throwable). Two REQUIRED findings, both false.
    for f in pkg.php_files:
        if is_vendored(f):
            continue
        text = read(f)
        if is_generated(text):
            continue
        class_at = [(mm.start(), mm.group(1))
                    for mm in re.finditer(r"\bclass\s+(\w+)", text)]
        for m in re.finditer(r"function\s+(\w+)\s*\(", text):
            method = m.group(1)
            enclosing = None
            for pos, name in class_at:
                if pos < m.start():
                    enclosing = name
                else:
                    break
            if (enclosing, method) not in pairs:
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
    #
    # Which is exactly why it cannot run against a zip. Tests are deliberately
    # never shipped, so from inside an archive every cited test looks missing
    # and the rule reports the correct behaviour as a defect. It did: the
    # Partial Fulfillment zip was flagged for citing ErpQuoteLineRollupTest.php
    # while that test sat, present and passing, in the package's own tests/
    # directory. The source pass sees both halves and is the one that can judge.
    if pkg.from_zip:
        return findings
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


def check_short_hbs_comment(pkg: Package) -> list[Finding]:
    """MLP018. A template comment containing braces, which leaks onto the page."""
    findings: list[Finding] = []
    for f in pkg.hbs_files:
        text = read(f)
        for m in HBS_COMMENT_OPEN.finditer(text):
            start = m.end()
            # Where the comment's author meant it to end, and where this
            # runtime actually ends it: the first closing pair, long form or
            # not. Everything between is comment prose about to be rendered.
            close = text.find("}}", start)
            if close == -1:
                continue
            body = text[start:close]
            if "{{" not in body and "}}" not in body:
                continue
            line_no = text.count("\n", 0, m.start()) + 1
            findings.append(Finding(
                "MLP018", REQUIRED, rel(f, pkg.root), line_no,
                "comment contains braces, so it closes at the first closing "
                "pair inside it and the rest is drawn on the page",
                "Describe the braces in words instead. The long form "
                "{{!-- --}} does NOT make this safe on this platform",
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
            "uses triple-mustache with no comment saying why the value is "
            "safe unescaped",
            # The long form specifically. This remedy used to say "{{! ... }}",
            # and following it put a stray ". }}" on six account badges,
            # because a comment about escaping mentions braces and the short
            # form closes at the first }} it meets. See MLP018.
            "Add a {{!-- ... --}} comment naming the source of the value",
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


rule(
    "MLP019", BLOCKER,
    "ModuleBuilder metadata parser classes in packaged PHP",
    "ERP-Epicor 1.1.24-rc8 through rc11.2 on a SugarCloud tenant, 2026-09-13",
    """SugarCloud runs Rector over every PHP file in a package before it
installs. Rector resolves each class the package names through the instance's
own autoloader, after first emptying Sugar's class map, and Sugar's autoloader
has no rule for modules/ModuleBuilder/parsers/views/. On an affected tenant the
scan stops with `Class "AbstractMetaDataImplementation" not found` at every
file that names DeployedMetaDataImplementation, reports it as "PHP Compatibility
Issues", and refuses the whole package. Before this rule existed, rc8, rc9 and
rc10 died mid-install with the same error, and rc11.2 was refused at the scan
on one tenant while installing cleanly on another. A local Rector run of the
same files came back clean, so no local gate caught it.

Guarding the include, deferring it into a constructor, or swapping the literal
include for class_exists() all failed on the hosted tenant. What removes the
risk is not naming the parser classes at all: read and write client viewdefs
with Sugarcrm\\Sugarcrm\\MetaData\\ViewdefManager, which is namespaced and
autoloads through src/.

Sees: the class names AbstractMetaDataImplementation,
DeployedMetaDataImplementation, DeployedSidecarSubpanelImplementation,
MetaDataImplementationInterface and ParserFactory in code (comments stripped,
string arguments such as class_exists('...') included), and any literal
include of modules/ModuleBuilder/parsers/views/. Cannot see: a class name built
at runtime.""",
)

PARSER_CLASS_RE = re.compile(
    r"\b(AbstractMetaDataImplementation|DeployedMetaDataImplementation|"
    r"DeployedSidecarSubpanelImplementation|MetaDataImplementationInterface|"
    r"ParserFactory)\b"
)
PARSER_INCLUDE_RE = re.compile(
    r"\b(?:require|include)(?:_once)?\s*\(?\s*['\"]modules/ModuleBuilder/parsers/views/"
)


def check_metadata_parser_usage(pkg: Package) -> list[Finding]:
    """MLP019. Package code that makes hosted Rector resolve a ModuleBuilder parser."""
    findings: list[Finding] = []
    for f in pkg.php_files:
        text = read(f)
        if is_generated(text):
            continue
        for line_no, line, code, window in code_lines(text):
            include = PARSER_INCLUDE_RE.search(code)
            named = PARSER_CLASS_RE.search(code)
            if not (include or named):
                continue
            if ignored(window, "MLP019"):
                continue
            what = (
                "includes a ModuleBuilder parser file by path"
                if include
                else f"names {named.group(1)}"
            )
            findings.append(Finding(
                "MLP019", BLOCKER, rel(f, pkg.root), line_no,
                f"{what}; SugarCloud's Rector scan resolves it through the "
                f"tenant's autoloader and can refuse the whole package with "
                f"Class \"AbstractMetaDataImplementation\" not found",
                "Load and save the viewdef with "
                "Sugarcrm\\Sugarcrm\\MetaData\\ViewdefManager instead of a "
                "ModuleBuilder parser class",
            ))
    return findings


SOURCE_RULES = [
    check_class_redeclare,
    check_metadata_parser_usage,
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
    check_short_hbs_comment,
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


def scanner_accepts_file_name(name: str) -> bool:
    """ModuleScanner::isValidExtension (ModuleScanner.php:680-696), in Python.

    PHP's pathinfo() takes the extension from the LAST dot of the basename, so
    `.DS_Store` has the extension `ds_store` and `LICENSE` has none.
    """
    base = name.rstrip("/").rsplit("/", 1)[-1].lower()
    if base == "license":
        return True
    if "." not in base or base == "files.md5":
        return False
    ext = base.rsplit(".", 1)[1]
    return bool(ext) and ext in SCANNER_VALID_EXTENSIONS


def lint_zip(path: Path) -> tuple[Package, list[Finding]]:
    import tempfile
    with tempfile.TemporaryDirectory() as tmp:
        dest = Path(tmp) / "pkg"
        with zipfile.ZipFile(path) as zf:
            names = zf.namelist()
            zf.extractall(dest)
        pkg = load_package(dest, path.name)
        pkg.from_zip = True
        findings = lint_package(pkg)
        if any(n.strip("/").lower().endswith(".md5") for n in names):
            findings.append(Finding(
                "MLP014", BLOCKER, path.name, 0,
                "ships a .md5 file, which ModuleScanner refuses; "
                "SugarCloud rejects the package with \"Invalid file extension\"",
                "Remove it from the archive. files.md5 belongs to Sugar's own "
                "upgrade packages, never to a module package",
            ))
        for name in names:
            if name.endswith("/") or name.lower().endswith(".md5"):
                continue
            if not scanner_accepts_file_name(name):
                findings.append(Finding(
                    "MLP014", BLOCKER, name, 0,
                    "has a name ModuleScanner refuses (its extension is not on "
                    "$validExt); SugarCloud rejects the package with "
                    "\"Invalid file extension\"",
                    "Remove it from the archive, or ship it under an extension "
                    "on the list: " + ", ".join(sorted(SCANNER_VALID_EXTENSIONS)),
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
    # missing files.md5) can only be evaluated against the generated one, and
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
