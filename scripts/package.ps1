param([Parameter(Mandatory=$true)][string]$OutputZip)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$pluginRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$zipPath = [System.IO.Path]::GetFullPath($OutputZip)
if (Test-Path -LiteralPath $zipPath) { throw "Output already exists: $zipPath" }
$includeFiles = @('trade-crm.php', 'README.fa.md', 'composer.json', 'composer.lock')
$files = @($includeFiles | ForEach-Object { Get-Item -LiteralPath (Join-Path $pluginRoot $_) })
foreach ($directory in @('includes', 'assets', 'vendor', 'docs')) {
    $files += @(Get-ChildItem -LiteralPath (Join-Path $pluginRoot $directory) -File -Recurse)
}
$stream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
$zip = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($pluginRoot.Length + 1).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $file.FullName, 'trade-crm/' + $relative, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally { $zip.Dispose(); $stream.Dispose() }
Get-Item -LiteralPath $zipPath | Select-Object Name, Length
Get-FileHash -LiteralPath $zipPath -Algorithm SHA256 | Select-Object Algorithm, Hash
