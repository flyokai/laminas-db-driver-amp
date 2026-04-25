# flyokai/laminas-db-driver-amp

> User docs → [`README.md`](README.md) · Agent quick-ref → [`CLAUDE.md`](CLAUDE.md) · Agent deep dive → [`AGENTS.md`](AGENTS.md)

> Native AMPHP MySQL driver for Laminas DB — non-blocking queries via `amphp/mysql` with fiber-aware connection pooling and transaction nesting.

Plugs the pure-PHP `amphp/mysql` client into Laminas DB. Every query suspends the current fiber rather than blocking the thread.

## Features

- **`AmpDriver`** — Laminas-DB-compatible driver implementing `DriverInterface`, `DriverFeatureInterface`, `ProfilerAwareInterface`
- **Named parameters** — `:param_name` placeholders
- **Fiber-local state** — transaction level, last-insert-id, rollback flag, current transaction
- **Transaction nesting** — outer commit only, nested rollbacks invalidate the outer commit
- **Custom MySQL platform** — value quoting (unquoted ints, escaped strings)
- **`InitConnector`** — runs `SET SQL_MODE=''` and `SET time_zone='+00:00'` on every fresh connection

## Installation

```bash
composer require flyokai/laminas-db-driver-amp
```

## Quick start

```php
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Flyokai\LaminasDbDriverAmp\AmpDriver;
use Flyokai\LaminasDbDriverAmp\Connection;
use Laminas\Db\Adapter\Adapter;

$config = MysqlConfig::fromString('host=localhost user=root password=pw db=app');
$pool   = new MysqlConnectionPool(null, config: $config);

$connection = new Connection($pool);
$driver     = new AmpDriver($connection);

$adapter = new Adapter($driver);

// Use the adapter exactly like a synchronous Laminas adapter — every call is async.
$rows = $adapter->query('SELECT * FROM users WHERE status = :status', ['status' => 'active'])
    ->getResource();
```

## Components

| Class | Role |
|-------|------|
| `AmpDriver` | Entry point. Creates `Statement` / `Result` objects via prototype cloning. Returns `PARAMETERIZATION_NAMED`. |
| `Connection` | Wraps `MysqlConnectionPool`. FiberLocal transaction state. |
| `Statement` | Wraps `MysqlStatement`. Lazy preparation, named params. |
| `Result` | Forward-only iterator over `MysqlResultWrapper`. |
| `MysqlResultWrapper` | PDO-like façade — `fetch()`, `fetchAll()`, `fetchColumn()`, `rowCount()` |
| `Platform\Mysql` | Custom value quoting |
| `InitConnector` | Wraps `SqlConnector` to run init SQL on every new connection |
| `mysqlConnector(...)` | Cached connector factory keyed by EventLoop driver |

### Transaction nesting

```php
$connection->beginTransaction();   // level 0 → 1, MysqlTransaction created
$connection->beginTransaction();   // level 1 → 2 (no DB call)
$connection->commit();             // level 2 → 1 (no DB call)
$connection->commit();             // level 1 → 0, real COMMIT

// Nested rollback:
$connection->beginTransaction();
$connection->beginTransaction();
$connection->rollBack();           // sets isRolledBack=true
$connection->commit();             // throws — outer commit refused after nested rollback
```

### Fiber-local state

When `fiber_mode=true` (default), these are `FiberLocal`:

- `$transactionLevel` — nesting counter
- `$lastInsertId` — last insert id
- `$isRolledBack` — rollback flag
- `$currentTransaction` — active `MysqlTransaction`

Each fiber sees its own values so concurrent fibers don't trample each other's transaction state.

### `InitConnector`

```php
new InitConnector($baseConnector, [
    "SET SQL_MODE=''",
    "SET time_zone='+00:00'",
    // your custom init statements
]);
```

Defaults: `SET SQL_MODE=''` and `SET time_zone='+00:00'`.

## Gotchas

- **Forward-only results** — cannot rewind after iterating. Use `fetchAll()` to buffer into an array if you need to re-read.
- **Fiber mode requires a fiber** — `fiber_mode=true` (default) requires Fiber/EventLoop context. `FiberLocal` fails outside fibers.
- **Pool is required** — `Connection` does not auto-create a pool; pass it via `setPool()` or `connection_pool` parameter.
- **Statement clone resets prep** — cloned statements re-prepare on next execute.
- **`InitConnector` clears `SQL_MODE`** — hard-coded `SET SQL_MODE=''`. May mask data issues in production.
- **No multi-result-set iteration** — `getNextResult()` exists but the `Result` iterator does not auto-advance.
- **Transaction rollback requires manual cleanup** — nested `rollBack()` sets a flag; outer `commit()` throws if the flag is set.
- **Requires active EventLoop** — every operation suspends via Revolt.

## See also

- [`flyokai/laminas-db`](../laminas-db/README.md) — base DB abstraction
- [`flyokai/laminas-db-driver-async`](../laminas-db-driver-async/README.md) — alternative strategy via worker pools

## License

MIT
