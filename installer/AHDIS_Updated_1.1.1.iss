; AHDIS 1.1.1 - Clean-release Inno Setup installer
; Build with Build-AHDIS-1.1.1.ps1 after reviewing this script.
; New AppId and install directory intentionally avoid overwriting an existing
; AHDIS-Clean installation. Future updates to this release family should keep
; this AppId and install directory so updating does not erase records.
;
; IMPORTANT: Uninstalling this release permanently deletes its own SQLite data.
; Do not change the [UninstallDelete] targets to {app}\* or shared AppData.

#define AppName "AHD Information System"
#define AppVersion "1.1.1"
#define AppPublisher "Commission on Population and Development - Region X"
#define AppExeName "Adolescent Health and Development Information System.exe"

#ifndef StageDir
  #define StageDir "C:\AHDIS-New-Installer\AHDIS_Stage_1.1.1"
#endif

[Setup]
AppId=CPD.RegionX.AHDIS.CleanRelease
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={localappdata}\Programs\AHDIS-Clean-1.1.1
DefaultGroupName={#AppName} 1.1.1
UsePreviousAppDir=no
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
OutputDir=C:\AHDIS-New-Installer\Output
OutputBaseFilename=AHDIS_Clean_Setup_1.1.1
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
UninstallDisplayIcon={app}\{#AppExeName}
CloseApplications=yes
RestartApplications=no
SetupLogging=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked

[Files]
; Only the sanitized staging directory is packaged. It must not contain app.db,
; dummy profiles or other mutable user data.
Source: "{#StageDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Dirs]
Name: "{app}\www\data"

[Icons]
Name: "{autoprograms}\AHD Information System 1.1.1"; Filename: "{app}\{#AppExeName}"; WorkingDir: "{app}"
Name: "{autodesktop}\AHD Information System 1.1.1"; Filename: "{app}\{#AppExeName}"; WorkingDir: "{app}"; Tasks: desktopicon

[UninstallDelete]
; This version stores all profile/record data under this app-owned directory.
; Never delete unrelated installations, user home folders or shared AppData.
Type: filesandordirs; Name: "{app}\www\data"
; Chromium cache is owned by this installation, not shared with other copies.
Type: filesandordirs; Name: "{app}\webcache"

[Code]
function InitializeUninstall: Boolean;
begin
  { Prevent unattended removal of records without an interactive warning. }
  if UninstallSilent then
  begin
    Result := False;
    Exit;
  end;

  Result := MsgBox(
    'WARNING: Uninstalling AHD Information System 1.1.1 will PERMANENTLY DELETE' + #13#10 +
    'all profiles, imported records and SQLite database files stored in this' + #13#10 +
    'specific installation.' + #13#10 + #13#10 +
    'Export or back up records before continuing.' + #13#10 + #13#10 +
    'Do you want to permanently erase this installation and its records?',
    mbConfirmation, MB_YESNO) = IDYES;
end;