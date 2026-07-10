# Staging Upgrade Readiness: APRIL and Amagno Connector

Assessment result on 2026-07-10: **NOT READY**.

This is an execution plan, not evidence of a deployment. No staging service,
database, Amagno object, tag, export, signature, release, or symlink was changed.

## Scope and immutable target

| Component | Running baseline | Target |
| --- | --- | --- |
| APRIL | `0876aa256812b8d281b6087c98fd124e250cf033` | `1252e5d1dda1a9e54342be093c45c8e9cb3e8763` |
| Connector | `e197403f20629b1da7eeaebb63ad517ca42c2b78` | `5dc4f43db6dd756cf48dd834463345a9fed64da2` |

The connector target contains the intake DI fix described below. Before deployment,
publish an immutable private tag for this exact commit and use that tag and source
reference in APRIL's lock file.

## Blockers

1. `/srv/april` was not mounted on the assessment host. The supplied running
   baseline, release ownership, runtime, database, shared files, and restart command
   therefore require operator verification on staging.
2. Connector commit `5dc4f43db6dd756cf48dd834463345a9fed64da2` is not yet
   available as an immutable tag or checksummed artifact that staging can install.
3. Staging-owned bundle configuration and protected environment values have not
   been prepared or tested.
4. The old JSON, matching files, connector templates, checkpoints, exports, and
   upload paths must be inventoried because legacy Core commands still use them.
5. Approved read-only Amagno smoke documents and a read-only account are not known
   in this environment.
6. Raw `composer test` exits 1 even though all 842 tests pass, because existing
   Doctrine deprecations are treated as failure. The release gate must explicitly
   accept the semantic run with `SYMFONY_DEPRECATIONS_HELPER=disabled` or resolve
   the policy before the deployment window.

Resolved technical blocker: `AmagnoEventIntake` is conditionally registered as a
private Symfony service when APRIL's `IncomingEventIntake` class and service are
available. Isolated and disabled bundle kernels still boot without APRIL wiring.
This only makes the mapper injectable; a production event trigger remains external
scope.

## Read-only staging inventory

Run and archive these commands on staging. Redact repository URLs. Never archive
environment values, payloads, context values, usernames, or credentials.

### Releases and Git

```bash
set -eu
CURRENT_RELEASE="$(readlink -f /srv/april/current)"
printf 'current=%s\n' "$CURRENT_RELEASE"
ls -ald /srv/april/current /srv/april/releases/* /srv/april/shared
git -C "$CURRENT_RELEASE" rev-parse HEAD
git -C "$CURRENT_RELEASE" status --short
git -C "$CURRENT_RELEASE" diff --check
```

Expected: baseline `0876aa...`, an empty worktree, and a symlinked `current`. Any
unclassified file is a stop condition until moved to shared storage or deliberately
reproduced in the new release.

### Composer and platform

```bash
cd "$(readlink -f /srv/april/current)"
php -v
php -m | sort
php bin/console about --env=prod --no-debug
composer validate --strict
composer show --locked iileven/amagno-connector-bundle --all
composer config --list | grep -E '^\[repositories\.' | \
  sed -E 's#(url] ).*#\1<redacted>#'
composer audit --locked
```

Record PHP, Symfony, Composer repository type, and required extensions. The assessed
target passed on PHP 8.4.21 and Symfony 7.4.14; requirements are PHP `>=8.2` and
Symfony `7.4.*`.

### Runtime and ownership

```bash
ps -eo user,group,pid,ppid,cmd | grep -Ei \
  '[f]rankenphp|[p]hp-fpm|[c]addy|[n]ginx|[a]pache'
systemctl list-units --type=service --state=running | grep -Ei \
  'april|franken|php|caddy|nginx|apache' || true
docker ps --format '{{.Names}} {{.Image}} {{.Status}}' 2>/dev/null || true
stat -c '%A %U:%G %n' /srv/april/current /srv/april/shared \
  /srv/april/current/var /srv/april/current/var/cache \
  /srv/april/current/var/log
systemctl show <APRIL_RUNTIME_UNIT> -p User -p Group -p ExecStart \
  -p EnvironmentFiles -p WorkingDirectory
```

