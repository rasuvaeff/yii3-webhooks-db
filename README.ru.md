# rasuvaeff/yii3-webhooks-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Build](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[English version](README.md)

База данных для хранения доставок и nonce в `rasuvaeff/yii3-webhooks`.
Обеспечивает production-хранилище попыток доставки и атомарную защиту от повторного воспроизведения.

> Используете AI-ассистента для написания кода? В [llms.txt](llms.txt) есть компактный справочник по API.

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

### Больше одного worker-а

`findPending()` отдаёт одни и те же строки каждому, кто спросит, и ничего не
знает про backoff: два worker-а доставят одно событие дважды, а бэклог доставок,
ожидающих backoff, займёт всю пачку, пока готовые за ними голодают.
`claimReady()` вместо этого захватывает строки в аренду:

```php
$now = $clock->now();

$batch = $deliveries->claimReady(
    now: $now,
    readyThresholds: $policy->readyThresholds($now),
    maxAttempts: $policy->getMaxAttempts(),
    leaseSeconds: 300,
    limit: 100,
);

foreach ($batch as $delivery) {
    // ... доставить, затем вывести из захвата:
    // $deliveries->markDelivered($delivery->withAttempt($now));
    // $deliveries->markFailed($delivery->withAttempt($now, error: $error));
    // либо, при повторяемой ошибке:
    //   $deliveries->save($delivery->withAttempt($now, error: $error));
    //   $deliveries->releaseClaim($delivery);
}
```

Владение — это аренда, а не статус: захваченная доставка остаётся `Pending` и
снова становится доступной, как только `claimed_at` старше `leaseSeconds`, —
умерший worker не оставляет ничего в состоянии, из которого нет выхода.
`leaseSeconds` обязан переживать самую медленную попытку доставки, иначе одну
доставку получат два worker-а. Доставка, исчерпавшая попытки, **выдаётся** —
ничто иное не сможет пометить её `Failed`.

`readyThresholds()` приходит из `WebhookRetryPolicy` пакета
`rasuvaeff/yii3-webhooks`: правило backoff принадлежит ядру и здесь не
пересчитывается. Как только выйдет релиз ядра с `ClaimingDeliveryStorage`, класс
объявит этот интерфейс, и worker сможет выбирать путь через `instanceof`.

### Удержание записей

Больше ничто в этом пакете не удаляет строки доставок — таблица растёт всё
время, пока живёт приложение:

```php
$deleted = $deliveries->deleteOlderThan(new DateTimeImmutable('-90 days'));
```

По умолчанию удаляются только терминальные статусы. Передача
`WebhookDeliveryStatus::Pending` удалит работу, которая так и не была сделана, —
это решение вызывающий обязан принять вслух.

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

В этом namespace живут две миграции: `M260612000000CreateWebhookTables` создаёт
обе таблицы, а `M260822120000AddDeliveryClaimColumns` добавляет колонки
`claimed_at` / `claimed_by`, нужные `claimReady()`. Инсталляция, уже накатившая
первую, получит только вторую. `down()` второй работает только на MySQL и
PostgreSQL — `yiisoft/db-sqlite` не умеет удалять колонки.

`yiisoft/db-migration` строит миграцию через `Injector::make()`, поэтому она
получает value object'ы имён таблиц из контейнера так же, как и хранилища —
никакой ручной проводки сверх `setSourceNamespaces()` выше не нужно.

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
| `save(delivery)` | Вставляет доставку либо обновляет состояние попыток уже сохранённой; статус существующей строки не пишет никогда |
| `findPending(limit)` | Возвращает ожидающие доставки, старейшие первыми — без аренды и без учёта backoff |
| `claimReady(now, readyThresholds, maxAttempts, leaseSeconds?, limit?)` | Захватывает готовые доставки в аренду этого worker-а |
| `releaseClaim(delivery)` | Досрочно возвращает аренду; false, если её не было |
| `markDelivered(delivery)` | Сохраняет доставку как успешную, если она ещё `Pending` |
| `markFailed(delivery)` | Сохраняет доставку как неуспешную, если она ещё `Pending` |
| `deleteOlderThan(threshold, ...statuses)` | Удаляет завершённые доставки, созданные раньше порога; возвращает число строк |
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
- При более чем одном worker-е используйте `claimReady()`. `findPending()` выдаёт
  всем одни и те же строки, и получатель увидит одно событие дважды.
- `endpoint_url` хранится дословно. `WebhookEndpoint` больше не принимает
  credentials в URL, так что новых секретов там не появится, — но строки,
  записанные старой версией ядра, могут содержать `https://user:pass@host/`.
  Проверьте колонку один раз и перепишите найденное; для аутентификации есть
  `headers` endpoint-а, они в базу не попадают.

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
