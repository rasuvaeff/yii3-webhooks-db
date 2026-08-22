# AGENTS.md — yii3-webhooks-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-webhooks-db` is the database backend for `rasuvaeff/yii3-webhooks`.
It provides `DbWebhookDeliveryStorage` and `DbNonceStorage` under namespace
`Rasuvaeff\Yii3WebhooksDb`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Nonce acceptance must be atomic.** `DbNonceStorage::add()` must rely on a
   database uniqueness constraint, not a read-then-write check. The same rule
   governs the delivery claim: `claimReady()` takes ownership with an `UPDATE`
   whose `WHERE` re-checks the lease and the status, never with a read followed
   by an unconditional write.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

## Invariants & gotchas

- **The table names are VOs, not strings, because `Injector` cannot resolve a
  scalar.** `yiisoft/db-migration` builds migrations via `Injector::make()`,
  which resolves arguments by name or by type and never reads a container
  definition keyed by the migration's own class. Never reintroduce scalar
  `string $deliveryTable` / `string $nonceTable` on a migration.
- **One source of truth per name.** `config/di.php` builds
  `WebhookDeliveryTableName` and `WebhookNonceTableName` from params (a shared
  `table_prefix` plus each table's own key) and passes them to the migration and
  to both storages; the identifier regex lives only in the VOs (it used to be
  duplicated in both storages).
- **Index names derive from their table's name.** In PostgreSQL index names are
  unique per schema, not per table.
- Migrations live in `src/Migration/` and are therefore covered by cs, psalm and
  infection. `MigrationTableNameTest` asserts both column sets and each index's
  columns.
- `composer test` runs only the Unit suite; `composer mutation` runs every
  suite.
- **`DbWebhookDeliveryStorage` must keep declaring `ClaimingDeliveryStorage`.**
  The core (^2.0) detects the claiming path with `instanceof` and nothing else:
  drop the clause and every worker silently falls back to `findPending()`, which
  is the double delivery the lease exists to prevent. `claimReady()` /
  `releaseClaim()` must match the interface byte for byte — a divergence is a
  fatal error at install time, not a test failure.
- **Never name a core symbol that is not in a published release** — not in
  `src/`, not in `tests/`, not in a docblock type. CI resolves against the
  published core, so an unreleased name makes the package uninstallable there
  while a local build stays green.
- **Each `readyThresholds` key covers a range**, from its own attempt count up to
  the next key, and the highest key covers everything above it. A map may skip
  counts — an equality test left those deliveries matching no branch, never ready
  and never exhausted, stuck `pending` for good.
- **Ownership is a lease, not a status.** A claimed delivery stays `pending` and
  becomes claimable again once `claimed_at` is older than `leaseSeconds`. Never
  add a fourth `WebhookDeliveryStatus` for it: a worker that dies must not leave
  a row in a state nothing recovers from.
- **`claimReady()` must hand out deliveries with `attempts >= maxAttempts`.** The
  caller can only mark what it was given; filter them and nothing ever marks them
  `Failed` — they stay `pending` forever, invisible to an alert watching `failed`.
- **`save()` must never write the `status` of a row that already exists.** The
  upsert takes an explicit `updateColumns`; a stale `pending` copy held by a
  worker that lost the race would otherwise resurrect a finished delivery.
  `markDelivered()`/`markFailed()` stay a CAS on `status = 'pending'` and clear
  the lease columns.
- **The backoff belongs to the core.** `claimReady()` takes
  `readyThresholds` as data and never derives a delay itself. The highest key of
  the map stands for every larger attempt count — the core ends it where the
  delay stops growing.
- Backend config binds only `WebhookDeliveryStorage` and `NonceStorage`.
- Never bind webhooks facade/dispatcher keys in this package.
- Nonce table must keep `nonce` as primary key or unique key.
- Delivery storage stores `WebhookDelivery` fields only; no endpoint secret is persisted.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`, explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
