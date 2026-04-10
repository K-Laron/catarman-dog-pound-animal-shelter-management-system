param(
    [switch]$NoRebuildPalace
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$mempalaceRoot = Join-Path $projectRoot 'storage\mempalace'
$homePath = Join-Path $mempalaceRoot 'home'
$palacePath = Join-Path $mempalaceRoot 'palace'
$sourceNotesPath = Join-Path $mempalaceRoot 'source'
$systemSourcePath = Join-Path $mempalaceRoot 'system-source'
$phpMirrorPath = Join-Path $systemSourcePath 'php_mirror'
$liveDumpPath = Join-Path $systemSourcePath 'database\live'

$env:HOME = $homePath
$env:USERPROFILE = $homePath
$env:PYTHONIOENCODING = 'utf-8'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

function Invoke-RobocopyDirectory {
    param(
        [Parameter(Mandatory = $true)][string]$Source,
        [Parameter(Mandatory = $true)][string]$Destination
    )

    robocopy $Source $Destination /E /R:1 /W:1 /NFL /NDL /NJH /NJS /NP /XD node_modules vendor .git storage tmp .tmp chat_history _for-deletion .playwright-cli .composer | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy failed from [$Source] to [$Destination] with exit code $LASTEXITCODE"
    }
}

function Copy-SystemSnapshot {
    if (Test-Path $systemSourcePath) {
        Remove-Item -LiteralPath $systemSourcePath -Recurse -Force
    }
    New-Item -ItemType Directory -Path $systemSourcePath -Force | Out-Null

    $dirs = @('src', 'routes', 'views', 'config', 'database', 'docs', 'public', 'tests', 'bootstrap', 'scripts')
    foreach ($dir in $dirs) {
        $source = Join-Path $projectRoot $dir
        if (Test-Path $source) {
            $destination = Join-Path $systemSourcePath $dir
            New-Item -ItemType Directory -Path $destination -Force | Out-Null
            Invoke-RobocopyDirectory -Source $source -Destination $destination
        }
    }

    $rootFiles = @(
        'README.md',
        'ARCHITECTURE.md',
        'API_ROUTES.md',
        'IMPLEMENTATION_GUIDE.md',
        'VALIDATION_RULES.md',
        'PAGE_LAYOUTS.md',
        'PRD_Catarman_Dog_Pound.md',
        'ROOT_LAYOUT.md',
        'llm_context.md',
        'system_summary.md',
        'database_schema.sql',
        'seeders.sql',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'phpunit.xml',
        '.env.example'
    )

    foreach ($file in $rootFiles) {
        $source = Join-Path $projectRoot $file
        if (Test-Path $source) {
            Copy-Item -LiteralPath $source -Destination (Join-Path $systemSourcePath $file) -Force
        }
    }

    $mempalaceYaml = @'
wing: revised_system
rooms:
  - name: architecture
    description: System architecture, layouts, and design docs
    keywords: [architecture, design, layout, system, module]
  - name: routing_api
    description: Route definitions and API behavior
    keywords: [route, routes, api, endpoint, middleware, controller]
  - name: animals_kennels
    description: Animal intake, profiles, kennels, and assignments
    keywords: [animal, breed, intake, kennel, assignment]
  - name: medical
    description: Medical records, treatments, prescriptions, and lab flows
    keywords: [medical, treatment, prescription, laboratory, examination]
  - name: adoption
    description: Adoption portal and workflow
    keywords: [adoption, adopter, application, interview, completion]
  - name: billing
    description: Billing, invoices, and payments
    keywords: [billing, invoice, payment, receipt, fee]
  - name: inventory
    description: Inventory stock, adjustments, and alerts
    keywords: [inventory, stock, transaction, adjustment, supplies]
  - name: auth_admin
    description: Authentication, users, roles, and permissions
    keywords: [auth, authentication, user, role, permission, session]
  - name: frontend_ui
    description: Frontend views, scripts, and styles
    keywords: [view, ui, css, js, javascript, html]
  - name: testing_ops
    description: Tests, scripts, and operational tooling
    keywords: [test, phpunit, script, backup, performance, maintenance]
  - name: general
    description: Everything else
'@

    Set-Content -LiteralPath (Join-Path $systemSourcePath 'mempalace.yaml') -Value $mempalaceYaml -Encoding ASCII
}

function Export-LiveDatabaseDump {
    New-Item -ItemType Directory -Path $liveDumpPath -Force | Out-Null
    Get-ChildItem -LiteralPath $liveDumpPath -Filter 'live-db-full-*.sql' -File -ErrorAction SilentlyContinue | Remove-Item -Force

    $scriptPath = Join-Path $projectRoot 'scripts\mempalace\export-live-db.php'
    & php $scriptPath "--output-dir=$liveDumpPath"
    if ($LASTEXITCODE -ne 0) {
        throw 'Live database export failed.'
    }
}

function New-PhpMirrorFiles {
    if (Test-Path $phpMirrorPath) {
        Remove-Item -LiteralPath $phpMirrorPath -Recurse -Force
    }
    New-Item -ItemType Directory -Path $phpMirrorPath -Force | Out-Null

    $phpFiles = Get-ChildItem -Path $systemSourcePath -Recurse -File -Filter '*.php' | Where-Object {
        $_.FullName -notlike "$phpMirrorPath*"
    }

    foreach ($file in $phpFiles) {
        $relative = $file.FullName.Substring($systemSourcePath.Length).TrimStart('\')
        $targetDir = Join-Path $phpMirrorPath ([System.IO.Path]::GetDirectoryName($relative))
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null

        $targetFile = Join-Path $targetDir ($file.Name + '.txt')
        $header = "SOURCE_FILE: $relative`n`n"
        $content = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8
        Set-Content -LiteralPath $targetFile -Value ($header + $content) -Encoding UTF8
    }
}

function Invoke-MemPalaceMine {
    param([Parameter(Mandatory = $true)][string]$Directory)
    & mempalace --palace $palacePath mine $Directory --agent Kenneth
    if ($LASTEXITCODE -ne 0) {
        throw "MemPalace mine failed for [$Directory]."
    }
}

Copy-SystemSnapshot
Export-LiveDatabaseDump
New-PhpMirrorFiles

if (-not $NoRebuildPalace -and (Test-Path $palacePath)) {
    Remove-Item -LiteralPath $palacePath -Recurse -Force
}

Invoke-MemPalaceMine -Directory $systemSourcePath

if (Test-Path (Join-Path $sourceNotesPath 'mempalace.yaml')) {
    Invoke-MemPalaceMine -Directory $sourceNotesPath
}

& mempalace --palace $palacePath status
if ($LASTEXITCODE -ne 0) {
    throw 'MemPalace status failed.'
}
