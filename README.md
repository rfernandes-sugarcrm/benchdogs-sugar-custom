# BenchDogs Sugar Customizations

Customer-specific Sugar packages for BenchDogs. Extracted from
`erp-integration-sugar`'s `benchdog` branch into its own repo.

Each directory in this repo corresponds to a Sugar product. Packages are
built independently per product and installed via Module Loader.

## Products

| Directory | Product | Description |
|-----------|---------|-------------|
| [sugar-sell/](sugar-sell/) | Sugar Sell | BenchDogs' custom extension package for the CRM/sales platform (`BenchDogs-Ext`), and the UAT configuration package (`BenchDogs-UAT-Config`: Studio layouts, labels, the Interaction Type field, the navigation bar and the Quick Create menu) |

## How It Works

Each package directory contains:

```
<PackageName>/
    src/        # Extension Framework files (upgrade-safe, no core overrides)
    pack.php    # Executable package builder
    version     # Current version
```

Build a package:

```bash
cd <product>/<PackageName>
php pack.php
```

`pack.php` reads the package's `version` file and dates the zip by the commit
that last changed it, so it runs in a full git clone (or with
`SOURCE_DATE_EPOCH` set).

This produces `releases/sugarcrm-<PackageName>-<version>.zip`, ready for
**Admin → Module Loader**.

## Conventions

- All packages use the Sugar Extension Framework — no core file overrides.
- `releases/` directories are gitignored; build artifacts are not committed.
- Each product directory is independently deployable.
