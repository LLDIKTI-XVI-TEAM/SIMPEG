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

# Buat symbolic link storage
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

Test unit dan feature menggunakan PHPUnit. Konfigurasi lokal default memakai SQLite in-memory, sedangkan CI menjalankan suite yang sama terhadap PostgreSQL 17.

```bash
# Seluruh test unit dan feature
composer test

# Seluruh quality gate: Pint, PHPStan, dan test
composer qa
```

Laravel Dusk tersedia untuk browser test. Jalankan perintah berikut dari host yang mempunyai Google Chrome/Chromium, dengan aplikasi telah tersedia pada `APP_URL` (default `http://localhost:8000`):

```bash
# Unduh ChromeDriver yang cocok dengan versi Chrome lokal (cukup saat versi Chrome berubah)
php artisan dusk:chrome-driver --detect

# Terminal 1: jalankan aplikasi lokal
php artisan serve

# Terminal 2: jalankan browser test dalam mode headless
composer test:browser

# Opsional: tampilkan jendela browser ketika mendiagnosis kegagalan
php artisan dusk --browse
```

Browser test tidak dipanggil oleh `composer qa` karena memerlukan browser dan ChromeDriver. Jalankan browser test sebelum mengubah alur UI utama atau ketika perubahan memerlukan verifikasi end-to-end.

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
