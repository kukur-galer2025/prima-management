#!/usr/bin/env bash
# Deploy tanpa downtime untuk server Linux (VPS / hosting dengan SSH).
#
# Rilis baru disiapkan di folder terpisah, diuji, baru kemudian diaktifkan dengan satu kali ganti symlink.
# Bila ada langkah yang gagal, situs tetap memakai rilis lama. Bila rilis baru ternyata tidak sehat setelah
# aktif, skrip otomatis kembali ke rilis sebelumnya.
#
#   DEPLOY_PATH=/var/www/prima APP_PUBLIC_URL=https://domainmu ./deploy.sh
#   DEPLOY_PATH=/var/www/prima ./deploy.sh rollback
#
# Document root web server harus mengarah ke: $DEPLOY_PATH/current/public
set -Eeuo pipefail

DEPLOY_PATH=${DEPLOY_PATH:?Isi DEPLOY_PATH, mis. /var/www/prima}
REPO_URL=${REPO_URL:-https://github.com/kukur-galer2025/prima-management.git}
BRANCH=${BRANCH:-main}
PHP_BIN=${PHP_BIN:-php}
COMPOSER_BIN=${COMPOSER_BIN:-composer}
KEEP_RELEASES=${KEEP_RELEASES:-5}
HEALTH_PORT=${HEALTH_PORT:-8199}
APP_PUBLIC_URL=${APP_PUBLIC_URL:-}
APP_PUBLIC_URL=${APP_PUBLIC_URL%/}
# Perintah opsional setelah ganti rilis, mis. "sudo systemctl reload php8.4-fpm" (membersihkan OPcache).
RELOAD_COMMAND=${RELOAD_COMMAND:-}

RELEASES=$DEPLOY_PATH/releases
SHARED=$DEPLOY_PATH/shared
CURRENT=$DEPLOY_PATH/current
HEALTH_PID=
RELEASE=

say() { printf '\n==> %s\n' "$*"; }
fail() {
    trap - ERR
    printf '\nGAGAL: %s\nSitus tetap memakai rilis sebelumnya.\n' "$*" >&2
    stop_health_server
    # Rilis yang gagal dibuang supaya tidak pernah terpilih saat rollback.
    if [ -n "$RELEASE" ] && [ -d "$RELEASE" ]; then cd "$DEPLOY_PATH" && rm -rf "$RELEASE"; fi
    exit 1
}
stop_health_server() { if [ -n "$HEALTH_PID" ]; then kill "$HEALTH_PID" 2>/dev/null || true; HEALTH_PID=; fi; }
trap stop_health_server EXIT
trap 'fail "langkah di baris $LINENO"' ERR

# Ganti symlink secara atomik: pengunjung selalu melihat rilis lama atau rilis baru, tidak pernah kosong.
activate() {
    # -sfn: sisa current.new dari deploy yang terputus ditimpa, bukan diikuti ke dalam foldernya.
    ln -sfn "$1" "$CURRENT.new"
    mv -T "$CURRENT.new" "$CURRENT"
    if [ -n "$RELOAD_COMMAND" ]; then bash -c "$RELOAD_COMMAND"; fi
}

# Status 200 saja tidak cukup (peringatan PHP pun bisa berstatus 200), jadi isi halamannya ikut diperiksa,
# termasuk file JavaScript Livewire: tanpanya halaman tampil tapi tidak ada tombol yang berfungsi.
healthy() {
    local base=$1 page bad=0 good=0 deadline=$((SECONDS + ${2:-30}))
    while [ $SECONDS -lt $deadline ]; do
        if curl -fsS -o /dev/null --max-time 15 "$base/up" 2>/dev/null \
            && page=$(curl -fsS -i --max-time 15 "$base/login" 2>/dev/null) \
            && [[ $page == *wire:snapshot* && $page == *'id="password"'* && $page == *'</html>'* ]] \
            && script=$(grep -o 'src="[^"]*livewire[^"]*\.js[^"]*"' <<<"$page" | head -n 1 | sed 's/^src="//; s/"$//; s/&amp;/\&/g') \
            && [ -n "$script" ] && case "$script" in http*) ;; *) script="$base$script" ;; esac \
            && curl -fsS -o /dev/null --max-time 15 "$script" 2>/dev/null; then
            # PHP bisa masih menyajikan rilis lama beberapa saat (realpath cache), jadi tunggu sampai rilis BARU
            # yang menjawab beberapa kali berturut-turut.
            if [[ ${page,,} == *"x-release: $(basename "$RELEASE")"* ]]; then good=$((good + 1)); else good=0; fi
            if [ $good -ge 8 ]; then return 0; fi
        else
            good=0
            bad=$((bad + 1))
            # Kumulatif: satu dari beberapa proses PHP yang rusak pun harus terdeteksi.
            if [ $bad -ge 5 ]; then return 1; fi
        fi
        sleep 0.5
    done
    return 1
}

