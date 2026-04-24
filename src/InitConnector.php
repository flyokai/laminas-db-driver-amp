<?php

namespace Flyokai\LaminasDbDriverAmp;

use Amp\Cancellation;
use Amp\Sql\SqlConfig as TConfig;
use Amp\Sql\SqlConnection as TConnection;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlConnector;

class InitConnector implements SqlConnector
{
    public function __construct(
        private readonly SqlConnector $connector,
        private readonly array $options = []
    ) {
    }

    public function connect(TConfig $config, ?Cancellation $cancellation = null): TConnection
    {
        $connection = $this->connector->connect($config, $cancellation);
        $connection->query("SET SQL_MODE=''");
        $connection->query("SET time_zone = '+00:00'");
        $initStatements = $this->options['initStatements'] ?? [];
        foreach ($initStatements as $statement) {
            $connection->query($statement);
        }
        return $connection;
    }
}
