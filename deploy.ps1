param(
    [Parameter(Position=0, ValueFromRemainingArguments=$true)]
    [string[]]$Files,
    [int]$Hours = 24
)

$FtpHost = "inhost.id"
$FtpUser = "root@inhost.id"
$FtpPass = "@combro#"
$RootDir = $PSScriptRoot

function Ensure-FtpDirectory([string]$remoteDir) {
    if ([string]::IsNullOrWhiteSpace($remoteDir)) { return }
    $parts = $remoteDir.Trim("/").Split("/")
    $curr = ""
    foreach ($p in $parts) {
        if ([string]::IsNullOrWhiteSpace($p)) { continue }
        $curr += "/" + $p
        try {
            $u = "ftp://" + $FtpHost + $curr
            $req = [System.Net.FtpWebRequest]::Create($u)
            $req.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
            $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $req.UsePassive = $true
            $req.KeepAlive = $false
            $req.Timeout = 15000
            $resp = $req.GetResponse()
            $resp.Close()
        } catch {
            # Abaikan jika direktori sudah ada
        }
    }
}

function Upload-File([string]$localFile, [string]$remoteName) {
    # Pastikan subdirektori remote sudah tersedia sebelum upload
    $lastSlash = $remoteName.LastIndexOfAny(@([char]'/', [char]'\'))
    if ($lastSlash -gt 0) {
        $parentDir = $remoteName.Substring(0, $lastSlash).Replace("\", "/")
        Ensure-FtpDirectory $parentDir
    }

    $uri = "ftp://" + $FtpHost + "/" + $remoteName.Replace("\", "/")
    $ftp = [System.Net.FtpWebRequest]::Create($uri)
    $ftp.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
    $ftp.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $ftp.UsePassive = $true
    $ftp.KeepAlive = $false
    $ftp.Timeout = 30000
    
    $bytes = [System.IO.File]::ReadAllBytes($localFile)
    $ftp.ContentLength = $bytes.Length
    $stream = $ftp.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
    
    $resp = $ftp.GetResponse()
    $resp.Close()
}

Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host " 🚀 CEISA 4.0 - Auto Deploy to inhost.id via FTP      " -ForegroundColor Cyan
Write-Host "=====================================================" -ForegroundColor Cyan

$targets = @()

if ($Files -and $Files.Count -gt 0) {
    # Bersihkan koma jika user mengetik dengan koma
    $cleanedFiles = @()
    foreach ($f in $Files) {
        $parts = $f -split ","
        foreach ($p in $parts) {
            $trimmed = $p.Trim()
            if ($trimmed) { $cleanedFiles += $trimmed }
        }
    }
    
    foreach ($f in $cleanedFiles) {
        $p = Join-Path $RootDir $f
        if (Test-Path $p) {
            if ((Get-Item $p) -is [System.IO.DirectoryInfo]) {
                $subFiles = Get-ChildItem -Path $p -File -Recurse
                foreach ($sf in $subFiles) { $targets += $sf }
            } else {
                $targets += (Get-Item $p)
            }
        } else {
            Write-Host "File tidak ditemukan: $f" -ForegroundColor Yellow
        }
    }
} else {
    $since = (Get-Date).AddHours(-$Hours)
    Write-Host "Mencari file yang diupdate dalam $Hours jam terakhir..." -ForegroundColor Gray
    $candidates = Get-ChildItem -Path $RootDir -File -Recurse | Where-Object { $_.LastWriteTime -ge $since }
    foreach ($item in $candidates) {
        $rel = $item.FullName.Substring($RootDir.Length).TrimStart("\", "/")
        if ($rel -notmatch "^(\.git|\.gemini|deploy\.ps1|test_.*\.ps1|\.vscode)" -and $rel -notmatch "\.(log|sql|zip)$") {
            $targets += $item
        }
    }
}

if ($targets.Count -eq 0) {
    Write-Host "Tidak ada file yang perlu diunggah." -ForegroundColor Yellow
    exit 0
}

Write-Host "Ditemukan $($targets.Count) file untuk diunggah:" -ForegroundColor Green

$okCount = 0
$failCount = 0

foreach ($file in $targets) {
    $rel = $file.FullName.Substring($RootDir.Length).TrimStart("\", "/").Replace("\", "/")
    $sizeKb = [Math]::Round($file.Length / 1024, 2)
    $msg = "  -> Uploading " + $rel + " (" + $sizeKb + " KB) ... "
    Write-Host -NoNewline $msg
    
    try {
        Upload-File -localFile $file.FullName -remoteName $rel
        Write-Host "BERHASIL" -ForegroundColor Green
        $okCount++
    } catch {
        Write-Host ("GAGAL: " + $_.Exception.Message) -ForegroundColor Red
        $failCount++
    }
}

Write-Host "-----------------------------------------------------" -ForegroundColor Cyan
Write-Host "Deploy Selesai: $okCount berhasil, $failCount gagal." -ForegroundColor Green
Write-Host "=====================================================" -ForegroundColor Cyan
