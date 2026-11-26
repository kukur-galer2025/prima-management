<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Cadangan database terkompresi di disk "backups": SQLite lewat VACUUM INTO, MySQL/MariaDB lewat mysqldump.
 */
class DatabaseBackup
{
    /**
     * @return string nama file cadangan di disk "backups"
     */
    public function create(int $keep): string
    {
        $driver = DB::connection()->getDriverName();
        $extension = match ($driver) {
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'sql',
            default => throw new RuntimeException("Cadangan otomatis belum mendukung {$driver}."),
        };

        $temporary = storage_path('app/private/backup-'.Str::random(8).'.'.$extension);

        try {
            $extension === 'sqlite' ? DB::statement('VACUUM INTO ?', [$temporary]) : $this->dumpMysql($temporary);

            $name = 'prima-management-'.now()->format('Ymd-His').".{$extension}.gz";
            $this->store($temporary, $name);
        } finally {
            @unlink($temporary);
            @unlink($temporary.'.gz');
        }

        $this->prune($keep);

        return $name;
    }

    /** Program dump yang tersedia (MariaDB baru hanya menyediakan mariadb-dump), atau null. */
    public function dumpBinary(): ?string
    {
        if ($configured = config('database.dump_binary')) {
            return is_file($configured) ? $configured : null;
        }

        $finder = new ExecutableFinder;

        return $finder->find('mysqldump') ?? $finder->find('mariadb-dump');
    }

    private function dumpMysql(string $target): void
    {
        $binary = $this->dumpBinary() ?? throw new RuntimeException('mysqldump tidak ditemukan. Pasang mysql-client atau isi DB_DUMP_BINARY.');
        $config = DB::connection()->getConfig();

        // Sandi lewat file sementara, bukan argumen, supaya tidak terlihat di daftar proses server.
        $credentials = tempnam(sys_get_temp_dir(), 'prima-dump');
        chmod($credentials, 0600);
        $quote = fn (?string $value) => '"'.addcslashes((string) $value, '"\\').'"';

        file_put_contents($credentials, implode("\n", array_filter([
            '[client]',
            'user='.$quote($config['username'] ?? null),
            'password='.$quote($config['password'] ?? null),
            filled($config['unix_socket'] ?? null) ? 'socket='.$quote($config['unix_socket']) : 'host='.$quote($config['host'] ?? null),
            filled($config['unix_socket'] ?? null) ? null : 'port='.(int) ($config['port'] ?? 3306),
        ]))."\n");

        try {
            $process = new Process([
                $binary, '--defaults-extra-file='.$credentials,
                // Satu potret data yang konsisten tanpa mengunci tabel, jadi situs tetap bisa dipakai.
                '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--default-character-set=utf8mb4',
                '--result-file='.$target, $config['database'],
            ], timeout: 900);
            $process->run();
        } finally {
            @unlink($credentials);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump gagal: '.Str::limit(trim($process->getErrorOutput()), 300));
        }

        // Dump yang terputus di tengah tidak punya baris penutup ini.
        $handle = fopen($target, 'rb');
        fseek($handle, -min(200, filesize($target)), SEEK_END);
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        if (! str_contains($tail, 'Dump completed')) {
            throw new RuntimeException('Hasil mysqldump tidak lengkap.');
        }
    }

    /** Kompres per potongan supaya database besar tidak menghabiskan memori. */
    private function store(string $source, string $name): void
    {
        $input = fopen($source, 'rb');
        $output = gzopen($source.'.gz', 'wb9');

        while (! feof($input)) {
            gzwrite($output, (string) fread($input, 1048576));
        }

        fclose($input);
        gzclose($output);

        $compressed = fopen($source.'.gz', 'rb');
        Storage::disk('backups')->writeStream($name, $compressed);

        if (is_resource($compressed)) {
            fclose($compressed);
        }
    }

    private function prune(int $keep): void
    {
        $disk = Storage::disk('backups');

        collect($disk->files())
            ->filter(fn (string $file) => Str::startsWith($file, 'prima-management-') && Str::endsWith($file, ['.sqlite', '.sqlite.gz', '.sql.gz']))
            ->sortDesc()
            ->slice(max(1, $keep))
            ->each(fn (string $file) => $disk->delete($file));
    }
}
