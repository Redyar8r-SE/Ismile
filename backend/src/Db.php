<?php
// Small helpers around PDO. Every query uses placeholders; no value is ever
// pasted into SQL text.

declare(strict_types=1);

namespace Ismile;

final class Db
{
    public static function all(string $sql, array $params = []): array
    {
        $statement = App::db()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $statement = App::db()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $statement = App::db()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function run(string $sql, array $params = []): int
    {
        $statement = App::db()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (!preg_match('/^[a-z_0-9]+$/', $column)) {
                throw new \InvalidArgumentException('Bad column name.');
            }
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        App::db()->prepare($sql)->execute(array_values($row));
        return (int) App::db()->lastInsertId();
    }

    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        $sets = [];
        foreach (array_keys($row) as $column) {
            if (!preg_match('/^[a-z_0-9]+$/', $column)) {
                throw new \InvalidArgumentException('Bad column name.');
            }
            $sets[] = "$column = ?";
        }
        $statement = App::db()->prepare("UPDATE $table SET " . implode(', ', $sets) . " WHERE $where");
        $statement->execute([...array_values($row), ...$params]);
        return $statement->rowCount();
    }

    /** Runs $work inside one transaction; rolls back on any error. */
    public static function transaction(callable $work): mixed
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $result = $work();
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
