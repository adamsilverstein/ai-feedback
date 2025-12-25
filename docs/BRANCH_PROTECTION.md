# Branch Protection Setup Guide

This guide is for repository maintainers to configure GitHub branch protection rules to enforce CI checks before merging pull requests.

## Overview

Branch protection ensures that all code merged into your main branches passes quality checks and code review. This maintains code quality and prevents breaking changes from being merged.

## Prerequisites

- Repository admin access
- GitHub Actions workflows already set up (see `.github/workflows/`)
- At least one successful workflow run (create a test PR to trigger workflows)

## Setting Up Branch Protection

### Step 1: Access Branch Protection Settings

1. Go to your repository on GitHub
2. Click **Settings** (requires admin access)
3. In the left sidebar, click **Branches**
4. Under "Branch protection rules", click **Add branch protection rule**

### Step 2: Configure the Rule

#### Branch name pattern
Enter the branch name you want to protect:
- `main` - for production branch
- `develop` - for development branch
- `main` or `develop` - to protect either branch (use wildcards if needed)

**Recommendation**: Set up separate rules for `main` and `develop` with different requirements if needed.

### Step 3: Enable Required Settings

Enable the following settings:

#### ✅ Require a pull request before merging
- Ensures all changes go through PR review process
- **Recommended setting**: ✅ Require approvals: 1 (or more for stricter review)
- **Optional**: ✅ Dismiss stale pull request approvals when new commits are pushed
- **Optional**: ✅ Require review from Code Owners (if CODEOWNERS file exists)

#### ✅ Require status checks to pass before merging
This is the key setting for CI enforcement.

**Required status checks** - Add these checks:
- `JavaScript Linting`
- `PHP Linting`
- `JavaScript Unit Tests`
- `PHP Unit Tests (8.0)` - At minimum, require the lowest PHP version
- Optionally, require all PHP version tests: `PHP Unit Tests (8.1)`, `PHP Unit Tests (8.2)`, `PHP Unit Tests (8.3)`

**Additional options**:
- ✅ **Require branches to be up to date before merging** (recommended)
  - Ensures the PR has the latest main branch changes
  - Helps prevent integration issues
  - May require more frequent rebasing/merging

#### ✅ Require conversation resolution before merging (optional but recommended)
- Ensures all review comments are addressed

#### ⚠️ Do not allow bypassing the above settings (optional)
- Prevents admins from bypassing rules
- Useful for strict compliance requirements
- May want to leave unchecked for emergency fixes

#### Additional Protection Options

**Consider enabling**:
- ✅ Require linear history - Enforces rebase or squash merging
- ✅ Require deployments to succeed before merging - If you have deployment workflows
- ❌ Lock branch - Only enable if branch should be completely read-only

**Usually leave disabled**:
- ❌ Allow force pushes - Can rewrite history, generally not recommended
- ❌ Allow deletions - Prevents accidental branch deletion

### Step 4: Save

Click **Create** or **Save changes** at the bottom of the page.

## Example Configuration

### For `main` branch (production):
```
Branch name pattern: main

Required settings:
✅ Require a pull request before merging
  ✅ Require approvals: 2
  ✅ Dismiss stale pull request approvals when new commits are pushed
  
✅ Require status checks to pass before merging
  ✅ Require branches to be up to date before merging
  Required checks:
    - JavaScript Linting
    - PHP Linting
    - JavaScript Unit Tests
    - PHP Unit Tests (8.0)
    - PHP Unit Tests (8.1)
    - PHP Unit Tests (8.2)
    - PHP Unit Tests (8.3)
    
✅ Require conversation resolution before merging
✅ Require linear history
```

### For `develop` branch (development):
```
Branch name pattern: develop

Required settings:
✅ Require a pull request before merging
  ✅ Require approvals: 1
  
✅ Require status checks to pass before merging
  Required checks:
    - JavaScript Linting
    - PHP Linting
    - JavaScript Unit Tests
    - PHP Unit Tests (8.0)
```

## Verifying the Setup

1. **Create a test PR** with a small change
2. **Check that workflows run** automatically
3. **Verify that the Merge button is disabled** until all checks pass
4. **Try to merge** - you should see "Required status checks have not passed"
5. Once checks pass, the **Merge button should become enabled**

## Troubleshooting

### Status checks not appearing

**Problem**: The status checks don't appear in the list when setting up branch protection.

**Solution**: 
1. Status checks only appear after they've run at least once
2. Create a test PR to trigger the workflows
3. Wait for workflows to complete
4. Return to branch protection settings - the checks should now be available

### Wrong status check names

**Problem**: Status check names don't match what you see in PRs.

**Solution**: 
1. Open any PR and check the **Checks** tab
2. Copy the exact names as they appear there
3. Use those exact names in branch protection settings
4. Check names are case-sensitive

### Checks always showing as pending

**Problem**: Status checks never complete or stay pending.

**Solution**:
1. Check the **Actions** tab for workflow run errors
2. Review workflow logs for failures
3. Ensure `GITHUB_TOKEN` has necessary permissions
4. Verify workflow syntax in `.github/workflows/` files

### "Required status checks must be selected" error

**Problem**: Can't save branch protection rule.

**Solution**:
1. Ensure you've selected at least one status check
2. Workflows must have run at least once for checks to be available
3. Check that workflow names match exactly

## Updating Branch Protection

When workflows change:
1. Go to **Settings** → **Branches**
2. Find your branch protection rule
3. Click **Edit**
4. Update required status checks
5. Click **Save changes**

## Disabling Branch Protection Temporarily

In rare cases (emergencies), you may need to disable protection:

1. Go to **Settings** → **Branches**
2. Find your branch protection rule
3. Click **Edit**
4. Uncheck the settings you want to disable
5. Click **Save changes**

**Remember to re-enable protection afterwards!**

## Best Practices

1. **Start strict, relax if needed** - It's easier to remove rules than add them later
2. **Require up-to-date branches** - Prevents integration issues
3. **Test the setup** - Create a test PR to verify everything works
4. **Document exceptions** - If you disable rules, document why and for how long
5. **Review rules periodically** - Update as project needs change
6. **Use CODEOWNERS** - For automatic reviewer assignment on sensitive files

## Related Documentation

- [CI Documentation](CI.md) - Details on what each workflow does
- [Contributing Guide](../CONTRIBUTING.md) - Instructions for contributors
- [Workflow README](../.github/workflows/README.md) - Technical workflow details

## Questions?

If you encounter issues setting up branch protection:
1. Check [GitHub's official documentation](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
2. Review workflow logs in the Actions tab
3. Open an issue in the repository
