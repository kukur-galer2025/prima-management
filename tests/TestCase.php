<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tes "hapus akun" menghapus folder lampiran; tanpa disk palsu, yang terhapus adalah storage asli.
        Storage::fake('local');
        Storage::fake('backups');
    }
}
