# Deploy-PtaTools.ps1
#
# Build the Azure Plugin source into a Linux-safe zip and push it to the
# specified App Service via Kudu's /api/zip endpoint. Verifies the deploy
# by reading the plugin file back from the server and confirming the
# pta-tools/v1 REST namespace is registered (i.e. the plugin actually
# loaded after extraction).
#
# Usage:
#   .\Deploy-PtaTools.ps1 -Site wilderptsa
#   .\Deploy-PtaTools.ps1 -Site lwptsa
#   .\Deploy-PtaTools.ps1 -Site both

param(
    [Parameter(Mandatory)]
    [ValidateSet('wilderptsa', 'lwptsa', 'both')]
    [string]$Site,

    [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'

$RepoRoot  = $PSScriptRoot
$SourceDir = Join-Path $RepoRoot 'Azure Plugin'
$BuildDir  = Join-Path $RepoRoot '.build'
$ZipPath   = Join-Path $BuildDir 'azure-plugin.zip'

function Get-SiteConfig([string]$siteName) {
    $envFile = Join-Path $RepoRoot (
        @{ wilderptsa = 'wilder.env'; lwptsa = 'ltptsa.env' }[$siteName]
    )
    if (-not (Test-Path $envFile)) {
        throw "Env file for $siteName not found at $envFile"
    }
    $cfg = @{ Site = $siteName }
    Get-Content $envFile | ForEach-Object {
        if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.+?)\s*$') {
            $cfg[$Matches[1]] = $Matches[2]
        }
    }
    return $cfg
}

function New-AuthHeaders($cfg) {
    $user = '$' + $cfg.Site
    $pair = "${user}:$($cfg.FTPS_PASSWORD)"
    $b64  = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pair))
    return @{ Authorization = "Basic $b64" }
}

function Get-LocalPluginVersion {
    $pluginPhp = Join-Path $SourceDir 'azure-plugin.php'
    $head = Get-Content $pluginPhp -TotalCount 15
    foreach ($line in $head) {
        if ($line -match 'Version:\s*([0-9.]+)') { return $Matches[1] }
    }
    return 'unknown'
}

