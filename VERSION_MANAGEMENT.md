# Version Management Summary

## 🎯 Quick Start

### Option 1: Automated Release (Recommended)

1. Go to GitHub → Actions → "Version Bump"
2. Select version type (patch/minor/major)
3. Run workflow
4. Done! 🎉

### Option 2: Manual Release

```bash
composer run release
```

### Option 3: Command Line

```bash
git tag v1.0.0
git push origin v1.0.0
```

## 📋 Version Strategy

-   **v1.0.1** - Bug fixes (patch)
-   **v1.1.0** - New features (minor)
-   **v2.0.0** - Breaking changes (major)

## 🔄 What Happens on Release

1. **GitHub Actions runs**:

    - Tests all code
    - Creates GitHub release
    - Updates documentation

2. **Packagist updates** automatically:
    - New version available via Composer
    - Users can `composer update`

## 📦 Packagist

The package is published as [`devuni/notifier-agent`](https://packagist.org/packages/devuni/notifier-agent). A pushed `v*` tag becomes available via Composer automatically - no manual step per release.

## 🛠️ Available Commands

```bash
composer test           # Run tests
composer analyse        # Static analysis
composer format         # Code formatting
composer test-coverage  # Test coverage
composer pre-commit     # All checks
composer release        # Interactive release
```

## 📁 Important Files

-   `CHANGELOG.md` - Version history
-   `CONTRIBUTING.md` - Development guide
-   `docs/RELEASE_GUIDE.md` - Detailed instructions
-   `scripts/release.sh` - Release automation
-   `.github/workflows/` - CI/CD automation

## 🔗 Useful Links

-   [Semantic Versioning](https://semver.org/)
-   [Keep a Changelog](https://keepachangelog.com/)
-   [GitHub Releases](https://docs.github.com/en/repositories/releasing-projects-on-github)
-   [Packagist](https://packagist.org/)

## ✅ Pre-Release Checklist

-   [ ] Tests pass (`composer test`)
-   [ ] Code analysis passes (`composer analyse`)
-   [ ] Code formatted (`composer format`)
-   [ ] CHANGELOG.md updated
-   [ ] Version follows SemVer
-   [ ] Documentation current

That's it! You're ready to manage versions like a pro! 🚀
