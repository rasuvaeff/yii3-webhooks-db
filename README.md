# rasuvaeff/yii3-webhooks-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Build](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[Русская версия](README.ru.md)

Database storage for `rasuvaeff/yii3-webhooks` deliveries and nonces: a
production-grade record of delivery attempts and atomic replay protection.

> **Using an AI coding assistant?** [llms.txt](llms.txt) contains a compact API
> reference you can share with the model.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-webhooks` ^1.0
- `yiisoft/db` ^2.0
- `yiisoft/db-migration` ^2.0
- `psr/clock` ^1.0

## Installation

```bash
composer require rasuvaeff/yii3-webhooks-db
```

## Usage

```php
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;

$deliveries = new DbWebhookDeliveryStorage(db: $db);
$nonces = new DbNonceStorage(db: $db, clock: $clock);

$delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint);
$deliveries->save(delivery: $delivery);
$accepted = $nonces->add(nonce: $signature->getValue());
```

Under `yiisoft/config` this package binds only `WebhookDeliveryStorage` and
`NonceStorage`.

## Migration

Register the bundled migration
(`Rasuvaeff\Yii3WebhooksDb\Migration\M260612000000CreateWebhookTables`)
**by namespace** — no vendor paths:

```php
// config/common/di/migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [['App\\Migration', 'Rasuvaeff\\Yii3WebhooksDb\\Migration']],
    ],
];
```

```bash
./yii migrate:up
```

Set the table names in params — the same values reach the migration **and**
both storages (as `WebhookDeliveryTableName` / `WebhookNonceTableName`):

```php
// config/common/params.php
'rasuvaeff/yii3-webhooks-db' => [
    'deliveryTable' => 'my_webhook_deliveries',
    'nonceTable' => 'my_webhook_nonces',
    'table_prefix' => '',   // prepended to both; e.g. 'rsv_' → rsv_my_webhook_deliveries
],
```

Index names follow the table names, so two installations can share one
PostgreSQL schema — index names are unique per schema there, not per table.

> **Do not configure the migration through the DI container.**
> `M...::class => ['__construct()' => [...]]` does not work: the migration is
> built by `Injector::make()`, which resolves arguments by type and never reads
> a container definition keyed by the migration's own class. Worse, adding that
> definition makes the container fatal at build time in **every** request,
> because the class is not autoloadable until the migration runner requires it.
> That recipe was documented in 1.x; it never worked.

## API reference

### DbWebhookDeliveryStorage

| Method | Description |
|---|---|
| `save(delivery)` | Inserts or updates the delivery record |
| `findPending(limit)` | Returns pending deliveries, oldest first |
| `markDelivered(delivery)` | Stores the delivery as succeeded |
| `markFailed(delivery)` | Stores the delivery as failed |
| `getById(id)` | Loads a delivery by id |

### DbNonceStorage

| Method | Description |
|---|---|
| `has(nonce)` | Whether the nonce is already known |
| `add(nonce)` | Atomic insert; returns false on a duplicate |
| `deleteOlderThan(threshold)` | Drops stale nonces for retention cleanup |

## Security

- `DbNonceStorage::add()` relies on the primary key and catches duplicate-key
  errors — that is what makes replay protection atomic instead of a
  check-then-write race.
- `DbWebhookDeliveryStorage` persists `WebhookDelivery` fields only; endpoint
  secrets are never stored.
- Keep nonce rows for at least the webhook timestamp tolerance window: prune
  them sooner and a replay becomes possible again.

## Examples

See [examples/](examples/) for a runnable SQLite example.

## Development

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