Confirm `APP_ENV=prod` through `bin/console about`, not an environment dump. Record
the exact `<RUNTIME_RESTART_COMMAND>`. Cache and log locations must be writable by
the actual runtime user/deployment group. Cache should normally be release-local.

### Database, counts only

```bash
cd "$(readlink -f /srv/april/current)"
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:query:sql 'SELECT version()'
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:status --show-versions
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:list
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:up-to-date
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:schema:validate

for table in intelligence_incoming_event intelligence_process_event \
  intelligence_process_instance intelligence_process_version \
  intelligence_context_snapshot intelligence_visibility_check_result; do
  APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:query:sql \
    "SELECT COUNT(*) AS row_count FROM ${table}"
done
```

Do not query business columns. Record the driver, version, current/latest migration,
and counts only. Unexpected zero event/snapshot counts are a stop condition.

### Shared and manual files

```bash
find /srv/april/shared -maxdepth 5 \
  -printf '%y %M %u:%g %p -> %l\n' | sort
find "$(readlink -f /srv/april/current)" -xdev \
  \( -type l -o -type f \) -path '*/config/*' \
  -printf '%y %M %u:%g %p -> %l\n' | sort
git -C "$(readlink -f /srv/april/current)" status --short --ignored
```

Classify process templates, secret/ENV bindings, old Amagno JSON, matching files,
connector templates, `var/checkpoints`, exports/uploads, logs, and generated files.
Composer authentication and secrets must be external or shared mode `0600`.

## Compatibility classification

| Area | Category | Decision |
| --- | --- | --- |
| Doctrine event/snapshot stores and read providers | 1 | No migration or entity change between baseline and target. |
| Journey match, timeline, `when`, suggestions | 1/5 | Synthetically proven; real data needs smoke tests. |
| Library to Symfony bundle | 3 | Require package, register bundle, load its extension. |
| Neutral factory registry | 3 | Core registry exists; bundle supplies one tagged factory. |
| Old connections JSON | 2 | Retain for legacy Core services during transition. |
| New named connection config | 2 | Add bundle YAML plus protected environment variables. |
| Credentials/token behavior | 2/5 | Per-connection clients and caches; test authentication. |
| Context provider | 1/2/5 | Works; templates need type/connection and tag mappings. |
| Event intake adapter | 1/3/5 | Private DI wiring and mapping are tested; a production trigger is still external. |
| Sync/export/upload/signature | 5/6 | Still Core-local and not migrated into the new bundle. |
| Amagno access probe | 5/6 | Still Core-local and uses the legacy Core gateway. |
| Old credentials subscriber | 6 | Removed; do not re-register it. |
| Old Core context factory | 6 | Present but untagged/unused; never add the new tag. |
| Shared templates | 2 | Replace target's template directory before warmup. |
| Database | 1/7 | No new migration, subject to staging baseline verification. |

Categories: 1 automatic, 2 configuration, 3 wiring, 4 database migration,
5 manual test, 6 potentially unsupported, 7 unclear. No category-4 work is required
by the target diff.

## Configuration migration

| Old source | New bundle node | Source | Secret | Action/test |
| --- | --- | --- | --- | --- |
| `base_uri` / `AMAGNO_BASE_URI` | `connections.<name>.base_url` | environment | internal | migrate; read-only auth |
| username | `connections.<name>.username` | environment | yes | migrate; auth |
| password | `connections.<name>.password` | environment | yes | migrate; auth |
| `vault_id` | `connections.<name>.vault_id` | environment | sensitive ID | migrate; metadata read |
| `magnet_id` | `connections.<name>.magnet_id` | environment | sensitive ID | migrate; document read |
| configuration `id` | connection map key | deployment YAML | no | migrate; debug config |
| fixed TTL | `token_ttl` | deployment YAML/ENV | no | review; refresh test |
| `active` | bundle and connection `enabled` | deployment YAML | no | migrate; both boot modes |
| multiple entries | multiple named connections | separate ENV sets | mixed | migrate; test each |
| `auth_type` | `authentication_type` | environment | no | migrate if used |
| matching/field maps | template `field_mapping` plus legacy files | shared | sensitive | retain/review |
| access probes | template access plus Core provider | shared template | sensitive IDs | retain/read-only test |
| export/folder/stamps/errors | no bundle equivalent | old JSON/files | mixed | retain; dry-run only |
| `signature_check` | no bundle equivalent | old JSON | sensitive IDs | retain; dry-run only |
| checkpoints | no bundle equivalent | shared path | operational | retain and permission-check |

