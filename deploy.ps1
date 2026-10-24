<#
.SYNOPSIS
    Dual-Deploy Automation for CEISA 4.0: VPS Bekasi (DCI Cibitung) & cPanel (inhost.id).
    Memastikan UI, fitur, template, dan database logic di kedua server SELALU 100% SINKRON.
#>
param(
    [Parameter(Position=0, ValueFromRemainingArguments=$true)]
    [string[]]$Files,
    [int]$Hours = 24,
    [switch]$All,
    [ValidateSet("All", "Vps", "Cpanel")]
    [string]$Target = "All"
)

$RootDir     = $PSScriptRoot

# 1. Konfigurasi VPS Bekasi (DCI Cibitung)
$VpsHost     = "202.10.35.199"
$VpsPort     = 22
$VpsUser     = "root"
$VpsPass     = "K@y010782"
$VpsPath     = "/var/www/inhost"
$VpsHostKey  = "SHA256:uCOFAdVGHhaGAWzAEPhrz/ZEmNRxEamt8/89vpVENis"
$PscpExe     = "C:\Program Files\PuTTY\pscp.exe"
$PlinkExe    = "C:\Program Files\PuTTY\plink.exe"

# 2. Konfigurasi cPanel Hosting (inhost.id)
$FtpHost     = "inhost.id"
$FtpUser     = "root@inhost.id"
$FtpPass     = "@combro#"

# Helper: FTP Directory creation for cPanel
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
        } catch {}
    }
}

# Helper: Upload to cPanel via FTP
function Upload-Cpanel([string]$localFile, [string]$relName) {
    $remoteName = $relName.Replace("\", "/")
    $lastSlash = $remoteName.LastIndexOf('/')
    if ($lastSlash -gt 0) {
        $parentDir = $remoteName.Substring(0, $lastSlash)
        Ensure-FtpDirectory $parentDir
    }

    $uri = "ftp://" + $FtpHost + "/" + $remoteName
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

# Helper: Upload to VPS Bekasi via PSCP
function Upload-Vps([string]$localFile, [string]$relName) {
    $remoteName = $relName.Replace("\", "/")
    $dest = "$($VpsUser)@$($VpsHost):$($VpsPath)/$remoteName"
    
    # Ensure remote parent directory exists if in subdirectory
    $lastSlash = $remoteName.LastIndexOf('/')
    if ($lastSlash -gt 0) {
        $parentDir = $remoteName.Substring(0, $lastSlash)
        $mkdirCmd = "mkdir -p '$($VpsPath)/$parentDir'"
        & $PlinkExe -batch -hostkey $VpsHostKey -P $VpsPort -pw $VpsPass "$($VpsUser)@$($VpsHost)" $mkdirCmd 2>&1 | Out-Null
    }

    $res = & $PscpExe -batch -hostkey $VpsHostKey -P $VpsPort -pw $VpsPass "$localFile" "$dest" 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "PSCP Error: $res"
    }
}

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " 🚀 CEISA 4.0 - DUAL DEPLOY (VPS BEKASI + CPANEL INHOST.ID)      " -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " Target Mode: $Target" -ForegroundColor Gray

# Deteksi berkas target
$targets = @()

if ($Files -and $Files.Count -gt 0) {
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
} elseif ($All) {
    Write-Host "Mengumpulkan seluruh file proyek untuk sinkronisasi massal..." -ForegroundColor Gray
    $candidates = Get-ChildItem -Path $RootDir -File -Recurse
    foreach ($item in $candidates) {
        $rel = $item.FullName.Substring($RootDir.Length).TrimStart("\", "/")
        if ($rel -notmatch "^(\.git|\.gemini|deploy\.ps1|\.vscode|scratch|backups)" -and $rel -notmatch "\.(log|sql)$") {
            $targets += $item
        }
    }
} else {
    $since = (Get-Date).AddHours(-$Hours)
    Write-Host "Mencari file yang diupdate dalam $Hours jam terakhir..." -ForegroundColor Gray
    $candidates = Get-ChildItem -Path $RootDir -File -Recurse | Where-Object { $_.LastWriteTime -ge $since }
    foreach ($item in $candidates) {
        $rel = $item.FullName.Substring($RootDir.Length).TrimStart("\", "/")
        if ($rel -notmatch "^(\.git|\.gemini|deploy\.ps1|\.vscode|scratch|backups)" -and $rel -notmatch "\.(log|sql)$") {
            $targets += $item
        }
    }
}

if ($targets.Count -eq 0) {
    Write-Host "Tidak ada file yang perlu diunggah." -ForegroundColor Yellow
    exit 0
}

Write-Host "Ditemukan $($targets.Count) file untuk disinkronkan." -ForegroundColor Green
Write-Host "-----------------------------------------------------------------" -ForegroundColor Gray

$vpsOk = 0; $vpsFail = 0
$cpanelOk = 0; $cpanelFail = 0

foreach ($file in $targets) {
    $rel = $file.FullName.Substring($RootDir.Length).TrimStart("\", "/").Replace("\", "/")
    $sizeKb = [Math]::Round($file.Length / 1024, 2)
    Write-Host -NoNewline ("[$rel - $sizeKb KB] ") -ForegroundColor White

    # Upload to VPS Bekasi
    if ($Target -eq "All" -or $Target -eq "Vps") {
        try {
            Upload-Vps -localFile $file.FullName -relName $rel
            Write-Host -NoNewline "VPS: OK | " -ForegroundColor Green
            $vpsOk++
        } catch {
            Write-Host -NoNewline "VPS: GAGAL | " -ForegroundColor Red
            $vpsFail++
        }
    }

    # Upload to cPanel
    if ($Target -eq "All" -or $Target -eq "Cpanel") {
        try {
            Upload-Cpanel -localFile $file.FullName -relName $rel
            Write-Host "cPanel: OK" -ForegroundColor Green
            $cpanelOk++
        } catch {
            Write-Host ("cPanel: GAGAL (" + $_.Exception.Message + ")") -ForegroundColor Red
            $cpanelFail++
        }
    } else {
        Write-Host ""
    }
}

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " Dual Deploy Selesai:" -ForegroundColor Cyan
if ($Target -eq "All" -or $Target -eq "Vps") {
    Write-Host "   -> VPS Bekasi: $vpsOk sukses, $vpsFail gagal." -ForegroundColor $(if ($vpsFail -eq 0) { "Green" } else { "Red" })
}
if ($Target -eq "All" -or $Target -eq "Cpanel") {
    Write-Host "   -> cPanel inhost.id: $cpanelOk sukses, $cpanelFail gagal." -ForegroundColor $(if ($cpanelFail -eq 0) { "Green" } else { "Red" })
}
Write-Host "=================================================================" -ForegroundColor Cyan
