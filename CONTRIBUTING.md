# Contributing

We welcome PRs. Please follow these guidelines.

## Workflow

1. Fork and create a topic branch: `git checkout -b fix/short-description`.
2. Write tests for new behavior or regression.
3. Run the full suite locally: `./vendor/bin/phpunit --testdox`.
4. Ensure lint passes: `find packages tests examples -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors'`.
5. Open a PR against `main`.

## Code Style

- Strict types (`declare(strict_types=1)`).
- PSR-4, PSR-12 via composer scripts (run `composer cs-fix` if available).
- Final classes by default; interfaces over abstract base.
- No public setters; use immutable value objects.
- Exceptions are `RuntimeException` derivatives in dedicated `Exception` namespaces.

## Test Requirements

- Every new feature needs a test in `tests/`.
- Integration tests for adapters live in their own package when split.
- Tests must be deterministic: no sleep, no network, no time-dependent assertions.

## Commit Messages

Use Conventional Commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`. One-line subject, blank line, body if needed.

## Breaking Changes

- Require a major version bump (1.0 → 2.0).
- Document in `CHANGELOG.md` under `## [Unreleased]` → `### Breaking`.
- Provide migration path in PR description.

## Dependencies

- Core: zero framework dependencies.
- Adapters: only framework contracts (PSR, container, console).
- `league/flysystem` optional via S3 package.
- Minimum PHP 8.1.