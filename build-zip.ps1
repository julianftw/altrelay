# Build distribution zip for AltRelay.
# Usage: powershell -NoProfile -File build-zip.ps1
#
# Files are selected by .distignore, which is the single source of truth for
# what ships. Anything not listed there is copied; anything matching a pattern
# is skipped.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = $PSScriptRoot
$distDir = Join-Path $root 'dist'
$stageDir = Join-Path $distDir 'altrelay'
$zipPath = Join-Path $distDir 'altrelay.zip'
$distIgnorePath = Join-Path $root '.distignore'

if (-not (Test-Path $distIgnorePath)) {
    throw ".distignore not found at $distIgnorePath"
}

# Translate .distignore patterns into regexes matched against relative paths.
$ignorePatterns = Get-Content $distIgnorePath |
    ForEach-Object { $_.Trim() } |
    Where-Object { $_ -ne '' -and -not $_.StartsWith('#') } |
    ForEach-Object {
        $pattern = $_.TrimStart('/')
        $isDir = $_.EndsWith('/')
        $pattern = $pattern.TrimEnd('/')

        # Escape regex metacharacters, then restore glob wildcards.
        $regex = [Regex]::Escape($pattern).Replace('\*', '[^/]*')

        if ($isDir) {
            # A directory pattern excludes the directory and everything below it.
            "^$regex(/|$)"
        }
        else {
            "^$regex$"
        }
    }

function Test-Ignored {
    param([string] $RelativePath)

    $normalized = $RelativePath.Replace('\', '/')

    foreach ($regex in $ignorePatterns) {
        if ($normalized -match $regex) {
            return $true
        }
    }

    return $false
}

# Clean previous build.
if (Test-Path $distDir) {
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
    Remove-Item -Recurse -Force $distDir
}

New-Item -ItemType Directory $stageDir -Force | Out-Null

$copied = 0

Get-ChildItem $root -Recurse -File -Force | ForEach-Object {
    $relative = $_.FullName.Substring($root.Length).TrimStart('\', '/')

    if (Test-Ignored $relative) {
        return
    }

    $destination = Join-Path $stageDir $relative
    $destinationDir = Split-Path $destination -Parent

    if (-not (Test-Path $destinationDir)) {
        New-Item -ItemType Directory $destinationDir -Force | Out-Null
    }

    Copy-Item $_.FullName $destination -Force
    $script:copied++
}

if ($copied -eq 0) {
    throw 'No files were staged. Check the .distignore patterns.'
}

# Create zip with forward-slash entry names (required by PHP ZipArchive).
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $stagePrefix = $stageDir + [System.IO.Path]::DirectorySeparatorChar
    Get-ChildItem $stageDir -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($stagePrefix.Length).Replace('\', '/')
        $entryName = "altrelay/$relative"
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $zip.Dispose()
}

$size = [math]::Round((Get-Item $zipPath).Length / 1KB)
Write-Host "Staged $copied files."
Write-Host "Created dist/altrelay.zip ($size KB)"
