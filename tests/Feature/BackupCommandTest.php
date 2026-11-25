<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sengaja tanpa RefreshDatabase: VACUUM INTO tidak boleh dijalankan di dalam transaksi.
 */
class BackupCommandTest extends TestCase
{
    public function test_backup_creates_sqlite_copies_and_keeps_only_the_latest(): void
    {
        Storage::fake('local');

        foreach (['2026-11-23 02:00', '2026-11-24 02:00', '2026-11-25 02:00'] as $moment) {
            $this->travelTo(Carbon::parse($moment));
            $this->artisan('app:backup-database', ['--keep' => 2])->assertSuccessful();
        }

        $files = collect(Storage::disk('local')->files('backups'))->sort()->values()->all();

        $this->assertSame([
            'backups/prima-management-20261124-020000.sqlite',
            'backups/prima-management-20261125-020000.sqlite',
        ], $files);
        $this->assertSame('SQLite format 3', substr(Storage::disk('local')->get($files[1]), 0, 15));
    }
}
