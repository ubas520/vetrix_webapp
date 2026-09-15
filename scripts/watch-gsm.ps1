param(
    [string]$Port = 'COM5',
    [int]$BaudRate = 115200
)
$ErrorActionPreference = 'Stop'
$mutex = New-Object Threading.Mutex($false, 'Local\VetrixGsmMonitor')
if (!$mutex.WaitOne(0)) { throw 'A Vetrix GSM monitor is already running.' }
$stateDir = Join-Path $PSScriptRoot '../artifacts/gsm'
[void][IO.Directory]::CreateDirectory($stateDir)
[IO.File]::WriteAllText((Join-Path $stateDir '.htaccess'), "Require all denied`n")
$statePath = Join-Path $stateDir 'status.json'
$serial = $null
$lastResponse = 0.0
$buffer = ''
$lastError = ''
function Get-EpochSeconds { return ([DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds() / 1000.0) }
function Save-GsmState([bool]$connected) {
    $json = @{connected=$connected; updated_at=(Get-EpochSeconds); last_response_at=$script:lastResponse} | ConvertTo-Json -Compress
    # A concurrent read may see incomplete JSON and will fail closed. Avoid file
    # replacement because OneDrive/antivirus can lock the destination on Windows.
    try { [IO.File]::WriteAllText($statePath, $json) }
    catch { Write-Warning 'Cannot update GSM heartbeat; SMS will remain blocked until writes recover.' }
}
Write-Host "Monitoring $Port at $BaudRate baud. Close Arduino Serial Monitor first. Ctrl+C stops the gate."
try {
    while ($true) {
        try {
            $availablePorts = @([IO.Ports.SerialPort]::GetPortNames())
            if ($availablePorts -notcontains $Port) {
                if ($availablePorts.Count -eq 1) {
                    $Port = $availablePorts[0]
                    Write-Host "Serial port changed to $Port; waiting for fresh modem responses."
                }
                throw 'Port disconnected'
            }
            if ($null -eq $serial) {
                $lastResponse = 0.0
                $buffer = ''
                $serial = New-Object IO.Ports.SerialPort $Port,$BaudRate,'None',8,'One'
                # Native USB Arduino sketches may wait for Serial's DTR signal.
                $serial.DtrEnable = $true
                $serial.RtsEnable = $false
                $serial.Open()
                $serial.DiscardInBuffer()
            }
            $buffer += $serial.ReadExisting()
            while ($buffer.Contains("`n")) {
                $lineEnd = $buffer.IndexOf("`n")
                $line = $buffer.Substring(0, $lineEnd).Trim()
                $buffer = $buffer.Substring($lineEnd + 1)
                # Only modem response lines count, never echoed AT commands or guide text.
                if ($line -eq 'VETRIX_GSM:NO_RESPONSE') {
                    $lastResponse = 0.0
                } elseif ($line -eq 'VETRIX_GSM:RESPONDING' -or $line -match '^\+CSQ:\s*\d{1,2},\s*\d{1,2}$' -or $line -match '^\+CREG:\s*[012],\s*[0-5]$') {
                    $lastResponse = Get-EpochSeconds
                }
            }
            if ($buffer.Length -gt 4096) { $buffer = '' }
            Save-GsmState (((Get-EpochSeconds) - $lastResponse) -le 12)
            $lastError = ''
        } catch {
            if ($lastError -ne $_.Exception.Message) { Write-Warning $_.Exception.Message }
            $lastError = $_.Exception.Message
            $lastResponse = 0.0
            if ($null -ne $serial) { $serial.Dispose(); $serial = $null }
            Save-GsmState $false
        }
        Start-Sleep -Milliseconds 250
    }
} finally {
    if ($null -ne $serial) { $serial.Dispose() }
    Save-GsmState $false
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}