command -v "$PHP_BIN" >/dev/null || fail "PHP tidak ditemukan ($PHP_BIN)"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);'     || fail "butuh PHP 8.4+, yang terpakai $("$PHP_BIN" -r 'echo PHP_VERSION;'). Arahkan PHP_BIN ke PHP 8.4, mis. PHP_BIN=/usr/bin/php8.4"
[ -f "$SHARED/.env" ] || fail "buat dulu $SHARED/.env (contoh di README bagian Deploy)"
DB_EXTENSION=$(sed -n 's/^DB_CONNECTION=//p' "$SHARED/.env" | tr -d '"\r' | tail -n 1)
case "$DB_EXTENSION" in mysql|mariadb) DB_EXTENSION=mysql ;; pgsql) ;; *) DB_EXTENSION=sqlite ;; esac
MISSING=$("$PHP_BIN" -r 'echo implode(" ", array_filter(["ctype", "curl", "dom", "fileinfo", "filter", "mbstring", "openssl", "pdo_'"$DB_EXTENSION"'", "session", "tokenizer", "xml", "zlib"], fn ($e) => ! extension_loaded($e)));')
[ -z "$MISSING" ] || fail "ekstensi PHP belum aktif: $MISSING"
for tool in git npm curl; do command -v "$tool" >/dev/null || fail "$tool belum terpasang"; done
if [ -f "$COMPOSER_BIN" ]; then COMPOSER_FILE=$COMPOSER_BIN; else COMPOSER_FILE=$(command -v "$COMPOSER_BIN" || true); fi
[ -n "$COMPOSER_FILE" ] || fail "Composer tidak ditemukan ($COMPOSER_BIN)"

mkdir -p "$RELEASES" "$SHARED"
exec 9>"$DEPLOY_PATH/.deploy.lock"
if command -v flock >/dev/null; then flock -n 9 || fail "deploy lain sedang berjalan"; fi

PREVIOUS=$(readlink -f "$CURRENT" 2>/dev/null || true)
[ -d "$PREVIOUS" ] || PREVIOUS=

