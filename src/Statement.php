<?php

namespace Doctrine\DBAL;

use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ForwardCompatibility\ResultStatement as ForwardCompatibleResultStatement;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use IteratorAggregate;
use Throwable;
use Traversable;
use function is_string;

/**
 * A thin wrapper around a Doctrine\DBAL\Driver\Statement that adds support
 * for logging, DBAL mapping types, etc.
 */
class Statement implements IteratorAggregate, DriverStatement, ForwardCompatibleResultStatement
{
    /**
     * The SQL statement.
     *
     * @var string
     */
    protected $sql;

    /**
     * The bound parameters.
     *
     * @var mixed[]
     */
    protected $params = [];

    /**
     * The parameter types.
     *
     * @var int[]|string[]
     */
    protected $types = [];

    /**
     * The underlying driver statement.
     *
     * @var \Doctrine\DBAL\Driver\Statement
     */
    protected $stmt;

    /**
     * The underlying database platform.
     *
     * @var AbstractPlatform
     */
    protected $platform;

    /**
     * The connection this statement is bound to and executed on.
     *
     * @var Connection
     */
    protected $conn;

    /**
     * Creates a new <tt>Statement</tt> for the given SQL and <tt>Connection</tt>.
     *
     * @param string     $sql  The SQL of the statement.
     * @param Connection $conn The connection on which the statement should be executed.
     */
    public function __construct($sql, Connection $conn)
    {
        $this->sql      = $sql;
        $this->stmt     = $conn->getWrappedConnection()->prepare($sql);
        $this->conn     = $conn;
        $this->platform = $conn->getDatabasePlatform();
    }

    /**
     * Binds a parameter value to the statement.
     *
     * The value can optionally be bound with a PDO binding type or a DBAL mapping type.
     * If bound with a DBAL mapping type, the binding type is derived from the mapping
     * type and the value undergoes the conversion routines of the mapping type before
     * being bound.
     *
     * @param string|int $name  The name or position of the parameter.
     * @param mixed      $value The value of the parameter.
     * @param mixed      $type  Either a PDO binding type or a DBAL mapping type name or instance.
     *
     * @return bool TRUE on success, FALSE on failure.
     */
    public function bindValue($name, $value, $type = ParameterType::STRING)
    {
        $this->params[$name] = $value;
        $this->types[$name]  = $type;
        if ($type !== null) {
            if (is_string($type)) {
                $type = Type::getType($type);
            }

            if ($type instanceof Type) {
                $value       = $type->convertToDatabaseValue($value, $this->platform);
                $bindingType = $type->getBindingType();
            } else {
                $bindingType = $type;
            }

            return $this->stmt->bindValue($name, $value, $bindingType);
        }

        return $this->stmt->bindValue($name, $value);
    }

    /**
     * Binds a parameter to a value by reference.
     *
     * Binding a parameter by reference does not support DBAL mapping types.
     *
     * @param string|int $name   The name or position of the parameter.
     * @param mixed      $var    The reference to the variable to bind.
     * @param int        $type   The PDO binding type.
     * @param int|null   $length Must be specified when using an OUT bind
     *                           so that PHP allocates enough memory to hold the returned value.
     *
     * @return bool TRUE on success, FALSE on failure.
     */
    public function bindParam($name, &$var, $type = ParameterType::STRING, $length = null)
    {
        $this->params[$name] = $var;
        $this->types[$name]  = $type;

        return $this->stmt->bindParam($name, $var, $type, $length);
    }

    /**
     * Executes the statement with the currently bound parameters.
     *
     * @param mixed[]|null $params
     *
     * @return bool TRUE on success, FALSE on failure.
     *
     * @throws DBALException
     */
    public function execute($params = null)
    {
        if ($params !== null) {
            $this->params = $params;
        }

        $logger = $this->conn->getConfiguration()->getSQLLogger();
        if ($logger !== null) {
            $logger->startQuery($this->sql, $this->params, $this->types);
        }

        try {
            $stmt = $this->stmt->execute($params);
        } catch (Throwable $ex) {
            if ($logger !== null) {
                $logger->stopQuery();
            }

            throw DBALException::driverExceptionDuringQuery(
                $this->conn->getDriver(),
                $ex,
                $this->sql,
                $this->conn->resolveParams($this->params, $this->types)
            );
        }

        if ($logger !== null) {
            $logger->stopQuery();
        }

        $this->params = [];
        $this->types  = [];

        return $stmt;
    }

