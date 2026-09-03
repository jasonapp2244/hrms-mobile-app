<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Support\SqlDumper;
use Symfony\Component\Process\Process;

/**
 * Take a dump of the database, and prove it can be read back.
 *
 * Attendance and leave are the record of what people worked and are owed. There
 * is no second copy of it anywhere, so a backup is not housekeeping here — it is
 * the difference between a bad afternoon and losing the year.
 *
 * --verify is the reason this is a command rather than a cron line calling
 * mysqldump. A dump that was never restored is a guess: truncated output, a
 * mysqldump that exited 0 after writing an error into the file, a compression
 * step that silently produced nothing. Verifying loads the dump into a scratch
 * database, counts what came back, and drops it — so the answer is "this file
 * restores" rather than "this file exists".
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
                            {--verify : Restore the new dump into a scratch database to prove it reads back}
                            {--keep= : Override how many dumps to retain}
                            {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Dump the database, verify it restores, and rotate old copies';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            $this->error("db:backup only supports MySQL; the active connection is '{$connection}'.");

            return self::FAILURE;
        }

        $db   = config("database.connections.{$connection}");
        $dir  = rtrim(config('backup.path'), '/\\');
        $keep = (int) ($this->option('keep') ?? config('backup.keep'));
        $gzip = (bool) config('backup.compress');

        $stamp    = now()->format('Y-m-d_His');
        $filename = sprintf('%s_%s.sql%s', $db['database'], $stamp, $gzip ? '.gz' : '');
        $target   = $dir . DIRECTORY_SEPARATOR . $filename;

        if ($this->option('dry-run')) {
            $this->line("Would write {$target}");
            $this->line("Would keep the newest {$keep} dump(s) in {$dir}");

            return self::SUCCESS;
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            $this->error("Could not create the backup directory: {$dir}");

            return self::FAILURE;
        }

        // The password never goes on the command line — argv is readable by any
        // other process on the box via ps. mysqldump reads it from a file
        // instead, written next to the dump and removed in the finally block.
        $credentials = $this->writeCredentialsFile($db);

        try {
            $this->line("Dumping {$db['database']} …");

            if (! $this->dump($credentials, $db, $target, $gzip)) {
                return self::FAILURE;
            }

            $size = filesize($target) ?: 0;

            // A dump smaller than its own header is not a dump. Caught here
            // rather than in three months when someone tries to restore it.
            if ($size < 512) {
                $this->error("The dump is only {$size} bytes — treating it as failed and keeping it for inspection.");

                return self::FAILURE;
            }

            $this->info(sprintf('Wrote %s (%s)', $filename, $this->humanSize($size)));

            // false is a dump that failed to restore, which is a real problem.
            // null is a dump that could not be checked at all because the
            // database user may not create databases — normal on managed
            // hosting, and not a reason to fail a deploy over a dump that was
            // written perfectly well.
            if ($this->option('verify') && $this->verify($credentials, $db, $target, $gzip) === false) {
                return self::FAILURE;
            }

            // Rotation happens only after everything above succeeded. Deleting
            // first would mean a failed run leaves fewer backups than it found.
            $this->rotate($dir, $db['database'], $keep);
        } finally {
            @unlink($credentials);
        }

        return self::SUCCESS;
    }

    /**
     * mysqldump reads the password from here rather than from argv.
     *
     * Written with restrictive permissions and removed on the way out. chmod is
     * a no-op on Windows, which is why the file is short-lived rather than
     * relying on the mode alone.
     */
    protected function writeCredentialsFile(array $db): string
    {
        $path = tempnam(sys_get_temp_dir(), 'emp-backup-');

        file_put_contents($path, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
            $db['username'],
            $db['password'],
            $db['host'],
            $db['port'] ?? 3306,
        ));

        @chmod($path, 0600);

        return $path;
    }

    protected function dump(string $credentials, array $db, string $target, bool $gzip): bool
    {
        $binary = self::locateBinary((string) config('backup.mysqldump'));

        // Managed webspace ships the client library but not the command-line
        // tools. Failing here would mean no backup at all on such a host, so
        // the dump is written through PHP instead — see App\Support\SqlDumper.
        if ($binary === null) {
            $this->warn('No mysqldump on this host — writing the dump through PHP instead.');

            return $this->dumpWithPhp($db, $target, $gzip);
        }

        $command = [
            $binary,
            "--defaults-extra-file={$credentials}",
            // Consistent snapshot without locking the whole database — staff can
            // keep clocking in while the backup runs.
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            // Without this a dump taken mid-write can restore into a different
            // row order and mask the problem.
            '--order-by-primary',
            // MySQL 8 reads INFORMATION_SCHEMA.FILES for tablespaces, which
            // needs the global PROCESS privilege. Managed hosting does not
            // grant it, so every dump came back with an "Access denied ...
            // PROCESS" warning on stderr for information this application has
            // no use for — InnoDB tables in the default tablespace.
            '--no-tablespaces',
            $db['database'],
        ];

        $process = new Process($command, timeout: (int) config('backup.timeout'));
        $handle = $gzip ? gzopen($target, 'wb9') : fopen($target, 'wb');

        if (! $handle) {
            $this->error("Could not open {$target} for writing.");

            return false;
        }

        $stderr = '';

        try {
            $process->run(function ($type, $chunk) use ($handle, $gzip, &$stderr) {
                if ($type === Process::OUT) {
                    $gzip ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);
                } else {
                    $stderr .= $chunk;
                }
            });
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }

        if (! $process->isSuccessful()) {
            $this->error('mysqldump failed: ' . trim($stderr ?: $process->getErrorOutput()));
            // Leave nothing half-written to be mistaken for a real backup.
            @unlink($target);

            return false;
        }

        // mysqldump can exit 0 having written a warning to stderr. Worth showing,
        // not worth failing on.
        if (trim($stderr) !== '') {
            $this->warn('mysqldump said: ' . trim($stderr));
        }

        return true;
    }

    /**
     * Write the dump over the application's own connection.
     *
     * The result restores with any MySQL client — phpMyAdmin included, which
     * on a host with no binaries is how a restore will actually be performed.
     */
    protected function dumpWithPhp(array $db, string $target, bool $gzip): bool
    {
        $handle = $gzip ? gzopen($target, 'wb9') : fopen($target, 'wb');

        if (! $handle) {
            $this->error("Could not open {$target} for writing.");

            return false;
        }

        $pdo = DB::connection()->getPdo();

        // Unbuffered, so attendance_logs streams rather than arriving in
        // memory in one piece. Restored afterwards because the connection is
        // the application's, not ours to leave altered.
        $buffered = null;

        try {
            $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        } catch (\Throwable) {
            // Not a MySQL driver, or the attribute is unavailable. The dump
            // still works; it simply holds each table in memory.
        }

        try {
            (new SqlDumper($pdo, $db['database']))->dump(
                fn (string $sql) => $gzip ? gzwrite($handle, $sql) : fwrite($handle, $sql)
            );
        } catch (\Throwable $e) {
            $this->error('The PHP dump failed: ' . $e->getMessage());
            $gzip ? gzclose($handle) : fclose($handle);
            // Leave nothing half-written to be mistaken for a real backup.
            @unlink($target);

            return false;
        } finally {
            if ($buffered !== null) {
                try {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                } catch (\Throwable) {
                    // Nothing to restore it to; the process is about to end.
                }
            }
        }

        $gzip ? gzclose($handle) : fclose($handle);

        return true;
    }

    /**
     * The usable path to a binary, or null when this host does not have it.
     *
     * A configured value carrying a separator is taken at its word. A bare
     * name is searched across PATH directly rather than by shelling out to
     * `which`, which is itself absent on some stripped-down managed hosts —
     * and a missing `which` would otherwise read as a missing mysqldump.
     */
    public static function locateBinary(string $configured, ?string $path = null): ?string
    {
        if (trim($configured) === '') {
            return null;
        }

        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return is_executable($configured) ? $configured : null;
        }

        $path ??= (string) getenv('PATH');

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if (trim($dir) === '') {
                continue;
            }

            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $configured;

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Load the dump into a scratch database and count what came back.
     *
     * The scratch name carries the timestamp so two runs cannot collide, and it
     * is dropped whether or not the load succeeded — a verification that leaves
     * databases behind would eventually fill the server.
     *
     * @return bool|null true verified, false the dump is bad, null could not check
     */
    protected function verify(string $credentials, array $db, string $target, bool $gzip): ?bool
    {
        // No client binary means there is nothing to restore *with*, which is
        // the same situation as being unable to create the scratch database:
        // the dump may be perfect and cannot be proven so. Saying that here
        // keeps it out of the error path, where it would fail a deploy over a
        // backup that was written correctly.
        if (self::locateBinary((string) config('backup.mysql')) === null) {
            $this->warn('Cannot verify on this host: there is no mysql client to restore with.');
            $this->warn('The dump was written but has NOT been restored to prove it reads back.');
            $this->line('  Verify it yourself: load the dump into a scratch database with phpMyAdmin.');

            return null;
        }

        $scratch = substr('vrfy_' . $db['database'] . '_' . now()->format('His'), 0, 60);
        $expected = count(DB::select('SHOW TABLES'));

        $this->line("Verifying by restoring into {$scratch} …");

        try {
            DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            // Managed hosting hands out a user with rights to one database and
            // nothing else, so there is nowhere to restore a test copy to. That
            // says nothing about the dump — treating it as a bad backup meant
            // every deploy on such a host aborted at the backup step, which is
            // how people end up deploying with no backup at all.
            if ($this->looksLikeAPrivilegeProblem($e)) {
                $this->warn('Cannot verify on this host: the database user may not create databases.');
                $this->warn('The dump was written but has NOT been restored to prove it reads back.');
                $this->line('  Verify it yourself on a machine that can: gunzip -c <dump> | mysql <scratch-db>');

                return null;
            }

            $this->error('Could not create the scratch database: ' . $e->getMessage());
            $this->warn('The dump was written but has NOT been verified.');

            return false;
        }

        try {
            $mysql = new Process(
                [config('backup.mysql'), "--defaults-extra-file={$credentials}", $scratch],
                timeout: (int) config('backup.timeout'),
            );
            $mysql->setInput($gzip ? gzopen($target, 'rb') : fopen($target, 'rb'));
            $mysql->run();

            if (! $mysql->isSuccessful()) {
                $this->error('The dump did not restore: ' . trim($mysql->getErrorOutput()));

                return false;
            }

            $restored = count(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = ?", [$scratch]));

            if ($restored < $expected) {
                $this->error("Restored {$restored} tables but the live database has {$expected}. The dump is incomplete.");

                return false;
            }

            $this->info("Verified — {$restored} tables restored cleanly.");

            return true;
        } finally {
            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
        }
    }

    /**
     * Is this "you are not allowed", rather than "that did not work"?
     *
     * MySQL reports a denied CREATE DATABASE as error 1044 and a denied
     * connection as 1045; both arrive wrapped in a PDOException whose message
     * carries the text. Matched on the code where there is one, and on the
     * wording as a fallback, because the driver does not always surface it.
     */
    protected function looksLikeAPrivilegeProblem(\Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (['1044', '1045', '1142'] as $code) {
            if (str_contains($message, $code)) {
                return true;
            }
        }

        return str_contains($message, 'Access denied')
            || str_contains($message, 'command denied');
    }

    protected function rotate(string $dir, string $database, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $dumps = glob($dir . DIRECTORY_SEPARATOR . $database . '_*.sql*') ?: [];

        // Newest first by name — the timestamp format sorts correctly as text,
        // so this does not depend on filesystem mtimes that a copy would reset.
        rsort($dumps, SORT_STRING);

        foreach (array_slice($dumps, $keep) as $old) {
            if (@unlink($old)) {
                $this->line('Removed ' . basename($old));
            }
        }

        $remaining = count($dumps) > $keep ? $keep : count($dumps);
        $this->line("Keeping {$remaining} dump(s).");
    }

    protected function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' TB';
    }
}
