<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lainnya')] class extends Component
{
    /**
     * @return array<int, array{route: string, icon: string, label: string, description: string}>
     */
    public function items(): array
    {
        return array_values(array_filter([
            ['route' => 'schedule', 'icon' => 'calendar', 'label' => 'Jadwal', 'description' => 'Pola fokus mingguan & seberapa banyak yang terlaksana'],
            ['route' => 'calendar', 'icon' => 'calendar-grid', 'label' => 'Kalender', 'description' => 'Deadline, tugas, jadwal, dan fokus dalam satu bulan'],
            ['route' => 'review', 'icon' => 'sparkle', 'label' => 'Review mingguan', 'description' => 'Ringkasan otomatis & refleksi tiap minggu'],
            ['route' => 'habits', 'icon' => 'repeat', 'label' => 'Kebiasaan', 'description' => 'Rutinitas harian dengan streak'],
            ['route' => 'journal', 'icon' => 'book', 'label' => 'Jurnal', 'description' => 'Mood & satu kalimat tentang hari ini'],
            ['route' => 'finance', 'icon' => 'wallet', 'label' => 'Keuangan', 'description' => 'Dompet, pemasukan, pengeluaran, dan anggaran'],
            ['route' => 'savings', 'icon' => 'piggy', 'label' => 'Tabungan', 'description' => 'Menabung untuk tujuan tertentu'],
            ['route' => 'debts', 'icon' => 'handshake', 'label' => 'Utang & piutang', 'description' => 'Pinjaman, cicilan, dan jatuh tempo'],
            ['route' => 'notes', 'icon' => 'note', 'label' => 'Catatan', 'description' => 'Ringkasan materi, ide, dan link'],
            ['route' => 'groups', 'icon' => 'users', 'label' => 'Grup', 'description' => 'Papan fokus mingguan bersama teman'],
            ['route' => 'stats', 'icon' => 'chart', 'label' => 'Statistik', 'description' => 'Tren fokus, tugas, kebiasaan, dan mood'],
            ['route' => 'account', 'icon' => 'user', 'label' => 'Akun', 'description' => 'Profil, notifikasi, Telegram, dan ekspor data'],
            auth()->user()->is_admin ? ['route' => 'admin', 'icon' => 'shield', 'label' => 'Admin', 'description' => 'Kelola akun pengguna'] : null,
        ]));
    }
};
?>

<div class="space-y-5">
    <x-page-header title="Lainnya" subtitle="Semua fitur Prima Management." icon="grid" />

    <div class="grid gap-2 sm:grid-cols-2">
        @foreach ($this->items() as $item)
            <a href="{{ route($item['route']) }}" wire:navigate wire:key="menu-{{ $item['route'] }}" class="card card-hover flex items-center gap-3 py-3">
                <span class="icon-tile">
                    <x-icon :name="$item['icon']" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-bold">{{ $item['label'] }}</span>
                    <span class="block truncate text-xs text-muted">{{ $item['description'] }}</span>
                </span>
                <x-icon name="chevron-right" class="size-4 text-muted" />
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('logout') }}" onsubmit="window.primaOffline?.clearPages()">
        @csrf
        <button class="btn btn-ghost w-full"><x-icon name="logout" class="size-4" /> Keluar</button>
    </form>
</div>