Use a transitional dual configuration. Preserve the old JSON at a shared path and
link it to `var/april/local/connector-connections.json` in each new release: target
Core no longer reads `AMAGNO_CONNECTIONS_FILE`. Do not transform unsupported export
or signature fields into the bundle tree.

Example, with values supplied externally:

```yaml
amagno_connector:
  enabled: '%env(bool:AMAGNO_CONNECTOR_ENABLED)%'
  token_ttl: '%env(int:AMAGNO_TOKEN_TTL)%'
  connections:
    default:
      base_url: '%env(AMAGNO_BASE_URL)%'
      username: '%env(AMAGNO_USERNAME)%'
      password: '%env(AMAGNO_PASSWORD)%'
      vault_id: '%env(AMAGNO_VAULT_ID)%'
      magnet_id: '%env(AMAGNO_MAGNET_ID)%'
      authentication_type: '%env(default::AMAGNO_AUTHENTICATION_TYPE)%'
```

Additional names must use distinct prefixed variables so credentials and cache
identity cannot be accidentally shared.

## Composer and bundle installation

### Recommended: private VCS and immutable tag

Create a private tag such as `0.1.0-staging.1` pointing exactly to
`5dc4f43db6dd756cf48dd834463345a9fed64da2`. Add the private VCS repository and
require that exact tag in the reviewed manifest:

```json
{
  "repositories": [
    {"type": "vcs", "url": "<PRIVATE_CONNECTOR_REPOSITORY>"}
  ],
  "require": {
    "iileven/amagno-connector-bundle": "<CONNECTOR_RELEASE_TAG>"
  }
}
```

Verify `composer show --locked iileven/amagno-connector-bundle --all` reports
`5dc4f43db6dd756cf48dd834463345a9fed64da2`. Do not deploy floating `dev-main`.
Store private access
in protected shared `auth.json` or injected `COMPOSER_AUTH`. If VCS is unavailable,
abort rather than silently using a cache.

If staging cannot access VCS, use an immutable Composer artifact built from the
target commit, with an assigned version and recorded SHA-256. A path repository is
appropriate for rehearsal only, not the final release.

Register in `config/bundles.php`:

```php
Iileven\AmagnoConnector\AmagnoConnectorBundle::class => ['all' => true],
```

Install without scripts until all shared links and environment bindings exist:

```bash
composer install --no-dev --classmap-authoritative --no-interaction \
  --no-progress --no-scripts
```

## Required service wiring

```bash
php bin/console debug:config amagno_connector --env=prod --no-debug
php bin/console debug:container --tag=app.connector_context_provider_factory \
  --env=prod --no-debug
php bin/console debug:container \
  'App\Intelligence\Application\ConnectorContextProviderFactoryRegistry' \
  --env=prod --no-debug
php bin/console debug:container \
  'Iileven\AmagnoConnector\Connection\ConnectionRegistry' \
  --env=prod --no-debug
php bin/console debug:container \
  'Iileven\AmagnoConnector\April\Event\AmagnoEventIntake' \
  --env=prod --no-debug
```

There must be exactly one Amagno factory tagged
`app.connector_context_provider_factory`. The registry must contain it. The final
lookup must show a private, shared, autowired service whose argument is
`App\Intelligence\Application\IncomingEventIntake`. If no production service
consumes it yet, Symfony may report that this private definition was removed or
inlined; that optimization is expected. A poller, webhook, scheduler, message
consumer, command, or other event trigger is not supplied by this adapter.

