# Upgrade guide

## 2.x → 3.0

### 1. Upgrade the core first

This release requires `rasuvaeff/yii3-webhooks` ^2.0 and cannot be installed
beside a 1.x core. `DbWebhookDeliveryStorage` declares `ClaimingDeliveryStorage`,
which 2.0.0 introduces, and a worker takes the claiming path only for a storage
that declares it — the core checks with `instanceof` and nothing else.

```bash
composer require rasuvaeff/yii3-webhooks:^2.0
composer require rasuvaeff/yii3-webhooks-db:^3.0
```

The core has its own upgrade notes; read them before this file.

### 2. Apply the migration

`DbWebhookDeliveryStorage::claimReady()` reads two new columns. Apply the new
migration before deploying code that calls it:

```bash
./yii migrate:up
```

It adds `claimed_at` and `claimed_by` to the delivery table, both nullable, plus
an index on `claimed_by`, and touches nothing else.
`M260612000000CreateWebhookTables` is unchanged, so an installation that already
ran it only gets `M260822120000AddDeliveryClaimColumns`.

Rolling the migration back works on MySQL and PostgreSQL only —
`yiisoft/db-sqlite` cannot drop a column.

### 3. Stop relying on `save()` to change a status

`save()` no longer writes the `status` of a row that already exists. If your
worker relied on `save()` to move a delivery back to `pending`, use the storage's
own transitions instead: `markDelivered()`, `markFailed()`, or `releaseClaim()`.

Every existing method keeps the signature it had in 2.x, and the class gained
`claimReady()`, `releaseClaim()` and `deleteOlderThan()` — the public API is
extended, not reshaped. That is why the backward-compatibility check reports
nothing: it compares PHP signatures, and the breaks in this release are the
required core version, the schema and the behaviour above. This file is the only
place they are written down.

### 4. Audit `endpoint_url` once

Finally, audit `endpoint_url` once. Older core versions accepted
`https://user:pass@host/hook`, and this backend copies the URL into every
delivery row, so basic-auth credentials may sit in the table and in your backups.
The core now refuses such URLs, but it cannot rewrite rows that already exist.
Move those credentials into the endpoint's `headers`, which never reach the
database.

## 1.x → 2.0

The bundled migration moved into the package namespace:

```
M260612000000CreateWebhookTables
→ Rasuvaeff\Yii3WebhooksDb\Migration\M260612000000CreateWebhookTables
```

`yiisoft/db-migration` stores the applied migration's class name verbatim in the
`migration` table. Without the two steps below, `migrate:up` sees the namespaced
class as a *new* migration and fails with "table already exists".

### 1. Rewrite the applied migration's name

```sql
UPDATE migration
SET name = 'Rasuvaeff\\Yii3WebhooksDb\\Migration\\M260612000000CreateWebhookTables'
WHERE name = 'M260612000000CreateWebhookTables';
```

Run this **before** the first `migrate:up` on 2.0. If you have never applied the
migration, skip it — there is nothing to rename.

### 2. Register by namespace instead of by path

```diff
 MigrationService::class => [
-    'setSourcePaths()' => [[__DIR__ . '/../vendor/rasuvaeff/yii3-webhooks-db/migrations']],
+    'setSourceNamespaces()' => [['Rasuvaeff\\Yii3WebhooksDb\\Migration']],
 ],
```

The path form no longer resolves: `migrations/` is gone and the class lives
under `src/Migration/`, autoloaded via PSR-4.

### 3. Remove any DI definition of the migration

```diff
-M260612000000CreateWebhookTables::class => [
-    '__construct()' => ['deliveryTable' => 'my_webhook_deliveries'],
-],
```

That recipe was documented in 1.x and **never worked** — the migration is built
by `Injector::make()`, which resolves arguments by type and ignores container
definitions keyed by the migration's class. It also makes the container fatal at
build time in every request, because the class is not autoloadable until the
migration runner requires it.

Set the table names in params instead; the same values now reach the migration
and both storages:

```php
'rasuvaeff/yii3-webhooks-db' => [
    'deliveryTable' => 'my_webhook_deliveries',
    'nonceTable' => 'my_webhook_nonces',
    'table_prefix' => '',
],
```

### Defaults are unchanged

The default table and index names are exactly what 1.x produced, so this release
needs no schema migration — only the `migration` table row above.
