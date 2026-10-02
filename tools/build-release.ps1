param(
    [string] $OutputDirectory = (Join-Path $PSScriptRoot "..\dist")
)

$ErrorActionPreference = "Stop"
$pluginRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$resolvedOutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
$outputPath = Join-Path $resolvedOutputDirectory "vyompress-boost-0.1.0.zip"
$archiveRoot = "vyompress-boost"

New-Item -ItemType Directory -Force -Path $resolvedOutputDirectory | Out-Null

if (Test-Path -LiteralPath $outputPath) {
    Remove-Item -LiteralPath $outputPath -Force
}

$files = @(
    "vyompress-boost.php",
    "uninstall.php",
    "index.php",
    "readme.txt",
    "LICENSE"
)

$files += Get-ChildItem -Path (Join-Path $pluginRoot "src") -File -Recurse |
    ForEach-Object { [System.IO.Path]::GetRelativePath($pluginRoot, $_.FullName) }
$files += Get-ChildItem -Path (Join-Path $pluginRoot "assets") -File -Recurse |
    ForEach-Object { [System.IO.Path]::GetRelativePath($pluginRoot, $_.FullName) }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stream = [System.IO.File]::Open($outputPath, [System.IO.FileMode]::CreateNew)

try {
    $archive = [System.IO.Compression.ZipArchive]::new(
        $stream,
        [System.IO.Compression.ZipArchiveMode]::Create,
        $false
    )

    try {
        foreach ($relativePath in $files) {
            $sourcePath = Join-Path $pluginRoot $relativePath
            $entryPath = "$archiveRoot/$($relativePath.Replace('\', '/'))"
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $sourcePath,
                $entryPath,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    }
    finally {
        $archive.Dispose()
    }
}
finally {
    $stream.Dispose()
}

Write-Output $outputPath
