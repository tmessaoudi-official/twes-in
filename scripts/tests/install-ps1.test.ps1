# SPDX-License-Identifier: AGPL-3.0-or-later
# The Windows installer (scripts/onprem/install.ps1) as far as it runs off Windows: PowerShell's own parser, PSScriptAnalyzer
# with no finding at any severity, and Add-WslSetting, taken out of the installer through its syntax tree and run over a
# made-up home. Run by `make gate-powershell` in the image infra/powershell/Dockerfile builds.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$installer = Join-Path $PSScriptRoot '../onprem/install.ps1'
$script:pass = 0
$script:fail = 0
function Test-Case([string]$Name, [bool]$Ok, [string]$Detail = '') {
    if ($Ok) { $script:pass++; Write-Output "  ok   $Name"; return }
    $script:fail++
    Write-Output "  FAIL $Name"
    if ($Detail) { Write-Output "       $Detail" }
}
Write-Output 'install.ps1'

$tokens = $null
$errors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($installer, [ref]$tokens, [ref]$errors)
Test-Case 'PowerShell parses it' ($errors.Count -eq 0) (($errors | ForEach-Object { $_.ToString() }) -join '; ')
$findings = @(Invoke-ScriptAnalyzer -Path $installer -Severity Information, Warning, Error)
Test-Case 'PSScriptAnalyzer finds nothing, at any severity' ($findings.Count -eq 0) `
    (($findings | ForEach-Object { "line $($_.Line) $($_.RuleName): $($_.Message)" }) -join '; ')

$definition = $ast.Find({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Add-WslSetting' }, $true)
Test-Case 'it defines Add-WslSetting' ($null -ne $definition)
if ($null -eq $definition) { exit 1 }
# The installer's own Stop-Install exits; here it throws, so a case reads what it would have said.
function Stop-Install([string]$Why) { throw "stopped: $Why" }
. ([scriptblock]::Create($definition.Extent.Text))

$homes = @()
# A home of its own for each case, holding the .wslconfig given (none when $null): what the file holds after is returned.
function Use-Home([string[]]$Lines) {
    $dir = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString())
    New-Item -ItemType Directory -Path $dir | Out-Null
    $script:homes += $dir
    $env:USERPROFILE = $dir
    if ($null -ne $Lines) { Set-Content -Path (Join-Path $dir '.wslconfig') -Value $Lines }
}
function Get-WslConfig { (Get-Content -Path (Join-Path $env:USERPROFILE '.wslconfig')) -join '|' }

Use-Home $null
$idle = Add-WslSetting -Section 'general' -Key 'instanceIdleTimeout' -Value '-1'
$mirrored = Add-WslSetting -Section 'wsl2' -Key 'networkingMode' -Value 'mirrored'
Test-Case 'no .wslconfig: both settings written' ($idle -and $mirrored -and (Get-WslConfig) -eq '[general]|instanceIdleTimeout=-1|[wsl2]|networkingMode=mirrored') (Get-WslConfig)
$again = Add-WslSetting -Section 'wsl2' -Key 'networkingMode' -Value 'mirrored'
Test-Case '  run again: nothing added, so WSL is not stopped' (-not $again -and (Get-WslConfig) -eq '[general]|instanceIdleTimeout=-1|[wsl2]|networkingMode=mirrored') (Get-WslConfig)

Use-Home @('[wsl2]', 'memory=8GB', '', '[experimental]', 'autoMemoryReclaim=gradual')
$added = Add-WslSetting -Section 'wsl2' -Key 'networkingMode' -Value 'mirrored'
Test-Case "the shop's own settings: the line added at the end of its section, every other line kept" `
    ($added -and (Get-WslConfig) -eq '[wsl2]|memory=8GB|networkingMode=mirrored||[experimental]|autoMemoryReclaim=gradual') (Get-WslConfig)

Use-Home @('[WSL2]', 'networkingMode = Mirrored')
$added = Add-WslSetting -Section 'wsl2' -Key 'networkingMode' -Value 'mirrored'
Test-Case '  set already, whatever its case and spaces: left as it is' (-not $added -and (Get-WslConfig) -eq '[WSL2]|networkingMode = Mirrored') (Get-WslConfig)

Use-Home @('[wsl2]', 'networkingMode=nat')
$said = ''
try { Add-WslSetting -Section 'wsl2' -Key 'networkingMode' -Value 'mirrored' | Out-Null } catch { $said = $_.Exception.Message }
Test-Case '  set to another value: stopped, naming it, never overwritten' ($said -like '*sets networkingMode=nat under `[wsl2`]*' -and (Get-WslConfig) -eq '[wsl2]|networkingMode=nat') "$said / $(Get-WslConfig)"

Use-Home @('[experimental]', 'networkingMode=nat', '[wsl2]', '# networkingMode=nat')
$added = Add-WslSetting -Section 'wsl2' -Key 'networkingMode' -Value 'mirrored'
Test-Case '  the same key in another section, or commented out, is not this one' `
    ($added -and (Get-WslConfig) -eq '[experimental]|networkingMode=nat|[wsl2]|# networkingMode=nat|networkingMode=mirrored') (Get-WslConfig)

$homes | ForEach-Object { Remove-Item -Recurse -Force -Path $_ }
Write-Output "install.ps1: $($script:pass) passed, $($script:fail) failed"
if ($script:fail -gt 0) { exit 1 }
