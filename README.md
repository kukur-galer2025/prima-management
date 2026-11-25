# Prima Management

Aplikasi manajemen diri berbasis web (PWA, bisa dipasang di HP): target, sesi fokus, jadwal, tugas, kebiasaan, keuangan, dan catatan di satu tempat.

Dibangun dengan Laravel 13, Livewire 4, Tailwind CSS 4, dan SQLite.

## Fitur

| Modul | URL | Isi |
|---|---|---|
| Beranda | `/` | Ringkasan hari ini, target mingguan, panduan pengguna baru, ajakan review mingguan, kebiasaan, deadline (termasuk yang terlewat), tugas terdekat |
| Target | `/target` | Target dengan langkah/materi, progres, **kecepatan menuju target** (butuh berapa menit/hari), tren skor, catatan terkait. Target yang dihapus masuk **Terhapus** dan bisa dipulihkan |
| Fokus | `/fokus` | Stopwatch atau **Pomodoro** (25/5, 50/10, 15/3) dengan bunyi & notifikasi. Bisa dipakai offline; sesi dikirim otomatis begitu online |
| Tugas | `/tugas` | To-do dengan prioritas, **jam tenggat** (+ pengingat), tugas berulang, pencarian, dan **input cepat berbahasa Indonesia** (mis. `Bayar UKT besok jam 10 !penting #toefl tiap minggu`, juga ada di Beranda) |
| Jadwal | `/jadwal` | Pola fokus mingguan, pencegahan jadwal bentrok, persentase jadwal yang benar-benar dijalankan |
| Kalender | `/kalender` | Tampilan bulanan: deadline, tugas, jadwal, sesi fokus, kebiasaan, dan keuangan per tanggal |
| Kebiasaan | `/kebiasaan` | Habit tracker, streak, konsistensi 30 hari, riwayat setahun (heatmap) |
| Keuangan | `/keuangan` | Pemasukan/pengeluaran per bulan, **transaksi rutin bulanan**, **anggaran per kategori**, anggaran total |
| Catatan | `/catatan` | Catatan bebas, bisa dikaitkan ke target, disematkan, dicari, dan diberi **lampiran file** (PDF, gambar, dokumen; privat per akun, ada kuota) |
| Statistik | `/statistik` | Tren fokus, per target, **heatmap setahun** (fokus & kebiasaan), keuangan 6 bulan |
| Review mingguan | `/review` | Ringkasan otomatis per minggu + refleksi (penilaian, yang berjalan baik, yang perlu diperbaiki, fokus minggu depan) |
| Akun | `/akun` | Profil, kata sandi, tema terang/gelap, target & anggaran, notifikasi push, **sinkron Google Calendar (iCal)**, ekspor & **impor/pulihkan data** |

Login juga punya **lupa kata sandi** lewat email.

## Multi-pengguna

- Siapa pun bisa mendaftar di `/daftar`, lalu wajib **memverifikasi email** sebelum memakai aplikasi. Tutup pendaftaran dengan `REGISTRATION_ENABLED=false`.
- Setiap data (target, tugas, keuangan, catatan, lampiran, dll.) hanya bisa dilihat pemiliknya.
- Pengguna bisa **menghapus akunnya** sendiri beserta seluruh data dan lampirannya (Akun → Hapus akun).
- Akun yang dibuat lewat `php artisan app:create-user` atau seeder langsung terverifikasi.
- Verifikasi email & lupa kata sandi butuh pengaturan SMTP (lihat bagian Email).

## Menjalankan secara lokal

Butuh PHP 8.4+, Composer, dan Node.js.

```bash
composer run setup              # install, .env, key, migrate, build aset
php artisan app:create-user     # buat akun login (tambahkan --with-goals untuk contoh target)
composer run dev                # server, queue, scheduler, dan Vite sekaligus
```

Atau isi database dengan akun demo: `php artisan db:seed` (email `demo@primamanagement.test`, kata sandi `password`).

## Notifikasi push

Pengingat dikirim 10 menit sebelum slot jadwal dimulai, ditambah ringkasan pagi (07.00–10.00).

1. Buat kunci VAPID: `php artisan app:vapid-keys`, lalu salin hasilnya ke `.env`. Isi juga `VAPID_SUBJECT` dengan `mailto:` email pengelola.
2. Pastikan scheduler berjalan. `composer run dev` sudah menyertakannya. Di server, tambahkan cron:
   ```
   * * * * * cd /path/ke/aplikasi && php artisan schedule:run >> /dev/null 2>&1
   ```
3. Buka aplikasi lewat **https** (atau `localhost`), masuk ke **Akun → Notifikasi pengingat → Aktifkan**. Di iPhone, pasang aplikasi ke Layar Utama terlebih dulu.

Push dikirim tanpa payload. Service worker lalu mengambil isi pengingat dari `/pengingat/terbaru`, sehingga tidak perlu library enkripsi tambahan.

## Email (lupa kata sandi)

Secara bawaan `MAIL_MAILER=log`, jadi email reset kata sandi hanya ditulis ke `storage/logs/laravel.log` (berguna saat pengembangan). Untuk benar-benar mengirim email, isi pengaturan SMTP di `.env`, misalnya:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=akun@gmail.com
MAIL_PASSWORD=app-password
MAIL_FROM_ADDRESS=akun@gmail.com
```

## Sinkron kalender

Di **Akun → Sinkron ke Google Calendar**, buat tautan iCal rahasia lalu tambahkan di Google Calendar (Setelan → Tambahkan kalender → Dari URL). Isinya: deadline target, tugas bertenggat, dan jadwal fokus mingguan. Google memperbarui kalender langganan secara berkala (bisa beberapa jam).

## Backup

- Otomatis setiap hari pukul 02.00, terkompresi (`.sqlite.gz`), 14 terakhir disimpan. Hanya untuk SQLite.
- Lokasinya diatur `BACKUP_PATH` (bawaan: `storage/app/private/backups`). **Arahkan ke folder yang disinkron ke cloud**, misalnya folder Google Drive/OneDrive di komputer server, agar backup tidak ikut hilang bila server rusak.
- Manual: `php artisan app:backup-database --keep=14`.
- Setiap pengguna bisa mengunduh seluruh datanya dari halaman **Akun**, dan memulihkannya lagi dari file yang sama.

## Pemantauan error

Isi `ADMIN_EMAIL` dan set `ERROR_ALERTS=true`: setiap error di produksi dikirim ke email itu (error yang sama paling banyak sekali per 30 menit). Butuh SMTP yang sudah diatur.

## CI

Setiap push ke `main` dan setiap pull request menjalankan Pint dan seluruh tes lewat GitHub Actions (`.github/workflows/tests.yml`).

## Tes

```bash
php artisan test --compact
```
