# BenchDogs Sugar Customizations

Customer-specific Sugar packages for BenchDogs. Extracted from
`erp-integration-sugar`'s `benchdog` branch into its own repo.

Each directory in this repo corresponds to a Sugar product. Packages are
built independently per product and installed via Module Loader.

## Products

| Directory | Product | Description |
|-----------|---------|-------------|
| [sugar-sell/](sugar-sell/) | Sugar Sell | BenchDogs' custom extension package for the CRM/sales platform |
| [sugar-predict/](sugar-predict/) | Sugar Predict | BenchDogs' custom extension package for sales intelligence (placeholder) |

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
php pack.php <version>
```

This produces `releases/sugarcrm-<PackageName>-<version>.zip`, ready for
**Admin → Module Loader**.

## Conventions

- All packages use the Sugar Extension Framework — no core file overrides.
- `releases/` directories are gitignored; build artifacts are not committed.
- Each product directory is independently deployable.
