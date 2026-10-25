# SPDX-License-Identifier: AGPL-3.0-or-later
<#
.SYNOPSIS
  Installs twes-in on a shop's Windows computer (docs/START.md section 16).

.DESCRIPTION
  Run it from the shop's own Windows account, the one signed in every morning, in PowerShell opened as administrator:

    powershell -ExecutionPolicy Bypass -File install.ps1

  Docker runs one of two ways, chosen at the shop with -Engine:
    Desktop (the default): Docker Desktop, which runs only once the shop's account has signed in, so nothing is up
      before someone signs in; Windows' tasks start twes at each sign-in and run twes tick every quarter hour.
    Wsl: Docker Engine inside Ubuntu, no Docker Desktop. systemd in Ubuntu starts Docker, twes start and twes tick; a
      Windows task keeps WSL running from Windows' start, before anyone signs in, and starts it again within a quarter
      hour of any stop; WSL's mirrored network lets phones reach it. Needs Windows 11 22H2 or later.

  Each run does what is still missing and skips what is done, so it is run again after each step it asks for:
    1. WSL and its Ubuntu (a restart of Windows may be asked, then Ubuntu opened once to make its user);
    2. Desktop: Docker Desktop (started once by hand, its terms accepted, "Start Docker Desktop when you sign in" ticked);
    3. git, gpg, curl and flock inside Ubuntu;
    4. the checkout, in Ubuntu's own home (never under /mnt/c), at the newest release;
       Wsl: then WSL's settings (.wslconfig) and Docker Engine, through the checkout's scripts/onprem/wsl-engine.sh;
    5. scripts/onprem/twes install there: the shop's facts, the release key once its fingerprint is confirmed, the
       release, the operator (Wsl: and systemd's units, sudo asking Ubuntu's password);
    6. on the Windows side: the installation's certificate authority trusted, the scheduled tasks under \twes-in\
       (Desktop: start at each sign-in and tick every quarter hour; Wsl: keep, and WSL's firewall letting the shop
       network in on ports 80 and 443), and a twes-in shortcut on the desktop.

  The scheduled tasks run wsl.exe, which opens a console window for the moment it runs. -HideWindows runs them through
  conhost.exe --headless instead, which shows none; that switch is not documented by Microsoft, so it is tried on the
  computer before it is kept (docs/START.md section 16). VBScript wrappers are not used: Windows is retiring VBScript.

.PARAMETER Engine
  Desktop (the default) or Wsl, as above.

.PARAMETER Distribution
  The WSL distribution that holds the checkout. Ubuntu by default.

.PARAMETER Repository
  Where the checkout comes from.

.PARAMETER HideWindows
  Runs the scheduled tasks through conhost.exe --headless, so no console window shows.

.PARAMETER ReleaseKey
  The fingerprint of the developer's release key, which the release checked out must be signed by before anything from
  it runs. The developer's own by default; this file is what carries it, so it comes from the developer, not the checkout.
