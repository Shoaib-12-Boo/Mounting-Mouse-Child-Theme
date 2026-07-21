$path = "functions.php"
$lines = [System.IO.File]::ReadAllLines($path, [System.Text.Encoding]::UTF8)
$newLines = @()
foreach ($line in $lines) {
    if ($line -match '^\s*</em>' ) {
        continue
    }
    $newLines += $line
}
[System.IO.File]::WriteAllLines($path, $newLines, [System.Text.Encoding]::UTF8)
