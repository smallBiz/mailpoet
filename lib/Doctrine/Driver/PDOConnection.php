<?php

namespace MailPoet\Doctrine\Driver;

use PDO;

/**
 * PDO implementation of the Connection interface.
 * Used by all PDO-based drivers.
 *
 * @since 2.0
 */
class PDOConnection implements \MailPoetVendor\Doctrine\DBAL\Driver\Connection, \MailPoetVendor\Doctrine\DBAL\Driver\ServerInfoAwareConnection {

  /** @var PDO */
  private $connection;

  /**
   * @param string $dsn
   * @param string|null $user
   * @param string|null $password
   * @param array|null $options
   *
   * @throws \PDOException in case of an error.
   */
  public function __construct($dsn, $user = null, $password = null, array $options = null) {
    try {
      $this->connection = new PDO($dsn, (string)$user, (string)$password, (array)$options);
      $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\PDOException $exception) {
      throw new \MailPoetVendor\Doctrine\DBAL\Driver\PDOException($exception);
    }
  }

  public function getConnection(): PDO {
    return $this->connection;
  }

  /**
   * {@inheritdoc}
   */
  public function exec($statement) {
    try {
      return $this->connection->exec($statement);
    } catch (\PDOException $exception) {
      throw new \MailPoetVendor\Doctrine\DBAL\Driver\PDOException($exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getServerVersion() {
    return $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare($prepareString, $driverOptions = []) {
    try {
      return $this->createStatement(
        $this->connection->prepare($prepareString, $driverOptions)
      );
    } catch (\PDOException $exception) {
      throw new \MailPoetVendor\Doctrine\DBAL\Driver\PDOException($exception);
    }
  }

  /**
   * Creates a wrapped statement
   */
  protected function createStatement(\PDOStatement $stmt): PDOStatement
  {
    return new PDOStatement($stmt);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $args = func_get_args();
    $argsCount = count($args);

    try {
      if ($argsCount == 4) {
        $stmt = $this->connection->query($args[0], $args[1], $args[2], $args[3]);
      } elseif ($argsCount == 3) {
        $stmt = $this->connection->query($args[0], $args[1], $args[2]);
      } elseif ($argsCount == 2) {
        $stmt = $this->connection->query($args[0], $args[1]);
      } else {
        $stmt = $this->connection->query($args[0]);
      }

      return $stmt;
    } catch (\PDOException $exception) {
      throw new \MailPoetVendor\Doctrine\DBAL\Driver\PDOException($exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function quote($input, $type = \PDO::PARAM_STR) {
    return $this->connection->quote($input, $type);
  }

  /**
   * {@inheritdoc}
   */
  public function lastInsertId($name = null) {
    return $this->connection->lastInsertId($name);
  }

  /**
   * {@inheritdoc}
   */
  public function requiresQueryForServerVersion() {
    return false;
  }

  /**
   * {@inheritdoc}
   */
  function beginTransaction() {
    return $this->connection->beginTransaction();
  }

  /**
   * {@inheritdoc}
   */
  function commit() {
    return $this->connection->commit();
  }

  /**
   * {@inheritdoc}
   */
  function rollBack() {
    return $this->connection->rollBack();
  }

  /**
   * {@inheritdoc}
   */
  function errorCode() {
    return $this->connection->errorCode();
  }

  /**
   * {@inheritdoc}
   */
  function errorInfo() {
    return $this->connection->errorInfo();
  }

}
