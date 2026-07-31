<?php

namespace Tests\Feature;

use App\Console\Commands\BackupDatabase;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The database is the only copy of what people worked and are owed, so the
 * backup command has to fail loudly rather than quietly produce nothing.
 *
 * The dump itself is exercised against MySQL by hand — the test suite runs on
 * SQLite, and a test that shells out to mysqldump would be testing mysqldump.
 * What is pinned here is everything that decides whether a real run is safe:
 * that it refuses a connection it cannot dump, that rotation keeps what it
 * claims, and that dumps are not written somewhere the web server would serve.
 */
class BackupCommandTest extends TestCase
{
    public function test_it_refuses_a_connection_it_cannot_dump(): void
    {
        // The suite runs on SQLite. Silently "succeeding" here would be the
        // worst outcome: a scheduled job reporting fine while producing nothing.
        $this->assertSame('sqlite', config('database.default'));

        $this->artisan('db:backup')
            ->expectsOutputToContain('only supports MySQL')
            ->assertExitCode(1);
    }

    public function test_dumps_are_not_written_anywhere_the_web_server_would_serve(): void
    {
        $path = str_replace('\\', '/', config('backup.path'));
        $public = str_replace('\\', '/', public_path());

        $this->assertStringStartsNotWith(
            $public,
            $path,
            'a backup under the web root is every employee record on the internet',
        );
    }

    public function test_it_keeps_at_least_one_dump_by_default(): void
    {
        $this->assertGreaterThanOrEqual(1, (int) config('backup.keep'));
    }

    // ================= rotation =================

    public function test_rotation_keeps_the_newest_and_removes_the_rest(): void
    {
        [$dir, $files] = $this->makeDumps(['2026-01-01_000000', '2026-02-01_000000', '2026-03-01_000000']);

        $this->rotate($dir, 'hrms', 2);

        $this->assertFileDoesNotExist($files['2026-01-01_000000'], 'the oldest should go');
        $this->assertFileExists($files['2026-02-01_000000']);
        $this->assertFileExists($files['2026-03-01_000000']);
    }

    public function test_rotation_leaves_everything_alone_when_under_the_limit(): void
    {
        [$dir, $files] = $this->makeDumps(['2026-01-01_000000', '2026-02-01_000000']);

        $this->rotate($dir, 'hrms', 14);

        foreach ($files as $file) {
            $this->assertFileExists($file);
        }
    }

    public function test_rotation_ignores_another_databases_dumps(): void
    {
        [$dir, $files] = $this->makeDumps(['2026-01-01_000000', '2026-02-01_000000', '2026-03-01_000000']);

        $foreign = $dir . DIRECTORY_SEPARATOR . 'otherapp_2020-01-01_000000.sql.gz';
        file_put_contents($foreign, 'x');

        $this->rotate($dir, 'hrms', 1);

        $this->assertFileExists($foreign, 'a dump belonging to another database is not ours to delete');
        $this->assertFileExists($files['2026-03-01_000000']);
    }

    public function test_rotation_does_nothing_when_told_to_keep_nothing(): void
    {
        // Guards against a mistyped BACKUP_KEEP=0 wiping every backup on the box.
        [$dir, $files] = $this->makeDumps(['2026-01-01_000000', '2026-02-01_000000']);

        $this->rotate($dir, 'hrms', 0);

        foreach ($files as $file) {
            $this->assertFileExists($file);
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    protected function makeDumps(array $stamps): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hrms-backup-test-' . uniqid();
        mkdir($dir, 0777, true);

        $files = [];
        foreach ($stamps as $stamp) {
            $path = $dir . DIRECTORY_SEPARATOR . "hrms_{$stamp}.sql.gz";
            file_put_contents($path, 'dump');
            $files[$stamp] = $path;
        }

        return [$dir, $files];
    }

    protected function rotate(string $dir, string $database, int $keep): void
    {
        $command = app(BackupDatabase::class);

        // rotate() reports what it removed, so it needs somewhere to write.
        // Outside artisan the command has no output attached.
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        (new ReflectionMethod(BackupDatabase::class, 'rotate'))
            ->invoke($command, $dir, $database, $keep);
    }
}
