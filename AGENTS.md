# flyokai/laminas-db-driver-amp

> User docs → [`README.md`](README.md) · Agent quick-ref → [`CLAUDE.md`](CLAUDE.md) · Agent deep dive → [`AGENTS.md`](AGENTS.md)

Native AMPHP MySQL driver for Laminas DB. Non-blocking database operations using `amphp/mysql` pure-PHP client with fiber-aware state management.

## Core Architecture

### AmpDriver

Implements `DriverInterface`, `DriverFeatureInterface`, `ProfilerAwareInterface`. Entry point for Laminas DB adapter integration.

- Uses prototype pattern: Statement and Result objects are cloned per operation
- `getPrepareType()` returns `PARAMETERIZATION_NAMED` (`:param_name` placeholders)
- `formatParameterName($name)` validates alphanumeric/underscore only

### Connection

Extends `AbstractConnection`. Manages MySQL connection pool with fiber-local state isolation.

**Key properties (FiberLocal when `fiber_mode=true`):**
- `$transactionLevel` — nesting counter
- `$lastInsertId` — per-fiber last insert ID
- `$isRolledBack` — rollback flag
- `$currentTransaction` — active `MysqlTransaction`

**Connection setup:**
```php
$config = new MysqlConfig('localhost', user: 'root', password: 'pw');
$pool = new MysqlConnectionPool(null, config: $config);
$connection = new Connection($pool);
```

**Transaction nesting:**
- `beginTransaction()` — increments level, creates `MysqlTransaction` from pool at level 0→1
- `commit()` — decrements level, commits only when reaching 1→0
- `rollBack()` — marks `isRolledBack=true` if nested, rolls back at outermost level
- Throws if attempting to commit after a nested rollback

### Statement

Wraps `MysqlStatement` for prepared statement execution.

- `prepare($sql)` — calls `pool->prepare($sql)`, sets `isPrepared=true`
- `execute($parameters)` — merges params, converts to scalar/string, calls `$resource->execute($namedParams)`
- Wraps result in `MysqlResultWrapper`, stores last insert ID
- `__clone()` resets preparation state

### Result

Forward-only iterator over `MysqlResultWrapper`.

- `current()` — lazy fetch via `fetchRow()`
- `rewind()` — throws if already iterated (forward-only, no buffering)
- `isQueryResult()` — true if column count > 0 (SELECT vs INSERT/UPDATE)
- `count()` — row count from resource

### MysqlResultWrapper

Decorator bridging AMPHP `MysqlResult` to PDO-compatible methods:
- `fetch($mode)` → delegates to `fetchRow()` (always associative)
- `fetchAll($mode)` → loops all rows
- `fetchColumn($column)` → single column by index
- `rowCount()` → alias for `getRowCount()`

### Platform

Extends `Laminas\Db\Adapter\Platform\Mysql` with custom value quoting:
- Integers returned unquoted
- Floats formatted with `%F`
- Strings escaped via `addcslashes()` for null bytes, newlines, backslashes, quotes, CTRL-Z

### InitConnector

Decorator wrapping `SqlConnector` to run initialization on new connections:
- `SET SQL_MODE=''` — clears strict mode
- `SET time_zone = '+00:00'` — forces UTC
- Custom init statements from options

### Helper Functions

`mysqlConnector(?SqlConnector, array $options): SqlConnector` — cached per EventLoop driver. Lazy-initializes: `SocketMysqlConnector` → `RetrySqlConnector` → `InitConnector`.

`connect(MysqlConfig, ?Cancellation): MysqlConnection` — convenience wrapper.

## Gotchas

- **Forward-only results**: Cannot rewind after iterating. Use `fetchAll()` to buffer into array.
- **Fiber mode must match context**: `fiber_mode=true` (default) requires Fiber/EventLoop context. `FiberLocal` fails outside fibers.
- **Pool must be injected**: Connection doesn't auto-create pool. Must call `setPool()` or pass via `connection_pool` parameter.
- **Statement clone resets prep**: Cloned statements must re-prepare, adding overhead.
- **InitConnector clears SQL_MODE**: Hard-coded `SET SQL_MODE=''` — may mask data issues in production.
- **No multiple result set iteration**: `getNextResult()` exists but Result iterator doesn't auto-advance.
- **Transaction rollback requires manual cleanup**: Nested rollback sets flag; outer commit throws if flag set.
- **Requires active EventLoop**: All operations suspend via Revolt. Hanging or errors outside event loop context.
