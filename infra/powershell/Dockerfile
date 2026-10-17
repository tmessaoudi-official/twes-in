# syntax=docker/dockerfile:1
# The Windows installer's checks (`make gate-powershell`, scripts/tests/install-ps1.test.ps1): PowerShell's own parser
# and PSScriptAnalyzer. Microsoft ships PowerShell in a container only inside the .NET SDK image now
# (https://learn.microsoft.com/powershell/scripting/install/powershell-in-docker); the PowerShell images still on MCR
# stopped at 7.5.0. Pinned by digest, the .NET 10 LTS image, PowerShell 7.6 (docs/UPDATE.md).
FROM mcr.microsoft.com/dotnet/sdk:10.0-noble@sha256:0d99ebf1ccad15037b5c98c9fe8979d6b8dbac23812feae2277a0b277b5c5ffa

ARG PSSCRIPTANALYZER_VERSION=1.25.0

# Exec form, so no shell reads PowerShell's $env: as its own variable; an error stops the build, and the module must be
# found where the tests look before the image is kept.
RUN ["pwsh", "-NoLogo", "-NoProfile", "-Command", "$ErrorActionPreference = 'Stop'; Set-PSRepository -Name PSGallery -InstallationPolicy Trusted; Install-Module -Name PSScriptAnalyzer -RequiredVersion $env:PSSCRIPTANALYZER_VERSION -Scope AllUsers -Force; if (-not (Get-Module -ListAvailable -Name PSScriptAnalyzer | Where-Object { $_.Version -eq $env:PSSCRIPTANALYZER_VERSION })) { throw 'PSScriptAnalyzer is not where PowerShell looks' }"]
