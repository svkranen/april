# Amagno connector extraction assessment

Status: preparation only. This document does not authorize repository extraction,
publication, or deployment.

## Proven baseline

APRIL previously installed `iileven/amagno-connector-bundle` from the private VCS
repository `AmagnoApiConnector.git` as `dev-main`. The last version recorded in
APRIL's `composer.lock` is commit
`e197403f20629b1da7eeaebb63ad517ca42c2b78` (2026-06-06). A Composer VCS cache on
the analysed workstation still contains that commit. Preserve it as a bundle before
cleaning Composer caches:

```bash
git --git-dir="$HOME/.cache/composer/vcs/http---10.0.3.198-3000-svankranen-AmagnoApiConnector.git" \
    bundle create "$HOME/amagno-connector-e197403f.bundle" --all
sha256sum "$HOME/amagno-connector-e197403f.bundle"
```

The private remote currently requires credentials and could not be queried. The
deployed Staging version and uncommitted Staging files therefore cannot be inferred
from this checkout. Before any upgrade, collect on Staging:

```bash
composer show iileven/amagno-connector-bundle --all
composer show --locked iileven/amagno-connector-bundle
git -C vendor/iileven/amagno-connector-bundle status --short
git -C vendor/iileven/amagno-connector-bundle rev-parse HEAD
git -C vendor/iileven/amagno-connector-bundle diff --binary > /secure-backup/amagno-vendor.patch
tar --exclude='*.log' --exclude='cache' -czf /secure-backup/amagno-connector.tgz \
    vendor/iileven/amagno-connector-bundle
```

Also back up the deployed lockfile, bundle registration, service configuration and
connector environment/config files into access-controlled storage. Never commit
that archive because it can contain credentials and customer identifiers.

## Current shape

