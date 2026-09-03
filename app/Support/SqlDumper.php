<?php

namespace App\Support;

use PDO;

/**
 * A database dump written in PHP, for hosts that have no mysqldump binary.
 *
 * Managed webspace frequently ships the MySQL *client library* — PDO works
 * fine, which is how the application runs at all — while providing none of the
 * command-line tools. On such a host `db:backup` had nothing to shell out to,
 * so it failed every night and the first anyone would hear of it is the day
 * they needed a restore. This produces the dump over the existing connection
 * instead.
 *
 * It is a deliberate subset of what mysqldump writes:
 *
 *   - Tables, their CREATE statements and their rows. That is the whole schema
 *     here; the application has no views, triggers, routines or generated
 *     columns, and a dumper that silently skipped them would be a trap.
 *   - No tablespace interrogation, so it needs no PROCESS privilege — the same
 *     reason the mysqldump path passes --no-tablespaces.
 *
 * Rows are read inside a consistent snapshot, so staff can keep clocking in
 * while the backup runs, and streamed rather than collected: attendance is the
 * table that grows without bound, and a dumper that assembled it in memory
 * would work for a year and then start dying on the largest install.
 */
final class SqlDumper
{
    /**
     * Rows per INSERT. Large enough that the statement overhead is noise,
     * small enough that a restore never meets max_allowed_packet.
     */
    public const ROWS_PER_STATEMENT = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
    ) {}

    /**
     * Write the whole dump through $write, which receives SQL in chunks.
     */
    public function dump(callable $write): void
    {
        $write(self::header($this->database));

        // The equivalent of mysqldump --single-transaction: every table is read
        // as it stood at the same instant, so a punch recorded halfway through
        // cannot appear in one table and be missing from another.
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            foreach ($this->tables() as $table) {
                $write($this->structureOf($table));
                $this->writeRows($table, $write);
            }
        } finally {
            $this->pdo->exec('COMMIT');
        }

        $write(self::footer());
    }

    /**
     * Every base table in the schema, views excluded.
     */
    public function tables(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?
              ORDER BY TABLE_NAME'
        );
        $statement->execute([$this->database, 'BASE TABLE']);

        return $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * DROP + CREATE for one table, taken from the server rather than rebuilt,
     * so indexes, collations and foreign keys survive exactly as they are.
     */
    public function structureOf(string $table): string
    {
        $row = $this->pdo->query('SHOW CREATE TABLE ' . self::quoteIdentifier($table))
            ->fetch(PDO::FETCH_ASSOC);

        $create = $row['Create Table'] ?? $row['create table'] ?? null;

        if ($create === null) {
            throw new \RuntimeException("Could not read the structure of {$table}.");
        }

        return "\n--\n-- Table: {$table}\n--\n\n"
            . 'DROP TABLE IF EXISTS ' . self::quoteIdentifier($table) . ";\n"
            . $create . ";\n\n";
    }

    /**
     * Stream one table's rows as batched INSERT statements.
     */
    private function writeRows(string $table, callable $write): void
    {
        $statement = $this->pdo->query('SELECT * FROM ' . self::quoteIdentifier($table));

        $columns = null;
        $batch   = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns ??= array_map([self::class, 'quoteIdentifier'], array_keys($row));

            $batch[] = '(' . implode(',', array_map([$this, 'formatValue'], $row)) . ')';

            if (count($batch) >= self::ROWS_PER_STATEMENT) {
                $write(self::insertStatement($table, $columns, $batch));
                $batch = [];
            }
        }

        if ($batch !== []) {
            $write(self::insertStatement($table, $columns ?? [], $batch));
        }
    }

    public static function insertStatement(string $table, array $columns, array $rows): string
    {
        return 'INSERT INTO ' . self::quoteIdentifier($table)
            . ' (' . implode(',', $columns) . ') VALUES '
            . implode(',', $rows) . ";\n";
    }

    /**
     * One value, ready to sit inside an INSERT.
     *
     * Anything that is not valid UTF-8 becomes a hex literal. That covers the
     * binary columns, and it also sidesteps the escaping question entirely for
     * them: a hex literal means the same thing whatever sql_mode the restoring
     * server happens to run with.
     */
    public function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $value = (string) $value;

        if ($value !== '' && ! preg_match('//u', $value)) {
            return '0x' . bin2hex($value);
        }

        return $this->pdo->quote($value);
    }

    /**
     * Backtick-quote an identifier. A backtick inside a name is doubled, which
     * is MySQL's own escape and the reason this is not just concatenation.
     */
    public static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public static function header(string $database): string
    {
        return "-- Employment Management Portal — database dump\n"
            . "-- Written by App\\Support\\SqlDumper, not mysqldump: this host has no\n"
            . "-- mysqldump binary. Restores with any MySQL client, phpMyAdmin included.\n"
            . '-- Database: ' . $database . "\n"
            . '-- Taken: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    }

    public static function footer(): string
    {
        return "\nSET FOREIGN_KEY_CHECKS=1;\n-- Dump complete.\n";
    }
}