function Build-Zip {
    # The active plugin directory on the server is "Azure Plugin/" (with
    # space and capital A). WordPress stores the active plugin path in
    # the wp_options.active_plugins option as `Azure Plugin/azure-plugin.php`,
    # so we MUST preserve that exact directory name in the deployed zip.
    # Earlier versions of this script staged into `.build/azure-plugin/`
    # (lowercase, no space) which made every deploy land in a phantom
    # `wp-content/plugins/azure-plugin/` directory while the active
    # `wp-content/plugins/Azure Plugin/` kept serving stale code. See
    # the v3.140 -> v3.141.0 cutover for the fix narrative.
    Write-Host "  - Staging files to .build/Azure Plugin/..." -NoNewline
    if (Test-Path $BuildDir) { Remove-Item -Recurse -Force $BuildDir }
    New-Item -ItemType Directory -Path $BuildDir | Out-Null

    $stagingPlugin = Join-Path $BuildDir 'Azure Plugin'
    Copy-Item -Path $SourceDir -Destination $stagingPlugin -Recurse -Force

    # Strip artifacts that should not ship to production.
    $excludePatterns = @('*.zip', '.git', '.DS_Store', 'node_modules', '*.log', 'PTATools.wiki', 'Deadcode.md')
    foreach ($pat in $excludePatterns) {
        Get-ChildItem -Path $stagingPlugin -Filter $pat -Recurse -Force -ErrorAction SilentlyContinue |
            Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }
    Write-Host " ok"

    Write-Host "  - Compressing..." -NoNewline
    if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
    # We avoid both Compress-Archive (file lock races) and
    # ZipFile.CreateFromDirectory (writes Windows backslash entries that
    # Linux Kudu Sync rejects with EINVAL). Walk files manually and
    # create entries with explicit forward slashes via ZipArchive.
    # Write to C:\Windows\Temp first to dodge Defender's real-time lock
    # and to avoid 8.3 short-name issues in user-profile paths.
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $tempRoot = 'C:\Windows\Temp'
    if (-not (Test-Path $tempRoot)) { $tempRoot = $env:TEMP }
    $tmpZip = Join-Path $tempRoot ("azure-plugin-{0}.zip" -f ([guid]::NewGuid().ToString('N')))
    $stream = [System.IO.File]::Open($tmpZip, [System.IO.FileMode]::Create)
    try {
        $archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            $buildRoot = (Resolve-Path -LiteralPath $BuildDir).Path.TrimEnd('\','/')
            $files = Get-ChildItem -LiteralPath $buildRoot -Recurse -File -Force
            foreach ($f in $files) {
                $rel = $f.FullName.Substring($buildRoot.Length).TrimStart('\','/')
                $rel = $rel -replace '\\', '/'
                $entry = $archive.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)
                $src = [System.IO.File]::Open($f.FullName, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
                try {
                    $dest = $entry.Open()
                    try { $src.CopyTo($dest) } finally { $dest.Dispose() }
                } finally {
                    $src.Dispose()
                }
            }
        } finally {
            $archive.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
    Move-Item -Path $tmpZip -Destination $ZipPath -Force
    $sizeKb = [math]::Round((Get-Item $ZipPath).Length / 1KB, 1)
    Write-Host " ok ($sizeKb KB)"
}

function Deploy-To-Site($cfg) {
    Write-Host ""
    Write-Host "==> Deploying to $($cfg.Site).azurewebsites.net" -ForegroundColor Cyan
    $headers = New-AuthHeaders $cfg
    $kudu    = "https://$($cfg.Site).scm.azurewebsites.net"

    Write-Host "  - Pre-flight: ping Kudu..." -NoNewline
    $null = Invoke-RestMethod -Uri "$kudu/api/diagnostics/runtime" -Headers $headers -TimeoutSec 30
    Write-Host " ok"

    Write-Host "  - Current deployed version..." -NoNewline
    $currentVer = '(none)'
    # Active plugin path is "Azure Plugin/azure-plugin.php" — note the
    # space and capital A. Kudu VFS requires URL-encoded space.
    $activePluginPhpUrl = "$kudu/api/vfs/site/wwwroot/wp-content/plugins/Azure%20Plugin/azure-plugin.php"
    try {
        $php = Invoke-RestMethod -Uri $activePluginPhpUrl -Headers $headers -TimeoutSec 30
        if ($php -match 'Version:\s*([0-9.]+)') { $currentVer = $Matches[1] }
    } catch { }
    Write-Host " $currentVer"

    Write-Host "  - Uploading and extracting..." -NoNewline
    $putUrl = "$kudu/api/zip/site/wwwroot/wp-content/plugins/"
    $zipBytes = [IO.File]::ReadAllBytes($ZipPath)
    try {
        $null = Invoke-RestMethod -Uri $putUrl -Headers $headers -Method Put -Body $zipBytes -ContentType 'application/zip' -TimeoutSec 300
        Write-Host " ok"
    } catch {
        $code = $null
        if ($_.Exception.Response) { $code = $_.Exception.Response.StatusCode.value__ }
        Write-Host " kudu returned $code (will verify on server)"
    }

    Write-Host "  - Verifying file on server..." -NoNewline
    $expectedVer = Get-LocalPluginVersion
    $php2 = $null
    foreach ($try in 1..3) {
        try {
            $php2 = Invoke-RestMethod -Uri $activePluginPhpUrl -Headers $headers -TimeoutSec 30
            if ($php2 -match "Version:\s*$([regex]::Escape($expectedVer))") { break }
        } catch { }
        Start-Sleep -Seconds 3
    }
    if (-not $php2 -or $php2 -notmatch "Version:\s*$([regex]::Escape($expectedVer))") {
        throw "Deployed file does not contain expected version $expectedVer (server file may be locked or upload truly failed)"
    }
    Write-Host " ok (v$expectedVer)"

    Write-Host "  - Verifying plugin is active in WordPress..."
    $publicHost = if ($cfg.Site -eq 'wilderptsa') { 'wilderptsa.net' } else { 'lwptsa.net' }
    $maxAttempts = 6
    $loaded = $false
    for ($i = 1; $i -le $maxAttempts; $i++) {
        try {
            $j = Invoke-RestMethod -Uri "https://$publicHost/wp-json/" -TimeoutSec 30
            if ($j.namespaces -contains 'pta-tools/v1') {
                Write-Host "    attempt $i/$maxAttempts -> LOADED" -ForegroundColor Green
                $loaded = $true
                break
            } else {
                Write-Host "    attempt $i/$maxAttempts -> not yet, retrying in 5s..."
            }
        } catch {
            Write-Host "    attempt $i/$maxAttempts -> error: $($_.Exception.Message), retrying..."
        }
        Start-Sleep -Seconds 5
    }

    if (-not $loaded) {
        Write-Host ""
        Write-Host "  DEPLOY VERIFICATION FAILED" -ForegroundColor Red
        Write-Host "  Files were uploaded but the plugin is NOT registered in WordPress." -ForegroundColor Red
        throw "Plugin not loaded after deploy on $($cfg.Site)"
    }

    Write-Host "  Deploy succeeded: v$(Get-LocalPluginVersion) on $publicHost" -ForegroundColor Green
}

# --- main ---
Write-Host "PTA Tools deploy" -ForegroundColor Cyan
Write-Host "  Source:  $SourceDir"
Write-Host "  Version: $(Get-LocalPluginVersion)"

if (-not $SkipBuild) {
    Write-Host ""
    Write-Host "==> Building zip"
    Build-Zip
} elseif (-not (Test-Path $ZipPath)) {
    throw "-SkipBuild used but $ZipPath does not exist"
}

$targets = if ($Site -eq 'both') { @('wilderptsa', 'lwptsa') } else { @($Site) }
foreach ($t in $targets) {
    $cfg = Get-SiteConfig $t
    Deploy-To-Site $cfg
}

Write-Host ""
Write-Host "All deployments complete." -ForegroundColor Green
