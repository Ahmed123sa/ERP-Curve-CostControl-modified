# ============================================================
# ERP System Setup Script — Windows
# Run this ONCE after extracting the project
# Requirements: PHP 8.2+, Composer, Node.js 18+, PostgreSQL 15+
# ============================================================

Write-Host "======================================" -ForegroundColor Cyan
Write-Host "  ERP Cost Control — Setup Script" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan

# --- Backend ---
Write-Host "`n[1/5] Installing Laravel dependencies..." -ForegroundColor Yellow
Set-Location backend
composer install --no-interaction
Copy-Item .env.example .env
php artisan key:generate
Write-Host "Backend dependencies installed." -ForegroundColor Green

# --- Database ---
Write-Host "`n[2/5] Running database migrations..." -ForegroundColor Yellow
Write-Host "Make sure PostgreSQL is running and you updated .env with your DB credentials."
php artisan migrate
Write-Host "Migrations done." -ForegroundColor Green

# --- Seed ---
Write-Host "`n[3/5] Seeding initial data..." -ForegroundColor Yellow
php artisan db:seed
Write-Host "Seeded." -ForegroundColor Green

# --- Frontend ---
Write-Host "`n[4/5] Installing frontend dependencies..." -ForegroundColor Yellow
Set-Location ..\frontend
npm install
Write-Host "Frontend dependencies installed." -ForegroundColor Green

# --- Done ---
Set-Location ..
Write-Host "`n[5/5] Setup complete!" -ForegroundColor Green
Write-Host "======================================" -ForegroundColor Cyan
Write-Host "  To run the system:"
Write-Host "  Backend:  cd backend && php artisan serve"
Write-Host "  Frontend: cd frontend && npm run dev"
Write-Host "  App URL:  http://localhost:3000"
Write-Host "======================================" -ForegroundColor Cyan
