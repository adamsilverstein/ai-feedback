# Contributing to AI Feedback

Thank you for your interest in contributing to the AI Feedback plugin! This document outlines the guidelines and processes for contributing to this project.

## Getting Started

1. **Fork the repository** and clone your fork locally
2. **Install dependencies**: `npm install && composer install`
3. **Create a feature branch**: `git checkout -b feature/your-feature-name`
4. **Make your changes** following our coding standards
5. **Test your changes** locally
6. **Submit a pull request** with a clear description

## Development Setup

See the [README.md](README.md) for detailed installation instructions.

Quick start:
```bash
# Install dependencies
npm install
composer install

# Start development environment
npm run env:start

# Build assets in watch mode
npm run start
```

## Coding Standards

All code must follow the established standards for the project:

### JavaScript
- **ESLint**: WordPress JavaScript coding standards
- **Prettier**: Automatic code formatting
- **Stylelint**: CSS/SCSS linting

Run linting:
```bash
npm run lint:js          # ESLint
npm run lint:css         # Stylelint
npm run lint:pkg-json    # Package.json validation
```

Auto-fix issues:
```bash
npm run format           # Fix Prettier issues
npm run lint:js -- --fix # Fix ESLint issues
npm run lint:css -- --fix # Fix Stylelint issues
```

### PHP
- **PHPCS**: WordPress Coding Standards
- **PHPStan**: Static analysis (level 5)

Run linting:
```bash
npm run lint:php         # Run both PHPCS and PHPStan
composer run phpcs       # PHPCS only
composer run phpstan     # PHPStan only
```

Auto-fix issues:
```bash
composer run phpcbf      # Fix PHPCS issues
```

## Testing

All contributions must include appropriate tests.

### JavaScript Tests
```bash
npm run test:unit        # Run Jest tests
npm run test:e2e         # Run Playwright E2E tests
```

### PHP Tests
```bash
npm run test:php         # Run PHPUnit tests
composer run test        # Alternative command
```

### Test Coverage
- Add unit tests for new functions/methods
- Add E2E tests for new user-facing features
- Maintain or improve existing test coverage

## Pull Request Process

### Before Submitting

1. **Run all checks locally**:
   ```bash
   # JavaScript checks
   npm run lint:js
   npm run lint:css
   npm run test:unit
   
   # PHP checks
   npm run lint:php
   npm run test:php
   ```

2. **Ensure your branch is up to date**:
   ```bash
   git fetch upstream
   git rebase upstream/main
   ```

3. **Write clear commit messages**:
   - Use present tense ("Add feature" not "Added feature")
   - First line should be 50 characters or less
   - Reference issues/PRs where appropriate

### Submitting

1. **Create a pull request** against the `main` branch
2. **Fill out the PR template** completely
3. **Link any related issues** using keywords (e.g., "Fixes #123")
4. **Wait for CI checks** to complete

### CI/CD Requirements

All pull requests must pass automated checks:

- ✅ **JavaScript Linting** - ESLint, Stylelint, Prettier
- ✅ **PHP Linting** - PHPCS, PHPStan
- ✅ **JavaScript Tests** - Jest unit tests
- ✅ **PHP Tests** - PHPUnit tests across PHP 8.0-8.3

**Pull requests cannot be merged until all checks pass.**

See [docs/CI.md](docs/CI.md) for detailed CI/CD documentation.

### Review Process

1. At least one maintainer must review and approve
2. All CI checks must pass
3. All review comments must be addressed
4. No merge conflicts

## Code Review Guidelines

When reviewing pull requests:

- Be respectful and constructive
- Focus on code quality and maintainability
- Test the changes locally when possible
- Ensure proper documentation and tests are included

## Reporting Issues

When reporting bugs or suggesting features:

1. **Search existing issues** first to avoid duplicates
2. **Use issue templates** when available
3. **Include reproduction steps** for bugs
4. **Provide context** for feature requests

### Bug Reports Should Include:
- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- Expected vs actual behavior
- Screenshots/logs if relevant

### Feature Requests Should Include:
- Clear use case
- Expected behavior
- Potential implementation approach
- Any alternatives considered

## Security Issues

**Do not report security issues publicly.** Please contact the maintainers privately.

## Questions?

- Check the [README.md](README.md) for general information
- Review [docs/CI.md](docs/CI.md) for CI/CD details
- Open a discussion for general questions

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.

Thank you for contributing! 🎉