if [ "${1:-}" = rollback ]; then
    TARGET=$(ls -1d "$RELEASES"/*/ | sed 's:/$::' | while read -r dir; do [ -e "$dir/FAILED" ] || echo "$dir"; done | sort | { [ -n "$PREVIOUS" ] && awk -v p="$PREVIOUS" '$0 < p' || cat; } | tail -n 1)
    [ -n "$TARGET" ] || fail "tidak ada rilis sebelumnya"
    say "Kembali ke $TARGET"
    activate "$TARGET"
    exit 0
fi

[ -f "$SHARED/.env" ] || fail "buat dulu $SHARED/.env (contoh di README bagian Deploy)"

RELEASE=$RELEASES/$(date +%Y%m%d%H%M%S)

say "Mengambil kode ($BRANCH)"
git clone --quiet --depth 1 --branch "$BRANCH" "$REPO_URL" "$RELEASE"

say "Menyambungkan data bersama (.env, storage)"
mkdir -p "$SHARED"/storage/{app/private,app/public,framework/cache/data,framework/sessions,logs}
# framework/views SENGAJA tidak dibagi: nama file view hasil kompilasi sama di semua rilis,
# jadi rilis lama dan baru akan saling menimpa bila foldernya dipakai bersama.
for folder in app framework/cache framework/sessions logs; do
    rm -rf "$RELEASE/storage/$folder"
    ln -s "$SHARED/storage/$folder" "$RELEASE/storage/$folder"
done
mkdir -p "$RELEASE/storage/framework/views"
ln -s "$SHARED/.env" "$RELEASE/.env"
basename "$RELEASE" > "$RELEASE/RELEASE"
cd "$RELEASE"

say "Memasang dependensi & membangun aset"
# Composer dijalankan dengan PHP yang sama, bukan PHP bawaan server.
"$PHP_BIN" "$COMPOSER_FILE" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --quiet
npm ci --silent --no-audit --no-fund
npm run build --silent
rm -rf node_modules
# JS Livewire sebagai file statis: banyak konfigurasi Nginx (termasuk CloudPanel) melayani semua *.js langsung
# dari disk, sehingga versi yang disajikan lewat PHP berakhir 404 dan seluruh tombol/form tidak berfungsi.
"$PHP_BIN" artisan vendor:publish --tag=livewire:assets --force --no-ansi >/dev/null

say "Memeriksa konfigurasi database"
config_value() { "$PHP_BIN" artisan tinker --execute="echo config('$1');" | tr -d '\r' | tail -n 1; }
DB_DRIVER=$(config_value database.default)
DB_FILE=$(config_value database.connections.sqlite.database)
if [ "$DB_DRIVER" = sqlite ]; then
    # Database di dalam folder rilis akan "hilang" begitu rilis berganti.
    case "$DB_FILE" in
        "$RELEASES"/*|"$CURRENT"/*) fail "isi DB_DATABASE di $SHARED/.env dengan path absolut, mis. $SHARED/database.sqlite" ;;
    esac
    [ -f "$DB_FILE" ] || { [ -z "$PREVIOUS" ] && touch "$DB_FILE"; } || fail "database $DB_FILE tidak ditemukan"
fi

"$PHP_BIN" artisan tinker --execute="try { DB::connection()->getPdo(); echo 'tersambung'; } catch (Throwable \$e) { echo \$e->getMessage(); }" | grep -q tersambung \
    || fail "tidak bisa tersambung ke database; periksa bagian DB_ di $SHARED/.env"

if "$PHP_BIN" artisan tinker --execute="echo Schema::hasTable('users') ? 'berisi' : 'kosong';" | grep -q berisi; then
    say "Mencadangkan database sebelum migrasi"
    "$PHP_BIN" artisan app:backup-database --keep=30
fi

say "Migrasi & cache"
"$PHP_BIN" artisan migrate --force --no-interaction --no-ansi
"$PHP_BIN" artisan config:cache --quiet
"$PHP_BIN" artisan route:cache --quiet
"$PHP_BIN" artisan event:cache --quiet
"$PHP_BIN" artisan view:cache --quiet
"$PHP_BIN" artisan app:warm-up --no-ansi

say "Pemeriksaan kesiapan produksi"
if ! "$PHP_BIN" artisan app:preflight; then
    [ -n "${ALLOW_PREFLIGHT_ERRORS:-}" ] || fail "perbaiki masalah di atas (deploy pertama tanpa admin: ALLOW_PREFLIGHT_ERRORS=1, lalu buat admin)"
fi

say "Menguji rilis baru sebelum diaktifkan"
if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$HEALTH_PORT/"; then fail "port $HEALTH_PORT sedang dipakai, atur HEALTH_PORT"; fi
# Langsung php -S (bukan artisan serve) supaya tidak ada proses anak yang tertinggal setelah dihentikan.
# Harus dari folder public: server.php mencari index.php di folder kerja.
cd "$RELEASE/public"
"$PHP_BIN" -d display_errors=0 -S "127.0.0.1:$HEALTH_PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1 &
HEALTH_PID=$!
cd "$RELEASE"
healthy "http://127.0.0.1:$HEALTH_PORT" || fail "rilis baru tidak lulus pengujian (/up atau halaman login rusak); lihat $SHARED/storage/logs"
stop_health_server

say "Mengaktifkan rilis baru"
activate "$RELEASE"

[ -z "$APP_PUBLIC_URL" ] || say "Memastikan rilis baru tersaji di $APP_PUBLIC_URL"
if [ -n "$APP_PUBLIC_URL" ] && ! healthy "$APP_PUBLIC_URL" 180; then
    if [ -n "$PREVIOUS" ]; then
        activate "$PREVIOUS"
        # Jangan dihapus: proses PHP bisa masih memakai folder ini beberapa menit (realpath cache).
        # Cukup ditandai supaya tidak terpilih saat rollback; dibersihkan oleh deploy berikutnya.
        touch "$RELEASE/FAILED"
        RELEASE=
        fail "rilis baru tidak sehat di $APP_PUBLIC_URL, sudah dikembalikan ke rilis sebelumnya"
    fi
    # Deploy pertama: rilis dipertahankan karena penyebabnya biasanya konfigurasi web server.
    RELEASE=
    fail "situs belum bisa dibuka di $APP_PUBLIC_URL (periksa konfigurasi web server)"
fi

"$PHP_BIN" artisan queue:restart --quiet || true

say "Membersihkan rilis lama (simpan $KEEP_RELEASES rilis sehat)"
# Rilis gagal dibuang lebih dulu, supaya yang disimpan benar-benar bisa dipakai untuk rollback.
# Yang berumur < 10 menit dibiarkan karena mungkin masih dipakai proses PHP.
{
    for dir in "$RELEASES"/*/; do dir=${dir%/}; [ -e "$dir/FAILED" ] && echo "$dir"; done
    for dir in "$RELEASES"/*/; do dir=${dir%/}; [ -e "$dir/FAILED" ] || echo "$dir"; done | sort | head -n -"$KEEP_RELEASES"
} | while read -r old; do
    if [ -n "$(find "$old" -maxdepth 0 -mmin -10)" ]; then continue; fi
    case "$old" in "$RELEASE"|"$PREVIOUS") ;; *) rm -rf "$old" ;; esac
done

say "Selesai: $RELEASE"
