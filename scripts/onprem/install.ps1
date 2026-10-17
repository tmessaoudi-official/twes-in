# SPDX-License-Identifier: AGPL-3.0-or-later
<#
.SYNOPSIS
  Installs twes-in on a shop's Windows computer (docs/START.md section 16).

.DESCRIPTION
  Run it from the shop's own Windows account, the one signed in every morning, in PowerShell opened as administrator:

    powershell -ExecutionPolicy Bypass -File install.ps1

  Each run does what is still missing and skips what is done, so it is run again after each step it asks for:
    1. WSL and its Ubuntu (a restart of Windows may be asked, then Ubuntu opened once to make its user);
    2. Docker Desktop (started once by hand, its terms accepted, "Start Docker Desktop when you sign in" ticked);
    3. git, gpg, curl and flock inside Ubuntu;
    4. the checkout, in Ubuntu's own home (never under /mnt/c), at the newest release;
    5. scripts/onprem/twes install there: the shop's facts, the release key once its fingerprint is confirmed, the
       release, the operator;
    6. on the Windows side: the installation's certificate authority trusted, twes start at each sign-in and twes tick
       every quarter hour as scheduled tasks under \twes-in\, and a twes-in shortcut on the desktop.

  The scheduled tasks run wsl.exe, which opens a console window for the moment it runs. -HideWindows runs them through
  conhost.exe --headless instead, which shows none; that switch is not documented by Microsoft, so it is tried on the
  computer before it is kept (docs/START.md section 16). VBScript wrappers are not used: Windows is retiring VBScript.

.PARAMETER Distribution
  The WSL distribution that holds the checkout. Ubuntu by default.

.PARAMETER Repository
  Where the checkout comes from.

.PARAMETER HideWindows
  Runs the scheduled tasks through conhost.exe --headless, so no console window shows.
