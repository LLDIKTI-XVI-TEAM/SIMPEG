# SIMPEG — Sistem Informasi Manajemen Kepegawaian

> Aplikasi manajemen kepegawaian LLDIKTI berbasis **Laravel 12**, **PostgreSQL 17**, dan **Tailwind CSS 4**, di-containerize menggunakan **Podman**.

---

## Tech Stack

| Komponen   | Teknologi                |
| ---------- | ------------------------ |
| Framework  | Laravel 12 (PHP 8.4)    |
| Database   | PostgreSQL 17            |
| CSS        | Tailwind CSS 4           |
| Bundler    | Vite 7                   |
| Web Server | Nginx (Alpine)           |
| Container  | Podman + Compose         |

---

## Prasyarat

Pastikan sudah terinstall di sistem anda:

- **[Podman Desktop](https://podman-desktop.io/)** atau **[Podman CLI](https://podman.io/)** (v4.0+)
- **[Git](https://git-scm.com/)**
- **Node.js** (v18+) & **npm** — _hanya jika ingin develop frontend di luar container_

> **Catatan Windows:** Podman membutuhkan Hyper-V dan beberapa perintah `podman machine` perlu dijalankan sebagai **Administrator**.

---

## Instalasi & Setup

### 1. Clone Repository

```bash
git clone <repository-url>
cd SIMPEG
```

### 2. Konfigurasi Environment

Salin file environment lalu sesuaikan jika diperlukan:

```bash
cp .env.example .env
```

Konfigurasi database default (sudah sesuai dengan `compose.yml`):

```env
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=simpeg
DB_USERNAME=simpeg
DB_PASSWORD=secret
```

> **Penting:** `DB_HOST=db` merujuk ke nama service PostgreSQL di `compose.yml`. Jangan diubah ke `localhost` atau `127.0.0.1` saat menggunakan container.

### 3. Build & Jalankan Containers

**Cara cepat** — menggunakan helper script (PowerShell):

```powershell
.\podman-up.ps1
```

Script ini otomatis: build image → start containers → install dependencies → generate key → jalankan migrasi.

**Cara manual:**

```bash
# Build image PHP
podman compose build

# Jalankan semua containers (background)
podman compose up -d

# Install dependencies PHP
podman compose exec app composer install

# Generate application key
podman compose exec app php artisan key:generate

# Jalankan database migration
podman compose exec app php artisan migrate

# Buat symbolic link hanya untuk aset yang memang bersifat publik
# (bukan untuk dokumen pegawai)
podman compose exec app php artisan storage:link
```

### 4. Install Frontend Dependencies (Opsional)

Jika ingin develop frontend dengan hot-reload:

```bash
npm install
npm run dev
```

### 5. Akses Aplikasi

Buka browser dan akses:

```
http://localhost:8000
```

---

## Update Aplikasi (Menarik Perubahan Baru)

Instalasi yang sudah berjalan tidak otomatis menyesuaikan schema database ketika kode baru
ditarik. Migration **tidak** dijalankan pada startup container dan **tidak** dijalankan dari
request HTTP, karena migration paralel dari beberapa container web/worker berisiko saling
berebut (race condition) dan mengubah schema di luar kendali rilis.

Jalankan langkah berikut **berurutan** setiap kali menarik perubahan yang memuat migration baru:

```bash
# 1. Ambil kode terbaru
git pull

# 2. Hentikan worker agar tidak ada job yang berjalan di atas schema lama
podman compose stop queue scheduler

# 3. Perbarui dependency bila composer.lock / package-lock.json berubah
podman compose exec app composer install
# Build asset dijalankan di host karena Node.js tidak tersedia di container app
npm ci && npm run build

# 4. Jalankan migration satu kali dari container app
podman compose exec app php artisan migrate

# 5. Bersihkan cache konfigurasi/route hasil build lama
podman compose exec app php artisan optimize:clear

# 6. Jalankan ulang aplikasi dan worker dengan kode + schema yang sudah sinkron
podman compose up -d
podman compose restart queue scheduler

# 7. Verifikasi tidak ada migration yang tertinggal
podman compose exec app php artisan migrate:status
podman compose exec app php artisan about --only=environment
```

Catatan penting:

- Langkah 4 harus selesai **sebelum** worker versi baru menerima job. Worker yang berjalan di
  atas schema lama akan gagal menyimpan state batch dan job berpotensi berakhir di `failed_jobs`.
- Pada instalasi dengan lebih dari satu container app/worker, jalankan `migrate` dari **satu**
  container saja.
- Untuk lingkungan yang melayani pengguna, aktifkan mode maintenance sebelum langkah 2 dan
  matikan setelah langkah 6:

  ```bash
  podman compose exec app php artisan down
  podman compose exec app php artisan up
  ```

- Jangan memakai `migrate:fresh`, `migrate:refresh`, atau `migrate:reset` pada database yang
  sudah memuat data nyata; ketiganya menghapus data.

> Prosedur rilis produksi kanonis (siapa yang menjalankan, jendela maintenance, dan strategi
> rollback) belum ditetapkan dalam dokumen proyek. Sampai keputusan tersebut ada, prosedur di
> atas menjadi acuan update manual dan tidak boleh diganti dengan auto-migration pada startup
> container tanpa persetujuan eksplisit.

---

## Runbook Cutover Dokumen ke Storage Privat

Dokumen pegawai sengaja disimpan di storage privat dan diunduh melalui akses backend yang berotorisasi. Arah ini mengutamakan standar keamanan saat ini, meskipun PRD versi lama masih mencantumkan path `storage/app/public` untuk dokumen. Jangan mengalihkan dokumen pegawai kembali ke disk `public` atau mengeksposnya melalui `storage:link`, karena hal tersebut membuka kembali risiko bypass authorization melalui symlink.

Jalankan urutan berikut sebagai **release gate sebelum aplikasi diaktifkan**. Proyek belum aktif, sehingga runbook ini adalah gerbang pra-aktivasi dan bukan klaim bahwa data produksi sudah dimigrasikan.

1. Jadwalkan downtime, pastikan backup database dan storage dokumen lama sudah tersedia sesuai prosedur infrastruktur LLDIKTI, lalu aktifkan maintenance mode:

   ```bash
   podman compose exec app php artisan down
   ```

2. Jalankan dry-run tanpa flag. Lanjutkan hanya bila ringkasannya menunjukkan `hilang=0` dan `konflik=0`. Jika `yatim` lebih dari nol, periksa setiap path yang dilaporkan dan pastikan semuanya memang dokumen pegawai legacy tanpa referensi database; file tersebut akan dipindahkan ke karantina privat saat mode eksekusi:

   ```bash
   podman compose exec app php artisan documents:migrate-to-private-storage
   ```

   Perbaiki setiap path yang berstatus `hilang` atau `konflik`. Dry-run juga gagal saat menemukan `yatim` agar file publik tanpa referensi tidak terlewat sebagai hasil bersih.

3. Setelah kondisi `hilang` dan `konflik` bersih serta setiap `yatim` sudah ditinjau, lakukan cutover dengan satu-satunya flag eksekusi yang tersedia:

   ```bash
   podman compose exec app php artisan documents:migrate-to-private-storage --execute
   ```

4. Jalankan dry-run kembali untuk verifikasi. Pastikan `siap=0`, `hilang=0`, `konflik=0`, dan `yatim=0`; baris yang sudah privat akan dihitung sebagai `sudah_privat`, sedangkan orphan yang berhasil diamankan tercatat sebagai `dikarantina` pada langkah eksekusi.

   ```bash
   podman compose exec app php artisan documents:migrate-to-private-storage
   ```

5. Aktifkan aplikasi hanya setelah verifikasi lulus:

   ```bash
   podman compose exec app php artisan up
   ```

Tidak ada fallback ke disk publik pada proses ini. Jika verifikasi gagal, tetap pertahankan maintenance mode dan selesaikan penyebabnya sebelum aktivasi.

---

## Perintah yang Sering Digunakan

### Container Management

```bash
# Start semua containers
podman compose up -d

# Stop semua containers
podman compose down

# Restart containers
podman compose restart

# Lihat status containers
podman compose ps

# Lihat logs (follow mode)
podman compose logs -f

# Lihat logs service tertentu
podman compose logs -f app
podman compose logs -f db
podman compose logs -f nginx
```

### Laravel Artisan (di dalam container)

```bash
# Jalankan migration
podman compose exec app php artisan migrate

# Rollback migration
podman compose exec app php artisan migrate:rollback

# Fresh migration + seed
podman compose exec app php artisan migrate:fresh --seed

# Buat model + migration + controller
podman compose exec app php artisan make:model NamaModel -mc

# Buat controller
podman compose exec app php artisan make:controller NamaController

# Clear semua cache
podman compose exec app php artisan optimize:clear

# Masuk ke Tinker (REPL)
podman compose exec app php artisan tinker

# Jalankan tests
podman compose exec app php artisan test
```

### Pengujian Otomatis

Test unit dan feature menggunakan PHPUnit dengan PostgreSQL 17. `phpunit.xml` secara eksplisit memaksa `APP_ENV=testing`, `DB_CONNECTION=pgsql`, dan `DB_DATABASE=simpeg_test`; koneksi normal `simpeg` tidak boleh menjadi target test. Host, port, username, dan password tetap mengikuti `.env` atau environment CI agar perintah dijalankan dari container `app` maupun CI tanpa menduplikasi credential.

Pastikan database disposable `simpeg_test` telah disiapkan operator sebelum menjalankan suite pertama. Jalankan perintah melalui container `app`, sehingga `DB_HOST=db` dari konfigurasi default tetap dapat dijangkau:

```bash
# Seluruh test unit dan feature
podman compose exec app composer test

# Seluruh quality gate: Pint, PHPStan, dan test
podman compose exec app composer qa
```

CI tetap membagi suite yang sama: lane paralel membuat worker database dari `simpeg_test`, lane serial memakai `simpeg_test`, lalu test migration destruktif berjalan terakhir hanya dengan `SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true`. Guard test menolak driver, environment, atau nama database selain target tersebut. Jangan mengarahkan perintah test ke `simpeg`, dan jangan menjalankan lane destruktif bersamaan dengan proses test lain.

Laravel Dusk tersedia untuk browser test, tetapi memakai konfigurasi terpisah (`phpunit.dusk.xml`) dan tidak dipanggil oleh `composer qa`. Resep eksekusi Dusk yang menjamin isolasi runner dan server aplikasi belum tersedia pada panduan ini; jangan menjalankan `composer test:browser` sebelum isolasi tersebut disiapkan dan diverifikasi.

> **Penting:** Beberapa browser test menjalankan `migrate:fresh`. Runner Dusk dan server `APP_URL` harus sama-sama memakai database testing disposable yang terisolasi, bukan `.env` normal/database `simpeg`. Jalankan Dusk sendiri, tidak bersamaan dengan lane PHPUnit paralel, serial, atau destruktif. Verifikasi end-to-end pada browser tetap diperlukan saat mengubah alur UI utama; hasil automated test bukan pengganti UAT.

### Masuk ke Shell Container

```bash
# Shell ke container app (PHP)
podman compose exec app bash

# Shell ke container database (psql)
podman compose exec db psql -U simpeg -d simpeg
```

### Helper Script (PowerShell)

```powershell
.\podman-up.ps1 up        # Build & start (default)
.\podman-up.ps1 down      # Stop containers
.\podman-up.ps1 restart   # Restart containers
.\podman-up.ps1 logs      # Lihat logs
.\podman-up.ps1 shell     # Masuk ke shell container app
.\podman-up.ps1 artisan migrate   # Jalankan artisan command
```

---

## Struktur Project

```
SIMPEG/
├── app/                    # Kode aplikasi Laravel (Models, Controllers, dll)
├── bootstrap/              # Bootstrap framework
├── config/                 # File konfigurasi Laravel
├── database/
│   ├── factories/          # Model factories
│   ├── migrations/         # Database migrations
│   └── seeders/            # Database seeders
├── docker/
│   ├── nginx/
│   │   └── default.conf    # Konfigurasi Nginx
│   └── php/
│       └── Dockerfile      # PHP 8.4-FPM + extensions
├── public/                 # Document root (index.php, assets)
├── resources/
│   ├── css/                # Stylesheet (Tailwind CSS)
│   ├── js/                 # JavaScript
│   └── views/              # Blade templates
├── routes/                 # Route definitions
├── storage/                # Logs, cache, uploads
├── tests/                  # Unit & feature tests
├── .env.example            # Template environment variables
├── compose.yml             # Podman Compose configuration
├── composer.json           # PHP dependencies
├── package.json            # Node.js dependencies
├── podman-up.ps1           # Helper script (PowerShell)
└── vite.config.js          # Vite bundler configuration
```

---

## Laravel Action Pattern

Endpoint SIMPEG memakai pola thin controller agar logic tidak menumpuk di controller:

```text
Route -> Controller -> FormRequest -> Action -> Service jika reusable -> Model/DB -> JsonResponse atau Resource
```

Aturan ringkas:

- Controller hanya menerima request/model binding, memanggil satu Action, lalu mengembalikan response.
- Endpoint mutasi wajib memakai FormRequest untuk validasi dan authorization request-level.
- Action mewakili satu use case, misalnya `CreateHariLiburAction` atau `MarkNotificationAsReadAction`.
- Service dipakai hanya untuk logic domain yang reusable, seperti audit, notifikasi, file storage, atau kalkulasi tanggal.
- Audit, transaction, workflow transition, dan query kompleks tidak ditaruh langsung di controller.

Contoh controller:

```php
public function store(StoreHariLiburRequest $request, CreateHariLiburAction $action): JsonResponse
{
    $hariLibur = $action->execute($request->validated(), $request);

    return response()->json([
        'message' => 'Hari libur berhasil ditambahkan.',
        'data' => $hariLibur->toApiArray(),
    ], 201);
}
```

Checklist PR endpoint baru/refactor:

- route hanya berisi path, middleware, dan controller method;
- controller tetap tipis;
- mutation endpoint memakai FormRequest;
- Action punya satu use case dan method `execute(...)`;
- Service tidak menjadi dumping ground untuk logic satu endpoint;
- response shape lama tidak berubah kecuali PR memang mengubah kontrak API.

---

## Arsitektur Container

```
┌─────────────────────────────────────────────────┐
│                  Podman Network                  │
│                 (simpeg_net)                      │
│                                                  │
│  ┌──────────┐   ┌──────────┐   ┌──────────────┐ │
│  │  Nginx   │──▶│ PHP-FPM  │──▶│ PostgreSQL   │ │
│  │ :80→8000 │   │  (app)   │   │   17 (db)    │ │
│  │  Alpine  │   │ PHP 8.4  │   │  :5432       │ │
│  └──────────┘   └──────────┘   └──────────────┘ │
│                                                  │
└─────────────────────────────────────────────────┘
```

| Container        | Image                | Port          |
| ---------------- | -------------------- | ------------- |
| `simpeg_nginx`   | nginx:alpine         | 8000 → 80    |
| `simpeg_app`     | php:8.4-fpm (custom) | 9000 (internal) |
| `simpeg_postgres` | postgres:17         | 5432          |

---

## Troubleshooting

### Podman tidak ditemukan di PATH (Windows)

Tambahkan Podman ke PATH secara manual:

```powershell
$env:Path = "C:\Program Files\RedHat\Podman;" + $env:Path
```

Atau tambahkan secara permanen melalui **System Environment Variables**.

### `podman machine` membutuhkan admin authority

Jalankan PowerShell sebagai **Administrator** untuk perintah `podman machine`:

```powershell
podman machine init
podman machine start
```

### Error: could not find driver (pgsql)

Container belum di-rebuild setelah perubahan Dockerfile:

```bash
podman compose down
podman compose build --no-cache app
podman compose up -d
```

### Permission denied pada storage/

```bash
podman compose exec app chmod -R 777 storage bootstrap/cache
```

### Database connection refused

Pastikan container PostgreSQL sudah running:

```bash
podman compose ps
podman compose logs db
```

Tunggu beberapa detik setelah `podman compose up` agar PostgreSQL selesai inisialisasi.

### Import pegawai menolak dengan pesan "Database aplikasi belum siap"

Endpoint eksekusi import memeriksa kolom wajib tabel `import_batches` sebelum batch diklaim.
Bila ada kolom yang belum tersedia, endpoint menolak dengan HTTP 503 dan tidak membuat batch,
job, maupun data pegawai. Penyebab paling umum adalah migration yang belum dijalankan setelah
menarik kode baru.

Periksa dan jalankan migration yang tertinggal:

```bash
podman compose exec app php artisan migrate:status
podman compose exec app php artisan migrate
podman compose restart queue
```

Daftar kolom yang hilang dicatat pada `storage/logs/laravel.log` dengan konteks
`import.pegawai.schema_readiness`.

---

## License

Aplikasi ini dibangun menggunakan framework [Laravel](https://laravel.com) yang dilisensikan di bawah [MIT License](https://opensource.org/licenses/MIT).