Confirm Core reads remain Doctrine-backed:

```bash
for service in \
 'App\Intelligence\Application\ProcessDocumentUuidProvider' \
 'App\Intelligence\Application\DocumentTimelineProvider' \
 'App\Intelligence\Application\ContextSnapshotStore' \
 'App\Intelligence\Application\ContextSnapshotHistoryProvider' \
 'App\Intelligence\Application\JourneyDocumentCandidateProvider' \
 'App\Intelligence\Application\JourneyTemplateCheckService'; do
  php bin/console debug:container "$service" --env=prod --no-debug
done
```

Expected implementations include `DoctrineProcessDocumentUuidProvider`,
`DoctrineDocumentTimelineProvider`, `DoctrineContextSnapshotRepository`, and
`DoctrineContextSnapshotHistoryProvider`. The bundle must replace none of them.

Legacy Core services remain temporarily necessary for `amagno:sync`,
`amagno:verify-signatures`, exports/uploads, signature checks, and Amagno access
probes. Their IDs differ from bundle IDs, so they can coexist. Do not tag Core's old
`AmagnoContextProviderFactory`. The in-memory access provider only supports
`fake:*`; it does not replace the Amagno provider.

## Database decision

**No migration is introduced by this upgrade.** No migration file or Doctrine
event/snapshot/intake entity changed between baseline and target. Target has seven
migrations, latest `DoctrineMigrations\Version20260615120000`; staging must show
all baseline migrations executed.

Still take a credential-safe PostgreSQL backup immediately before activation:

```bash
umask 077
BACKUP_DIR="/srv/april/shared/backups/$(date -u +%Y%m%dT%H%M%SZ)-1252e5d"
install -d -m 0700 "$BACKUP_DIR"
sudo -u <DB_BACKUP_USER> pg_dump --format=custom \
  --dbname=<PGSERVICE_NAME> --file="$BACKUP_DIR/april.dump"
pg_restore --list "$BACKUP_DIR/april.dump" >/dev/null
```

Use the staging DB type confirmed by preflight; the example assumes PostgreSQL.
Do not pass a credential-bearing URL on the command line. If `up-to-date` is green,
do not run `doctrine:migrations:migrate`. Pending migrations indicate baseline
drift and require a separate assessment. Never use `schema:update --force`.

No DB rollback is planned. If any migration is nevertheless applied, a symlink
rollback is insufficient until backward compatibility is proven or the verified
backup is restored.

## Shared templates and files

These shared templates must survive unchanged:

- `kp_rebu_nev01.yaml`
- `rm_aufmass_journey.yaml`
- `vk-amagno-001.yaml`

```bash
SHARED_TEMPLATES=/srv/april/shared/config/april/process-templates
for file in kp_rebu_nev01.yaml rm_aufmass_journey.yaml vk-amagno-001.yaml; do
  test -r "$SHARED_TEMPLATES/$file"
  stat -c '%A %U:%G %s %n' "$SHARED_TEMPLATES/$file"
  sha256sum "$SHARED_TEMPLATES/$file"
done
```

Target APRIL tracks `ai-rechnungen.yaml` and `incident-management.yaml` in the
same release directory. Shared is authoritative. Do not merge or overwrite either
copy automatically. If an example is needed in shared, review and copy it as a
separate controlled change.

Before warmup, replace only the new release directory with the link:

```bash
rm -rf "$NEW_RELEASE/config/april/process-templates"
ln -s /srv/april/shared/config/april/process-templates \
  "$NEW_RELEASE/config/april/process-templates"
test "$(readlink -f "$NEW_RELEASE/config/april/process-templates")" = \
  /srv/april/shared/config/april/process-templates
```

Never run this through `/srv/april/current`. The tracked `ai-rechnungen.yaml` is
not visible after the whole directory becomes a symlink and cannot conflict; it is
also unavailable unless intentionally present in shared.

Backup and validate shared templates:

```bash
umask 077
tar --acls --xattrs -C /srv/april/shared/config/april \
  -czf "$BACKUP_DIR/process-templates.tar.gz" process-templates
tar -tzf "$BACKUP_DIR/process-templates.tar.gz" >/dev/null
```

Restore only if shared data was actually changed:

```bash
tar --acls --xattrs -C /srv/april/shared/config/april \
  -xzf "$BACKUP_DIR/process-templates.tar.gz"
```

## Deployment sequence

Do not execute until all blockers and prerequisites are closed.

1. Confirm window, operator, runtime restart command, DB backup identity, smoke
   documents, and rollback owner.
2. Record immutable paths:

   ```bash
   set -euo pipefail
   DEPLOY_ID="$(date -u +%Y%m%dT%H%M%SZ)-1252e5d"
   OLD_RELEASE="$(readlink -f /srv/april/current)"
   NEW_RELEASE="/srv/april/releases/$DEPLOY_ID"
   BACKUP_DIR="/srv/april/shared/backups/$DEPLOY_ID"
   printf 'old=%s new=%s\n' "$OLD_RELEASE" "$NEW_RELEASE"
   ```

3. Re-run inventory. Require exact old commit `0876aa...` and no unexplained diff.
4. Create and verify DB and shared backups; record current lock/package metadata.
5. Create `NEW_RELEASE` from exact APRIL commit `1252e5d...`. Never alter
   `OLD_RELEASE` or install through `/srv/april/current`.
6. Apply reviewed Composer manifest/lock and bundle registration. Require connector
   source reference `5dc4f43db6dd756cf48dd834463345a9fed64da2`.
7. Run production Composer install with `--no-scripts`.
8. Replace the new release template directory with the shared symlink.
9. Bind protected ENV, bundle YAML, legacy JSON at
   `var/april/local/connector-connections.json`, matching files, legacy templates,
   checkpoints, exports/uploads, and logs according to preflight.
10. Verify modes. Runtime needs config read access and only approved runtime paths
    writable.
11. With connector disabled, lint YAML/config/container and boot the kernel.
12. Build release-local production cache only after links exist:

    ```bash
    cd "$NEW_RELEASE"
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --no-warmup
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
    APP_ENV=prod APP_DEBUG=0 php bin/console assets:install public
    APP_ENV=prod APP_DEBUG=0 php bin/console importmap:install
    ```

13. Confirm migrations are up to date. Run no migration for this release.
14. Run Gates A/B before switching. Enable connector, rebuild cache, then run the
    read-only connector and intelligence gates.
15. Atomically activate:

    ```bash
    NEXT_LINK="/srv/april/.current-$DEPLOY_ID"
    ln -s "$NEW_RELEASE" "$NEXT_LINK"
    mv -Tf "$NEXT_LINK" /srv/april/current
    ```

16. Execute the confirmed `<RUNTIME_RESTART_COMMAND>` and check service health.
17. Run web and real read-only gates while watching logs.
18. Release only when every gate is green; otherwise rollback immediately.
19. Delete no release or backup during the window.

## Readiness gates

### Gate A: package and container

| Check | Command | Expected | Abort when |
| --- | --- | --- | --- |
| connector lock | `composer show --locked iileven/amagno-connector-bundle --all` | exact tag/ref `5dc4f43db6dd756cf48dd834463345a9fed64da2` | absent, floating, wrong |
| bundle | `debug:container AmagnoConnectorBundle` | loaded | missing |
| config | `debug:config amagno_connector` | enabled, named connections | invalid/missing |
| container | `lint:container --env=prod --no-debug` | success | any error |
| factory | `debug:container --tag=app.connector_context_provider_factory` | one Amagno factory | zero/multiple |
| intake | `debug:container Iileven\\AmagnoConnector\\April\\Event\\AmagnoEventIntake` | private service with APRIL intake argument | missing/wrong argument |
| read ports | service commands above | Doctrine providers | connector/fake replacement |
| environment | `debug:container --env-vars`, viewed locally | all used vars resolved | missing var |

Boot once with connector disabled. Unsupported types and disabled/unreachable named
connections must yield an unavailable/provider warning, never a silent empty
provider or kernel failure. Do not archive unredacted `debug:config` output.