#>
# Write-Host is how an installer run by hand talks to the person running it; Stop-Install and New-TwesAction change
# nothing that -WhatIf could hold back. The file stays ASCII: Windows PowerShell 5.1 reads a file without a byte-order
# mark in the computer's ANSI code page.
[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSAvoidUsingWriteHost', '', Justification = 'an interactive installer')]
[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSUseShouldProcessForStateChangingFunctions', '', Justification = 'no state changed')]
[CmdletBinding()]
param(
    [string]$Distribution = 'Ubuntu',
    [string]$Repository = 'https://github.com/tmessaoudi-official/twes-in.git',
    [switch]$HideWindows
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# wsl.exe writes UTF-16 unless told otherwise, which PowerShell would read as text full of NUL characters.
$env:WSL_UTF8 = '1'

function Stop-Install([string]$Why) {
    Write-Host "twes-in: $Why" -ForegroundColor Yellow
    exit 1
}
# A command in Ubuntu, its output shown and its exit code returned. Windows PowerShell 5.1 drops the double quotes inside
# an argument it hands a program, so no command sent this way holds one.
function Invoke-Ubuntu([string]$Command, [switch]$AsRoot) {
    if ($Command.Contains('"')) { throw "a command for Ubuntu holds a double quote, which Windows PowerShell would drop: $Command" }
    $arguments = @('-d', $Distribution)
    if ($AsRoot) { $arguments += @('-u', 'root') }
    $arguments += @('--cd', '~', '--', 'bash', '-c', $Command)
    & wsl.exe @arguments | Out-Host
    return $LASTEXITCODE
}

# --- Whose installation ---------------------------------------------------------------------------------------------
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Stop-Install 'open PowerShell as administrator (right click, Run as administrator), from the shop''s own account, then run this again.'
}
# WSL, the checkout and the scheduled tasks belong to one Windows account: an administrator other than the one signed in
# would install them for the wrong person.
$signedIn = (Get-CimInstance -ClassName Win32_ComputerSystem).UserName
if ($signedIn -and $signedIn -ne $identity.Name) {
    Stop-Install "this PowerShell runs as $($identity.Name) while $signedIn is signed in: run it from $signedIn's own account, as administrator."
}
$user = $identity.Name
Write-Host "twes-in: installing for $user"

# --- 1. WSL and Ubuntu ------------------------------------------------------------------------------------------------
& wsl.exe --status *> $null
$distributions = @()
if ($LASTEXITCODE -eq 0) { $distributions = @(& wsl.exe --list --quiet | ForEach-Object { $_.Trim() } | Where-Object { $_ }) }
if ($distributions -notcontains $Distribution) {
    & wsl.exe --install --distribution $Distribution
    Stop-Install "WSL and $Distribution are being installed. Restart Windows if asked, open $Distribution once from the Start menu to make its user, then run this again."
}
& wsl.exe --set-default $Distribution
if ((Invoke-Ubuntu 'true') -ne 0) { Stop-Install "$Distribution does not start: open it once from the Start menu, then run this again." }

# --- 2. Docker Desktop ------------------------------------------------------------------------------------------------
$dockerDesktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
if (-not (Test-Path $dockerDesktop)) {
    & winget.exe install --exact --id Docker.DockerDesktop --accept-package-agreements --accept-source-agreements
    Stop-Install 'Docker Desktop is installed: start it once, accept its terms, tick "Start Docker Desktop when you sign in" (Settings > General), then run this again.'
}
if ((Invoke-Ubuntu 'docker info >/dev/null 2>&1') -ne 0) {
    Stop-Install "Docker Desktop does not answer inside ${Distribution}: start it, and in Settings > Resources > WSL integration switch $Distribution on, then run this again."
}
# Its settings file's name and keys are Docker's own and change between versions: read, said, never written.
$autoStart = $null
foreach ($name in @('settings-store.json', 'settings.json')) {
    $file = Join-Path $env:APPDATA "Docker\$name"
    if (Test-Path $file) {
        $settings = Get-Content -Raw $file | ConvertFrom-Json
        foreach ($key in @('AutoStart', 'autoStart')) {
            if ($settings.PSObject.Properties.Name -contains $key) { $autoStart = $settings.$key }
        }
        if ($null -ne $autoStart) { break }
    }
}
if ($autoStart -ne $true) {
    Write-Host 'twes-in: check Docker Desktop > Settings > General > "Start Docker Desktop when you sign in": twes start waits five minutes for it at each sign-in.' -ForegroundColor Yellow
}

# --- 3. The tools inside Ubuntu ---------------------------------------------------------------------------------------
if ((Invoke-Ubuntu 'command -v git && command -v gpg && command -v curl && command -v flock' ) -ne 0) {
    if ((Invoke-Ubuntu 'apt-get update -q && apt-get install -y -q git gnupg curl util-linux' -AsRoot) -ne 0) {
        Stop-Install "git, gpg, curl and flock could not be installed in ${Distribution}: is the internet reachable?"
    }
}

# --- 4. The checkout, at the newest release ---------------------------------------------------------------------------
if ((Invoke-Ubuntu "test -d ~/twes-in/.git || git clone --quiet '$Repository' ~/twes-in") -ne 0) {
    Stop-Install "the checkout could not be cloned from $Repository into $Distribution's ~/twes-in."
}
# The installer runs released code: the checkout moves to the newest release tag, whose signature twes checks from then on.
# A checkout with changes of its own is left as it is: twes refuses to deploy from one, and says so.
$atRelease = 'cd ~/twes-in && git fetch --quiet --tags origin && t=$(git tag --list ''v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*'' --sort=-v:refname | head -n 1) && case $t in v*) ;; *) exit 1 ;; esac && { git diff --quiet HEAD || exit 0; git checkout --quiet --detach $t; }'
if ((Invoke-Ubuntu $atRelease) -ne 0) { Stop-Install "no release is published at $Repository yet, or it could not be read." }

# --- 5. The installation itself ---------------------------------------------------------------------------------------
# It asks its questions on this console, so its output is not taken through PowerShell's pipeline.
& wsl.exe -d $Distribution --cd '~' -- bash twes-in/scripts/onprem/twes install
if ($LASTEXITCODE -ne 0) { Stop-Install 'the installation did not finish: read the lines above, then run this again.' }

# --- 6. Windows' side -------------------------------------------------------------------------------------------------
# The installation's own authority, so https://localhost opens without a warning on this computer. Edge and Chrome read
# Windows' store; Firefox only once "Allow Firefox to automatically trust third-party root certificates you install" is
# ticked in its settings.
$root = Join-Path $env:TEMP 'twes-in-root.crt'
Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost/root.crt' -OutFile $root
$imported = Import-Certificate -FilePath $root -CertStoreLocation Cert:\LocalMachine\Root
Remove-Item $root
Write-Host "twes-in: this computer trusts the installation's authority ($($imported.Thumbprint))"

# One place says how a task reaches Ubuntu.
function New-TwesAction([string]$Command, [bool]$Hidden) {
    $line = "-d $Distribution --cd ~ -- bash twes-in/scripts/onprem/twes $Command"
    if ($Hidden) { return New-ScheduledTaskAction -Execute 'conhost.exe' -Argument "--headless wsl.exe $line" }
    return New-ScheduledTaskAction -Execute 'wsl.exe' -Argument $line
}
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)
$atSignIn = New-ScheduledTaskTrigger -AtLogOn -User $user
# Microsoft documents no meaning for a repetition left without a duration: ten years is written out, and a run of this
# installer starts them again.
$quarterly = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 15) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
Register-ScheduledTask -TaskPath '\twes-in\' -TaskName 'start' -Action (New-TwesAction 'start' $HideWindows.IsPresent) -Trigger $atSignIn `
    -Principal $principal -Settings $settings -Description 'twes-in: starts the installation at sign-in' -Force | Out-Null
Register-ScheduledTask -TaskPath '\twes-in\' -TaskName 'tick' -Action (New-TwesAction 'tick' $HideWindows.IsPresent) -Trigger $quarterly `
    -Principal $principal -Settings $settings -Description 'twes-in: health, backups and updates every quarter hour' -Force | Out-Null
Write-Host 'twes-in: scheduled \twes-in\start at each sign-in and \twes-in\tick every quarter hour'

$shortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'twes-in.url'
Set-Content -Path $shortcut -Value "[InternetShortcut]`r`nURL=https://localhost/`r`n" -Encoding ASCII
Write-Host "twes-in: done. Open https://localhost here; a phone on the shop's network opens http://<this computer's address>/root.crt once, then the HTTPS address twes status names."
