param(
    [string]$Domain = 'noble.gt.tc',
    [int]$TimeoutSeconds = 20,
    [switch]$Json
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function New-CheckResult {
    param(
        [string]$Name,
        [string]$Status,
        [string]$Details,
        [string]$Url = ''
    )

    [pscustomobject]@{
        Name    = $Name
        Status  = $Status
        Details = $Details
        Url     = $Url
    }
}

function Format-StatusColor {
    param([string]$Status)

    switch ($Status) {
        'PASS' { return 'Green' }
        'WARN' { return 'Yellow' }
        'FAIL' { return 'Red' }
        default { return 'Gray' }
    }
}

function Add-Result {
    param(
        [System.Collections.Generic.List[object]]$Results,
        [string]$Name,
        [string]$Status,
        [string]$Details,
        [string]$Url = ''
    )

    $Results.Add((New-CheckResult -Name $Name -Status $Status -Details $Details -Url $Url))
}

function Test-TextContainsAny {
    param(
        [string]$Text,
        [string[]]$Needles
    )

    foreach ($needle in $Needles) {
        if ($Text.IndexOf($needle, [System.StringComparison]::OrdinalIgnoreCase) -ge 0) {
            return $true
        }
    }

    return $false
}

function Get-ResponseHeaders {
    param($Response)

    $headers = @{}

    if ($null -eq $Response) {
        return $headers
    }

    foreach ($headerName in $Response.Headers.Keys) {
        $headers[$headerName] = [string]$Response.Headers[$headerName]
    }

    return $headers
}

function Invoke-AuditRequest {
    param(
        [string]$Uri,
        [int]$TimeoutSeconds
    )

    try {
        $response = Invoke-WebRequest -Uri $Uri -MaximumRedirection 5 -TimeoutSec $TimeoutSeconds -ErrorAction Stop
        return [pscustomobject]@{
            Success      = $true
            Uri          = $Uri
            StatusCode   = [int]$response.StatusCode
            Headers      = Get-ResponseHeaders -Response $response
            Body         = [string]$response.Content
            FinalUri     = [string]$response.BaseResponse.ResponseUri.AbsoluteUri
            ErrorMessage = ''
        }
    } catch {
        $webResponse = $_.Exception.Response
        if ($null -ne $webResponse) {
            $reader = $null
            $body = ''
            try {
                $stream = $webResponse.GetResponseStream()
                if ($null -ne $stream) {
                    $reader = New-Object System.IO.StreamReader($stream)
                    $body = $reader.ReadToEnd()
                }
            } catch {
                $body = ''
            } finally {
                if ($null -ne $reader) {
                    $reader.Dispose()
                }
            }

            return [pscustomobject]@{
                Success      = $false
                Uri          = $Uri
                StatusCode   = [int]$webResponse.StatusCode
                Headers      = Get-ResponseHeaders -Response $webResponse
                Body         = $body
                FinalUri     = $Uri
                ErrorMessage = $_.Exception.Message
            }
        }

        return [pscustomobject]@{
            Success      = $false
            Uri          = $Uri
            StatusCode   = 0
            Headers      = @{}
            Body         = ''
            FinalUri     = $Uri
            ErrorMessage = $_.Exception.Message
        }
    }
}

function Get-BodySnippet {
    param([string]$Body)

    if ([string]::IsNullOrWhiteSpace($Body)) {
        return ''
    }

    $normalized = ($Body -replace '\s+', ' ').Trim()
    if ($normalized.Length -le 180) {
        return $normalized
    }

    return $normalized.Substring(0, 180) + '...'
}

function Get-LockoutGuidance {
    return @(
        'Manual follow-up checks still recommended:',
        '1. Same username + wrong password 3 times should lock login.',
        '2. Same user + wrong OTP 3 times should lock 2FA verify.',
        '3. Confirm inline lock message appears and fields disable.',
        '4. Try uploading .php or .exe in Documents and confirm rejection.',
        '5. Try direct role-protected URLs with a lower-privilege account.'
    )
}

$baseHttps = 'https://' + $Domain.Trim('/')
$baseHttp = 'http://' + $Domain.Trim('/')
$results = New-Object 'System.Collections.Generic.List[object]'

$rootHttps = Invoke-AuditRequest -Uri ($baseHttps + '/') -TimeoutSeconds $TimeoutSeconds
$rootHttp = Invoke-AuditRequest -Uri ($baseHttp + '/') -TimeoutSeconds $TimeoutSeconds

$challengeDetected = Test-TextContainsAny -Text $rootHttps.Body -Needles @(
    '/aes.js',
    'toNumbers(',
    'openresty',
    'challenge',
    'browser verification'
)

if ($rootHttps.StatusCode -eq 200) {
    Add-Result -Results $results -Name 'HTTPS root reachable' -Status 'PASS' -Details ('HTTPS returned ' + $rootHttps.StatusCode + '.') -Url ($baseHttps + '/')
} else {
    Add-Result -Results $results -Name 'HTTPS root reachable' -Status 'FAIL' -Details ('Unexpected HTTPS status: ' + $rootHttps.StatusCode + '.') -Url ($baseHttps + '/')
}

$rootHttpLocation = ''
if ($rootHttp.Headers.ContainsKey('Location')) {
    $rootHttpLocation = [string]$rootHttp.Headers['Location']
}

if ($rootHttp.FinalUri -like 'https://*' -or ($rootHttpLocation -like 'https://*')) {
    Add-Result -Results $results -Name 'HTTP redirects to HTTPS' -Status 'PASS' -Details ('HTTP request redirected to secure origin: ' + ($rootHttp.FinalUri)) -Url ($baseHttp + '/')
} else {
    Add-Result -Results $results -Name 'HTTP redirects to HTTPS' -Status 'WARN' -Details ('HTTP request did not clearly redirect to HTTPS. Final URI: ' + $rootHttp.FinalUri) -Url ($baseHttp + '/')
}

if ($challengeDetected) {
    Add-Result -Results $results -Name 'Host-layer challenge detected' -Status 'WARN' -Details 'InfinityFree/OpenResty challenge page is intercepting public requests, so public audit results are partially host-layer, not purely app-layer.' -Url ($baseHttps + '/')
} else {
    Add-Result -Results $results -Name 'Host-layer challenge detected' -Status 'PASS' -Details 'No obvious upstream anti-bot challenge markers detected on the root response.' -Url ($baseHttps + '/')
}

$securityHeaders = @(
    'Strict-Transport-Security',
    'X-Frame-Options',
    'X-Content-Type-Options',
    'Content-Security-Policy',
    'Referrer-Policy',
    'Permissions-Policy'
)

foreach ($header in $securityHeaders) {
    $headerValue = $null
    foreach ($key in $rootHttps.Headers.Keys) {
        if ($key -ieq $header) {
            $headerValue = $rootHttps.Headers[$key]
            break
        }
    }

    if ([string]::IsNullOrWhiteSpace([string]$headerValue)) {
        $status = if ($challengeDetected) { 'WARN' } else { 'FAIL' }
        $details = if ($challengeDetected) {
            $header + ' not visible on the public response because the host challenge may be answering before the app.'
        } else {
            $header + ' header not present on the HTTPS response.'
        }
        Add-Result -Results $results -Name ('Header: ' + $header) -Status $status -Details $details -Url ($baseHttps + '/')
    } else {
        Add-Result -Results $results -Name ('Header: ' + $header) -Status 'PASS' -Details ($header + ': ' + $headerValue) -Url ($baseHttps + '/')
    }
}

$sensitivePaths = @(
    '.env',
    'storage/',
    'app/',
    'config/',
    'routes/',
    'public/index.php'
)

foreach ($path in $sensitivePaths) {
    $url = $baseHttps + '/' + $path
    $response = Invoke-AuditRequest -Uri $url -TimeoutSeconds $TimeoutSeconds
    $bodySnippet = Get-BodySnippet -Body $response.Body

    if ($response.StatusCode -eq 403 -or $response.StatusCode -eq 401) {
        Add-Result -Results $results -Name ('Sensitive path blocked: /' + $path) -Status 'PASS' -Details ('Blocked with status ' + $response.StatusCode + '.') -Url $url
        continue
    }

    if ($response.StatusCode -eq 404) {
        Add-Result -Results $results -Name ('Sensitive path blocked: /' + $path) -Status 'PASS' -Details 'Returned 404, which is acceptable for hidden internals.' -Url $url
        continue
    }

    if ($challengeDetected -and (Test-TextContainsAny -Text $response.Body -Needles @('/aes.js', 'toNumbers('))) {
        Add-Result -Results $results -Name ('Sensitive path blocked: /' + $path) -Status 'WARN' -Details ('Upstream challenge intercepted request before app/path denial could be verified. Body snippet: ' + $bodySnippet) -Url $url
        continue
    }

    if ($response.StatusCode -eq 0) {
        Add-Result -Results $results -Name ('Sensitive path blocked: /' + $path) -Status 'WARN' -Details ('Connection ended before a clear app-layer verdict. Error: ' + $response.ErrorMessage) -Url $url
        continue
    }

    Add-Result -Results $results -Name ('Sensitive path blocked: /' + $path) -Status 'FAIL' -Details ('Unexpected status ' + $response.StatusCode + '. Body snippet: ' + $bodySnippet) -Url $url
}

$summary = [pscustomobject]@{
    Domain              = $Domain
    CheckedAt           = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    HostChallengeActive = $challengeDetected
    Results             = $results
    ManualChecks        = Get-LockoutGuidance
}

if ($Json) {
    $summary | ConvertTo-Json -Depth 5
    exit 0
}

Write-Host ''
Write-Host ('Travel Agency Live Security Audit - ' + $Domain) -ForegroundColor Cyan
Write-Host ('Checked at: ' + $summary.CheckedAt)
Write-Host ''

foreach ($result in $results) {
    $color = Format-StatusColor -Status $result.Status
    Write-Host ('[' + $result.Status + '] ' + $result.Name) -ForegroundColor $color
    Write-Host ('  ' + $result.Details)
    if ($result.Url -ne '') {
        Write-Host ('  URL: ' + $result.Url) -ForegroundColor DarkGray
    }
    Write-Host ''
}

Write-Host 'Manual follow-up checks:' -ForegroundColor Cyan
foreach ($line in (Get-LockoutGuidance)) {
    Write-Host ('  ' + $line)
}

$failCount = @($results | Where-Object { $_.Status -eq 'FAIL' }).Count
$warnCount = @($results | Where-Object { $_.Status -eq 'WARN' }).Count
$passCount = @($results | Where-Object { $_.Status -eq 'PASS' }).Count

Write-Host ''
Write-Host ('Summary: ' + $failCount + ' fail, ' + $warnCount + ' warn, ' + $passCount + ' pass') -ForegroundColor Cyan

if ($failCount -gt 0) {
    exit 1
}

exit 0
