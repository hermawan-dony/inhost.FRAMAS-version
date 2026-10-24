# CEISA 4.0 - Push to GitHub helper using Windows Credential Manager
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
using System.Text;

public class GitCredHelper {
    [DllImport("Advapi32.dll", EntryPoint = "CredReadW", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern bool CredRead(string target, int type, int reservedFlag, out IntPtr credentialPtr);

    [DllImport("Advapi32.dll", EntryPoint = "CredFree", SetLastError = true)]
    public static extern void CredFree(IntPtr cred);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct CREDENTIAL {
        public int Flags;
        public int Type;
        public string TargetName;
        public string Comment;
        public System.Runtime.InteropServices.ComTypes.FILETIME LastWritten;
        public int CredentialBlobSize;
        public IntPtr CredentialBlob;
        public int Persist;
        public int AttributeCount;
        public IntPtr Attributes;
        public string TargetAlias;
        public string UserName;
    }

    public static string[] GetCred(string target) {
        IntPtr credPtr;
        if (CredRead(target, 1, 0, out credPtr)) {
            CREDENTIAL cred = (CREDENTIAL)Marshal.PtrToStructure(credPtr, typeof(CREDENTIAL));
            byte[] blob = new byte[cred.CredentialBlobSize];
            Marshal.Copy(cred.CredentialBlob, blob, 0, cred.CredentialBlobSize);
            CredFree(credPtr);
            string unicode = Encoding.Unicode.GetString(blob);
            return new string[] { cred.UserName, unicode };
        }
        return null;
    }
}
"@

$cred = [GitCredHelper]::GetCred("git:https://github.com")
if (-not $cred) {
    $cred = [GitCredHelper]::GetCred("LegacyGeneric:target=git:https://github.com")
}

if (-not $cred -or -not $cred[1]) {
    Write-Error "Tidak dapat menemukan kredensial GitHub di Windows Credential Manager."
    exit 1
}

$user = $cred[0]
$token = $cred[1].Trim()
$remoteUrl = "https://${user}:${token}@github.com/hermawan-dony/inhost.web.id.git"

Write-Host "Mendorong perubahan (push) ke GitHub repository: https://github.com/hermawan-dony/inhost.web.id..." -ForegroundColor Cyan
git push $remoteUrl main

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Berhasil push ke GitHub (branch: main)!" -ForegroundColor Green
} else {
    Write-Error "Gagal melakukan push ke GitHub. Kode keluar: $LASTEXITCODE"
}
