<?php

namespace Tests\Unit;

use App\Console\Commands\BackupDatabase;
use App\Support\SqlDumper;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The PHP dump exists for hosts with no mysqldump binary, where it is the only
 * backup there is. It cannot be exercised end to end here — the suite runs on
 * SQLite and the dumper speaks MySQL — so what is pinned is everything that
 * decides whether the output restores: identifier quoting, value formatting,
 * and the statement shape. A dump that is subtly malformed reads as a working
 * backup right up until someone needs it.
 */
class SqlDumperTest extends TestCase
{
    private function dumper(): SqlDumper
    {
        return new SqlDumper(new PDO('sqlite::memory:'), 'emp');
    }

    // -- Identifiers ------------------------------------------------------

    public function test_identifiers_are_backtick_quoted(): void
    {
        $this->assertSame('`employees`', SqlDumper::quoteIdentifier('employees'));
    }

    public function test_a_backtick_inside_a_name_is_doubled_rather_than_ending_the_quote(): void
    {
        // Concatenation would produce `we`ird`, which is a syntax error at
        // best and a way out of the quoting at worst.
        $this->assertSame('`we``ird`', SqlDumper::quoteIdentifier('we`ird'));
    }

    // -- Values -----------------------------------------------------------

    public function test_null_is_written_as_null_and_not_as_an_empty_string(): void
    {
        // '' and NULL are different values, and a clock-out that is genuinely
        // absent must not restore as one that happened at the epoch.
        $this->assertSame('NULL', $this->dumper()->formatValue(null));
    }

    public function test_an_ordinary_string_is_quoted(): void
    {
        $out = $this->dumper()->formatValue('Ada');

        $this->assertStringStartsWith("'", $out);
        $this->assertStringEndsWith("'", $out);
        $this->assertStringContainsString('Ada', $out);
    }

    public function test_a_quote_in_the_value_does_not_end_the_literal(): void
    {
        $out = $this->dumper()->formatValue("O'Brien");

        // However the driver escapes it, the result must not be a bare
        // apostrophe sitting between two quotes.
        $this->assertNotSame("'O'Brien'", $out);
        $this->assertSame(1, preg_match('/^\'.*\'$/s', $out));
    }

    public function test_bytes_that_are_not_utf8_become_a_hex_literal(): void
    {
        // Hex means the same thing whatever sql_mode the restoring server
        // runs with, which quoting and backslash escaping do not.
        $this->assertSame('0x80ff', $this->dumper()->formatValue("\x80\xff"));
    }

    public function test_an_empty_string_stays_a_quoted_empty_string(): void
    {
        $this->assertSame("''", $this->dumper()->formatValue(''));
    }

    public function test_numbers_survive_as_their_own_text(): void
    {
        $this->assertStringContainsString('42', $this->dumper()->formatValue(42));
    }

    // -- Statements -------------------------------------------------------

    public function test_an_insert_names_its_columns(): void
    {
        // Without the column list the dump depends on column order, so a later
        // migration that adds a column silently shifts every value one across.
        $sql = SqlDumper::insertStatement('employees', ['`id`', '`name`'], ["(1,'Ada')"]);

        $this->assertSame("INSERT INTO `employees` (`id`,`name`) VALUES (1,'Ada');\n", $sql);
    }

    public function test_rows_are_batched_into_one_statement(): void
    {
        $sql = SqlDumper::insertStatement('t', ['`a`'], ['(1)', '(2)', '(3)']);

        $this->assertSame("INSERT INTO `t` (`a`) VALUES (1),(2),(3);\n", $sql);
    }

    public function test_the_batch_size_stays_clear_of_max_allowed_packet(): void
    {
        $this->assertLessThanOrEqual(1000, SqlDumper::ROWS_PER_STATEMENT);
    }

    // -- Header and footer ------------------------------------------------

    public function test_the_header_turns_off_foreign_key_checks_and_the_footer_turns_them_back_on(): void
    {
        // Tables restore in alphabetical order, so a child arrives before its
        // parent and every foreign key would reject its own data.
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0;', SqlDumper::header('emp'));
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1;', SqlDumper::footer());
    }

    public function test_the_header_sets_utf8mb4(): void
    {
        // Names and addresses are not ASCII. Restoring under latin1 mangles
        // them silently rather than failing.
        $this->assertStringContainsString('SET NAMES utf8mb4;', SqlDumper::header('emp'));
    }

    public function test_the_header_says_it_was_not_written_by_mysqldump(): void
    {
        // Whoever opens this file in an emergency should not have to work out
        // why it looks unfamiliar.
        $this->assertStringContainsString('mysqldump', SqlDumper::header('emp'));
        $this->assertStringContainsString('emp', SqlDumper::header('emp'));
    }

    // -- Choosing between the binary and the fallback ---------------------

    public function test_an_unset_binary_is_reported_missing(): void
    {
        $this->assertNull(BackupDatabase::locateBinary(''));
        $this->assertNull(BackupDatabase::locateBinary('   '));
    }

    public function test_a_configured_path_that_does_not_exist_is_reported_missing(): void
    {
        // Rather than being handed to Process to fail cryptically at run time.
        $this->assertNull(BackupDatabase::locateBinary('/nonexistent/bin/mysqldump'));
    }

    public function test_a_bare_name_is_not_found_when_the_path_is_empty(): void
    {
        $this->assertNull(BackupDatabase::locateBinary('mysqldump', ''));
    }

    public function test_a_bare_name_is_found_on_the_path(): void
    {
        // PHP's own binary is the one executable every host running this test
        // is guaranteed to have.
        $found = BackupDatabase::locateBinary(basename(PHP_BINARY), dirname(PHP_BINARY));

        $this->assertNotNull($found);
        $this->assertTrue(is_executable($found));
    }
}
