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
   database uniqueness constraint, not a read-then-write check.
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

- Backend config binds only `WebhookDeliveryStorage` and `NonceStorage`.
- Never bind webhooks facade/dispatcher keys in this package.
- Nonce table must keep `nonce` as primary key or unique key.
- Delivery storage stores `WebhookDelivery` fields only; no endpoint secret is persisted.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`, explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
