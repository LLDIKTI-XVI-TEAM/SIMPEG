# =============================================================
# SIMPEG - podman compose Helper Script
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

Write-Host "`n=== SIMPEG - Podman Compose ===" -ForegroundColor Cyan
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
        podman compose build

        Write-Host "`n[2/3] Starting containers..." -ForegroundColor Cyan
        podman compose up -d

        Write-Host "`n[3/3] Installing dependencies & setup Laravel..." -ForegroundColor Cyan
        podman compose exec app composer install --no-interaction
        if ($LASTEXITCODE -ne 0) {
            throw "composer install gagal. Setup Laravel dihentikan."
        }

        podman compose exec app php artisan key:generate --force
        if ($LASTEXITCODE -ne 0) {
            throw "key:generate gagal. Setup Laravel dihentikan."
        }

        podman compose exec app php artisan migrate --force
        if ($LASTEXITCODE -ne 0) {
            throw "migrate gagal. Setup Laravel dihentikan."
        }

        # storage:link idempotent; kegagalan karena link sudah ada tidak
        # boleh menggagalkan setup yang telah selesai.
        $storageLinkErrorAction = $ErrorActionPreference
        try {
            $ErrorActionPreference = "Continue"
            podman compose exec app php artisan storage:link --force 2>$null
        } finally {
            $ErrorActionPreference = $storageLinkErrorAction
        }

        Write-Host "`n============================================" -ForegroundColor Green
        Write-Host "  SIMPEG berjalan di: http://localhost:8000" -ForegroundColor Green
        Write-Host "============================================" -ForegroundColor Green
        Write-Host "`nContainers:" -ForegroundColor Cyan
        podman compose ps
    }
    "down" {
        Write-Host "Stopping containers..." -ForegroundColor Yellow
        podman compose down
        Write-Host "[OK] Containers stopped." -ForegroundColor Green
    }
    "restart" {
        Write-Host "Restarting containers..." -ForegroundColor Yellow
        podman compose restart
        podman compose ps
    }
    "logs" {
        podman compose logs -f
    }
    "shell" {
        podman compose exec app bash
    }
    "artisan" {
        $artisanArgs = $args[1..($args.Count - 1)] -join " "
        podman compose exec app php artisan $artisanArgs
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
