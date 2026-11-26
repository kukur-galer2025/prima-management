# Prima Management

Aplikasi manajemen diri berbasis web (PWA, bisa dipasang di HP): target, sesi fokus, jadwal, tugas, kebiasaan, keuangan, dan catatan di satu tempat.

Dibangun dengan Laravel 13, Livewire 4, Tailwind CSS 4, dan SQLite.

## Fitur

| Modul | URL | Isi |
|---|---|---|
| Beranda | `/` | Ringkasan hari ini, target mingguan, panduan pengguna baru, ajakan review mingguan, kebiasaan, deadline (termasuk yang terlewat), tugas terdekat |
| Target | `/target` | Target dengan langkah/materi, progres, **kecepatan menuju target** (butuh berapa menit/hari), tren skor, catatan terkait. Target yang dihapus masuk **Terhapus** dan bisa dipulihkan |
| Fokus | `/fokus` | Stopwatch atau **Pomodoro** (25/5, 50/10, 15/3) dengan bunyi & notifikasi. Bisa dipakai offline; sesi dikirim otomatis begitu online |
| Tugas | `/tugas` | To-do dengan prioritas, **jam tenggat** (+ pengingat), tugas berulang, **subtugas (checklist)**, tampilan **daftar** atau **papan kanban** (Belum / Dikerjakan / Selesai, drag-and-drop), pencarian, dan **input cepat berbahasa Indonesia** (mis. `Bayar UKT besok jam 10 !penting #toefl tiap minggu`, juga ada di Beranda) |
| Jadwal | `/jadwal` | Pola fokus mingguan, pencegahan jadwal bentrok, persentase jadwal yang benar-benar dijalankan |
| Kalender | `/kalender` | Tampilan bulanan: deadline, tugas, jadwal, sesi fokus, kebiasaan, dan keuangan per tanggal |
| Kebiasaan | `/kebiasaan` | Habit tracker, streak, konsistensi 30 hari, riwayat setahun (heatmap) |
| Jurnal | `/jurnal` | Mood harian (1–5) + catatan singkat, kalender mood, dan **pola mood**: dibandingkan dengan menit fokus, kebiasaan, dan hari dalam seminggu (juga tampil di Statistik) |
| Keuangan | `/keuangan` | **Dompet** (tunai, bank, e-wallet) dengan saldo masing-masing, **transfer antar-dompet**, pemasukan/pengeluaran per bulan, **transaksi rutin bulanan**, **anggaran per kategori**, anggaran total |
| Tabungan | `/tabungan` | Tujuan menabung (mis. "Laptop baru Rp8 juta"), setor/tarik (opsional dari dompet), progres, dan **berapa yang perlu disisihkan per bulan** agar tepat waktu |
| Utang & piutang | `/utang` | Pinjaman dan uang yang dipinjamkan, **cicilan**, status lunas otomatis, jatuh tempo (masuk ringkasan pagi) |
| Catatan | `/catatan` | Catatan bebas, bisa dikaitkan ke target, disematkan, dicari, dan diberi **lampiran file** (PDF, gambar, dokumen; privat per akun, ada kuota) |
| Statistik | `/statistik` | Tren fokus, per target, **heatmap setahun** (fokus & kebiasaan), keuangan 6 bulan |
| Grup | `/grup` | Grup akuntabilitas lewat kode undangan: papan fokus mingguan (menit, hari aktif, streak), kirim semangat 👏 ke anggota (lewat notifikasi/Telegram, sekali sehari), dan berbagi progres target bila diizinkan masing-masing anggota |
| Review mingguan | `/review` | Ringkasan otomatis per minggu + refleksi (penilaian, yang berjalan baik, yang perlu diperbaiki, fokus minggu depan) |
| Akun | `/akun` | Profil, kata sandi, tema terang/gelap, target & anggaran, notifikasi push, **pengingat Telegram**, **sinkron Google Calendar (iCal)**, ekspor & **impor/pulihkan data** |
| Admin | `/admin` | Khusus admin: tambah pengguna (kata sandi awal atau email undangan), edit, jadikan admin, reset kata sandi, nonaktifkan/aktifkan, hapus, pemakaian lampiran, jumlah pengguna aktif |

Login juga punya **lupa kata sandi** lewat email.

## Pengguna & admin

- **Tidak ada pendaftaran mandiri.** Akun dibuat admin di `/admin`, atau lewat `php artisan app:create-user` (tambahkan `--admin` untuk membuat admin).
- Pengguna pertama di database otomatis menjadi admin saat migrasi.
- Akun baru bisa diberi **kata sandi awal**, atau dikirimi **email undangan** untuk membuat kata sandi sendiri (butuh SMTP).
- Akun yang **dinonaktifkan** langsung keluar dari semua perangkat dan tidak bisa masuk lagi sampai diaktifkan.
- Setiap tindakan admin (buat, undang, ubah, jadikan/cabut admin, ganti sandi, nonaktifkan, hapus) tercatat di **Aktivitas admin**, tetap terbaca walau akunnya sudah dihapus.
- Setiap data (target, tugas, keuangan, catatan, lampiran, dll.) hanya bisa dilihat pemiliknya.
- Pengguna bisa **menghapus akunnya** sendiri (Akun → Hapus akun), kecuali admin terakhir.
- Keamanan login: batas percobaan per email+IP (5) dan per IP (20 per 10 menit), batas permintaan lupa kata sandi per IP, serta header keamanan (anti-clickjacking, nosniff, HSTS di https).

