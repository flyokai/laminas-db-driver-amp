<?php

namespace Flyokai\LaminasDbDriverAmp;

use AllowDynamicProperties;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\Common\SqlCommonConnectionPool;
use Laminas\Db\Adapter\Driver\AbstractConnection;
use Laminas\Db\Adapter\Driver\ConnectionInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlLink;
use Laminas\Db\Adapter\Exception;
use Revolt\EventLoop\FiberLocal;
use function Flyokai\LaminasDbDriverAmp\mysqlConnector;

class Connection extends AbstractConnection
{
    protected AmpDriver $driver;
    protected MysqlConfig $ampConfig;
    protected ?MysqlConnectionPool $pool = null;
    /**
     * @var FiberLocal|int
     */
    protected mixed $lastInsertId;
    /**
     * @var FiberLocal|int
     */
    protected mixed $transactionLevel;
    /**
     * @var FiberLocal|bool
     */
    protected mixed $isRolledBack;
    /**
     * @var MysqlTransaction|FiberLocal
     */
    protected mixed $currentTransaction;

    private bool $fiberMode = true;

    public function __construct($connectionParameters = null)
    {
        if (is_array($connectionParameters)) {
            $this->setConnectionParameters($connectionParameters);
        } elseif ($connectionParameters instanceof MysqlConnectionPool) {
            $this->setPool($connectionParameters);
        } elseif (null !== $connectionParameters) {
            throw new Exception\InvalidArgumentException(
                '$connection must be an array of parameters, Amp\Mysql\MysqlConnectionPool object or null'
            );
        }
        if ($this->fiberMode) {
            $this->lastInsertId = new FiberLocal(static fn() => 0);
            $this->transactionLevel = new FiberLocal(static fn() => 0);
            $this->isRolledBack = new FiberLocal(static fn() => false);
            $this->currentTransaction = new FiberLocal(static fn() => null);
        } else {
            $this->lastInsertId = 0;
            $this->transactionLevel = 0;
            $this->isRolledBack = false;
            $this->currentTransaction = null;
        }
    }

    public function setConnectionParameters(array $connectionParameters)
    {
        if (isset($connectionParameters['connection_pool'])) {
            $this->setPool($connectionParameters['connection_pool']);
            unset($connectionParameters['connection_pool']);
        }
        if (array_key_exists('fiber_mode', $connectionParameters) && !$connectionParameters['fiber_mode']) {
            $this->fiberMode = false;
        } else {
            $this->fiberMode = true;
        }
        unset($connectionParameters['fiber_mode']);
        parent::setConnectionParameters($connectionParameters);
    }

    public function getCurrentSchema()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $result = new MysqlResultWrapper($this->pool->query('SELECT DATABASE()'));
        return $result->fetchColumn()??false;
    }

    public function connect()
    {
        if ($this->pool) {
            return $this;
        }

        throw new Exception\RuntimeException('Connection pool is not specified');

    }

    public function isConnected()
    {
        return $this->pool instanceof MysqlConnectionPool;
    }

    public function isRolledBack($flag=null)
    {
        if ($flag !== null) {
            if ($this->fiberMode) {
                $this->isRolledBack->set($flag);
            } else {
                $this->isRolledBack = $flag;
            }
        }
        return $this->fiberMode ? $this->isRolledBack->get() : $this->isRolledBack;
    }

    public function beginTransaction()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        if ($this->isRolledBack()) {
            throw new Exception\RuntimeException('Rolled back transaction has not been completed correctly.');
        }

        if (0 === $this->getTransactionLevel()) {
            $this->setCurrentTransaction($this->pool->beginTransaction());
            $this->inTransaction = true;
        }

        $this->incTransactionLevel(1);

        return $this;
    }

    public function commit()
    {
        if ($this->getTransactionLevel() === 1 && !$this->isRolledBack()) {
            $this->_commit();
        } elseif ($this->getTransactionLevel() === 0) {
            throw new Exception\RuntimeException('Asymmetric transaction commit.');
        } elseif ($this->isRolledBack()) {
            throw new Exception\RuntimeException('Rolled back transaction has not been completed correctly.');
        }
        $this->incTransactionLevel(-1);
        return $this;
    }

    protected function _commit()
    {
        $this->getCurrentTransaction()->commit();
        $this->removeTransaction();
    }

    private function removeTransaction()
    {
        $this->setCurrentTransaction(null);
    }

    public function getCurrentTransaction()
    {
        return $this->fiberMode ? $this->currentTransaction->get() : $this->currentTransaction;
    }

    public function setCurrentTransaction($value)
    {
        if ($this->fiberMode) {
            $this->currentTransaction->set($value);
        } else {
            $this->currentTransaction = $value;
        }
        return $this;
    }

    public function getTransactionLevel()
    {
        return $this->fiberMode ? $this->transactionLevel->get() : $this->transactionLevel;
    }

    public function setTransactionLevel($value)
    {
        if ($this->fiberMode) {
            $this->transactionLevel->set($value);
        } else {
            $this->transactionLevel = $value;
        }
        return $this;
    }

    public function incTransactionLevel($value)
    {
        if ($this->fiberMode) {
            $this->transactionLevel->set($this->transactionLevel->get()+$value);
        } else {
            $this->transactionLevel += $value;
        }
        return $this;
    }

    public function rollBack()
    {
        if ($this->getTransactionLevel() === 1) {
            $this->_rollBack();
            $this->isRolledBack(false);
        } elseif ($this->getTransactionLevel() === 0) {
            throw new Exception\RuntimeException('Asymmetric transaction rollback.');
        } else {
            $this->isRolledBack(true);
        }
        $this->incTransactionLevel(-1);
        return $this;
    }

    protected function _rollback()
    {
        $this->getCurrentTransaction()->rollback();
        $this->removeTransaction();
    }

    public function execute($sql)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        if ($this->profiler) {
            $this->profiler->profilerStart($sql);
        }

        $resultResource = new MysqlResultWrapper($this->pool->query($sql));

        if ($this->profiler) {
            $this->profiler->profilerFinish($sql);
        }

        return $this->driver->createResult($resultResource, $sql);
    }

    public function getLastGeneratedValue($name = null)
    {
        return $this->fiberMode ? $this->lastInsertId->get() : $this->lastInsertId;
    }

    public function setLastGeneratedValue($value)
    {
        if ($this->fiberMode) {
            $this->lastInsertId->set($value);
        } else {
            $this->lastInsertId = $value;
        }
        return $this;
    }

    public function setDriver(AmpDriver $driver)
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * @return resource|null|MysqlLink
     */
    public function getResource()
    {
        return $this->getCurrentTransaction()
            ? $this->getCurrentTransaction()
            : $this->pool;
    }

    public function setPool(MysqlConnectionPool $pool)
    {
        $this->pool = $pool;
        return $this;
    }

}
