# rasuvaeff/yii3-webhooks-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Build](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)

Database-backed delivery and nonce storage for `rasuvaeff/yii3-webhooks`.
It provides production storage for delivery attempts and atomic replay protection.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.

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

Run `M260612000000CreateWebhookTables` to create `webhook_deliveries` and `webhook_nonces`.

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

With `yiisoft/config`, this package binds only `WebhookDeliveryStorage` and `NonceStorage`.

## API reference

### DbWebhookDeliveryStorage

| Method | Description |
|---|---|
| `save(delivery)` | Inserts or updates a delivery row |
| `findPending(limit)` | Returns pending deliveries ordered by creation time |
| `markDelivered(delivery)` | Stores the delivery as delivered |
| `markFailed(delivery)` | Stores the delivery as failed |
| `getById(id)` | Loads a delivery by ID |

### DbNonceStorage

| Method | Description |
|---|---|
| `has(nonce)` | Checks whether a nonce exists |
| `add(nonce)` | Atomic insert; returns false on duplicate |
| `deleteOlderThan(threshold)` | Deletes old nonces for retention cleanup |

## Security

- `DbNonceStorage::add()` relies on the primary key and catches duplicate-key errors.
- `DbWebhookDeliveryStorage` persists only `WebhookDelivery` data, never endpoint secrets.
- Keep nonce rows at least as long as your webhook timestamp tolerance window.

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
