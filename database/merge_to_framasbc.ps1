# Merge tpbdb / tpbdelta / tpbfrm3 / tpbplb (MySQL5 :3306)
#   -> framas (staging on MySQL5)
#   -> framasbc (MySQL8 :3307)
#
# cmp_id map:
#   FRM-1  = tpbdb
#   FRM-2  = tpbdelta
#   FRM-3  = tpbfrm3
#   PDPLB  = tpbplb

$ErrorActionPreference = "Continue"
$Mysql5 = "D:\mysql\bin\mysql.exe"
$MysqlDump5 = "D:\mysql\bin\mysqldump.exe"
$Mysql8 = "D:\my8\mysql\bin\mysql.exe"
$LogDir = "D:\xampp\htdocs\inhost-web-id\database\_merge_work"
$LogFile = "D:\xampp\htdocs\inhost-web-id\database\merge_to_framasbc.log"
$DumpFile = Join-Path $LogDir "framas_staging.sql"
$bt = [char]96

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$msg) {
    $line = "{0} {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $msg
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    Write-Host $line
}

function Invoke-Mysql5([string]$inputSql) {
    $tmp = Join-Path $LogDir ("_q5_{0}.sql" -f ([guid]::NewGuid().ToString("N").Substring(0, 8)))
    $stdoutPath = Join-Path $LogDir ("_o5_{0}.txt" -f ([guid]::NewGuid().ToString("N").Substring(0, 8)))
    $stderrPath = Join-Path $LogDir ("_e5_{0}.txt" -f ([guid]::NewGuid().ToString("N").Substring(0, 8)))
    try {
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($tmp, $inputSql, $utf8NoBom)
        cmd.exe /c "`"$Mysql5`" --host=127.0.0.1 --port=3306 -ubeacukai --password=beacukai --default-character-set=utf8mb4 < `"$tmp`" > `"$stdoutPath`" 2> `"$stderrPath`""
        $exitCode = $LASTEXITCODE
        $out = ""; $err = ""
        if (Test-Path $stdoutPath) { $out = Get-Content $stdoutPath -Raw -ErrorAction SilentlyContinue }
        if (Test-Path $stderrPath) { $err = Get-Content $stderrPath -Raw -ErrorAction SilentlyContinue }
        if ($exitCode -ne 0) { throw ("MySQL5 exit {0}: {1}{2}" -f $exitCode, $out, $err) }
        return $out
    } finally {
        Remove-Item $tmp, $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-Mysql8([string]$inputSql) {
    $tmp = Join-Path $LogDir ("_q8_{0}.sql" -f ([guid]::NewGuid().ToString("N").Substring(0, 8)))
    $stdoutPath = Join-Path $LogDir ("_o8_{0}.txt" -f ([guid]::NewGuid().ToString("N").Substring(0, 8)))
    $stderrPath = Join-Path $LogDir ("_e8_{0}.txt" -f ([guid]::NewGuid().ToString("N").Substring(0, 8)))
    try {
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($tmp, $inputSql, $utf8NoBom)
        cmd.exe /c "`"$Mysql8`" --host=127.0.0.1 --port=3307 -ubeacukai --password=beacukai --default-character-set=utf8mb4 < `"$tmp`" > `"$stdoutPath`" 2> `"$stderrPath`""
        $exitCode = $LASTEXITCODE
        $out = ""; $err = ""
        if (Test-Path $stdoutPath) { $out = Get-Content $stdoutPath -Raw -ErrorAction SilentlyContinue }
        if (Test-Path $stderrPath) { $err = Get-Content $stderrPath -Raw -ErrorAction SilentlyContinue }
        if ($exitCode -ne 0) { throw ("MySQL8 exit {0}: {1}{2}" -f $exitCode, $out, $err) }
        return $out
    } finally {
        Remove-Item $tmp, $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}

function Get-Scalar5([string]$sql) {
    $out = Invoke-Mysql5 $sql
    return (($out | Out-String).Trim() -split "`r?`n" | Where-Object { $_ -and $_ -notmatch 'Warning:' } | Select-Object -Last 1)
}

function Table-Exists5([string]$schema, [string]$table) {
    $c = Get-Scalar5 "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$schema' AND table_name='$table';"
    return ($c -eq "1")
}

function Get-Columns5([string]$schema, [string]$table) {
    $raw = Get-Scalar5 "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') FROM information_schema.columns WHERE table_schema='$schema' AND table_name='$table';"
    if ([string]::IsNullOrWhiteSpace($raw)) { return @() }
    return @($raw.Split(",") | Where-Object { $_ })
}

function Get-AutoCol5([string]$schema, [string]$table) {
    return (Get-Scalar5 "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='$schema' AND table_name='$table' AND EXTRA LIKE '%auto_increment%' LIMIT 1;")
}

function Get-PkCols5([string]$schema, [string]$table) {
    $raw = Get-Scalar5 "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.statistics WHERE table_schema='$schema' AND table_name='$table' AND INDEX_NAME='PRIMARY';"
    if ([string]::IsNullOrWhiteSpace($raw)) { return @() }
    return @($raw.Split(",") | Where-Object { $_ })
}

$Tenants = @(
    @{
        Src = "tpbdb"; CmpId = "FRM-1"
        Name = "PT FRAMAS INDONESIA (PLANT 1 / MM2100)"
        Roles = "tpb"; Subdomain = "frm-1"
        Alamat = "KAWASAN INDUSTRI MM 2100 BLOK M 33-34, KELURAHAN GANDAMEKAR, KECAMATAN CIKARANG BARAT, KAB. BEKASI, JAWA BARAT"
        Npwp = "010816064431000"
    },
    @{
        Src = "tpbdelta"; CmpId = "FRM-2"
        Name = "PT FRAMAS INDONESIA (PLANT 2 / KENARI DS5)"
        Roles = "tpb"; Subdomain = "frm-2"
        Alamat = "JL. KENARI RAYA BLOK G2, KAVLING 08B-09A DELTA SILICON 5, KAWASAN INDUSTRI LIPPO CIKARANG, KEL. CICAU, KEC. CIKARANG PUSAT, KAB. BEKASI, JAWA BARAT"
        Npwp = "010816064431000"
    },
    @{
        Src = "tpbfrm3"; CmpId = "FRM-3"
        Name = "PT FRAMAS INDONESIA (PLANT 3 / DS6)"
        Roles = "tpb"; Subdomain = "frm-3"
        Alamat = "JL RAMIN BLOK G6A, DELTA SILICON VI, KAWASAN INDUSTRI LIPPO CIKARANG, DESA/KELURAHAN JAYAMUKTI, KEC. CIKARANG PUSAT, KAB. BEKASI, JAWA BARAT"
        Npwp = "010816064431000"
    },
    @{
        Src = "tpbplb"; CmpId = "PDPLB"
        Name = "PT FRAMAS INDONESIA (PLB / PDPLB)"
        Roles = "plb"; Subdomain = "pdplb"
        Alamat = "KAWASAN INDUSTRI MM2100, BLOK O-1-2, KEL. GANDAMEKAR, KEC. CIKARANG BARAT, KAB. BEKASI, JAWA BARAT"
        Npwp = "010816064431000"
    }
)

$SharedTables = @(
    "bc_ceisa_reff",
    "bc_ceisa_reff_dok",
    "bc_ceisa_reffkolom",
    "bc_ceisa_varheader",
    "bc_ceisa_varkolom",
    "bc_tipe",
    "bc_type",
    "bc_dokumen",
    "bc_dept",
    "bc_color",
    "bc_filter",
    "bc_ceisa_versi",
    "bc_ceisa_setting_desimal",
    "bc_ceisa_setting_kolom",
    "bc_ceisa_setting_tab"
)

$TenantTablesPrefer = @(
    "bc_ceisa_header","bc_ceisa_barang","bc_ceisa_barangtarif","bc_ceisa_bahanbaku","bc_ceisa_bahanbakutarif",
    "bc_ceisa_dokumen","bc_ceisa_entitas","bc_ceisa_pengangkut","bc_ceisa_kemasan","bc_ceisa_kontainer",
    "bc_ceisa_pungutan","bc_ceisa_jaminan","bc_ceisa_bankdevisa","bc_ceisa_kesiapanbarang","bc_ceisa_barangbom",
    "bc_ceisa_bom","bc_ceisa_status","bc_ceisa_respons","bc_ceisa_respon_logs","bc_ceisa_aju_posted",
    "bc_ceisa_json","bc_ceisa_setaju","bc_ceisa_setingaju","bc_ceisa_scan27","bc_ceisa_req_unpost",
    "bc_ceisa_tracebb","bc_ceisa_atur_seri","bc_ceisa_wh_trn","bc_ceisa_nib","bc_ceisa_nib2","bc_ceisa_oto",
    "bc_ceisa_bs_entitas","bc_ceisa_docidx","bc_ceisa_entidx","bc_ceisa_tarifbm","bc_ceisa_token",
    "bc_ceisa_jaminan_master","bc_ceisa_get_tps","bc_ceisa_get_user","bc_ceisa_get_data","bc_ceisa_get_barang",
    "bc_ceisa_get_dokumen","bc_ceisa_get_entitas","bc_ceisa_get_kemasan","bc_ceisa_get_pdf",
    "bc_material","bc_bs","bc_jaminan","bc_ceisa_data","bc_ceisa_data_compareit",
    "bc_ceisa_barangdokumen","bc_ceisa_barangentitas","bc_ceisa_barangspekkhusus","bc_ceisa_barangvd",
    "bc_ceisa_bahanbakudokumen","bc_ceisa_komponenbiaya","bc_ceisa_manifes_header","bc_ceisa_manifes_bl",
    "bc_ceisa_manifes_bl_dokumen","bc_ceisa_manifes_bl_hs","bc_ceisa_manifes_bl_petikemas",
    "bc_ceisa_manifes_kelompok_pos","bc_ceisa_manifes_lampiran","bc_ceisa_manifes_master_bl",
    "bc_ceisa_manifes_respon_logs","bc_ceisa_setting","bc_ceisa_setting_desimal","bc_ceisa_setting_kolom",
    "bc_ceisa_setting_tab","bc_ceisa_sppb","bc_ceisa_versi","bc_ceisa_odoo_bom","bc_ceisa_odoo_detail",
    "bc_ceisa_odoo_header","bc_ceisa_hs_map","bc_ceisa_get_data_xls","bc_ceisa_get_barang_xls",
    "bc_ceisa_get_dokumen_xls","bc_ceisa_get_entitas_xls","bc_ceisa_get_kemasan_xls",
    "bc_1ab2","bc_1ab","bc_1ab2_revisi","bc_1c","bc_1d",
    "bc_barang","bc_bom","bc_do_dtl","bc_issue_aju","bc_issue_dtl","bc_issue_hdr","bc_issue_hdr2","bc_issue2","bc_issue_dept_req_hdr","bc_issue_dept_req_dtl",
    "bc_material_approval","bc_pi_dtl","bc_po_dtl","bc_si_aju_ceisa","bc_si_dtl","bc_so","bc_so0","bc_wh_trn","tpb_respon","bc_ppkek_masuk_detail","bc_ppkek_masuk_header","bc_ppkek_masuk_kontainer",
    "bc_kek_setting"
)

if (Test-Path $LogFile) { Remove-Item $LogFile -Force }
Log "=== START merge to framasbc (FRM-1/FRM-2/FRM-3/PDPLB) ==="

# ---------- Phase 1: rebuild staging framas on MySQL5 ----------
Log "Phase1: recreate staging DB framas on MySQL5"
Invoke-Mysql5 @"
DROP DATABASE IF EXISTS framas;
CREATE DATABASE framas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON framas.* TO 'beacukai'@'localhost';
GRANT ALL PRIVILEGES ON framas.* TO 'beacukai'@'%';
FLUSH PRIVILEGES;
"@ | Out-Null

Log "Create bc_ceisa_company"
Invoke-Mysql5 @"
USE framas;
CREATE TABLE bc_ceisa_company (
  cmp_id VARCHAR(30) NOT NULL,
  cmp_name VARCHAR(150) DEFAULT NULL,
  logo VARCHAR(255) DEFAULT NULL,
  card_color VARCHAR(120) DEFAULT '#1890ff',
  subdomain VARCHAR(50) DEFAULT NULL,
  app_password VARCHAR(100) DEFAULT NULL,
  npwp VARCHAR(30) DEFAULT NULL,
  npwp16 VARCHAR(30) DEFAULT NULL,
  nitku VARCHAR(30) DEFAULT NULL,
  nib VARCHAR(50) DEFAULT NULL,
  alamat TEXT,
  kode_kantor VARCHAR(15) DEFAULT NULL,
  kode_jenis_tpb VARCHAR(15) DEFAULT NULL,
  nomor_skep VARCHAR(100) DEFAULT NULL,
  tanggal_skep DATE DEFAULT NULL,
  username_ceisa VARCHAR(50) DEFAULT NULL,
  password_ceisa VARCHAR(100) DEFAULT NULL,
  secret_mfa VARCHAR(100) DEFAULT NULL,
  secret_mfa_insw VARCHAR(64) DEFAULT NULL,
  id_user_ceisa VARCHAR(50) DEFAULT NULL,
  is_final TINYINT(1) NOT NULL DEFAULT 0,
  token_ceisa TEXT,
  api_key_ceisa VARCHAR(255) DEFAULT NULL,
  insw_client_id VARCHAR(100) DEFAULT NULL,
  insw_client_secret VARCHAR(100) DEFAULT NULL,
  insw_token TEXT,
  insw_api_url VARCHAR(255) DEFAULT NULL,
  ERP_Name VARCHAR(50) DEFAULT NULL,
  ERP_User VARCHAR(100) DEFAULT NULL,
  ERP_Password VARCHAR(100) DEFAULT NULL,
  ERP_Constring TEXT,
  AI_Name VARCHAR(50) DEFAULT NULL,
  AI_API_Key VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  IS_INV TINYINT(1) NOT NULL DEFAULT 0,
  company_roles VARCHAR(150) NOT NULL DEFAULT 'tpb',
  business_type VARCHAR(50) DEFAULT 'MANUFACTURING',
  allowed_docs VARCHAR(255) DEFAULT NULL,
  allow_lowercase_code TINYINT(1) NOT NULL DEFAULT 0,
  alert_it_inv_unposted TINYINT(1) NOT NULL DEFAULT 0,
  show_unimported_h2h TINYINT(1) NOT NULL DEFAULT 0,
  db_mode VARCHAR(20) NOT NULL DEFAULT 'global',
  db_name VARCHAR(50) DEFAULT NULL,
  app_mode VARCHAR(20) NOT NULL DEFAULT 'standar',
  use_tabs TINYINT(1) NOT NULL DEFAULT 1,
  db_host VARCHAR(100) DEFAULT NULL,
  db_port INT DEFAULT NULL,
  db_user VARCHAR(50) DEFAULT NULL,
  db_pass VARCHAR(100) DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (cmp_id),
  UNIQUE KEY uk_subdomain (subdomain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"@ | Out-Null

foreach ($t in $Tenants) {
    $name = $t.Name.Replace("'", "''")
    $alamat = $t.Alamat.Replace("'", "''")
    $allowed = if ($t.Roles -eq "plb") { "16,28,33,331" } else { "23,25,261,262,27,40,41" }
    $color = switch ($t.CmpId) {
        "FRM-1" { "linear-gradient(135deg, #1677ff 0%, #0958d9 100%)" }
        "FRM-2" { "linear-gradient(135deg, #059669 0%, #047857 100%)" }
        "FRM-3" { "linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)" }
        "PDPLB" { "linear-gradient(135deg, #ea580c 0%, #c2410c 100%)" }
        default { "linear-gradient(135deg, #1677ff 0%, #0958d9 100%)" }
    }
    Invoke-Mysql5 @"
INSERT INTO framas.bc_ceisa_company
  (cmp_id, cmp_name, card_color, subdomain, app_password, npwp, alamat, kode_kantor, company_roles, allowed_docs, ERP_Name, username_ceisa, password_ceisa, secret_mfa, id_user_ceisa, token_ceisa, is_active, IS_INV, created_at, updated_at)
VALUES
  ('$($t.CmpId)', '$name', '$color', '$($t.Subdomain)', '1', '$($t.Npwp)', '$alamat', '050900', '$($t.Roles)', '$allowed', 'FRAMAS', 'maikelwawoh', 'Framas2007', 'MNTDETJSOBXDG4LMMZJES2CDK5CFGR2E', '1b6fdb63-0b91-4c3e-bd42-27b3cd9fe604', '1322604451', 1, 1, NOW(), NOW());
"@ | Out-Null
    Log "Seed company $($t.CmpId) <= $($t.Src)"
}

if (Table-Exists5 "framasbc" "bc_ceisa_company") {
    Invoke-Mysql5 @"
UPDATE framas.bc_ceisa_company d
JOIN framasbc.bc_ceisa_company s ON d.cmp_id = s.cmp_id
SET 
  d.card_color     = IFNULL(s.card_color, d.card_color),
  d.ERP_Name       = IFNULL(s.ERP_Name, 'FRAMAS'),
  d.username_ceisa = IFNULL(s.username_ceisa, d.username_ceisa),
  d.password_ceisa = IFNULL(s.password_ceisa, d.password_ceisa),
  d.secret_mfa     = IFNULL(s.secret_mfa, d.secret_mfa),
  d.id_user_ceisa  = IFNULL(s.id_user_ceisa, d.id_user_ceisa),
  d.token_ceisa    = IFNULL(s.token_ceisa, d.token_ceisa),
  d.allowed_docs   = IFNULL(s.allowed_docs, d.allowed_docs),
  d.show_unimported_h2h = IFNULL(s.show_unimported_h2h, d.show_unimported_h2h),
  d.app_password   = IFNULL(s.app_password, d.app_password);
"@ | Out-Null
    Log "Preserved company credentials from framasbc"
}

foreach ($tbl in $SharedTables) {
    $src = $null
    foreach ($cand in @("tpbfrm3", "tpbplb", "tpbdb", "tpbdelta", "framasbc")) {
        if (Table-Exists5 $cand $tbl) { $src = $cand; break }
    }
    if (-not $src) { Log "SKIP shared missing: $tbl"; continue }
    $rows = Get-Scalar5 "SELECT COUNT(*) FROM ${src}.${bt}${tbl}${bt};"
    Log "Shared $tbl <= $src ($rows rows)"
    Invoke-Mysql5 "DROP TABLE IF EXISTS framas.${bt}${tbl}${bt}; CREATE TABLE framas.${bt}${tbl}${bt} LIKE ${src}.${bt}${tbl}${bt}; INSERT INTO framas.${bt}${tbl}${bt} SELECT * FROM ${src}.${bt}${tbl}${bt};" | Out-Null
}

$AllTenantTables = New-Object System.Collections.Generic.List[string]
foreach ($name in $TenantTablesPrefer) { [void]$AllTenantTables.Add($name) }

$extraOut = Invoke-Mysql5 "SELECT table_name FROM information_schema.tables WHERE table_schema='tpbdb' AND table_name LIKE 'bc\_ceisa%' AND table_name NOT LIKE '%\_tmp' AND table_name NOT LIKE '%bak%' AND table_name NOT LIKE '%backup%' AND table_name NOT LIKE '%deleted%' ORDER BY table_name;"
foreach ($e in ($extraOut -split "`r?`n")) {
    $tn = ("$e").Trim()
    if (-not $tn) { continue }
    if ($tn -match 'Warning:') { continue }
    if ($SharedTables -contains $tn) { continue }
    if ($AllTenantTables -contains $tn) { continue }
    if ($tn -eq "bc_ceisa_login" -or $tn -eq "bc_ceisa_company") { continue }
    [void]$AllTenantTables.Add($tn)
}
Log ("Tenant tables planned: {0}" -f $AllTenantTables.Count)

foreach ($tbl in $AllTenantTables) {
    $schemaSrc = $null
    foreach ($cand in @("tpbdb", "tpbfrm3", "tpbplb", "tpbdelta", "framasbc")) {
        if (Table-Exists5 $cand $tbl) { $schemaSrc = $cand; break }
    }
    if (-not $schemaSrc) { Log "SKIP no source schema: $tbl"; continue }

    Log "==== $tbl (schema from $schemaSrc) ===="
    Invoke-Mysql5 "DROP TABLE IF EXISTS framas.${bt}${tbl}${bt}; CREATE TABLE framas.${bt}${tbl}${bt} LIKE ${schemaSrc}.${bt}${tbl}${bt};" | Out-Null
    try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null } catch {}

    $cols = Get-Columns5 "framas" $tbl
    if ($cols -notcontains "cmp_id") {
        Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT '' FIRST;" | Out-Null
        $cols = @("cmp_id") + $cols
    }

    $autoCol = Get-AutoCol5 "framas" $tbl
    $pkCols = Get-PkCols5 "framas" $tbl

    if ($tbl -eq "bc_1ab2") {
        try {
            Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} DROP PRIMARY KEY, ADD PRIMARY KEY (cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI(100), KODE_BARANG, URAIAN(50), QTY, SERI_BARANG);" | Out-Null
            Log "  PK => (cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI(100), KODE_BARANG, URAIAN(50), QTY, SERI_BARANG)"
        } catch {
            Log "  WARN bc_1ab2 PK change failed: $($_.Exception.Message)"
        }
    } elseif ($pkCols.Count -gt 0 -and ($pkCols -notcontains "cmp_id") -and [string]::IsNullOrWhiteSpace($autoCol)) {
        $newPk = @("cmp_id") + $pkCols
        $pkList = ($newPk | ForEach-Object { "${bt}${_}${bt}" }) -join ", "
        try {
            Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} DROP PRIMARY KEY, ADD PRIMARY KEY ($pkList);" | Out-Null
            Log "  PK => ($($newPk -join ', '))"
        } catch {
            Log "  WARN PK change failed: $($_.Exception.Message)"
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_cmp_id (cmp_id);" | Out-Null } catch {}
        }
        if ($cols -contains "nomor_aju") {
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_nomor_aju (nomor_aju);" | Out-Null } catch {}
        }
        if ($cols -contains "nomorAju") {
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_nomor_aju (nomorAju);" | Out-Null } catch {}
        }
    } else {
        try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_cmp_id (cmp_id);" | Out-Null } catch {}
        if ($cols -contains "nomor_aju") {
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_nomor_aju (nomor_aju);" | Out-Null } catch {}
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_cmp_aju (cmp_id, nomor_aju);" | Out-Null } catch {}
        }
        if ($cols -contains "nomorAju") {
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_nomor_aju (nomorAju);" | Out-Null } catch {}
        }
        if ($tbl -eq "bc_material" -and ($cols -contains "KODE_BARANG")) {
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} DROP PRIMARY KEY;" | Out-Null } catch {}
            try {
                Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD PRIMARY KEY (cmp_id, ${bt}KODE_BARANG${bt});" | Out-Null
                Log "  PK => (cmp_id, KODE_BARANG)"
            } catch {
                try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_cmp_kode (cmp_id, ${bt}KODE_BARANG${bt});" | Out-Null } catch {}
            }
        }
        if ($tbl -eq "bc_bs" -and ($cols -contains "KODE")) {
            try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} DROP PRIMARY KEY;" | Out-Null } catch {}
            try {
                Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD PRIMARY KEY (cmp_id, ${bt}KODE${bt});" | Out-Null
                Log "  PK => (cmp_id, KODE)"
            } catch {
                try { Invoke-Mysql5 "ALTER TABLE framas.${bt}${tbl}${bt} ADD KEY idx_cmp_kode (cmp_id, ${bt}KODE${bt});" | Out-Null } catch {}
            }
        }
    }

    foreach ($t in $Tenants) {
        if (-not (Table-Exists5 $t.Src $tbl)) { continue }
        $srcCols = Get-Columns5 $t.Src $tbl
        $dstCols = @(Get-Columns5 "framas" $tbl | Where-Object { $_ -ne "cmp_id" })
        $common = @()
        foreach ($c in $dstCols) {
            if ($srcCols -contains $c) { $common += $c }
        }
        if (-not [string]::IsNullOrWhiteSpace($autoCol)) {
            $common = @($common | Where-Object { $_ -ne $autoCol })
        }
        if ($common.Count -eq 0) { Log "  SKIP $($t.Src) no common cols"; continue }

        $colList = ($common | ForEach-Object { "${bt}${_}${bt}" }) -join ", "
        $cmp = $t.CmpId
        $ins = "INSERT INTO framas.${bt}${tbl}${bt} (${bt}cmp_id${bt}, $colList) SELECT '$cmp', $colList FROM $($t.Src).${bt}${tbl}${bt};"
        try {
            Invoke-Mysql5 $ins | Out-Null
            $cnt = Get-Scalar5 "SELECT COUNT(*) FROM framas.${bt}${tbl}${bt} WHERE cmp_id='$cmp';"
            Log "  + $($t.Src) => $cmp rows=$cnt"
        } catch {
            Log "  WARN insert $($t.Src): $($_.Exception.Message)"
            try {
                $ins2 = $ins.Replace("INSERT INTO", "INSERT IGNORE INTO")
                Invoke-Mysql5 $ins2 | Out-Null
                $cnt = Get-Scalar5 "SELECT COUNT(*) FROM framas.${bt}${tbl}${bt} WHERE cmp_id='$cmp';"
                Log "  + $($t.Src) INSERT IGNORE => rows=$cnt"
            } catch {
                Log "  FAIL $($t.Src).$tbl"
            }
        }
    }

    if ($tbl -eq "bc_ceisa_sppb" -and (Table-Exists5 "framasbc" "bc_ceisa_sppb")) {
        try {
            Invoke-Mysql5 "CREATE TABLE IF NOT EXISTS framas.bc_ceisa_sppb LIKE framasbc.bc_ceisa_sppb;" | Out-Null
            Invoke-Mysql5 "INSERT IGNORE INTO framas.bc_ceisa_sppb SELECT * FROM framasbc.bc_ceisa_sppb;" | Out-Null
            $cnt = Get-Scalar5 "SELECT COUNT(*) FROM framas.bc_ceisa_sppb;"
            Log "  + preserved framasbc.bc_ceisa_sppb rows=$cnt"
        } catch {}
    }
    if ($tbl -eq "bc_kek_setting" -and (Table-Exists5 "framasbc" "bc_kek_setting")) {
        try {
            Invoke-Mysql5 "CREATE TABLE IF NOT EXISTS framas.bc_kek_setting LIKE framasbc.bc_kek_setting;" | Out-Null
            Invoke-Mysql5 "INSERT IGNORE INTO framas.bc_kek_setting SELECT * FROM framasbc.bc_kek_setting;" | Out-Null
            $cnt = Get-Scalar5 "SELECT COUNT(*) FROM framas.bc_kek_setting;"
            Log "  + preserved framasbc.bc_kek_setting rows=$cnt"
        } catch {}
    }
}

