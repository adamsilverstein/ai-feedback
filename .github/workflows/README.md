# GitHub Actions Workflows

This directory contains GitHub Actions workflows for continuous integration and code quality checks.

## Workflows

### JavaScript Linting

**File**: `lint-js.yml`

Checks JavaScript/CSS code quality and formatting.

**Checks performed**:
- ESLint for JavaScript code quality
- Stylelint for CSS/SCSS code quality
- Package.json format validation
- Prettier code formatting check

**Triggered on**: Pull requests and pushes when JavaScript, CSS, or package files change

### PHP Linting

**File**: `lint-php.yml`

Checks PHP code quality and standards compliance.

**Checks performed**:
- PHPCS (PHP_CodeSniffer) with WordPress Coding Standards
- PHPStan static analysis (level 5)

**Triggered on**: Pull requests and pushes when PHP or composer files change

### JavaScript Testing

**File**: `test-js.yml`

Runs JavaScript unit tests using Jest.

**Tests run**:
- All Jest unit tests in `tests/js/`

**Triggered on**: Pull requests and pushes when JavaScript or test files change

### PHP Testing

**File**: `test-php.yml`

Runs PHP unit tests using PHPUnit across multiple PHP versions.

**Tests run**:
- PHPUnit tests across PHP 8.1, 8.2, and 8.3

**Triggered on**: Pull requests and pushes when PHP or test files change

## Setting Up Branch Protection

To enforce these checks before merging pull requests:

1. Go to your repository settings
2. Navigate to **Branches** → **Branch protection rules**
3. Add a rule for your main branch (e.g., `main`)
4. Enable **Require status checks to pass before merging**
5. Add the following required status checks:
   - `JavaScript Linting`
   - `PHP Linting`
   - `JavaScript Unit Tests`
   - `PHP Unit Tests (8.1)` - Select at minimum `PHP Unit Tests (8.1)`, or optionally all PHP versions (8.1, 8.2, 8.3)
6. Enable **Require branches to be up to date before merging** (recommended)
7. Save the branch protection rule

This ensures all linting and testing must pass before code can be merged.

## Running Checks Locally

Before pushing code, you can run these checks locally:

### JavaScript
```bash
npm run lint:js          # ESLint
npm run lint:css         # Stylelint
npm run lint:pkg-json    # Package.json format
npm run format -- --check # Prettier
npm run test:unit        # Jest tests
```

### PHP
```bash
composer run phpcs       # PHPCS
composer run phpstan     # PHPStan
composer run test        # PHPUnit tests
```

### All checks
```bash
npm run test:all         # Runs JS linting and tests
composer run phpcs && composer run phpstan && composer run test
```

## Updating Workflows

When modifying workflows:

1. Test changes on a feature branch first
2. Ensure workflow files use consistent Node.js/PHP versions
3. Keep caching strategies aligned with dependencies
4. Update this README if adding new workflows or changing required checks

## Troubleshooting

### Workflow not triggering

- Check the `paths` filter matches your changed files
- Ensure branch names match the `branches` filter
- Verify workflow YAML syntax is valid

### Cache issues

- GitHub Actions caches can be cleared from the repository settings
- Cache keys are based on lockfile hashes - changes to dependencies will create new caches

### Node/PHP version mismatches

- Node version is controlled by `.nvmrc` file
- PHP version(s) are specified in workflow matrix
- Ensure these match project requirements in composer.json and package.json
