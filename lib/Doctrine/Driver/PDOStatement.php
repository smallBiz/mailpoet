<?php

namespace MailPoet\Doctrine\Driver;

use MailPoetVendor\Doctrine\DBAL\Driver\PDOException;
use MailPoetVendor\Doctrine\DBAL\Driver\Statement;

/**
 * The PDO implementation of the Statement interface.
 * Used by all PDO-based drivers.
 *
 * @since 2.0
 */
class PDOStatement implements Statement, \Iterator {

  /** @var \PDOStatement */
  private $statement;

  /**
   * Protected constructor.
   */
  public function __construct(\PDOStatement $stmt)
  {
    $this->statement = $stmt;
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null)
  {
    // This thin wrapper is necessary to shield against the weird signature
    // of PDOStatement::setFetchMode(): even if the second and third
    // parameters are optional, PHP will not let us remove it from this
    // declaration.
    try {
      if ($arg2 === null && $arg3 === null) {
        return $this->statement->setFetchMode($fetchMode);
      }

      if ($arg3 === null) {
        return $this->statement->setFetchMode($fetchMode, $arg2);
      }

      return $this->statement->setFetchMode($fetchMode, $arg2, $arg3);
    } catch (\PDOException $exception) {
      throw new PDOException($exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function bindValue($param, $value, $type = \PDO::PARAM_STR)
  {
    try {
      return $this->statement->bindValue($param, $value, $type);
    } catch (\PDOException $exception) {
      throw new PDOException($exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function bindParam($column, &$variable, $type = \PDO::PARAM_STR, $length = null, $driverOptions = null)
  {
    try {
      return $this->statement->bindParam($column, $variable, $type, $length, $driverOptions);
    } catch (\PDOException $exception) {
      throw new PDOException($exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function closeCursor()
  {
    try {
      return $this->statement->closeCursor();
    } catch (\PDOException $exception) {
      // Exceptions not allowed by the interface.
      // In case driver implementations do not adhere to the interface, silence exceptions here.
      return true;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute($params = null)
  {
    try {
      return $this->statement->execute($params);
    } catch (\PDOException $exception) {
      throw new PDOException($exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($fetchMode = null, $cursorOrientation = null, $cursorOffset = null)
  {
    try {
      if ($fetchMode === null && $cursorOrientation === null && $cursorOffset === null) {
        return $this->statement->fetch();
      }

      if ($cursorOrientation === null && $cursorOffset === null) {
        return $this->statement->fetch($fetchMode);
      }

      if ($cursorOffset === null) {
        return $this->statement->fetch($fetchMode, $cursorOrientation);
      }

      return $this->statement->fetch($fetchMode, $cursorOrientation, $cursorOffset);
    } catch (\PDOException $exception) {
      throw new PDOException($exception);
    }
  }

  /**
   * {@inheritdoc}
   */
    public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null)
    {
        try {
            if ($fetchMode === null && $fetchArgument === null && $ctorArgs === null) {
                return $this->statement->fetchAll();
            }

            if ($fetchArgument === null && $ctorArgs === null) {
                return $this->statement->fetchAll($fetchMode);
            }

            if ($ctorArgs === null) {
                return $this->statement->fetchAll($fetchMode, $fetchArgument);
            }

            return $this->statement->fetchAll($fetchMode, $fetchArgument, $ctorArgs);
        } catch (\PDOException $exception) {
            throw new PDOException($exception);
        }
    }

  /**
   * {@inheritdoc}
   */
  public function fetchColumn($columnIndex = 0)
  {
    try {
      return $this->statement->fetchColumn($columnIndex);
    } catch (\PDOException $exception) {
      throw new PDOException($exception);
    }
  }

  public function columnCount() {
    return $this->statement->columnCount();
  }

  function errorCode() {
    return $this->statement->errorCode();
  }

  function errorInfo() {
    return $this->statement->errorInfo();
  }

  function rowCount() {
    return $this->statement->rowCount();
  }

  public function current() {
    return $this->statement->current();
  }

  public function next() {
    return $this->statement->next();
  }

  public function key() {
    return $this->statement->key();
  }

  public function valid() {
    return $this->statement->valid();
  }

  public function rewind() {
    return $this->statement->rewind();
  }

}
