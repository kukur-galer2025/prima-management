<?php

namespace Tests\Feature;

use App\Services\DatabaseBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Sengaja tanpa RefreshDatabase: VACUUM INTO & mysqldump harus berjalan di luar transaksi.
 */
class BackupCommandTest extends TestCase
{
    public function test_backup_creates_compressed_copies_and_keeps_only_the_latest(): void
    {
        $extension = $this->isMysql() ? 'sql' : 'sqlite';

        foreach (['2026-11-23 02:00', '2026-11-24 02:00', '2026-11-25 02:00'] as $moment) {
            $this->travelTo(Carbon::parse($moment));
            $this->artisan('app:backup-database', ['--keep' => 2])->assertSuccessful();
        }

        $files = collect(Storage::disk('backups')->files())->sort()->values()->all();

        $this->assertSame([
            "prima-management-20261124-020000.{$extension}.gz",
            "prima-management-20261125-020000.{$extension}.gz",
        ], $files);

        $content = gzdecode(Storage::disk('backups')->get($files[1]));
        $this->isMysql()
            ? $this->assertStringContainsString('Dump completed', $content)
            : $this->assertSame('SQLite format 3', substr($content, 0, 15));

        $this->assertSame([], glob(storage_path('app/private/backup-*')), 'File sementara harus dihapus.');
    }

    public function test_mysql_backup_can_be_restored_with_emoji_and_quotes_intact(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('Khusus MySQL/MariaDB.');
        }

        Schema::dropIfExists('uji_backup');
        Schema::create('uji_backup', fn ($table) => $table->string('isi'));
        DB::table('uji_backup')->insert(['isi' => "Dana 🛟 'darurat' \"1,5jt\" \\ é"]);

        try {
            $this->artisan('app:backup-database')->assertSuccessful();
            Schema::drop('uji_backup');

            $config = DB::connection()->getConfig();
            $client = preg_replace('/(mysql|mariadb-)dump/', str_contains(app(DatabaseBackup::class)->dumpBinary(), 'mariadb') ? 'mariadb' : 'mysql', app(DatabaseBackup::class)->dumpBinary());
            $restore = new Process([$client, '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--default-character-set=utf8mb4', $config['database']], env: ['MYSQL_PWD' => $config['password']], timeout: 120);
            $restore->setInput(gzdecode(Storage::disk('backups')->get(Storage::disk('backups')->files()[0])));
            $restore->mustRun();

            $this->assertSame("Dana 🛟 'darurat' \"1,5jt\" \\ é", DB::table('uji_backup')->value('isi'));
        } finally {
            Schema::dropIfExists('uji_backup');
        }
    }

    public function test_failed_backup_reports_an_error_and_leaves_no_files(): void
    {
        if (! $this->isMysql()) {
            $this->markTestSkipped('Khusus MySQL/MariaDB.');
        }

        config(['database.dump_binary' => '/tidak/ada/mysqldump']);

        $this->artisan('app:backup-database')->assertFailed();
        $this->assertSame([], Storage::disk('backups')->files());
    }

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