## Menjalankan secara lokal

Butuh PHP 8.4+, Composer, dan Node.js.

```bash
composer run setup              # install, .env, key, migrate, build aset
php artisan app:create-user --admin   # buat akun admin (tambahkan --with-goals untuk contoh target)
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

## Pengingat Telegram

Pengingat yang sama juga bisa dikirim ke Telegram (lebih andal di iPhone).

1. Buat bot lewat [@BotFather](https://t.me/BotFather), lalu isi `.env`:
   ```
   TELEGRAM_BOT_TOKEN=123456:ABC...
   TELEGRAM_BOT_USERNAME=NamaBotKamu_bot
   TELEGRAM_WEBHOOK_SECRET=string-acak-panjang
   ```
2. Di server dengan https: `php artisan app:telegram-webhook`. Di lokal (tanpa https) scheduler otomatis memakai polling (`app:telegram-poll`), atau jalankan `php artisan app:telegram-poll --loop`.
3. Pengguna membuka **Akun → Pengingat lewat Telegram → Sambungkan**, lalu menekan **Start** di Telegram. Ketik `/stop` di bot untuk berhenti.

Setelah tersambung, pengguna bisa mencatat langsung dari chat:

| Perintah | Contoh |
|---|---|
| `/tugas` | `/tugas Bayar UKT besok jam 10 !penting #toefl` |
| `/keluar` | `/keluar 25rb makan siang @gopay` (nominal: `25000`, `25.000`, `25rb`, `25k`, `1,5jt`) |
| `/masuk` | `/masuk 1,5jt gaji` |
| `/mood` | `/mood 4 capek tapi produktif` |
| `/hari` | ringkasan hari ini |
| `/bantuan` | daftar perintah |

Kata pertama setelah nominal menjadi kategori, sisanya keterangan; `@namadompet` memilih dompet (bawaan: dompet pertama).

## Pengingat yang dikirim

Lewat notifikasi push dan/atau Telegram:

- 10 menit sebelum slot jadwal dan tugas berjam
- Ringkasan pagi (07.00–10.00): tugas, kebiasaan, deadline, utang/piutang jatuh tempo
- **Kebiasaan** pada jam yang diatur di masing-masing kebiasaan (kalau belum dicentang)
- **Anggaran** saat pemakaian mencapai 80% dan 100% (sekali per ambang per bulan, tidak dikirim malam hari)
- Review mingguan (Minggu malam) dan ajakan jurnal (20.30, bagi yang memakai jurnal)

## Email (undangan & lupa kata sandi)

Secara bawaan `MAIL_MAILER=log`, jadi email undangan dan reset kata sandi hanya ditulis ke `storage/logs/laravel.log` (berguna saat pengembangan). Untuk benar-benar mengirim email, isi pengaturan SMTP di `.env`, misalnya:

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

## Deploy

`deploy.sh` (Linux dengan SSH) menyiapkan rilis baru di folder terpisah, mengujinya, baru mengaktifkannya dengan satu kali ganti symlink. Langkah yang gagal tidak menyentuh situs yang sedang berjalan, dan rilis yang ternyata rusak setelah aktif otomatis dikembalikan. Sebelum migrasi, database selalu dicadangkan.

Kebutuhan server: PHP 8.4.1+ (`ctype curl dom fileinfo mbstring openssl pdo_mysql` atau `pdo_sqlite`, `tokenizer xml zlib`), Composer, Node.js 22+, git, curl, dan `mysqldump` bila memakai MySQL/MariaDB.

### Contoh: CloudPanel + MySQL

Ganti `USER` dengan Site User dan `DOMAIN` dengan domainmu.

