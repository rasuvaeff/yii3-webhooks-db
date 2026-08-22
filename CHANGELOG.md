# Changelog

## 3.0.0 — 2026-08-22

**Breaking.** See [UPGRADE.md](UPGRADE.md): this release requires
`rasuvaeff/yii3-webhooks` ^2.0, needs a schema migration, and changes what
`save()` writes. The backward-compatibility check reports none of that — it
compares PHP signatures, and the breaks here are in the required core version,
in the schema and in behaviour.

- **Breaking (dependency).** `rasuvaeff/yii3-webhooks` is now required at ^2.0,
  up from ^1.0. `DbWebhookDeliveryStorage` declares `ClaimingDeliveryStorage`,
  the interface 2.0.0 introduces, and a worker takes the claiming path only for
  a storage that declares it — the core detects it with `instanceof` and nothing
  else. Upgrade the core first; this package cannot be installed beside a 1.x
  core any more.
- `DbWebhookDeliveryStorage::claimReady()` and `releaseClaim()`: lease-based
  claiming, so two workers polling the same table no longer hand the same
  delivery to both — for as long as the lease holds. A lease that expires before
  its worker finishes is exactly what makes the delivery claimable again, so
  `leaseSeconds` must outlive the slowest delivery attempt; set it too short and
  a second worker re-claims a delivery still in flight and the receiver sees the
  event twice. The claim also filters by readiness — a backlog of deliveries
  waiting out their backoff no longer fills every batch while the ready ones
  behind them starve — and it deliberately hands out deliveries that are out of
  attempts, because nothing else could ever mark them `Failed`.
- Ownership is a lease, not a status: a claimed delivery stays `pending`, the
  claim writes only the `claimed_at` / `claimed_by` columns, and the delivery
  becomes claimable again once `claimed_at` is older than `leaseSeconds` — a
  worker that dies strands nothing.
- Each `readyThresholds` key governs every attempt count from itself up to the
  next key, rather than that one count alone. A map that skips a count — legal,
  since callers may build one by hand — used to leave a delivery on the skipped
  count matching no branch at all: never ready, never exhausted, stuck `pending`
  for good.
- **Breaking (schema).** New migration `M260822120000AddDeliveryClaimColumns`
  adds the nullable `claimed_at` / `claimed_by` columns the claim needs, plus an
  index on `claimed_by`. Apply it before deploying code that calls
  `claimReady()` — see [UPGRADE.md](UPGRADE.md). Rolling it back works on MySQL
  and PostgreSQL only: `yiisoft/db-sqlite` cannot drop a column.
- **Breaking (behaviour).** `save()` no longer writes the `status` of a delivery
  that already exists. The upsert used to overwrite every column, so a worker
  that lost the race and still held a stale `pending` copy put a finished
  delivery back into the queue and the webhook went out again. Status is now
  written by `markDelivered()`/`markFailed()` alone, and lease ownership by the
  claim; the attempt state is still written by `save()`.
- `markDelivered()`/`markFailed()` clear the lease on the terminal transition, so
  a finished row never looks busy to whoever reads the table.
- `DbWebhookDeliveryStorage::deleteOlderThan()`: retention for the delivery
  table, which previously had no supported way to remove finished rows at all —
  only hand-written SQL around the package. Terminal statuses by default; the
  existing `(status, created_at)` index serves the query.
- Tooling: `rasuvaeff/rector-named-literals` in `require-dev` and its rule in
  `rector.php`; the mutation job in CI has its own narrow paths filter, so a
  documentation change no longer pays a full mutation run.

## 2.0.2 — 2026-08-04

### Fixed

- Require `yiisoft/db-migration` ^2.1, which fixes `setSourceNamespaces()` matching a sibling namespace as a parent (upstream [yiisoft/db-migration#350](https://github.com/yiisoft/db-migration/pull/350)). Drop the manual `Injector::make()` migration workaround from both READMEs.

## 2.0.1 — 2026-08-01

- Docs: the documented `setSourceNamespaces()` migration registration does not
  find the bundled migration and never has — `yiisoft/db-migration` matches the
  PSR-4 map by string prefix and resolves into the core package, so
  `./yii migrate:up` exits 0 having created nothing. Both READMEs now say so and
  give a working `Injector`-based recipe until the upstream fix ships.

## 2.0.0 — 2026-07-25

**Breaking.** See [UPGRADE.md](UPGRADE.md) — an installation that already
applied the migration must rewrite one row in the `migration` table.

- The bundled migration moved to `Rasuvaeff\Yii3WebhooksDb\Migration\M260612000000CreateWebhookTables`
  (`src/Migration/`, PSR-4 autoloaded) from a global class in `migrations/`.
  Register it with `setSourceNamespaces()` instead of a `vendor/` path. Being
  autoloadable is what makes it safe to reference in DI at all: with the old
  global class, adding any container definition for it made
  `Yiisoft\Di\Container` fatal at build time in every request, because
  `new ReflectionClass()` ran before the migration runner had required the file.
- **The documented way to rename the table never worked.**
  `M...::class => ['__construct()' => ['table' => ...]]` is ignored:
  `yiisoft/db-migration` builds migrations through `Injector::make()`, which
  resolves arguments by name or type from the container and does not read
  definitions keyed by the migration's class — and a scalar `string $table` has
  no type to resolve. Users following the README silently got the default name.
- The table names are now typed value objects that `Injector` *can* resolve,
  built by `config/di.php` from params. One source of truth: the migration and
  `DbWebhookDeliveryStorage` cannot disagree any more (in 1.x the runtime read params while the
  migration used its own default, so configuring params pointed the runtime at a
  table the migration had never created).
- New `table_prefix` param, prepended to both `deliveryTable` and `nonceTable`
  — a single place to keep package tables out of the way of an application's
  own.
- Two value objects, one per table (`WebhookDeliveryTableName`,
  `WebhookNonceTableName`), matching the two existing params keys. A single
  `table_prefix` applies to both.
- All three index names are derived from their table's name. Unchanged for the
  default names; in PostgreSQL, where index names are unique per schema rather
  than per table, hard-coded names collided between two installations sharing a
  schema.
- The identifier regex, previously duplicated in both storages, now lives only
  in the value objects.


## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-19

- Initial database-backed delivery and nonce storage for yii3-webhooks.

