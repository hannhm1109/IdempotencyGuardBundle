# Initial release preparation

Recommended Composer package: **hanane/idempotency-guard-bundle**.

Recommended initial tag: **v0.1.0**. The implementation has a focused contract and tested failure behavior, but 0.x allows deliberate API/schema adjustments before promising 1.0 stability. This is not a claim of exactly-once execution or distributed coordination.

GitHub description:

> Symfony bundle for idempotency keys, request fingerprinting, single-host concurrency protection, and safe HTTP response replay.

Packagist description:

> Idempotency keys and safe response replay for selected Symfony HTTP operations.

## Before tagging

1. Confirm CI is green for the exact commit, including the lowest-dependency and Windows jobs. Review supported dependency/runtime constraints and any audit results.
2. Run `composer check`, `composer audit`, `composer check-platform-reqs`, and `composer dump-autoload --optimize --strict-psr` locally.
3. Inspect a Composer archive for runtime files, README, docs, and MIT license, with no `vendor/`, tests, tools, caches, secrets, or development configuration. Verify a clean consuming application can install and boot it without development dependencies.
4. Keep the Phase 1 key/fingerprint transition separate from an active deployment. Do not mix differently scoped workers or clear live records as an upgrade shortcut.
5. Change the changelog's unreleased heading to the release/version date after checks and upgrade notes are final. Confirm the package vendor name is available to your Packagist account.

Composer infers versions from VCS tags; do not add a hard-coded `version` to `composer.json`. The `dev-main` alias is `0.1.x-dev`.

## Publication commands

After the checks and final release decision, tag the reviewed commit:

```bash
git tag -a v0.1.0 -m "Release v0.1.0"
git push origin v0.1.0
```

Create GitHub release notes from the changelog and submit `https://github.com/hannhm1109/IdempotencyGuardBundle` through your Packagist account. Configure GitHub/Packagist auto-updates through the account integration if desired. Those steps require the maintainer's account and are not performed by repository preparation. Verify that Packagist indexes 0.1.0 before advertising the stable install command:

```bash
composer require "hanane/idempotency-guard-bundle:~0.1.0"
```

Before publication, the README's VCS `dev-main` instructions work directly from GitHub. Do not create a Flex recipe merely to hide required deployment decisions: registration and the three configuration options are already documented. Consider a recipe only if a later release needs repeatable additional application files.

References: [Composer version metadata](https://getcomposer.org/doc/04-schema.md#version), [Packagist package submission](https://packagist.org/about), and [Symfony reusable bundle practices](https://symfony.com/doc/6.4/bundles/best_practices.html).
