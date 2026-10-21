# Releasing

Initial GitHub release: [v0.1.0](https://github.com/hannhm1109/IdempotencyGuardBundle/releases/tag/v0.1.0). Packagist submission and indexing remain separate from GitHub publication. The steps below describe this release workflow and can be reused with the appropriate version for later releases.

Recommended Composer package: **hanane/idempotency-guard-bundle**.

Initial tag: **v0.1.0**. The implementation has a focused contract and tested failure behavior, but 0.x allows deliberate API/schema adjustments before promising 1.0 stability. This is not a claim of exactly-once execution or distributed coordination.

GitHub description:

> Symfony bundle for idempotency keys, request fingerprinting, single-host concurrency protection, and safe HTTP response replay.

Packagist description:

> Idempotency keys and safe response replay for selected Symfony HTTP operations.

## Same publishing workflow as OutboundGuardBundle

The existing `hanane/outbound-guard-bundle` uses a `v0.1.0` GitHub release and a Packagist entry. This bundle can use the same sequence: push a version tag, create its GitHub release, then submit its public repository to Packagist. Keeping `hanane/` as the vendor is appropriate when signed in as a maintainer of the existing package.

The installation mode differs: OutboundGuard is a development tool, while IdempotencyGuard protects runtime requests. Consumers should use `composer require hanane/idempotency-guard-bundle` without `--dev` and register the bundle in all relevant environments.

## Before tagging

1. Confirm CI is green for the exact commit, including the lowest-dependency and Windows jobs. Review supported dependency/runtime constraints and any audit results.
2. Run `composer check`, `composer audit`, `composer check-platform-reqs`, and `composer dump-autoload --optimize --strict-psr` locally.
3. Inspect a Composer archive for runtime files, README, docs, and MIT license, with no `vendor/`, tests, tools, caches, secrets, or development configuration. Verify a clean consuming application can install and boot it without development dependencies.
4. Keep the Phase 1 key/fingerprint transition separate from an active deployment. Do not mix differently scoped workers or clear live records as an upgrade shortcut.
5. Add the release version/date to the changelog after checks and upgrade notes are final. Confirm the package vendor name is available to your Packagist account.

Composer infers versions from VCS tags; do not add a hard-coded `version` to `composer.json`. The `dev-main` alias is `0.1.x-dev`.

## Publication commands

After the checks and final release decision, tag the reviewed commit:

```bash
git tag -a v0.1.0 -m "Release v0.1.0"
git push origin v0.1.0
```

Create GitHub release notes from the changelog, then use the same Packagist account that maintains OutboundGuard:

1. Open [Packagist Submit](https://packagist.org/packages/submit).
2. Enter `https://github.com/hannhm1109/IdempotencyGuardBundle` as the repository URL.
3. Run the repository check, review the detected package name `hanane/idempotency-guard-bundle`, then complete submission.
4. Confirm the package page lists `v0.1.0`/`0.1.0`. If needed, use its **Update** action to trigger a crawl.
5. Check automatic GitHub updates in the Packagist account's package list. Use the existing GitHub integration, or configure a webhook as described in Packagist's documentation.

Packagist reads the name, description, requirements, autoloading, and license from `composer.json`; versions come from Git tags. A separate GitHub repository does not require a separate Packagist account.

Verify that Packagist indexes 0.1.0 before advertising the stable install command:

```bash
composer require "hanane/idempotency-guard-bundle:~0.1.0"
```

Before Packagist indexing, the README's VCS instructions resolve the published GitHub tag directly. Do not create a Flex recipe merely to hide required deployment decisions: registration and the three configuration options are already documented. Consider a recipe only if a later release needs repeatable additional application files.

References: [Composer version metadata](https://getcomposer.org/doc/04-schema.md#version), [Packagist package submission](https://packagist.org/about), and [Symfony reusable bundle practices](https://symfony.com/doc/6.4/bundles/best_practices.html).
