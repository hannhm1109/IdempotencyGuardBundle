# IdempotencyGuardBundle documentation

Start with the [README](../README.md) for installation, bundle registration, configuration, HTTP behavior, replay policy, and limitations. Run `composer example` from a source checkout to see a first request, logically equivalent JSON retry, and conflicting request through Symfony HttpKernel.

## Guides

- [Symfony usage example](symfony-usage.md): controller integration, retry examples, and a dedicated cache pool.
- [Security boundaries](../SECURITY.md): authorization, storage trust, privacy, deployment, and reporting.
- [Phase 1](phase-1.md): original architecture and event/DI choices.
- [Phase 2](phase-2.md): JSON, TTL, concurrency, failure policies, and the Phase 1 format upgrade.
- [Phase 3](phase-3.md): review findings, final checks, package decisions, interview explanation, and realistic next steps.
- [Release procedure](releasing.md): version recommendation and publication steps.
- [Changelog](../CHANGELOG.md) and [contributing](../CONTRIBUTING.md).

Phase guides are historical checkpoints. The README, security policy, and release notes describe the current contract.