Log "Create bc_ceisa_login"
Invoke-Mysql5 @"
DROP TABLE IF EXISTS framas.bc_ceisa_login;
CREATE TABLE framas.bc_ceisa_login (
  cmp_id VARCHAR(30) NOT NULL,
  user_id VARCHAR(50) NOT NULL,
  nama_lengkap VARCHAR(150) DEFAULT NULL,
  password VARCHAR(100) DEFAULT NULL,
  lvl VARCHAR(10) DEFAULT '1',
  role VARCHAR(50) DEFAULT 'user',
  allowed_menus TEXT,
  allowed_docs VARCHAR(255) DEFAULT '*',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  oto VARCHAR(100) DEFAULT NULL,
  last_cmp_id VARCHAR(30) DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (cmp_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"@ | Out-Null

foreach ($t in $Tenants) {
    if (Table-Exists5 $t.Src "bc_ceisa_login") {
        Invoke-Mysql5 @"
INSERT IGNORE INTO framas.bc_ceisa_login (cmp_id, user_id, nama_lengkap, password, lvl, role, allowed_docs, is_active, oto, created_at, updated_at)
SELECT '$($t.CmpId)', user_id, user_id, password, IFNULL(lvl,'1'),
       CASE WHEN IFNULL(lvl,'1') IN ('0','9') THEN 'admin' ELSE 'user' END,
       '*', 1, oto, NOW(), NOW()
FROM $($t.Src).bc_ceisa_login
WHERE user_id IS NOT NULL AND user_id <> '';
"@ | Out-Null
    }
    Invoke-Mysql5 @"
INSERT IGNORE INTO framas.bc_ceisa_login (cmp_id, user_id, nama_lengkap, password, lvl, role, allowed_docs, is_active, oto, created_at, updated_at)
VALUES ('$($t.CmpId)', 'admin', 'Administrator', '1', '0', 'admin', '*', 1, 'ALL', NOW(), NOW());
"@ | Out-Null
}

Invoke-Mysql5 @"
INSERT INTO framas.bc_ceisa_company (cmp_id, cmp_name, subdomain, app_password, npwp, company_roles, is_active, created_at, updated_at)
VALUES ('SYSTEM', 'SYSTEM SUPERADMIN', 'system', 'K@y010782', '', 'tpb,plb,ppkek,pib,peb,manifest', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE app_password='K@y010782';
INSERT INTO framas.bc_ceisa_login (cmp_id, user_id, nama_lengkap, password, lvl, role, allowed_docs, is_active, oto, created_at, updated_at)
VALUES 
('SYSTEM', 'dp', 'Super Administrator', 'K@y010782', '0', 'superadmin', '*', 1, 'ALL', NOW(), NOW()),
('*', 'dp', 'Super Administrator', 'K@y010782', '0', 'superadmin', '*', 1, 'ALL', NOW(), NOW()),
('SYSTEM', 'admin', 'Super Administrator', 'K@y010782', '0', 'superadmin', '*', 1, 'ALL', NOW(), NOW())
ON DUPLICATE KEY UPDATE password='K@y010782', role='superadmin', allowed_docs='*', is_active=1;
"@ | Out-Null

$sum1 = Invoke-Mysql5 @"
SELECT table_schema, COUNT(*) AS tables, ROUND(SUM(data_length+index_length)/1024/1024,2) AS size_mb
FROM information_schema.tables WHERE table_schema='framas';
SELECT cmp_id, COUNT(*) AS headers FROM framas.bc_ceisa_header GROUP BY cmp_id;
SELECT cmp_id, cmp_name, company_roles, subdomain FROM framas.bc_ceisa_company ORDER BY cmp_id;
"@
Log "Phase1 summary:"
Log $sum1

# ---------- Phase 2: dump staging + replace framasbc on MySQL8 ----------
Log "Phase2: dump framas from MySQL5"
if (Test-Path $DumpFile) { Remove-Item $DumpFile -Force }
$dumpErr = Join-Path $LogDir "dump.err"
cmd.exe /c "`"$MysqlDump5`" --host=127.0.0.1 --port=3306 -ubeacukai --password=beacukai --default-character-set=utf8mb4 --single-transaction --routines=false --triggers --events=false --databases framas > `"$DumpFile`" 2> `"$dumpErr`""
if ($LASTEXITCODE -ne 0) {
    $derr = Get-Content $dumpErr -Raw -ErrorAction SilentlyContinue
    throw "mysqldump failed: $derr"
}
$dumpSize = (Get-Item $DumpFile).Length
Log ("Dump size bytes={0}" -f $dumpSize)

Log "Phase2: empty/replace framasbc on MySQL8"
Invoke-Mysql8 @"
DROP DATABASE IF EXISTS framasbc;
CREATE DATABASE framasbc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON framasbc.* TO 'beacukai'@'localhost';
GRANT ALL PRIVILEGES ON framasbc.* TO 'beacukai'@'%';
FLUSH PRIVILEGES;
"@ | Out-Null

# Rewrite dump: USE framas -> USE framasbc, CREATE DATABASE framas -> framasbc
Log "Phase2: rewrite dump database name to framasbc"
$DumpFile2 = Join-Path $LogDir "framasbc_import.sql"
$reader = [System.IO.StreamReader]::new($DumpFile)
$writer = [System.IO.StreamWriter]::new($DumpFile2, $false, [System.Text.UTF8Encoding]::new($false))
$fromDb1 = 'CREATE DATABASE /*!32312 IF NOT EXISTS*/ ' + $bt + 'framas' + $bt
$toDb1   = 'CREATE DATABASE /*!32312 IF NOT EXISTS*/ ' + $bt + 'framasbc' + $bt
$fromDb2 = 'CREATE DATABASE IF NOT EXISTS ' + $bt + 'framas' + $bt
$toDb2   = 'CREATE DATABASE IF NOT EXISTS ' + $bt + 'framasbc' + $bt
$fromUse = 'USE ' + $bt + 'framas' + $bt
$toUse   = 'USE ' + $bt + 'framasbc' + $bt
while (($line = $reader.ReadLine()) -ne $null) {
    $line = $line.Replace($fromDb1, $toDb1).Replace($fromDb2, $toDb2).Replace($fromUse, $toUse)
    $writer.WriteLine($line)
}
$writer.Close(); $reader.Close()
Log ("Rewritten dump size={0}" -f (Get-Item $DumpFile2).Length)

Log "Phase2: import into MySQL8 framasbc (this can take a while)"
$impErr = Join-Path $LogDir "import.err"
$impOut = Join-Path $LogDir "import.out"
cmd.exe /c "`"$Mysql8`" --host=127.0.0.1 --port=3307 -ubeacukai --password=beacukai --default-character-set=utf8mb4 --max_allowed_packet=512M < `"$DumpFile2`" > `"$impOut`" 2> `"$impErr`""
if ($LASTEXITCODE -ne 0) {
    $ierr = Get-Content $impErr -Raw -ErrorAction SilentlyContinue
    throw "MySQL8 import failed: $ierr"
}
Log "Import done"

# ---------- Phase 3: standardize collations to utf8mb4_unicode_ci ----------
Log "Phase3: standardize collation to utf8mb4_unicode_ci on all tables in framasbc"
Invoke-Mysql8 @"
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = '';
ALTER DATABASE framasbc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
UPDATE framasbc.bc_1ab2 SET TGL_PROSES = NULL WHERE TGL_PROSES = '0000-00-00' OR CAST(TGL_PROSES AS CHAR) = '0000-00-00';
"@ | Out-Null

try {
    Invoke-Mysql8 @"
SET sql_mode = '';
ALTER TABLE framasbc.bc_1ab2 DROP PRIMARY KEY;
ALTER TABLE framasbc.bc_1ab2 ADD PRIMARY KEY (cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI(100), KODE_BARANG, URAIAN(50), QTY, SERI_BARANG);
"@ | Out-Null
} catch {}

$allTblsOut = Invoke-Mysql8 "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='framasbc';"
foreach ($line in ($allTblsOut -split "`r?`n")) {
    $tbl = $line.Trim()
    if (-not $tbl -or $tbl -match 'Warning' -or $tbl -eq 'TABLE_NAME') { continue }
    try {
        Invoke-Mysql8 "SET sql_mode = ''; ALTER TABLE framasbc.${bt}${tbl}${bt} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    } catch {
        Log "  WARN convert $tbl failed: $($_.Exception.Message)"
        try {
            Invoke-Mysql8 "SET sql_mode = ''; ALTER TABLE framasbc.${bt}${tbl}${bt} MODIFY COLUMN cmp_id VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '';" | Out-Null
        } catch {}
    }
}
Invoke-Mysql8 "SET FOREIGN_KEY_CHECKS = 1;" | Out-Null
Log "Phase3: Collation standardization complete."

# ---------- Phase 4: Harmonize multi-plant cmp_id on bc_ceisa_data ----------
Log "Phase4: Harmonize multi-plant cmp_id on bc_ceisa_data (Framas 1-Entitas CEISA)"
Invoke-Mysql8 @"
UPDATE framasbc.bc_ceisa_data SET cmp_id = 'FRM-1' WHERE nomorAju LIKE '%FRM01%' OR nomorAju LIKE '%FRM1%';
UPDATE framasbc.bc_ceisa_data SET cmp_id = 'FRM-2' WHERE nomorAju LIKE '%FRM02%' OR nomorAju LIKE '%FRM2%';
UPDATE framasbc.bc_ceisa_data SET cmp_id = 'FRM-3' WHERE nomorAju LIKE '%FRM03%' OR nomorAju LIKE '%FRM3%';
UPDATE framasbc.bc_ceisa_data SET cmp_id = 'PDPLB' WHERE nomorAju LIKE '%PDPLB%' OR nomorAju LIKE '%PLB%' OR kodeDokumen IN ('16','28','33','331');

UPDATE framasbc.bc_ceisa_data d
JOIN (SELECT NOMOR_AJU, MAX(cmp_id) AS cmp_id FROM framasbc.bc_1ab2 GROUP BY NOMOR_AJU) b ON d.nomorAju = b.NOMOR_AJU
SET d.cmp_id = b.cmp_id
WHERE d.nomorAju NOT LIKE '%FRM%' AND d.nomorAju NOT LIKE '%PLB%' AND d.kodeDokumen NOT IN ('16','28','33','331');

UPDATE framasbc.bc_ceisa_data d
JOIN (SELECT NOMOR_AJU, MAX(cmp_id) AS cmp_id FROM framasbc.bc_ceisa_header GROUP BY NOMOR_AJU) h ON d.nomorAju = h.NOMOR_AJU
SET d.cmp_id = h.cmp_id
WHERE d.nomorAju NOT LIKE '%FRM%' AND d.nomorAju NOT LIKE '%PLB%' AND d.kodeDokumen NOT IN ('16','28','33','331')
  AND d.cmp_id = 'FRM-1';

UPDATE framasbc.bc_ceisa_header h
JOIN framasbc.bc_ceisa_data d ON h.nomor_aju = d.nomorAju
SET 
  h.nomor_daftar = COALESCE(NULLIF(h.nomor_daftar, ''), d.nomorDaftar),
  h.tanggal_daftar = COALESCE(NULLIF(h.tanggal_daftar, ''), DATE_FORMAT(d.tanggalDaftar, '%Y-%m-%d')),
  h.tanggal_pernyataan = COALESCE(NULLIF(h.tanggal_pernyataan, ''), DATE_FORMAT(d.tanggalDaftar, '%Y-%m-%d'));

UPDATE framasbc.bc_ceisa_header h
SET 
  h.tanggal_pernyataan = CONCAT(SUBSTRING(h.nomor_aju, 13, 4), '-', SUBSTRING(h.nomor_aju, 17, 2), '-', SUBSTRING(h.nomor_aju, 19, 2))
WHERE (h.tanggal_pernyataan IS NULL OR h.tanggal_pernyataan = '')
  AND LENGTH(h.nomor_aju) >= 20
  AND SUBSTRING(h.nomor_aju, 13, 4) BETWEEN '2015' AND '2030';

CREATE TABLE IF NOT EXISTS framasbc.bc_ceisa_sppb (
  cmp_id VARCHAR(30) NOT NULL DEFAULT '',
  nomorAju VARCHAR(40) NOT NULL,
  nomorDaftar VARCHAR(255) DEFAULT NULL,
  tanggalDaftar DATE DEFAULT NULL,
  kodeDokumen VARCHAR(255) DEFAULT NULL,
  namaDokumen VARCHAR(255) DEFAULT NULL,
  kodeKantor VARCHAR(255) DEFAULT NULL,
  namaKantorPendek VARCHAR(255) DEFAULT NULL,
  namaEntitas VARCHAR(255) DEFAULT NULL,
  kodeProses VARCHAR(255) DEFAULT NULL,
  kodeEntitas VARCHAR(255) DEFAULT NULL,
  namaProses VARCHAR(255) DEFAULT NULL,
  idPerusahaan VARCHAR(255) DEFAULT NULL,
  namaPerusahaan VARCHAR(255) DEFAULT NULL,
  idPpjk VARCHAR(255) DEFAULT NULL,
  nipPfpd VARCHAR(255) DEFAULT NULL,
  nipPfpb VARCHAR(255) DEFAULT NULL,
  nipAp VARCHAR(255) DEFAULT NULL,
  kodeJalur VARCHAR(255) DEFAULT NULL,
  idRespon VARCHAR(255) DEFAULT NULL,
  namaRespon VARCHAR(255) DEFAULT 'SPPB',
  nomorRespon VARCHAR(255) DEFAULT NULL,
  tanggalRespon DATETIME DEFAULT NULL,
  pdf TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  upd_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (cmp_id, nomorAju),
  KEY idx_nomor_aju (nomorAju)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS framasbc.bc_tipe (
  tipe VARCHAR(30) DEFAULT NULL,
  r INT DEFAULT NULL,
  g INT DEFAULT NULL,
  b INT DEFAULT NULL,
  grp VARCHAR(2) DEFAULT NULL,
  idx INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO framasbc.bc_tipe (tipe, r, g, b, grp, idx)
SELECT 'Finished Goods', 0, 0, 255, 'FG', 2 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE grp='FG')
UNION ALL SELECT 'Raw Materials', 0, 255, 0, 'RM', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE grp='RM')
UNION ALL SELECT 'Work In Progress', 100, 0, 0, 'WI', 3 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE grp='WI')
UNION ALL SELECT 'Product Sample', 100, 100, 0, 'PS', 4 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE grp='PS')
UNION ALL SELECT 'Wastes', 200, 200, 200, 'W', 5 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE grp='W')
UNION ALL SELECT 'Mold', 255, 250, 50, 'CG', 6 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE tipe='Mold')
UNION ALL SELECT 'Machine', 255, 250, 50, 'CG', 7 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE tipe='Machine')
UNION ALL SELECT 'Part Machine', 255, 250, 50, 'CG', 8 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE tipe='Part Machine')
UNION ALL SELECT 'Tools', 255, 250, 50, 'CG', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE tipe='Tools')
UNION ALL SELECT 'Factory Equipment', 255, 250, 50, 'CG', 11 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE tipe='Factory Equipment')
UNION ALL SELECT '-- SEMUA KLAS --', 255, 255, 255, '%', 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM framasbc.bc_tipe WHERE grp='%');

CREATE TABLE IF NOT EXISTS framasbc.bc_kek_setting (
  cmp_id VARCHAR(30) NOT NULL,
  is_kek_active TINYINT(1) NOT NULL DEFAULT 0,
  kek_mode VARCHAR(20) NOT NULL DEFAULT 'dummy',
  insw_key VARCHAR(100) DEFAULT 'pZ66hobzPpXBn2bMHVPTz0wG1pxuWQdo',
  unique_key VARCHAR(255) DEFAULT '',
  unique_key_real VARCHAR(255) DEFAULT '',
  unique_key_dummy VARCHAR(255) DEFAULT 'e655669b83fe553f0bd756966311ecb694df907991293e5f0961d7e8157641e7',
  business_type VARCHAR(50) DEFAULT 'manufaktur',
  saldo_awal_final TINYINT(1) DEFAULT 0,
  saldo_awal_final_at DATETIME DEFAULT NULL,
  last_push_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (cmp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"@ | Out-Null
Log "Phase4: Harmonization complete."

$sum2 = Invoke-Mysql8 @"
SELECT table_schema, COUNT(*) AS tables, ROUND(SUM(data_length+index_length)/1024/1024,2) AS size_mb
FROM information_schema.tables WHERE table_schema='framasbc';
SELECT cmp_id, COUNT(*) AS headers FROM framasbc.bc_ceisa_header GROUP BY cmp_id ORDER BY cmp_id;
SELECT cmp_id, COUNT(*) AS ceisa_portal_docs FROM framasbc.bc_ceisa_data GROUP BY cmp_id ORDER BY cmp_id;
SELECT cmp_id, cmp_name, company_roles, subdomain FROM framasbc.bc_ceisa_company ORDER BY cmp_id;
SELECT cmp_id, COUNT(*) AS users FROM framasbc.bc_ceisa_login GROUP BY cmp_id ORDER BY cmp_id;
"@
Log "Phase2 summary:"
Log $sum2
Log "=== DONE ==="
