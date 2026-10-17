$WorkingDir = "c:\dp\g-ceisa"
Set-Location $WorkingDir

$git = "C:\Users\DP\AppData\Local\GitHubDesktop\app-3.6.7\resources\app\git\cmd\git.exe"

& $git add -A
& $git commit -m "feat: synchronize dynamic dark theme and compact 10.5px typography across modules"

Add-Type -TypeDefinition @"
using System;
using System.Text;
using System.Runtime.InteropServices;
public class CredReaderPushNow {
    [DllImport("advapi32.dll", EntryPoint = "CredReadW", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern bool CredRead(string target, int type, int reservedFlag, out IntPtr credentialPtr);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct CREDENTIAL {
        public int Flags;
        public int Type;
        public string TargetName;
        public string Comment;
        public long LastWritten;
        public int CredentialBlobSize;
        public IntPtr CredentialBlob;
        public int Persist;
        public int AttributeCount;
        public IntPtr Attributes;
        public string TargetAlias;
        public string UserName;
    }

    public static string ReadUTF8(string target) {
        IntPtr credPtr;
        if (CredRead(target, 1, 0, out credPtr)) {
            CREDENTIAL cred = (CREDENTIAL)Marshal.PtrToStructure(credPtr, typeof(CREDENTIAL));
            byte[] bytes = new byte[cred.CredentialBlobSize];
            Marshal.Copy(cred.CredentialBlob, bytes, 0, cred.CredentialBlobSize);
            return Encoding.UTF8.GetString(bytes);
        }
        return null;
    }
}
"@

$token = [CredReaderPushNow]::ReadUTF8("LegacyGeneric:target=GitHub - https://api.github.com/hermawan-dony")
if (-not $token) {
    Write-Error "Failed to retrieve GitHub credential from Windows Credential Manager."
    exit 1
}

$remoteWithAuth = "https://hermawan-dony:$token@github.com/hermawan-dony/inhost.web.id.git"
& $git remote set-url origin $remoteWithAuth

try {
    $pushOutput = & $git push origin main 2>&1
    Write-Host ($pushOutput -join "`n")
} finally {
    & $git remote set-url origin https://github.com/hermawan-dony/inhost.web.id.git
}

Write-Host "GIT PUSH COMPLETED SUCCESSFULLY!" -ForegroundColor Green
