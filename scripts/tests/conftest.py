"""pytest-only wiring for scripts/tests. Plain `python3 test_x.py` never reads it.

ONE MARKER: `sugarent_tree`. A test carries it when it reads a SugarEnt-Full
source tree (~/Documents/Code/SugarEnt-Full-25.2.0 / -26.1.0): ModuleInstaller,
PackageManager, MlpLogger and friends, read as the platform's own statement of
a fact the package relies on. That source is SugarCRM's, licensed under the
Master Subscription Agreement, and there is no public copy, so CI can never
have it. Pinning excerpts here would turn those tests into fixtures, and a
fixture cannot test the belief it encodes.

A test opts in by DECLARING it, next to the test itself:

    class SomeTest(unittest.TestCase):
        requires_sugarent_tree = True          # every method in the class

    def test_x(self): ...
    test_x.requires_sugarent_tree = True       # one method

(an attribute, not `@pytest.mark`, because the suite imports nothing outside
the standard library and must still run under plain unittest.)

What the marker does: NOTHING by default. Locally the tests run where the tree
exists and skip, visibly, where it does not. CI deselects them explicitly with
`-m "not sugarent_tree"`, so they are not collected there, instead of being
collected and then skipped, and CI checks the deselected set against a list of
names written in .github/workflows/mlp-lint.yml. Marking another test fails
that check; it does not quietly hide the test.

Run exactly this set:  python3 -m pytest scripts/tests -m sugarent_tree -rs
"""
from __future__ import annotations

import pytest

MARKER = "sugarent_tree"
ATTRIBUTE = "requires_sugarent_tree"


def pytest_configure(config):
    config.addinivalue_line(
        "markers",
        f"{MARKER}: reads a proprietary SugarEnt-Full source tree; CI has none "
        f"and deselects these (declare with `{ATTRIBUTE} = True`)",
    )


def _declares_it(item) -> bool:
    if getattr(getattr(item, "cls", None), ATTRIBUTE, False) is True:
        return True
    return getattr(getattr(item, "obj", None), ATTRIBUTE, False) is True


# tryfirst: the marker has to be on the item before the built-in `-m`
# selection reads the markers.
@pytest.hookimpl(tryfirst=True)
def pytest_collection_modifyitems(config, items):
    for item in items:
        if _declares_it(item):
            item.add_marker(MARKER)
