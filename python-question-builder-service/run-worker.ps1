# Runs the question-builder worker on this machine against the local site.
#
#   .\run-worker.ps1
#
# Reads python-question-builder-service\.env (see deploy\qb-worker.local.env.example).
# Stop it with Ctrl+C. It opens no port; it asks the local site for queued jobs.

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $here

$envFile = Join-Path $here '.env'

if (-not (Test-Path $envFile)) {
    Write-Host "No .env here yet. Create it from the template, then fill in the secret and the key:" -ForegroundColor Yellow
    Write-Host "  copy deploy\qb-worker.local.env.example .env"
    exit 1
}

$python = Join-Path $here 'venv\Scripts\python.exe'

if (-not (Test-Path $python)) {
    Write-Host "No virtual environment. Create it once:" -ForegroundColor Yellow
    Write-Host "  python -m venv venv"
    Write-Host "  venv\Scripts\pip install -r requirements.txt"
    exit 1
}

# What it will use, without ever printing a secret.
$values = @{}
foreach ($line in Get-Content $envFile) {
    if ($line -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$') { $values[$Matches[1]] = $Matches[2] }
}

function Show([string]$name, [string]$fallback) {
    $v = if ($values.ContainsKey($name) -and $values[$name] -ne '') { $values[$name] } else { $fallback }
    Write-Host ("  {0,-20} {1}" -f $name, $v)
}

function Secret([string]$name) {
    $set = $values.ContainsKey($name) -and $values[$name] -ne ''
    Write-Host ("  {0,-20} {1}" -f $name, $(if ($set) { 'set' } else { 'NOT SET' }))
}

Write-Host "Starting the question-builder worker:"
Show 'QB_PHP_URL' 'http://127.0.0.1'
Show 'QB_BACKEND' 'groq'
Show 'QB_WORKER_ID' '(host-pid)'
Secret 'QB_WORKER_SECRET'
Secret 'GROQ_API_KEY'
Write-Host ""

& $python -m qb.worker
