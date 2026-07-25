# Changelog

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

