# flyokai/laminas-db-driver-amp

Native AMPHP MySQL driver for Laminas DB with fiber-aware connection pooling and transaction nesting.

See [AGENTS.md](AGENTS.md) for detailed documentation.

## Quick Reference

- **Driver**: `AmpDriver` — Laminas DB adapter with named parameterization
- **Connection**: Wraps `MysqlConnectionPool`, fiber-local state for transactions/lastInsertId
- **Statement**: Wraps `MysqlStatement`, lazy preparation, named params
- **Result**: Forward-only iterator over `MysqlResultWrapper`
- **Platform**: Custom MySQL value quoting (unquoted ints, escaped strings)
- **Init**: `SET SQL_MODE=''`, `SET time_zone='+00:00'` on every new connection
- **Key rule**: Fiber mode (default) requires active EventLoop context
