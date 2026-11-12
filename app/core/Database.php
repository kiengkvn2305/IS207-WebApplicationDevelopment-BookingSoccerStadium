<?php

class Database
{
    private static ?PDO $pdo = null;
    private static function callRoutine(string $routine): void
    {
        $statement = self::pdo()->query("CALL $routine()");
        $statement->closeCursor();
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            self::$pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '+07:00'");
        }
        return self::$pdo;
    }

    public static function beginTransaction(): bool
    {
        self::callRoutine('sannha_tx_begin');
        if (!self::pdo()->inTransaction()) {
            throw new RuntimeException('The database did not start the requested transaction.');
        }
        return true;
    }

    public static function commit(): bool
    {
        if (!self::pdo()->inTransaction()) {
            throw new LogicException('Cannot commit because no database transaction is active.');
        }
        self::callRoutine('sannha_tx_commit');
        if (self::pdo()->inTransaction()) {
            throw new RuntimeException('The database transaction is still active after commit.');
        }
        return true;
    }

    public static function rollBack(): bool
    {
        if (!self::pdo()->inTransaction()) {
            return false;
        }
        self::callRoutine('sannha_tx_rollback');
        if (self::pdo()->inTransaction()) {
            throw new RuntimeException('The database transaction is still active after rollback.');
        }
        return true;
    }

    public static function q(string $sql, array $parameters = []): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
