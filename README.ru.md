# rasuvaeff/yii3-webhooks-db
[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
[![Build](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks-db/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks-db)
База данных для хранения доставок и nonce в `rasuvaeff/yii3-webhooks`.
 Обеспечивает производственно-хранилище доставки и атомарную защиту от повторного перехода.

 > Используете AI-ассистент для написания кода? В [llms.txt](llms.txt) есть компактный справочник по API. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `rasuvaeff/yii3-webhooks` ^1.0
 - `yiisoft/db` ^2.0
 - `yiisoft/db-migration` ^2.0
 - `psr/lock` ^1.0

## Установка
```bash
composer require rasuvaeff/yii3-webhooks-db
```
## Использование
Запустите миграцию «M260612000000CreateWebhookTables» для создания таблиц «webhook_deliveries» и «webhook_nonces». @@ЛИНИЯ@@
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
При использовании `yiisoft/config` этот пакет связывает только `WebhookDeliveryStorage` и `NonceStorage`. @@ЛИНИЯ@@
## Справочник API
### DbWebhookDeliveryStorage
| Метод | Описание |
 |---|---|
 | `сохранить(доставка)` | Вводит или обновляет запись доставки |
 | `findPending(лимит)` | Возвращает ожидаемые доставки, отсортированные по времени создания |
 | `markDelivered(доставка)` | Сохраняет доставку как успешно завершенную |
 | `markFailed(доставка)` | Сохраняет доставку как неуспешную |
 | `getById(id)` | Загружает доставку по удостоверению личности | @@ЛИНИЯ@@
### DbNonceStorage
| Метод | Описание |
 |---|---|
 | `имеет(одноразовый номер)` | Доказывает, существует ли nonce |
 | `добавить(одноразовый номер)` | Атомарная доставка; вернуть ложь при дублировании |
 | `deleteOlderThan(порог)` | Удаляет закрытие nonce для очистки на срок хранения | @@ЛИНИЯ@@
## Безопасность
- `DbNonceStorage::add()` опирается на первичный ключ и перехватывает ошибки дублирования ключа.
 - `DbWebhookDeliveryStorage` сохраняет данные только `WebhookDelivery`, секреты конечных точек не сохраняются.
 - Держите записывает не менее одного раза, равного допустимому окну временной метки webhook'а. @@ЛИНИЯ@@
## Примеры
Посмотрите [examples/](examples/) для запускаемого примера на SQLite. @@ЛИНИЯ@@
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
BSD-3-пункт. Смотрите [ЛИЦЕНЗИЯ.md](LICENSE.md).