The cached package uses `Iileven\AmagnoConnector\`, has package type `library`, and
autoloads the repository root. `Iileven\AmagnoConnector\AmagnoConnectorBundle`
extends Symfony `Bundle` and loads `Resources/config/services.yaml` in `build()`.
There is no Dependency Injection extension, although
`DependencyInjection/Configuration.php` exists. The bundle was not registered in
APRIL's `config/bundles.php`; its services were therefore not loaded by normal
Symfony bundle registration. APRIL instead contained bridge services for its token
and credential event contracts. Those bridges were removed for the Community
release.

Amagno functionality currently remains mixed into APRIL:

- `src/Intelligence/Connector/Amagno/`: context/tag adapters. These belong in the
  connector package.
- `src/Service/Amagno/`, `src/Service/SignatureCheck/AmagnoSignatureCheckService.php`,
  `src/SignatureCheck/AmagnoTagValueExtractor.php`: legacy API, authentication and
  signature adapter code. These belong in the connector package after removing
  application-specific DTO dependencies.
- `src/Intelligence/Infrastructure/Access/AmagnoMagnetDocumentsAccessProbeProvider.php`,
  Amagno commands/exporters and Amagno-specific DI parameters: connector or private
  deployment package, not Community Core.
- `TemplateMappedContextProviderResolver` currently imports connector classes. Its
  generic resolver contract belongs in Core; Amagno selection/factory wiring belongs
  in the bundle.
- Domain and Application interfaces and their neutral result value objects remain
  in APRIL. No APRIL class may import `Iileven\AmagnoConnector`.

The cached connector is not independent yet: `Consumer/AmagnoApiConsumer.php`
imports application entities/services, and `Service/AmagnoApiService.php` imports
an application service interface. These classes must be removed, generalized, or
moved to a private deployment adapter before publication.

## Port and wiring matrix

| Contract / need | Current implementation and origin | Current wiring | Compatibility / required action |
| --- | --- | --- | --- |
| `ApiTokenProviderInterface` | `CommunityApiTokenProvider` in APRIL legacy; old bridge used bundle `TokenProviderInterface` | APRIL alias | Bundle owns token handling. Restore an adapter inside the bundle, then remove the APRIL legacy interface after consumers migrate. |
| `CredentialStoreInterface` | APRIL `CredentialStore`; old event subscriber bridged bundle credential events | APRIL alias | Deployment credentials must remain outside public package. Bundle should accept a typed credential provider port; APRIL/private deployment implements it. |
| `DocumentGatewayInterface` | APRIL `DocumentFetcher` | APRIL alias; `DocumentFetcherGateway` adapts it to context loading | Duplicate API client. Implement the gateway in the bundle using its `DocumentService`; remove the legacy implementation only after parity tests. |
| `SignatureCheckServiceInterface` | APRIL `AmagnoSignatureCheckService` | APRIL alias | Amagno-specific implementation and helper move to bundle. The legacy interface depends on APRIL DTOs and should either remain a public APRIL port or be replaced by a neutral Intelligence port in a separately discussed change. |
| `ContextProvider` | APRIL `AmagnoContextProvider`; global fallback is `NullContextProvider` | Template resolver creates the Amagno provider | Bundle implements the Core port and registers resolver/provider integration. Missing explicit connector configuration must produce visible warnings, never an indistinguishable empty fallback. |
| `ContextSnapshotStore` / `ContextSnapshotHistoryProvider` | Doctrine repositories in APRIL | explicit Core aliases | Compatible and must remain Core. Connector supplies attributes; APRIL persists immutable snapshots. |
| `DocumentTimelineProvider` | Doctrine provider in APRIL | explicit Core alias | Compatible. It returns all stored events for a UUID across process keys, including `eventPhase=before`; the bundle must not override it with a live/empty provider. |
| `ProcessDocumentUuidProvider` | Doctrine provider in APRIL | explicit Core alias | Compatible. `match.any_process` candidates are found from stored events; every relevant Amagno event must be ingested. |
| `DocumentListProvider` | Doctrine provider in APRIL | explicit Core alias | Compatible, but it lists documents known through APRIL events, not all live Amagno documents. Smoke-test wording must reflect this. |
| `AccessProbeProvider` | `AmagnoMagnetDocumentsAccessProbeProvider` in APRIL | autoconfigured tag | Move implementation to bundle; keep contract/registry/results in Core. |
| Journey checks and suggestions | APRIL Application services | autowired to Doctrine timeline/candidate/snapshot ports | No connector implementation required. Connector completeness is the prerequisite. |

## Journey compatibility

`match.any_process` queries `ProcessDocumentUuidProvider` once per configured match
process and deduplicates by UUID. The document check then builds the complete stored
timeline. `UNEXPECTED_PROCESS` and journey `when` rules are evaluated by Core using
that timeline and stored snapshots. `suggest-from-document` selects process or
journey suggestion logic from the template and uses the same timeline.

Consequently, the bundle is compatible only if intake records stable document UUID,
external ID, version, process key, event key, timestamps and phase for every relevant
event. Earlier optional entry events must be sent and retained. Context required by
`when` rules must be included inline or loaded by the Amagno `ContextProvider` when
the event is accepted. Live API listing cannot repair missing historical events.

The Core aliases for timeline, UUID candidates, document list and snapshot history
must remain explicit when the bundle is enabled. A bundle boot test must assert these
service IDs still resolve to Doctrine implementations and that an Amagno-mapped
template resolves to the bundle provider.

## Proposed Composer package

Keep the established ownership and namespace until repository ownership is formally
changed. Renaming to `april/*` without control of that Composer vendor would be
misleading. The first standalone package should therefore be:

```json
{
  "name": "iileven/amagno-connector-bundle",
  "description": "Optional Amagno connector for APRIL",
  "type": "symfony-bundle",
  "license": "proprietary",
  "require": {
    "php": ">=8.2 <8.5",
    "april/community-core": "^0.1",
    "psr/log": "^3.0",
    "symfony/config": "^7.4",
    "symfony/dependency-injection": "^7.4",
    "symfony/framework-bundle": "^7.4",
    "symfony/http-client-contracts": "^3.6",
    "symfony/yaml": "^7.4"
  },
  "autoload": {
    "psr-4": {
      "Iileven\\AmagnoConnector\\": "src/"
    }
  },
  "extra": {
    "symfony": {
      "require": "7.4.*"
    }
  }
}
```

Use `src/AmagnoConnectorBundle.php`,
`src/DependencyInjection/AmagnoConnectorExtension.php`,
`src/DependencyInjection/Configuration.php` and
`config/services.yaml`. The extension loads validated configuration; service loading
must not be done from `Bundle::build()`. Register manually until a reviewed Flex
recipe exists:

```php
Iileven\AmagnoConnector\AmagnoConnectorBundle::class => ['all' => true],
```

Suggested configuration namespace and environment variables:

```yaml
amagno_connector:
  enabled: '%env(bool:AMAGNO_CONNECTOR_ENABLED)%'
  base_uri: '%env(AMAGNO_BASE_URI)%'
  credential_id: '%env(int:AMAGNO_CREDENTIAL_ID)%'
  connections_file: '%env(resolve:AMAGNO_CONNECTIONS_FILE)%'
  token_ttl: '%env(int:AMAGNO_TOKEN_TTL)%'
```

Do not put username, password or API tokens in YAML. Prefer an injected secret-store
implementation. `enabled=true` with incomplete authentication must fail container
validation or reject connector use; it must never enable anonymous access.

For local development, add to APRIL without committing a developer-specific path:

```json
{
  "repositories": [{
    "type": "path",
    "url": "../april-amagno-connector-bundle",
    "options": { "symlink": true }
  }],
  "require": {
    "iileven/amagno-connector-bundle": "dev-main"
  }
}
```

Then run `composer update iileven/amagno-connector-bundle -W`. A later public API
split would avoid requiring the full APRIL project, but introducing that package is
a schema/public-contract decision and is outside this smallest compatibility step.

## Public repository gate

The cached commit must not be published as-is. Before GitHub publication:

- confirm license and ownership of the API knowledge and every source file;
- delete or rewrite application-coupled consumers and obsolete proprietary docs;
- remove the unnecessary RabbitMQ and Doctrine bundle dependencies unless retained
  functionality demonstrably uses them;
- scan all history, not only HEAD, for credentials, private hosts, internal email
  addresses, customer/tenant IDs, real vault/magnet IDs, document UUIDs, process
  keys, mappings and fixtures; rotate anything ever committed;
- replace deployment connections, matching files, customer templates, signature
  rules, exports and internal storage paths with `example.local` examples;
- ensure logs never include tokens, passwords, credential payloads or document data;
- separate generic API/client, auth and APRIL-port adapters from private deployment
  configuration and customer rules;
- add a security policy, Apache-compatible license if intended, CI, dependency audit,
  bundle boot test and sanitized fixtures.

## Staging upgrade and rollback

1. Capture the deployed package commit, dirty diff, lockfile and protected config;
   create both archive checksum and database backup.
2. Port the APRIL adapters to the preserved connector commit and make container
   validation fail visibly for missing enabled-connector services.
3. Tag or pin an immutable reviewed connector commit; do not deploy `dev-main`.
4. Test the exact APRIL lockfile, connector commit, PHP version and database engine in
   a staging-like environment with sanitized fixtures first.
5. Map old parameters to the new `amagno_connector` configuration and secret store;
   preserve the old files for rollback outside the release directory.
6. Deploy APRIL without changing or deleting event/snapshot history.
7. Install the pinned bundle and register it explicitly.
8. Clear/warm cache and run container, YAML and Twig lints.
9. Run read-only smoke tests with approved real test documents.
10. Verify rollback restores both application lockfile and connector/config versions;
    rehearse it without rolling back database migrations destructively.

Staging smoke commands:

```bash
php bin/console lint:container
php bin/console intelligence:template:check-journey-documents rm_aufmass_journey
php bin/console intelligence:template:suggest-from-document <documentUuid> <templateKey>
php bin/console intelligence:document:timeline <documentUuid>
```

Verify that known Amagno events appear in the document list, the timeline contains
multiple process keys including the optional earlier entry, match-process candidates
include the test UUID, an injected unexpected process yields
`DEVIATION / CRITICAL / UNEXPECTED_PROCESS`, snapshots contain fields used by `when`,
and existing single-process checks are unchanged. Real integration success may only
be recorded when the target API was reachable and the exact endpoint, time and
sanitized test identifiers were captured in the deployment record.