### Gate B: database and historical data

After migration, schema, and count checks:

```bash
php bin/console intelligence:document:timeline <KNOWN_DOCUMENT_UUID> \
  --format=json --env=prod --no-debug
php bin/console intelligence:document:context-history \
  <KNOWN_DOCUMENT_UUID> <KNOWN_PROCESS_KEY> --json --env=prod --no-debug
```

Expected: existing ordered events and snapshots with no write. Abort on DB errors,
unexpected zero counts, missing historical events, or empty known snapshots.

### Gate C: connector

Use a reviewed read-only probe that authenticates and calls only approved document,
tag-definition, vault, or magnet GET endpoints. It should print status/category,
not bodies. Verify one approved document, tag definitions, context loading,
connection identity, token refresh isolation, and warning behavior for a deliberately
disabled or unknown connection.

Do not use sync as a generic auth test. If legacy sync/signature paths must be
tested, first review their code and run only `amagno:sync --dry-run --limit=1` or
`amagno:verify-signatures --dry-run --limit=1`; abort if the selected path can
still update checkpoints, tags, stamps, exports, uploads, or signatures.

Abort on auth failure, 401/403/429/5xx, secret-bearing warning, unexpected empty
context, or any write request.

### Gate D: Intelligence

```bash
php bin/console intelligence:template:check-process <KNOWN_PROCESS_KEY> \
  --only-deviations --env=prod --no-debug
php bin/console intelligence:template:check-journey-documents \
  <JOURNEY_TEMPLATE_KEY> --limit=20 --format=json --env=prod --no-debug
php bin/console intelligence:document:timeline <JOURNEY_DOCUMENT_UUID> \
  --with-context --format=json --env=prod --no-debug
php bin/console intelligence:template:check-journey \
  <JOURNEY_DOCUMENT_UUID> <JOURNEY_SOURCE_PROCESS_KEY> \
  --env=prod --no-debug
php bin/console intelligence:template:suggest-from-document \
  <KNOWN_DOCUMENT_UUID> <KNOWN_PROCESS_KEY> --env=prod --no-debug
```

Do not pass `--output`. Expected: `match.any_process` selects the approved document,
the optional preceding process is in the full timeline, `when` uses a stored
snapshot, the normal journey is satisfied, and the known deviation reports
`CRITICAL / UNEXPECTED_PROCESS` with the expected process key. Any candidate,
timeline, context, severity, or key mismatch aborts.

### Gate E: web

Test login, template catalogue/detail, document list, and journey page/CLI using an
approved account. Check status without printing bodies:

```bash
curl --fail --silent --show-error --output /dev/null \
  --write-out '%{http_code}\n' \
  https://<STAGING_HOST>/<PUBLIC_HEALTH_OR_LOGIN_PATH>
```

Expected status follows existing authentication behavior. Abort on new 500s,
failed login, missing pages, or new critical logs.

## Approved real smoke documents

Keep actual UUIDs only in the protected change record:

| Scenario | Placeholder | Evidence |
| --- | --- | --- |
| normal process | `<KNOWN_DOCUMENT_UUID>` | known ordered events and process result |
| optional journey intake | `<JOURNEY_DOCUMENT_UUID>` | earlier optional process and complete journey |
| multiple process keys | `<MULTI_PROCESS_DOCUMENT_UUID>` | full cross-process timeline |
| critical deviation | `<UNEXPECTED_PROCESS_DOCUMENT_UUID>` | expected critical finding |
| context snapshot | `<CONTEXT_DOCUMENT_UUID>` | known field and connection provenance |
| restricted visibility | `<RESTRICTED_DOCUMENT_UUID>` | expected hidden/unknown read result |

Use approved versions. Never write tags, stamps, exports, uploads, signatures,
checkpoints, events, snapshots, or access results. Do not pass `--persist`.

## Rollback

Triggers: failed container boot/auth, missing documents, empty/wrong context, wrong
journey candidates, critical 500s, DB errors, or any unexpected write.

