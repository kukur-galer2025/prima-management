<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class StarterGoalsSeeder extends Seeder
{
    /**
     * Isi target awal untuk akun baru. Semuanya bisa diubah dari aplikasi.
     */
    public function run(User $user): void
    {
        if ($user->goals()->exists()) {
            return;
        }

        $goals = [
            ['name' => 'TOEFL', 'icon' => '📘', 'category' => 'Bahasa', 'color' => '#2563eb', 'priority' => 1, 'topics' => ['Listening Comprehension', 'Structure & Written Expression', 'Reading Comprehension', 'Try out simulasi lengkap']],
            ['name' => 'TPA', 'icon' => '🧩', 'category' => 'Tes', 'color' => '#0891b2', 'priority' => 1, 'topics' => ['Verbal (sinonim, antonim, analogi)', 'Numerik (deret, aritmetika)', 'Logika & penalaran', 'Figural / spasial']],
            ['name' => 'SKD', 'icon' => '🏛️', 'category' => 'Tes', 'color' => '#dc2626', 'priority' => 2, 'topics' => ['TWK', 'TIU', 'TKP', 'Try out CAT']],
            ['name' => 'Machine Learning', 'icon' => '🤖', 'category' => 'Skill', 'color' => '#7c3aed', 'priority' => 2, 'topics' => ['Python, NumPy & Pandas', 'Supervised learning', 'Unsupervised learning', 'Evaluasi model', 'Neural network dasar', 'Mini project']],
            ['name' => 'Matematika Dasar', 'icon' => '📐', 'category' => 'Skill', 'color' => '#059669', 'priority' => 2, 'topics' => ['Aljabar linear', 'Kalkulus', 'Probabilitas & statistik']],
            ['name' => 'Proposal Riset S2', 'icon' => '📝', 'category' => 'Riset', 'color' => '#d97706', 'priority' => 1, 'topics' => ['Tentukan topik & calon pembimbing', 'Studi literatur', 'Rumusan masalah & tujuan', 'Metodologi', 'Draft lengkap', 'Revisi akhir']],
            ['name' => 'Surat Rekomendasi', 'icon' => '✉️', 'category' => 'Berkas', 'color' => '#db2777', 'priority' => 2, 'topics' => ['Daftar calon pemberi rekomendasi', 'Hubungi dosen', 'Kirim CV & draft poin rekomendasi', 'Surat diterima']],
        ];

        foreach ($goals as $data) {
            $goal = $user->goals()->create(collect($data)->except('topics')->all());

            foreach ($data['topics'] as $index => $topic) {
                $goal->topics()->create(['name' => $topic, 'sort_order' => $index]);
            }
        }
    }
}
