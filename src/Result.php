<?php

namespace Flyokai\LaminasDbDriverAmp;

use Iterator;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Exception;
use ReturnTypeWillChange;

class Result implements Iterator, ResultInterface
{
    protected MysqlResultWrapper $resource;
    /**
     * @var true
     */
    protected bool $currentComplete = false;
    protected mixed $currentData;
    protected int $position = -1;

    public const STATEMENT_MODE_SCROLLABLE = 'scrollable';
    public const STATEMENT_MODE_FORWARD    = 'forward';
    /** @var string */
    protected $statementMode = self::STATEMENT_MODE_FORWARD;
    /**
     * @var mixed|null
     */
    protected mixed $context;

    #[ReturnTypeWillChange]
    public function current()
    {
        if ($this->currentComplete) {
            return $this->currentData;
        }

        $this->currentData     = $this->resource->fetchRow();
        $this->currentComplete = true;
        return $this->currentData;
    }

    #[ReturnTypeWillChange]
    public function next()
    {
        $this->currentData     = $this->resource->fetch();
        $this->currentComplete = true;
        $this->position++;
        return $this->currentData;
    }

    #[ReturnTypeWillChange]
    public function key()
    {
        return $this->position;
    }

    #[ReturnTypeWillChange]
    public function valid()
    {
        return $this->currentData !== null;
    }

    #[ReturnTypeWillChange]
    public function rewind()
    {
        if ($this->statementMode === self::STATEMENT_MODE_FORWARD && $this->position > 0) {
            throw new Exception\RuntimeException(
                'This result is a forward only result set, calling rewind() after moving forward is not supported'
            );
        }
        if (!$this->currentComplete) {
            $this->currentData     = $this->resource->fetch();
            $this->currentComplete = true;
        }
        $this->position = 0;
    }

    #[ReturnTypeWillChange]
    public function count()
    {
        return $this->resource->getRowCount();
    }

    public function buffer()
    {
    }

    public function isBuffered()
    {
        return false;
    }

    public function isQueryResult()
    {
        return $this->resource->getColumnCount() > 0;
    }

    public function getAffectedRows()
    {
        return $this->resource->getRowCount();
    }

    public function getGeneratedValue()
    {
        return $this->resource->getLastInsertId();
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function getFieldCount()
    {
        return $this->resource->getColumnCount();
    }

    public function initialize(MysqlResultWrapper $resource, $context = null)
    {
        $this->resource = $resource;
        $this->context = $context;
        return $this;
    }

    public function getContext(): mixed
    {
        return $this->context;
    }

}
