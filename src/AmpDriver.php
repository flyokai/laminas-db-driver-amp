<?php

namespace Flyokai\LaminasDbDriverAmp;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Laminas\Db\Adapter\Driver\ConnectionInterface;
use Laminas\Db\Adapter\Driver\DriverInterface;
use Laminas\Db\Adapter\Driver\Feature\AbstractFeature;
use Laminas\Db\Adapter\Driver\Feature\DriverFeatureInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Exception;
use Laminas\Db\Adapter\Profiler;
use Laminas\Db\Adapter\Profiler\ProfilerInterface;
use Amp\Mysql\MysqlConnectionPool;

class AmpDriver implements DriverInterface, DriverFeatureInterface, Profiler\ProfilerAwareInterface
{
    protected ProfilerInterface $profiler;
    protected Connection $connection;
    protected Statement $statementPrototype;
    protected Result $resultPrototype;
    protected array $features = [];

    public function __construct(
        Connection|array $connection,
        ?Statement $statementPrototype = null,
        ?Result $resultPrototype = null
    )
    {
        if (!$connection instanceof Connection) {
            $connection = new Connection($connection);
        }
        $this->registerConnection($connection);
        $this->registerStatementPrototype($statementPrototype ?: new Statement());
        $this->registerResultPrototype($resultPrototype ?: new Result());
    }

    public function setupDefaultFeatures()
    {
        return $this;
    }

    public function addFeature($name, $feature)
    {
        return $this;
    }

    public function getFeature($name)
    {
        if (isset($this->features[$name])) {
            return $this->features[$name];
        }
        return false;
    }

    public function getDatabasePlatformName($nameFormat = self::NAME_FORMAT_CAMELCASE)
    {
        if ($nameFormat === self::NAME_FORMAT_CAMELCASE) {
            return 'Mysql';
        }
        return 'MySQL';
    }

    public function checkEnvironment()
    {
        if (!class_exists('\Amp\Mysql\MysqlConnectionPool')) {
            throw new Exception\RuntimeException(
                'The amphp/mysql package is required for this adapter but is not available'
            );
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function createStatement($sqlOrResource = null)
    {
        $statement = clone $this->statementPrototype;
        if ($sqlOrResource instanceof MysqlStatement) {
            $statement->setResource($sqlOrResource);
        } else {
            if (is_string($sqlOrResource)) {
                $statement->setSql($sqlOrResource);
            }
            if (!$this->connection->isConnected()) {
                $this->connection->connect();
            }
            /* probably not needed
            $statement->initialize($this->connection->getResource());
            */
        }
        return $statement;
    }

    /**
     * @param MysqlResult $resource
     * @param Statement $context
     * @return ResultInterface
 */
    public function createResult($resource, $context = null)
    {
        $result = clone $this->resultPrototype;
        $result->initialize($resource, $context);
        return $result;
    }

    public function getPrepareType()
    {
        return self::PARAMETERIZATION_NAMED;
    }

    public function formatParameterName($name, $type = null)
    {
        if ($type === null && ! is_numeric($name) || $type === self::PARAMETERIZATION_NAMED) {
            $name = ltrim($name, ':');
            // @see https://bugs.php.net/bug.php?id=43130
            if (preg_match('/[^a-zA-Z0-9_]/', $name)) {
                throw new Exception\RuntimeException(sprintf(
                    'The PDO param %s contains invalid characters.'
                    . ' Only alphabetic characters, digits, and underscores (_)'
                    . ' are allowed.',
                    $name
                ));
            }
            return ':' . $name;
        }

        return '?';
    }

    public function getLastGeneratedValue($name=null)
    {
        return $this->connection->getLastGeneratedValue($name);
    }

    public function setProfiler(ProfilerInterface $profiler)
    {
        $this->profiler = $profiler;
        if ($this->connection instanceof Profiler\ProfilerAwareInterface) {
            $this->connection->setProfiler($profiler);
        }
        if ($this->statementPrototype instanceof Profiler\ProfilerAwareInterface) {
            $this->statementPrototype->setProfiler($profiler);
        }
        return $this;
    }

    public function registerConnection(Connection $connection)
    {
        $this->connection = $connection;
        $this->connection->setDriver($this);
        return $this;
    }

    /**
     * Register statement prototype
     */
    public function registerStatementPrototype(Statement $statementPrototype)
    {
        $this->statementPrototype = $statementPrototype;
        $this->statementPrototype->setDriver($this);
    }

    /**
     * Register result prototype
     */
    public function registerResultPrototype(Result $resultPrototype)
    {
        $this->resultPrototype = $resultPrototype;
    }

}
