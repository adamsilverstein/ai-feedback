# Continuous Integration

This repository uses GitHub Actions to automatically run linting and tests on all pull requests. This ensures that only high-quality code is merged into the main branch.

## Quick Start

All checks run automatically when you create or update a pull request. You can see the status of these checks at the bottom of your PR.

## Available Workflows

The CI system includes the following workflows:

### JavaScript Linting
**File**: `.github/workflows/lint-js.yml`

Checks JavaScript/CSS code quality and formatting:
- ESLint for JavaScript code quality
- Stylelint for CSS/SCSS quality
- Prettier code formatting check
- Package.json validation

**Triggered on**: Pull requests and pushes when JavaScript, CSS, or package files change

### PHP Linting
**File**: `.github/workflows/lint-php.yml`

Checks PHP code quality and standards compliance:
- PHPCS (PHP_CodeSniffer) with WordPress Coding Standards
- PHPStan static analysis (level 5)

**Triggered on**: Pull requests and pushes when PHP or composer files change

### JavaScript Testing
**File**: `.github/workflows/test-js.yml`

Runs JavaScript unit tests using Jest.

**Triggered on**: Pull requests and pushes when JavaScript or test files change

### PHP Testing
**File**: `.github/workflows/test-php.yml`

Runs PHP unit tests using PHPUnit across multiple PHP versions (8.1, 8.2, 8.3).

**Triggered on**: Pull requests and pushes when PHP or test files change

## Setting Up Branch Protection

To require all checks to pass before merging:

1. Go to **Settings** → **Branches** in your repository
2. Click **Add branch protection rule**
3. Enter your branch name (e.g., `main` or `develop`)
4. Enable **Require status checks to pass before merging**
5. Search for and select these required checks:
   - `JavaScript Linting`
   - `PHP Linting`
   - `JavaScript Unit Tests`
   - `PHP Unit Tests (8.1)` (minimum PHP version)
   - Optionally add `PHP Unit Tests (8.2)`, `PHP Unit Tests (8.3)` for all PHP versions
6. Optionally enable **Require branches to be up to date before merging**
7. Click **Create** or **Save changes**

## Running Checks Locally

Before pushing your code, you can run all the checks locally to catch issues early:

### JavaScript Checks
```bash
# Linting
npm run lint:js          # ESLint
npm run lint:css         # Stylelint
npm run lint:pkg-json    # Package.json validation
npm run format -- --check # Prettier formatting

# Testing
npm run test:unit        # Jest unit tests
```

### PHP Checks
```bash
# Linting
npm run lint:php         # Runs both PHPCS and PHPStan
composer run phpcs       # WordPress Coding Standards
composer run phpstan     # Static analysis

# Testing
npm run test:php         # PHPUnit tests
composer run test        # PHPUnit tests (alternative)
```

### Run Everything
```bash
# JavaScript linting and tests
npm run test:all

# PHP linting
npm run lint:php

# PHP tests
npm run test:php
```

## Fixing Issues

### JavaScript Linting Issues

Many JavaScript linting issues can be auto-fixed:

```bash
npm run format           # Auto-fix Prettier issues
npm run lint:js -- --fix # Auto-fix ESLint issues
npm run lint:css -- --fix # Auto-fix Stylelint issues
```

### PHP Linting Issues

Some PHP coding standard issues can be auto-fixed:

```bash
composer run phpcbf      # Auto-fix PHPCS issues
```

PHPStan issues typically require manual fixes as they relate to type safety and logic.

## Workflow Triggers

Workflows are triggered:

### On Pull Requests
- When you create a PR
- When you push new commits to a PR
- When files matching the workflow's path filters change

### On Push to Main/Develop
- When code is merged or pushed directly to `main` or `develop`

### Path Filters
Workflows only run when relevant files change:

- **JavaScript workflows**: Trigger on `.js`, `.jsx`, `.json`, `.css`, `.scss` files
- **PHP workflows**: Trigger on `.php`, `composer.json`, `composer.lock` files

This makes CI faster by skipping irrelevant checks.

## Troubleshooting

### Check Failed - What Now?

1. **Click on the failed check** in your PR to see detailed logs
2. **Look for the error message** - it will show which file and line failed
3. **Fix the issue locally** and test with the local commands above
4. **Push your fix** - the workflow will re-run automatically

### Common Issues

**"No tests found"** - This is normal if test files don't exist yet. The workflow uses `--passWithNoTests` to allow this.

**"PHPCS/PHPStan not found"** - Make sure you've run `composer install` locally.

**"ESLint errors"** - Run `npm run lint:js -- --fix` to auto-fix many issues.

**"Prettier formatting"** - Run `npm run format` to auto-fix formatting.

### Workflow Not Running

- Check that your branch isn't excluded by the workflow's branch filters
- Check that your changed files match the workflow's path filters
- Ensure GitHub Actions is enabled for your repository

## Understanding CI Results

### ✅ All Checks Passed
Your code meets all quality standards and is ready to merge!

### ⚠️ Some Checks Failed
Click on the failed check to see details. Fix the issues and push again.

### 🟡 Checks Pending
The workflows are still running. Wait for them to complete.

### ⏭️ Checks Skipped
The workflow was skipped because no relevant files changed.

## Performance

The CI system is optimized for speed:

- **Caching**: Node modules and Composer packages are cached between runs
- **Path filters**: Workflows only run when relevant files change
- **Parallel execution**: Different check types run simultaneously
- **Matrix strategy**: PHP tests run in parallel across different PHP versions

Most CI runs complete in 2-5 minutes for typical changes.

## Need Help?

- **Workflow logs**: Click on any check in your PR to see detailed logs
- **Local debugging**: Use the local commands above to reproduce issues
- **Documentation**: See `.github/workflows/README.md` for workflow details
