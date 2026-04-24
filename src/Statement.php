<?php

namespace Flyokai\LaminasDbDriverAmp;

use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Exception;
use Laminas\Db\Adapter\ParameterContainer;
use Laminas\Db\Adapter\Profiler;
use Laminas\Db\Adapter\Profiler\ProfilerInterface;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlResult;

class Statement implements StatementInterface, Profiler\ProfilerAwareInterface
{
    protected ?ProfilerInterface $profiler = null;
    protected string $sql = '';
    protected ?ParameterContainer $parameterContainer=null;
    protected bool $isPrepared = false;
    protected bool $parametersBound = false;
    protected ?MysqlStatement $resource;
    protected AmpDriver $driver;
    protected ?MysqlResult $result;

    /**
     * @param ProfilerInterface $profiler
     * @return $this
     */
    public function setProfiler(Profiler\ProfilerInterface $profiler)
    {
        $this->profiler = $profiler;
        return $this;
    }

    /**
     * @return null|ProfilerInterface
     */
    public function getProfiler()
    {
        return $this->profiler;
    }

    /**
     * Set sql
     *
     * @param string $sql
     * @return $this Provides a fluent interface
     */
    public function setSql($sql)
    {
        $this->sql = $sql;
        return $this;
    }

    /**
     * Get sql
     *
     * @return string
     */
    public function getSql()
    {
        return $this->sql;
    }

    /**
     * @return $this Provides a fluent interface
     */
    public function setParameterContainer(ParameterContainer $parameterContainer)
    {
        $this->parametersBound = false;
        $this->parameterContainer = $parameterContainer;
        return $this;
    }

    /**
     * @return ParameterContainer
     */
    public function getParameterContainer()
    {
        return $this->parameterContainer;
    }

    /**
     * @return MysqlStatement
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @param MysqlStatement $resource
     * @return $this
     */
    public function setResource(MysqlStatement $resource)
    {
        $this->resource = $resource;
        return $this;
    }

    public function prepare($sql = null)
    {
        if ($this->isPrepared) {
            throw new Exception\RuntimeException('This statement has been prepared already');
        }

        if ($sql === null) {
            $sql = $this->sql;
        }

        $this->resource = $this->driver->getConnection()->getResource()->prepare($sql);

        $this->isPrepared = true;
    }

    public function isPrepared()
    {
        return $this->isPrepared;
    }

    public function execute($parameters = null)
    {
        if (! $this->isPrepared) {
            $this->prepare();
        }

        /** START Standard ParameterContainer Merging Block */
        if (! $this->parameterContainer instanceof ParameterContainer) {
            if ($parameters instanceof ParameterContainer) {
                $this->parametersBound = false;
                $this->parameterContainer = $parameters;
                $parameters               = null;
            } else {
                $this->parameterContainer = new ParameterContainer();
            }
        }

        if (is_array($parameters)) {
            $this->parametersBound = false;
            $this->parameterContainer->setFromArray($parameters);
        }

        $execParams = [];
        if ($this->parameterContainer->count() > 0) {
            $parameters = $this->parameterContainer->getNamedArray();
            foreach ($parameters as $name => &$value) {
                if (is_scalar($value) || is_null($value)) {
                    $execParams[$name] = $value;
                } else {
                    $execParams[$name] = (string)$value;
                }
            }
        }

        /* TODO: Check why bind doesn't work */
        /*if ($this->parameterContainer->count() > 0) {
            $this->bindParametersFromContainer();
        }*/
        /** END Standard ParameterContainer Merging Block */

        if ($this->profiler) {
            $this->profiler->profilerStart($this);
        }

        try {
            $this->result = new MysqlResultWrapper($this->resource->execute($execParams));
        } catch (\Throwable $e) {
            if ($this->profiler) {
                $this->profiler->profilerFinish();
            }

            $code = $e->getCode();
            if (!is_int($code)) {
                $code = 0;
            }

            throw new Exception\InvalidQueryException(
                'Statement could not be executed: ' . $e->getMessage(),
                $code,
                $e
            );
        }

        if ($this->profiler) {
            $this->profiler->profilerFinish();
        }

        $this->driver->getConnection()->setLastGeneratedValue($this->result->getLastInsertId());

        return $this->driver->createResult($this->result, $this);
    }

    protected function bindParametersFromContainer()
    {
        if ($this->parametersBound) {
            return;
        }

        $parameters = $this->parameterContainer->getNamedArray();
        foreach ($parameters as $name => &$value) {
            if (is_bool($value)) {
                $type = \PDO::PARAM_BOOL;
            } elseif (is_int($value)) {
                $type = \PDO::PARAM_INT;
            } else {
                $type = \PDO::PARAM_STR;
            }
            if ($this->parameterContainer->offsetHasErrata($name)) {
                switch ($this->parameterContainer->offsetGetErrata($name)) {
                    case ParameterContainer::TYPE_INTEGER:
                        $type = \PDO::PARAM_INT;
                        break;
                    case ParameterContainer::TYPE_NULL:
                        $type = \PDO::PARAM_NULL;
                        break;
                    case ParameterContainer::TYPE_LOB:
                        $type = \PDO::PARAM_LOB;
                        break;
                }
            }

            // parameter is named or positional, value is reference
            $this->resource->bind($name, (string)$value);
        }
    }

    public function getResult()
    {
        if (!$this->result) {
            throw new Exception\RuntimeException('Statement result is not available');
        }
        return $this->result;
    }

    /**
     * Perform a deep clone
     *
     * @return void
     */
    public function __clone()
    {
        $this->isPrepared      = false;
        $this->parametersBound = false;
        $this->resource        = null;
        if ($this->parameterContainer) {
            $this->parameterContainer = clone $this->parameterContainer;
        }
    }

    public function setDriver(AmpDriver $driver)
    {
        $this->driver = $driver;
        return $this;
    }

}