Fast rollback is valid because no schema migration is planned:

```bash
set -euo pipefail
OLD_RELEASE=<RECORDED_PREVIOUS_RELEASE_PATH>
test -d "$OLD_RELEASE"
test "$(git -C "$OLD_RELEASE" rev-parse HEAD)" = \
  0876aa256812b8d281b6087c98fd124e250cf033
ROLLBACK_LINK="/srv/april/.current-rollback-$(date -u +%Y%m%dT%H%M%SZ)"
ln -s "$OLD_RELEASE" "$ROLLBACK_LINK"
mv -Tf "$ROLLBACK_LINK" /srv/april/current
<RUNTIME_RESTART_COMMAND>
readlink -f /srv/april/current
git -C "$(readlink -f /srv/april/current)" rev-parse HEAD
```

The old release retains its vendor tree, lock, bundle registration, config links,
and cache. Do not run Composer during fast rollback. If its cache is missing,
restore its original links/environment and rebuild only that old release's cache.
Restore shared archives only if shared files were changed. Preserve the failed new
release for diagnosis.

If any migration or data repair happened despite this plan, stop: symlink rollback
alone is insufficient. Restore the verified DB backup or use a separately approved
and proven backward-compatible DB plan.

## Logging and observation

Choose only the commands matching the runtime:

```bash
tail -n 0 -F /srv/april/current/var/log/prod.log | \
  grep --line-buffered -Ei \
  'CRITICAL|ERROR|connector|context provider|intake|duplicate|idempot|401|403|429|5[0-9]{2}'
journalctl -fu <APRIL_RUNTIME_UNIT> --since '5 minutes ago' --output=short-iso
docker logs --since 5m --follow <APRIL_RUNTIME_CONTAINER>
```

Observe auth/token failures, unavailable connections, HTTP 401/403/429/5xx,
provider warnings, intake errors, duplicate/idempotency messages, Doctrine errors,
and web 500s. Never copy authorization headers, environment dumps, credentials,
response bodies, or context payloads into the change record.

## Verification evidence

Executed locally without staging or live Amagno access:

- APRIL `composer validate --strict`: pass.
- APRIL container, 19 YAML files, 24 Twig files, and `git diff --check`: pass.
- APRIL and connector `composer audit --locked`: no advisories.
- APRIL full suite: 842 tests, 3346 assertions, 27 skipped; all semantic tests pass.
  Raw exit is 1 due only to existing Doctrine deprecation policy; with
  `SYMFONY_DEPRECATIONS_HELPER=disabled`, exit is 0.
- Connector validation and suite after the DI fix: 11 tests, 51 assertions, pass.
- Isolated path-repository rehearsal with connector target
  `5dc4f43db6dd756cf48dd834463345a9fed64da2`:
  disabled boot, enabled boot, prod warmup, production `--no-dev
  --classmap-authoritative` install, and container lint pass.
- Factory tag/registry: one Amagno factory, pass.
- Event-intake service lookup: pass. The private definition is shared, autowired,
  autoconfigured, and references APRIL's `IncomingEventIntake`.
- Synthetic Doctrine journey E2E: 1 test, 28 assertions, pass. It additionally
  obtains the mapper from the container, proves a single service definition and
  verifies its injected APRIL dependency. It proves stable
  external-key idempotency, event mapping/processing, Doctrine snapshots/read
  providers, `match.any_process`, full timeline, connection provenance, snapshot
  `when`, satisfied journey, and critical `UNEXPECTED_PROCESS` deviation.
- No live auth/read, staging web test, staging DB query, restart, migration, or
  write operation was performed.

## Go/no-go decision

Current result: **NOT READY**.

Move to **READY WITH CONDITIONS** only after staging inventory is archived, the
fixed connector is immutably distributable, protected config and all shared links
are prepared, all seven baseline migrations are confirmed,
backup/restore and restart commands are known, and smoke documents are approved.
Move to **READY** only after a staging-like rehearsal passes Gates A through D.
During the real window, Gate E and final read-only connector checks decide release
or immediate rollback.