#>
# Write-Host is how an installer run by hand talks to the person running it; Stop-Install and New-TwesAction change
# nothing that -WhatIf could hold back, and Add-WslSetting only adds a line that is missing. The file stays ASCII: Windows PowerShell 5.1 reads a file without a byte-order
# mark in the computer's ANSI code page.
[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSAvoidUsingWriteHost', '', Justification = 'an interactive installer')]
[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSUseShouldProcessForStateChangingFunctions', '', Justification = 'no state changed')]
[CmdletBinding()]
param(
    [ValidateSet('Desktop', 'Wsl')]
    [string]$Engine = 'Desktop',
    [string]$Distribution = 'Ubuntu',
    [string]$Repository = 'https://github.com/tmessaoudi-official/twes-in.git',
    [switch]$HideWindows,
    [ValidatePattern('^[0-9A-F]{40}$')]
    [string]$ReleaseKey = '02014686996009C741A92ADF0C537690706EB7F5'
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# wsl.exe writes UTF-16 unless told otherwise, which PowerShell would read as text full of NUL characters.
$env:WSL_UTF8 = '1'

# Where the installer stands, so a step of minutes is not taken for a hang: each section says its place, what it does,
# how long it usually takes and what comes next, and the one before says its time once it ends. Section 2 is Docker
# Desktop's and 4b Docker Engine's, so either engine counts six. Every figure is "usually", never a promise.
if ($Engine -eq 'Desktop') {
    $script:steps = @(
        @{ What = "WSL and $Distribution"; Usually = 'a few minutes the first time' }
        @{ What = 'Docker Desktop'; Usually = '' }
        @{ What = "git, gpg, curl and flock in $Distribution"; Usually = '' }
        @{ What = 'the checkout at the newest release, its signature verified'; Usually = '' }
        @{ What = 'twes install: the shop''s facts, the release, the operator'; Usually = '~5-10 min the first time, mostly the images download by the link; the migrations under 1 min' }
        @{ What = 'Windows'' side: the authority trusted, the scheduled tasks, the shortcut'; Usually = '' }
    )
} else {
    $script:steps = @(
        @{ What = "WSL and $Distribution"; Usually = 'a few minutes the first time' }
        @{ What = "git, gpg, curl and flock in $Distribution"; Usually = '' }
        @{ What = 'the checkout at the newest release, its signature verified'; Usually = '' }
        @{ What = "Docker Engine inside $Distribution"; Usually = '~3-5 min the first time' }
        @{ What = 'twes install: the shop''s facts, the release, the operator, the systemd units'; Usually = '~5-10 min the first time, mostly the images download by the link; the migrations under 1 min' }
        @{ What = 'Windows'' side: the authority trusted, the keep task, the firewall rule, the shortcut'; Usually = '' }
    )
}
$script:stepAt = 0
$script:stepClock = $null
function Format-Elapsed([TimeSpan]$Span) {
    $seconds = [int][Math]::Floor($Span.TotalSeconds)
    if ($seconds -lt 60) { return "${seconds}s" }
    return "$([int][Math]::Floor($seconds / 60))m $($seconds % 60)s"
}
function Complete-Step {
    if ($null -eq $script:stepClock) { return }
    $script:stepClock.Stop()
    Write-Host "twes-in: step $($script:stepAt)/$($script:steps.Count) done in $(Format-Elapsed $script:stepClock.Elapsed)"
    $script:stepClock = $null
}
function Write-Step {
    Complete-Step
    $script:stepAt++
    $count = $script:steps.Count
    if ($script:stepAt -gt $count) { return }
    $step = $script:steps[$script:stepAt - 1]
    $line = "twes-in: step $($script:stepAt)/${count}: $($step.What)"
    if ($step.Usually) { $line += " (usually $($step.Usually))" }
    if ($script:stepAt -lt $count) { $line += " - next: $($script:steps[$script:stepAt].What)" } else { $line += ' - the last step' }
    Write-Host $line -ForegroundColor Cyan
    $script:stepClock = [Diagnostics.Stopwatch]::StartNew()
}

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
    # --exec starts bash itself: after -- wsl.exe hands the line to the default shell first, which expanded every $( ) and
    # $t of the command before bash saw it.
    $arguments += @('--cd', '~', '--exec', 'bash', '-c', $Command)
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
Write-Host "twes-in: installing for $user, Docker through $Engine"

# Phones reach Docker inside WSL only through WSL's mirrored network, which Windows 11 22H2 brought.
$build = [int](Get-CimInstance -ClassName Win32_OperatingSystem).BuildNumber
if ($Engine -eq 'Wsl' -and $build -lt 22621) {
    Stop-Install "-Engine Wsl needs Windows 11 22H2 or later (build 22621; this computer is $build): run this with -Engine Desktop instead."
}
# Windows may reserve port ranges for Hyper-V, and a reserved port cannot be published: the edge would never start.
foreach ($line in (& netsh.exe interface ipv4 show excludedportrange protocol=tcp)) {
    if ($line -match '^\s*(\d+)\s+(\d+)') {
        foreach ($port in @(80, 443)) {
            if ($port -ge [int]$Matches[1] -and $port -le [int]$Matches[2]) {
                Stop-Install "Windows reserves ports $($Matches[1]) to $($Matches[2]), port $port among them, which the installation publishes: docs/START.md section 16 says what to do."
            }
        }
    }
}

# --- 1. WSL and Ubuntu ------------------------------------------------------------------------------------------------
Write-Step
& wsl.exe --status *> $null
$distributions = @()
if ($LASTEXITCODE -eq 0) { $distributions = @(& wsl.exe --list --quiet | ForEach-Object { $_.Trim() } | Where-Object { $_ }) }
if ($distributions -notcontains $Distribution) {
    & wsl.exe --install --distribution $Distribution
    # A recent WSL opens the new Ubuntu at once to make its user and returns once its shell closes: go on when it now
    # answers as that user. A restart Windows still needs, or a user never made (root answers), stops here.
    $distributions = @(& wsl.exe --list --quiet 2>$null | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    if ($distributions -notcontains $Distribution -or (Invoke-Ubuntu 'test $(id -u) -ne 0') -ne 0) {
        Stop-Install "WSL and $Distribution are being installed. Restart Windows if asked, open $Distribution once from the Start menu to make its user, then run this again."
    }
    Write-Host "twes-in: $Distribution is installed and has its user; going on"
}
& wsl.exe --set-default $Distribution
if ((Invoke-Ubuntu 'true') -ne 0) { Stop-Install "$Distribution does not start: open it once from the Start menu, then run this again." }
# systemd, instanceIdleTimeout and the mirrored network belong to the WSL from the Microsoft Store, which answers --version.
if ($Engine -eq 'Wsl') {
    & wsl.exe --version *> $null
    if ($LASTEXITCODE -ne 0) { Stop-Install 'this WSL is the one built into Windows: run wsl --update, then run this again.' }
}

# --- 2. Docker Desktop ------------------------------------------------------------------------------------------------
# Under -Engine Wsl, only the check that Docker Desktop is absent: part of step 1's time, no step of its own.
if ($Engine -eq 'Desktop') { Write-Step }
$dockerDesktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
if ($Engine -eq 'Wsl') {
    # Its WSL integration puts its own docker in Ubuntu, which would answer instead of Docker Engine.
    if (Test-Path $dockerDesktop) {
        Stop-Install 'Docker Desktop is installed: -Engine Wsl runs Docker inside Ubuntu instead. Uninstall Docker Desktop (Settings > Apps), or run this with -Engine Desktop.'
    }
} elseif (-not (Test-Path $dockerDesktop)) {
    & winget.exe install --exact --id Docker.DockerDesktop --accept-package-agreements --accept-source-agreements
    Stop-Install 'Docker Desktop is installed: start it once, accept its terms, tick "Start Docker Desktop when you sign in" (Settings > General), then run this again.'
}
if ($Engine -eq 'Desktop' -and (Invoke-Ubuntu 'docker info >/dev/null 2>&1') -ne 0) {
    Stop-Install "Docker Desktop does not answer inside ${Distribution}: start it, and in Settings > Resources > WSL integration switch $Distribution on, then run this again."
}
# Its settings file's name and keys are Docker's own and change between versions: read, said, never written.
if ($Engine -eq 'Desktop') {
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
}

# --- 3. The tools inside Ubuntu ---------------------------------------------------------------------------------------
Write-Step
if ((Invoke-Ubuntu 'command -v git && command -v gpg && command -v curl && command -v flock' ) -ne 0) {
    if ((Invoke-Ubuntu 'apt-get update -q && apt-get install -y -q git gnupg curl util-linux' -AsRoot) -ne 0) {
        Stop-Install "git, gpg, curl and flock could not be installed in ${Distribution}: is the internet reachable?"
    }
}

# --- 4. The checkout, at the newest release ---------------------------------------------------------------------------
Write-Step
if ((Invoke-Ubuntu "test -d ~/twes-in/.git || git clone --quiet '$Repository' ~/twes-in") -ne 0) {
    Stop-Install "the checkout could not be cloned from $Repository into $Distribution's ~/twes-in."
}
# The installer runs released code: the checkout moves to the newest release tag, whose signature twes checks from then on.
# A checkout with changes of its own is left as it is, and the check below refuses it.
$atRelease = 'cd ~/twes-in && git fetch --quiet --tags origin && t=$(git tag --list ''v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*'' --sort=-v:refname | head -n 1) && case $t in v*) ;; *) exit 1 ;; esac && { git diff --quiet HEAD || exit 0; git checkout --quiet --detach $t; }'
if ((Invoke-Ubuntu $atRelease) -ne 0) { Stop-Install "no release is published at $Repository yet, or it could not be read." }
# The tag is verified here, against the fingerprint this installer carries, before anything from the checkout runs: twes
# install shows the checkout's own key to confirm, and a forged tag's twes could show any. A tag signed by a key a forged
# checkout carries fails too, since the fingerprint compared is this file's.
$verify = 'cd ~/twes-in || exit 2; git status --porcelain | grep -q . && exit 3; t=$(git describe --exact-match --tags HEAD) || exit 4; g=$(mktemp -d); gpg --homedir $g --batch --quiet --import infra/onprem/release-key.asc; f=$(GNUPGHOME=$g git verify-tag --raw $t 2>&1 | awk ''$2 ~ /^VALIDSIG$/ { print $NF }''); gpgconf --homedir $g --kill all; rm -rf $g; test x$f = xRELEASE_KEY || exit 5'
switch (Invoke-Ubuntu $verify.Replace('RELEASE_KEY', $ReleaseKey)) {
    0 { Write-Host "twes-in: the release checked out is signed by the release key $ReleaseKey" }
    3 { Stop-Install "the checkout ~/twes-in in $Distribution holds changes of its own: nothing from it is run. Remove it (wsl -d $Distribution -- rm -rf ~/twes-in), then run this again." }
    4 { Stop-Install "the checkout ~/twes-in in $Distribution is at no release tag: nothing from it is run." }
    default { Stop-Install "the release in ~/twes-in is not signed by the release key ${ReleaseKey}: nothing from it was run. Tell the developer." }
}

# --- 4b. Docker Engine inside Ubuntu (-Engine Wsl) --------------------------------------------------------------------
# What WSL reads at its start from %UserProfile%\.wslconfig (https://learn.microsoft.com/windows/wsl/wsl-config): a key
# missing is added; one set to another value is the shop's own choice, said and never overwritten. True once a line is added.
function Add-WslSetting([string]$Section, [string]$Key, [string]$Value) {
    $path = Join-Path $env:USERPROFILE '.wslconfig'
    $lines = [Collections.Generic.List[string]]::new()
    if (Test-Path $path) { foreach ($line in (Get-Content -Path $path)) { $lines.Add($line) } }
    $inside = $false
    $header = -1
    $end = $lines.Count
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = $lines[$i].Trim()
        if ($line -match '^\[(.+)\]$') {
            if ($inside) { $end = $i; break }
            $inside = $Matches[1].Trim() -ieq $Section
            if ($inside) { $header = $i }
        } elseif ($inside -and $line -match '^([^=#;]+)=(.*)$' -and $Matches[1].Trim() -ieq $Key) {
            if ($Matches[2].Trim() -ieq $Value) { return $false }
            Stop-Install "$path sets $Key=$($Matches[2].Trim()) under [$Section], where this installation needs $Key=${Value}: change that line, then run this again."
        }
    }
    if ($header -lt 0) {
        $lines.Add("[$Section]")
        $lines.Add("$Key=$Value")
    } else {
        # After the section's last line, before the blank lines that part it from the next.
        while ($end -gt $header + 1 -and -not $lines[$end - 1].Trim()) { $end-- }
        $lines.Insert($end, "$Key=$Value")
    }
    Set-Content -Path $path -Value $lines -Encoding ASCII
    return $true
}
if ($Engine -eq 'Wsl') {
    Write-Step
    # Never stopped for being idle: what runs inside is the shop's till. Mirrored: Ubuntu shares Windows' addresses, so a
    # phone reaches the edge at this computer's own address.
    $idle = Add-WslSetting -Section 'general' -Key 'instanceIdleTimeout' -Value '-1'
    $mirrored = Add-WslSetting -Section 'wsl2' -Key 'networkingMode' -Value 'mirrored'
    if ($idle -or $mirrored) {
        Write-Host 'twes-in: WSL settings written to .wslconfig; WSL starts again to read them'
        & wsl.exe --shutdown
    }
    $account = (& wsl.exe -d $Distribution -- id -un | Out-String).Trim()
    if ($account -cnotmatch '^[a-z_][a-z0-9_-]*$') { Stop-Install "$Distribution's account could not be read (got '$account')." }
    $engineScript = "bash ~$account/twes-in/scripts/onprem/wsl-engine.sh $account"
    $code = Invoke-Ubuntu $engineScript -AsRoot
    # 10: systemd was just switched on in /etc/wsl.conf, which WSL reads at its start.
    if ($code -eq 10) {
        & wsl.exe --shutdown
        $code = Invoke-Ubuntu $engineScript -AsRoot
    }
    if ($code -ne 0) { Stop-Install "Docker Engine could not be installed in ${Distribution}: read the lines above, then run this again." }
    # The docker group is read when Ubuntu starts a session: a WSL started before the account joined it starts again.
    if ((Invoke-Ubuntu 'docker info >/dev/null 2>&1') -ne 0) {
        & wsl.exe --terminate $Distribution
        if ((Invoke-Ubuntu 'docker info >/dev/null') -ne 0) { Stop-Install "Docker Engine does not answer $account in ${Distribution}: read the line above." }
    }
}

# --- 5. The installation itself ---------------------------------------------------------------------------------------
# It asks its questions on this console, so its output is not taken through PowerShell's pipeline. It counts its own
# steps, [1/4] and on, inside this one.
Write-Step
& wsl.exe -d $Distribution --cd '~' -- bash twes-in/scripts/onprem/twes install
if ($LASTEXITCODE -ne 0) { Stop-Install 'the installation did not finish: read the lines above, then run this again.' }

# --- 6. Windows' side -------------------------------------------------------------------------------------------------
Write-Step
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
# A switch from one engine to the other leaves no task of the first behind.
function Remove-TwesTask([string]$Name) {
    if (Get-ScheduledTask | Where-Object { $_.TaskPath -eq '\twes-in\' -and $_.TaskName -eq $Name }) {
        Unregister-ScheduledTask -TaskPath '\twes-in\' -TaskName $Name -Confirm:$false
    }
}
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)
$atSignIn = New-ScheduledTaskTrigger -AtLogOn -User $user
# Microsoft documents no meaning for a repetition left without a duration: ten years is written out, and a run of this
# installer starts them again.
$quarterly = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 15) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
if ($Engine -eq 'Desktop') {
    Remove-TwesTask 'keep'
    Register-ScheduledTask -TaskPath '\twes-in\' -TaskName 'start' -Action (New-TwesAction 'start' $HideWindows.IsPresent) -Trigger $atSignIn `
        -Principal $principal -Settings $settings -Description 'twes-in: starts the installation at sign-in' -Force | Out-Null
    Register-ScheduledTask -TaskPath '\twes-in\' -TaskName 'tick' -Action (New-TwesAction 'tick' $HideWindows.IsPresent) -Trigger $quarterly `
        -Principal $principal -Settings $settings -Description 'twes-in: health, backups and updates every quarter hour' -Force | Out-Null
    Write-Host 'twes-in: scheduled \twes-in\start at each sign-in and \twes-in\tick every quarter hour'
} else {
    # systemd in Ubuntu runs twes start and twes tick (twes install wrote its units); this task only keeps WSL running.
    Remove-TwesTask 'start'
    Remove-TwesTask 'tick'
    # From Windows' start with no one signed in (S4U: no password stored), at each sign-in, and every quarter hour, so a
    # WSL stopped by an update or by hand runs again within one; a run while one is running is ignored. No window shows:
    # an S4U task runs outside the desktop.
    $keepAction = New-ScheduledTaskAction -Execute 'wsl.exe' -Argument "-d $Distribution -u root -e sleep infinity"
    $keepPrincipal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Limited
    $keepSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero)
    $keepTriggers = @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -AtLogOn -User $user), $quarterly)
    Register-ScheduledTask -TaskPath '\twes-in\' -TaskName 'keep' -Action $keepAction -Trigger $keepTriggers `
        -Principal $keepPrincipal -Settings $keepSettings -Description 'twes-in: keeps WSL, and the installation inside it, running' -Force | Out-Null
    Start-ScheduledTask -TaskPath '\twes-in\' -TaskName 'keep'
    Write-Host 'twes-in: scheduled \twes-in\keep at the start of Windows, at each sign-in and every quarter hour'
    # WSL's own firewall (Hyper-V's) refuses what comes in from the network unless a rule lets it, as Microsoft's
    # networking page for the mirrored mode shows; the VM creator id is WSL's.
    if (-not (Get-NetFirewallHyperVRule | Where-Object { $_.Name -eq 'twes-in-web' })) {
        New-NetFirewallHyperVRule -Name 'twes-in-web' -DisplayName 'twes-in: the shop network opens HTTP and HTTPS' -Direction Inbound `
            -VMCreatorId '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}' -Protocol TCP -LocalPorts 80, 443 | Out-Null
    }
    Write-Host 'twes-in: WSL lets the shop network in on ports 80 and 443'
}

$shortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'twes-in.url'
Set-Content -Path $shortcut -Value "[InternetShortcut]`r`nURL=https://localhost/`r`n" -Encoding ASCII
Complete-Step
Write-Host "twes-in: done. Open https://localhost here; a phone on the shop's network opens http://<this computer's address>/root.crt once, then the HTTPS address twes status names."
Write-Host "twes-in: twes is typed in a new $Distribution window (twes status), or from PowerShell: wsl -d $Distribution --cd ~ -- bash twes-in/scripts/onprem/twes status"
