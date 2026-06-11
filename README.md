# SIMPEG LLDIKTI Wilayah XVI

Sistem Informasi Kepegawaian (employee information system) untuk LLDIKTI Wilayah XVI.

Repo ini berisi **Fase 1 / Core** dari SIMPEG. SAKIP dan modul lanjutan lain BUKAN bagian dari fase ini, dan akan dikerjakan pada fase berikutnya.

Dikerjakan oleh tim beranggotakan 5 orang dengan metodologi Scrum. Target Go-Live: **sebelum 1 September 2026**.

## Tech Stack

- **Backend**: Laravel 12 (PHP 8.2+), arsitektur monolith
- **Frontend**: Blade + CSS (server-side rendering, responsive). Bukan React/SPA
- **Database**: PostgreSQL 15+ (saat ini scaffold masih jalan di SQLite default; koneksi PostgreSQL akan dikonfigurasi pada fase migrasi)
- **Queue/Worker**: Redis + Laravel Horizon (rencana, untuk email, CSV import, dan scheduler EWS). Belum dipasang
- **Auth**: Keycloak SSO (OpenID Connect / OAuth 2.0), memakai instance LLDIKTI yang sudah ada
- **Timezone**: `Asia/Makassar` (WITA). Penting: scheduler harian EWS berjalan pada 07:00 WITA

### Paket utama yang direncanakan untuk Fase 1

Paket berikut belum dipasang, tapi sudah direncanakan:

- `owen-it/laravel-auditing` (audit log)
- `maatwebsite/excel` (ekspor Excel)
- `barryvdh/laravel-dompdf` (ekspor PDF)
- Keycloak socialite / web-guard (integrasi SSO)
- `laravel/horizon` (monitoring queue)

## Prerequisites

Pastikan tools berikut sudah terpasang sebelum mulai:

- **PHP >= 8.2** dengan ekstensi: `zip`, `pdo_sqlite`, `sqlite3` (untuk run awal), `pdo_pgsql`, `pgsql` (untuk PostgreSQL), `mbstring`, `openssl`, `curl`
- **Composer 2.x**
- **Node.js 18+** dan **npm** (untuk Vite/asset frontend)
- **PostgreSQL 15+** (untuk nanti; SQLite cukup untuk run awal)
- **Git**

## Local Setup

Ikuti langkah berikut secara berurutan untuk menjalankan aplikasi di mesin lokal.

1. Clone repo lalu masuk ke direktori proyek.

```bash
git clone <repo-url>
cd <nama-folder-hasil-clone>
```

2. Pasang dependency PHP.

```bash
composer install
```

3. Salin file environment.

```bash
# Windows
copy .env.example .env

# Unix / macOS / Linux
cp .env.example .env
```

4. Generate application key.

```bash
php artisan key:generate
```

5. Konfigurasi database. Secara default sudah memakai SQLite, jadi langkah ini bisa dilewati untuk run awal. Untuk memakai PostgreSQL nanti, atur variabel `DB_*` di file `.env`.

6. Jalankan migrasi database.

```bash
php artisan migrate
```

7. Pasang dependency frontend.

```bash
npm install
```

8. Jalankan dev server untuk asset frontend (gunakan terminal terpisah).

```bash
npm run dev
```

9. Jalankan aplikasi Laravel, lalu akses `http://localhost:8000`.

```bash
php artisan serve
```

## Git / Branch Workflow

Bagian ini wajib dibaca semua anggota tim. Alur kerja Git kita ketat agar `main` selalu stabil.

**Model branch:**

```
main (stabil / release)
  └── development (integrasi)
        └── feature/* atau chore/* (cabang kerja)
```

**Aturan:**

- Buat cabang kerja dari `development`, kerjakan fiturnya, push, lalu buka **Pull Request ke `development`**.
- **JANGAN push langsung ke `main` atau `development`.** Semua perubahan masuk lewat PR.
- Setiap PR **wajib melalui code review** sebelum di-merge.

**Contoh memulai cabang fitur baru:**

```bash
git checkout development
git checkout -b feature/nama-fitur
```

## Testing

Jalankan test suite dengan:

```bash
php artisan test
```

## Struktur Tim

Tim memakai model **fullstack-per-feature** di atas Laravel: satu orang memiliki satu fitur dari ujung ke ujung (migration, model, controller, hingga Blade view).