1. **CloudPanel → Sites → Add Site → PHP Site**: Application *Laravel*, PHP *8.4*, domain lengkap (mis. `primamanagement.amania.id`), sandi user hasil *Generate*.
2. **Site → Databases → Add Database.** Pakai sandi yang hanya berisi huruf & angka (tanda kutip, `#`, spasi, atau `\` mudah salah tulis di `.env`).
3. Sebagai `USER` lewat SSH, siapkan `.env` bersama:
   ```bash
   export DEPLOY_PATH=/home/USER/htdocs/DOMAIN PHP_BIN=/usr/bin/php8.4
   curl -fsSLO https://raw.githubusercontent.com/kukur-galer2025/prima-management/main/deploy.sh
   mkdir -p $DEPLOY_PATH/shared
   curl -fsSL https://raw.githubusercontent.com/kukur-galer2025/prima-management/main/.env.example -o $DEPLOY_PATH/shared/.env
   chmod 600 $DEPLOY_PATH/shared/.env && nano $DEPLOY_PATH/shared/.env
   ```
   Ubah minimal:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:...            # buat sekali: echo "base64:$(openssl rand -base64 32)"; jangan diganti setelah dipakai
   APP_URL=https://DOMAIN
   LOG_STACK=daily
   LOG_LEVEL=warning
   SESSION_SECURE_COOKIE=true
   TRUSTED_PROXIES=127.0.0.1     # CloudPanel meneruskan permintaan lewat proxy lokal
   VIEW_CHECK_CACHE_TIMESTAMPS=false
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=nama_database
   DB_USERNAME=user_database
   DB_PASSWORD=sandi_database
   BACKUP_PATH=/home/USER/htdocs/DOMAIN/shared/backups
   ```
4. Deploy pertama. Admin belum ada, jadi pemeriksaan admin dilewati **sekali**. Lalu buat admin dan kunci VAPID:
   ```bash
   ALLOW_PREFLIGHT_ERRORS=1 bash deploy.sh
   cd $DEPLOY_PATH/current
   $PHP_BIN artisan app:create-user --admin
   $PHP_BIN artisan app:vapid-keys          # salin hasilnya ke shared/.env
   $PHP_BIN artisan config:cache && $PHP_BIN artisan app:preflight
   ```
5. Di CloudPanel:
   - **Settings → Root Directory**: `DOMAIN/current/public`
   - **Vhost**: ubah `$document_root$fastcgi_script_name` menjadi `$realpath_root$fastcgi_script_name` supaya rilis baru langsung terbaca
   - **Cron Jobs** (setiap menit): `cd /home/USER/htdocs/DOMAIN/current && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1`
   - **SSL/TLS**: buat sertifikat Let's Encrypt
6. Update berikutnya:
   ```bash
   DEPLOY_PATH=/home/USER/htdocs/DOMAIN PHP_BIN=/usr/bin/php8.4 APP_PUBLIC_URL=https://DOMAIN bash ~/deploy.sh
   DEPLOY_PATH=/home/USER/htdocs/DOMAIN PHP_BIN=/usr/bin/php8.4 bash ~/deploy.sh rollback    # kembali ke rilis sebelumnya
   ```

### Catatan

- `deploy.sh` mempublikasikan JavaScript Livewire ke `public/vendor/livewire` (Nginx CloudPanel melayani `*.js` langsung dari disk). Kalau deploy manual, jalankan `php artisan vendor:publish --tag=livewire:assets --force`.
- `php artisan app:preflight` memeriksa kesiapan produksi kapan saja. `/up` mengecek database & storage; pasang di pemantau uptime.
- `RELOAD_COMMAND="sudo systemctl reload php8.4-fpm"` (bila user boleh sudo) membuat rilis baru tersaji seketika. Tanpa itu, skrip menunggu sampai PHP memakai rilis baru (bisa sampai ±2 menit).
- Rollback mengembalikan kode, bukan struktur database. Karena itu migrasi sebaiknya hanya **menambah** tabel/kolom; hapus kolom lama di rilis berikutnya. Untuk memulihkan data, pakai file di `BACKUP_PATH`.
- Memakai SQLite: isi `DB_DATABASE` dengan path absolut di luar folder rilis (mis. `$DEPLOY_PATH/shared/database.sqlite`). Jangan menyalin file database saat aplikasi berjalan; pakai `php artisan app:backup-database`.

## Backup

- Otomatis setiap hari pukul 02.00 dan sebelum setiap migrasi saat deploy, terkompresi, 14 terakhir disimpan (30 saat deploy).
  - SQLite: salinan file (`.sqlite.gz`).
  - MySQL/MariaDB: `mysqldump` tanpa mengunci tabel (`.sql.gz`). Pulihkan dengan: `gunzip < file.sql.gz | mysql -u USER -p NAMA_DATABASE`
- Lokasinya diatur `BACKUP_PATH` (bawaan: `storage/app/private/backups`). **Simpan juga salinannya di luar server**, misalnya folder yang disinkron ke cloud, agar backup tidak ikut hilang bila server rusak.
- Manual: `php artisan app:backup-database --keep=14`.
- Setiap pengguna bisa mengunduh seluruh datanya dari halaman **Akun**, dan memulihkannya lagi dari file yang sama.

## Pemantauan error

Isi `ADMIN_EMAIL` dan set `ERROR_ALERTS=true`: setiap error di produksi dikirim ke email itu (error yang sama paling banyak sekali per 30 menit). Butuh SMTP yang sudah diatur.

## CI

Setiap push ke `main` dan setiap pull request menjalankan Pint dan seluruh tes di **SQLite dan MySQL 8.4** lewat GitHub Actions (`.github/workflows/tests.yml`).

## Tes

```bash
php artisan test --compact
```
