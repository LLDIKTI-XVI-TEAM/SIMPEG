# =============================================================
# SIMPEG - python -m podman_compose Helper Script
# Jalankan script ini dengan: .\podman-up.ps1
# Untuk stop: .\podman-up.ps1 down
# =============================================================

$ErrorActionPreference = "Stop"

# Pastikan Podman ada di PATH
if (-not (Get-Command podman -ErrorAction SilentlyContinue)) {
    $podmanPath = "C:\Program Files\RedHat\Podman"
    if (Test-Path "$podmanPath\podman.exe") {
        $env:Path = "$podmanPath;$env:Path"
        Write-Host "[OK] Podman ditemukan di: $podmanPath" -ForegroundColor Green
    } else {
        Write-Host "[ERROR] Podman tidak ditemukan! Install dulu dari https://podman.io" -ForegroundColor Red
        exit 1
    }
}

Write-Host "`n=== SIMPEG - python -m podman_compose ===" -ForegroundColor Cyan
Write-Host "Podman version: $(podman --version)" -ForegroundColor DarkGray

$action = if ($args.Count -gt 0) { $args[0] } else { "up" }

switch ($action) {
    "up" {
        # Pastikan .env ada
        if (-not (Test-Path ".env")) {
            Write-Host "[INFO] Membuat .env dari .env.example..." -ForegroundColor Yellow
            Copy-Item ".env.example" ".env"
        }

        Write-Host "`n[1/3] Building containers..." -ForegroundColor Cyan
        podman build -t simpeg_app -f docker/php/Dockerfile .

        Write-Host "`n[2/3] Starting containers..." -ForegroundColor Cyan
        python -m podman_compose up -d

        Write-Host "`n[3/3] Installing dependencies & setup Laravel..." -ForegroundColor Cyan
        python -m podman_compose exec app composer install --no-interaction
        if ($LASTEXITCODE -ne 0) {
            throw "composer install gagal. Setup Laravel dihentikan."
        }

        python -m podman_compose exec app php artisan key:generate --force
        if ($LASTEXITCODE -ne 0) {
            throw "key:generate gagal. Setup Laravel dihentikan."
        }

        python -m podman_compose exec app php artisan migrate --force
        if ($LASTEXITCODE -ne 0) {
            throw "migrate gagal. Setup Laravel dihentikan."
        }

        # --force membuat symlink yang valid dapat dibuat ulang dengan aman.
        # Hentikan setup jika pembuatan atau validasi link storage gagal.
        python -m podman_compose exec app php artisan storage:link --force
        if ($LASTEXITCODE -ne 0) {
            throw "storage:link gagal. Setup Laravel dihentikan."
        }

        python -m podman_compose exec app sh -lc "test -L public/storage -a -e public/storage"
        if ($LASTEXITCODE -ne 0) {
            throw "Link public/storage tidak valid atau target storage tidak tersedia. Setup Laravel dihentikan."
        }

        Write-Host "`n============================================" -ForegroundColor Green
        Write-Host "  SIMPEG berjalan di: http://localhost:8000" -ForegroundColor Green
        Write-Host "============================================" -ForegroundColor Green
        Write-Host "`nContainers:" -ForegroundColor Cyan
        python -m podman_compose ps
    }
    "down" {
        Write-Host "Stopping containers..." -ForegroundColor Yellow
        python -m podman_compose down
        Write-Host "[OK] Containers stopped." -ForegroundColor Green
    }
    "restart" {
        Write-Host "Restarting containers..." -ForegroundColor Yellow
        python -m podman_compose restart
        python -m podman_compose ps
    }
    "logs" {
        python -m podman_compose logs -f
    }
    "shell" {
        python -m podman_compose exec app bash
    }
    "artisan" {
        $artisanArgs = $args[1..($args.Count - 1)] -join " "
        python -m podman_compose exec app php artisan $artisanArgs
    }
    default {
        Write-Host "Usage: .\podman-up.ps1 [command]" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "Commands:" -ForegroundColor Cyan
        Write-Host "  up        Build & start semua containers (default)"
        Write-Host "  down      Stop & hapus containers"
        Write-Host "  restart   Restart containers"
        Write-Host "  logs      Lihat logs (follow mode)"
        Write-Host "  shell     Masuk ke shell container app"
        Write-Host "  artisan   Jalankan artisan command, e.g.: .\podman-up.ps1 artisan migrate"
    }
}
