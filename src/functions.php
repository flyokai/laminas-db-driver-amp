<?php

namespace Flyokai\LaminasDbDriverAmp;

use Amp\Cancellation;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\SocketMysqlConnector;
use Amp\Sql\Common\RetrySqlConnector;
use Amp\Sql\SqlConnector;
use Amp\Sql\SqlException;
use Revolt\EventLoop;
use Flyokai\LaminasDbDriverAmp\InitConnector;

/**
 * @param SqlConnector<MysqlConfig, MysqlConnection>|null $connector
 *
 * @return SqlConnector<MysqlConfig, MysqlConnection>
 */
function mysqlConnector(?SqlConnector $connector = null, array $options = []): SqlConnector
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($connector) {
        return $map[$driver] = $connector;
    }

    /**
     * @psalm-suppress InvalidArgument
     * @var SqlConnector<MysqlConfig, MysqlConnection>
     */
    return $map[$driver] ??= new InitConnector(
        connector: new RetrySqlConnector(new SocketMysqlConnector()),
        options: $options
    );
}

/**
 * Create a connection using the global Connector instance.
 *
 * @throws SqlException If connecting fails.
 * @throws \Error If the connection string does not contain a host, user, and password.
 */
function connect(MysqlConfig $config, ?Cancellation $cancellation = null): MysqlConnection
{
    return mysqlConnector()->connect($config, $cancellation);
}
