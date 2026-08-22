# Примеры

| Скрипт | Демонстрирует | Нужен сервер? |
|---|---|---|
| `sqlite.php` | Сохранение доставок, аренда доставок двумя worker-ами, полный цикл worker-а (повторная попытка → `releaseClaim()`, успех → `markDelivered()`) и атомарное хранение nonce с in-memory SQLite | Нет |

Запуск:

```bash
php examples/sqlite.php
```