    /**
     * Closes the cursor, freeing the database resources used by this statement.
     *
     * @return bool TRUE on success, FALSE on failure.
     */
    public function closeCursor()
    {
        return $this->stmt->closeCursor();
    }

    /**
     * Returns the number of columns in the result set.
     *
     * @return int
     */
    public function columnCount()
    {
        return $this->stmt->columnCount();
    }

    /**
     * Fetches the SQLSTATE associated with the last operation on the statement.
     *
     * @deprecated The error information is available via exceptions.
     *
     * @return string|int|bool
     */
    public function errorCode()
    {
        return $this->stmt->errorCode();
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated The error information is available via exceptions.
     */
    public function errorInfo()
    {
        return $this->stmt->errorInfo();
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated Use one of the fetch- or iterate-related methods.
     */
    public function setFetchMode($fetchMode)
    {
        return $this->stmt->setFetchMode($fetchMode);
    }

    /**
     * Required by interface IteratorAggregate.
     *
     * @deprecated Use iterateNumeric(), iterateAssociative() or iterateColumn() instead.
     *
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return $this->stmt;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated Use fetchNumeric(), fetchAssociative() or fetchOne() instead.
     */
    public function fetch($fetchMode = null)
    {
        return $this->stmt->fetch($fetchMode);
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated Use fetchAllNumeric(), fetchAllAssociative() or fetchColumn() instead.
     */
    public function fetchAll($fetchMode = null)
    {
        return $this->stmt->fetchAll($fetchMode);
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated Use fetchOne() instead.
     */
    public function fetchColumn()
    {
        return $this->stmt->fetchColumn();
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchNumeric()
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                return $this->stmt->fetchNumeric();
            }

            return $this->stmt->fetch(FetchMode::NUMERIC);
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchAssociative()
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                return $this->stmt->fetchAssociative();
            }

            return $this->stmt->fetch(FetchMode::ASSOCIATIVE);
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws DBALException
     */
    public function fetchOne()
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                return $this->stmt->fetchOne();
            }

            return $this->stmt->fetch(FetchMode::COLUMN);
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchAllNumeric() : array
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                return $this->stmt->fetchAllNumeric();
            }

            return $this->stmt->fetchAll(FetchMode::NUMERIC);
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchAllAssociative() : array
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                return $this->stmt->fetchAllAssociative();
            }

            return $this->stmt->fetchAll(FetchMode::ASSOCIATIVE);
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,array<int,mixed>>
     *
     * @throws DBALException
     */
    public function iterateNumeric() : Traversable
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                while (($row = $this->stmt->fetchNumeric()) !== false) {
                    yield $row;
                }
            } else {
                while (($row = $this->stmt->fetch(FetchMode::NUMERIC)) !== false) {
                    yield $row;
                }
            }
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,array<string,mixed>>
     *
     * @throws DBALException
     */
    public function iterateAssociative() : Traversable
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                while (($row = $this->stmt->fetchAssociative()) !== false) {
                    yield $row;
                }
            } else {
                while (($row = $this->stmt->fetch(FetchMode::ASSOCIATIVE)) !== false) {
                    yield $row;
                }
            }
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,mixed>
     *
     * @throws DBALException
     */
    public function iterateColumn() : Traversable
    {
        try {
            if ($this->stmt instanceof ForwardCompatibleResultStatement) {
                while (($value = $this->stmt->fetchOne()) !== false) {
                    yield $value;
                }
            } else {
                while (($value = $this->stmt->fetch(FetchMode::COLUMN)) !== false) {
                    yield $value;
                }
            }
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * Returns the number of rows affected by the last execution of this statement.
     *
     * @return int The number of affected rows.
     */
    public function rowCount() : int
    {
        return $this->stmt->rowCount();
    }

    /**
     * Gets the wrapped driver statement.
     *
     * @return \Doctrine\DBAL\Driver\Statement
     */
    public function getWrappedStatement()
    {
        return $this->stmt;
    }
}