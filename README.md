# rasuvaeff/yii3-webhooks-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Build](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[Русская версия](README.ru.md)

База данных для хранения доставок и nonce в `rasuvaeff/yii3-webhooks`.
Обеспечивает production-хранилище попыток доставки и атомарную защиту от повторного воспроизведения.

> Используете AI-ассистент для написания кода? В [llms.txt](llms.txt) есть компактный справочник по API.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-webhooks` ^1.0
- `yiisoft/db` ^2.0
- `yiisoft/db-migration` ^2.0
- `psr/clock` ^1.0

## Установка

```bash
composer require rasuvaeff/yii3-webhooks-db
```

## Использование

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

При использовании `yiisoft/config` этот пакет биндит только `WebhookDeliveryStorage` и `NonceStorage`.

## Миграция

Регистрируйте поставляемую миграцию
(`Rasuvaeff\Yii3WebhooksDb\Migration\M260612000000CreateWebhookTables`)
**по namespace** — без путей в `vendor/`:

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

Имена таблиц задаются в params — те же значения получают и миграция, и оба
хранилища (через `WebhookDeliveryTableName` / `WebhookNonceTableName`):

```php
// config/common/params.php
'rasuvaeff/yii3-webhooks-db' => [
    'deliveryTable' => 'my_webhook_deliveries',
    'nonceTable' => 'my_webhook_nonces',
    'table_prefix' => '',   // добавляется к обоим; например 'rsv_' → rsv_my_webhook_deliveries
],
```

Имена индексов следуют за именами таблиц, поэтому две инсталляции могут делить
одну схему PostgreSQL — там имена индексов уникальны в пределах схемы, а не
таблицы.

> **Не настраивайте миграцию через DI-контейнер.**
> `M...::class => ['__construct()' => [...]]` не работает: миграцию создаёт
> `Injector::make()`, который резолвит аргументы по типу и никогда не читает
> определение контейнера по имени класса самой миграции. Хуже того, добавление
> такого определения роняет контейнер на этапе сборки в **каждом** запросе,
> потому что класс не автозагружается, пока его не подключит раннер миграций.
> Этот рецепт был описан в 1.x и никогда не работал.

## Справочник API

### DbWebhookDeliveryStorage

| Метод | Описание |
|---|---|
| `save(delivery)` | Вставляет или обновляет запись доставки |
| `findPending(limit)` | Возвращает ожидающие доставки, отсортированные по времени создания |
| `markDelivered(delivery)` | Сохраняет доставку как успешно выполненную |
| `markFailed(delivery)` | Сохраняет доставку как неуспешную |
| `getById(id)` | Загружает доставку по ID |

### DbNonceStorage

| Метод | Описание |
|---|---|
| `has(nonce)` | Проверяет, существует ли nonce |
| `add(nonce)` | Атомарная вставка; возвращает false при дубликате |
| `deleteOlderThan(threshold)` | Удаляет устаревшие nonce для очистки по сроку хранения |

## Безопасность

- `DbNonceStorage::add()` опирается на первичный ключ и перехватывает ошибки дублирования ключа.
- `DbWebhookDeliveryStorage` сохраняет только данные `WebhookDelivery`, секреты endpoint'ов не хранятся.
- Держите записи nonce не менее времени, равного допустимому окну временно́й метки webhook'а.

## Примеры

Смотрите [examples/](examples/) для запускаемого примера на SQLite.

## Разработка

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## Лицензия

BSD-3-Clause. Смотрите [LICENSE.md](LICENSE.md).
