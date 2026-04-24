<?php

namespace Flyokai\LaminasDbDriverAmp;

use Amp\Mysql\MysqlResult;
use Amp\Sql\SqlException;
use Amp\Sql\SqlResult;
use Amp\Mysql\Internal\MysqlPooledResult;
use PDO;

class MysqlResultWrapper implements MysqlResult, \IteratorAggregate
{
    public function __construct(
        protected MysqlResult $subject
    ) {
    }

    public function getIterator(): \Traversable
    {
        return $this->subject->getIterator();
    }

    /**
     * @inheirtDoc
     */
    public function getNextResult(): ?MysqlResult
    {
        return $this->subject->getNextResult();
    }

    /**
     * @inheirtDoc
     */
    public function getLastInsertId(): ?int
    {
        return $this->subject->getLastInsertId();
    }

    /**
     * @inheirtDoc
     */
    public function getColumnDefinitions(): ?array
    {
        return $this->subject->getColumnDefinitions();
    }

    /**
     * @inheirtDoc
     */
    public function fetchRow(): ?array
    {
        return $this->subject->fetchRow();
    }

    /**
     * @inheirtDoc
     */
    public function getRowCount(): ?int
    {
        return $this->subject->getRowCount();
    }

    /**
     * @inheirtDoc
     */
    public function getColumnCount(): ?int
    {
        return $this->subject->getColumnCount();
    }

    public function rowCount(): int
    {
        return $this->subject->getRowCount();
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->subject->fetchRow();
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array
    {
        $result = [];
        while ($row = $this->subject->fetchRow())  {
            $result[] = $row;
        };
        return $result;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $result = [];
        $row = $this->subject->fetchRow();
        if (is_array($row)) {
            $row = array_values($row);
            if (!array_key_exists($column, $row)) {
                throw new SqlException(sprintf('Unknown column "%s" in result set', $column));
            }
        }
        return $row[$column] ?? false;
    }
}
