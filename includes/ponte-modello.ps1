# ============================================================
#  Ponte di stampa del POS
#  Prende le stampe dal sito e le manda alle stampanti del locale.
#  installa-ponte.bat (-Installa): lo copia sul PC e lo fa partire da solo all'accesso a Windows.
#  avvia-ponte.bat: lo avvia una volta, a mano. -Rimuovi: toglie l'avvio automatico.
#  Generato dalla pagina Impostazioni > Stampanti: contiene la chiave segreta, non condividerlo.
# ============================================================
param([switch]$Installa, [switch]$Rimuovi)

$URL    = '{{URL}}'
$CHIAVE = '{{CHIAVE}}'

$ErrorActionPreference = 'Stop'
$cartella  = Join-Path $env:LOCALAPPDATA 'PontePOS'
$collega   = Join-Path ([Environment]::GetFolderPath('Startup')) 'Ponte di stampa POS.lnk'

if ($Rimuovi) {
    if (Test-Path $collega) { Remove-Item $collega }
    Write-Host "Avvio automatico del ponte tolto. (La copia in $cartella puoi cancellarla a mano.)"
    return
}

if ($Installa) {
    # Copia in una cartella fissa del PC, collegamento in Esecuzione automatica, avvio subito (ridotto a icona)
    New-Item -ItemType Directory -Force $cartella | Out-Null
    $copia = Join-Path $cartella 'ponte.ps1'
    Copy-Item $PSCommandPath $copia -Force
    $argomenti = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Minimized -File `"$copia`""
    $lnk = (New-Object -ComObject WScript.Shell).CreateShortcut($collega)
    $lnk.TargetPath  = (Get-Command powershell.exe).Source
    $lnk.Arguments   = $argomenti
    $lnk.WindowStyle = 7   # ridotta a icona
    $lnk.Description = 'Ponte di stampa del POS'
    $lnk.Save()
    Start-Process powershell.exe -ArgumentList $argomenti -WindowStyle Minimized
    Write-Host 'Ponte di stampa installato:'
    Write-Host " - parte da solo ogni volta che entri in Windows"
    Write-Host ' - adesso è già avviato (finestra ridotta a icona nella barra)'
    return
}

# Una sola copia alla volta: se il ponte è già acceso, questa si chiude
$unico = New-Object Threading.Mutex($false, 'Local\PontePOS')
if (-not $unico.WaitOne(0)) {
    Write-Host 'Il ponte di stampa è già in esecuzione su questo PC.'
    Start-Sleep -Seconds 3
    return
}
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
[Net.ServicePointManager]::DefaultConnectionLimit = 10
$Host.UI.RawUI.WindowTitle = 'Ponte di stampa POS'

# Sito su internet: sempre https (la chiave non deve viaggiare in chiaro)
if ($URL -match '^http://(?!localhost|127\.|10\.|192\.168\.)') { $URL = $URL -replace '^http://', 'https://' }

# Un clic dentro la finestra ("modalità selezione") bloccherebbe il ponte: la disattivo
try {
    Add-Type -Namespace PontePOS -Name Console -MemberDefinition @'
[DllImport("kernel32.dll")] public static extern System.IntPtr GetStdHandle(int h);
[DllImport("kernel32.dll")] public static extern bool GetConsoleMode(System.IntPtr h, out uint m);
[DllImport("kernel32.dll")] public static extern bool SetConsoleMode(System.IntPtr h, uint m);
'@
    $h = [PontePOS.Console]::GetStdHandle(-10); $m = [uint32]0
    if ([PontePOS.Console]::GetConsoleMode($h, [ref]$m)) {
        [void][PontePOS.Console]::SetConsoleMode($h, (($m -band (-bnot [uint32]0x40)) -bor [uint32]0x80))
    }
} catch { }

# Registro: %LOCALAPPDATA%\PontePOS\ponte.log (si accorcia da solo oltre 1 MB)
$registro = Join-Path $cartella 'ponte.log'
New-Item -ItemType Directory -Force $cartella | Out-Null
if ((Test-Path $registro) -and (Get-Item $registro).Length -gt 1MB) { Remove-Item $registro }

function Ora { Get-Date -Format 'HH:mm:ss' }

function Scrivi([string]$testo, [string]$colore = 'Gray') {
    Write-Host "$(Ora) $testo" -ForegroundColor $colore
    try { Add-Content -Path $registro -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $testo" -Encoding UTF8 } catch { }
}

# Richiesta al sito: connessione chiusa ogni volta e tempi massimi veri
# (Invoke-RestMethod di PowerShell 5 tiene aperte le connessioni e dopo un po' si blocca)
function Chiama([string]$azione, [string]$corpo = '') {
    $req = [Net.HttpWebRequest]::Create("$($URL)?azione=$azione")
    $req.KeepAlive        = $false
    $req.Timeout          = 15000
    $req.ReadWriteTimeout = 15000
    $req.UserAgent        = 'PontePOS'
    $req.Headers.Add('X-Ponte-Chiave', $CHIAVE)
    $req.Headers.Add('X-Ponte-Versione', '2')   # 2 = sa inviare gli scontrini alla Epson RT
    # Porte seriali di questo PC: la pagina Stampanti le mostra per scegliere quella giusta
    $req.Headers.Add('X-Ponte-Porte', (([IO.Ports.SerialPort]::GetPortNames() | Sort-Object -Unique) -join ','))
    if ($corpo) {
        $byte = [Text.Encoding]::UTF8.GetBytes($corpo)
        $req.Method        = 'POST'
        $req.ContentType   = 'application/json; charset=utf-8'
        $req.ContentLength = $byte.Length
        $s = $req.GetRequestStream(); try { $s.Write($byte, 0, $byte.Length) } finally { $s.Close() }
    }
    try {
        $risp = $req.GetResponse()
    } catch [Net.WebException] {
        # Messaggio del sito (es. chiave non valida) invece dell'errore generico
        $ex = $_.Exception
        $msg = $null
        if ($ex.Response) {
            $l = New-Object IO.StreamReader($ex.Response.GetResponseStream())
            $testo = $l.ReadToEnd(); $ex.Response.Close()
            try { $msg = ($testo | ConvertFrom-Json).errore } catch { }
        }
        if ($msg) { throw $msg } else { throw $ex }
    }
    try {
        $l = New-Object IO.StreamReader($risp.GetResponseStream(), [Text.Encoding]::UTF8)
        return ($l.ReadToEnd() | ConvertFrom-Json)
    } finally {
        $risp.Close()
    }
}

# Invia i byte alla stampante (RAW TCP, attesa massima 4 secondi)
function Invia-Stampante([string]$ip, [int]$porta, [byte[]]$dati) {
    $client = New-Object Net.Sockets.TcpClient
    try {
        $tentativo = $client.BeginConnect($ip, $porta, $null, $null)
        if (-not $tentativo.AsyncWaitHandle.WaitOne(4000) -or -not $client.Connected) {
            throw "la stampante $ip`:$porta non risponde (spenta o scollegata?)"
        }
        $client.EndConnect($tentativo)
        $flusso = $client.GetStream()
        $flusso.WriteTimeout = 4000
        $flusso.Write($dati, 0, $dati.Length)
        $flusso.Flush()
    } finally {
        $client.Close()
    }
}

# Invia i byte a una stampante seriale (porta COM di questo PC), 8 bit, nessuna parità, 1 bit di stop
function Invia-Seriale([string]$com, [int]$baud, [string]$flusso, [byte[]]$dati) {
    $sp = New-Object IO.Ports.SerialPort $com, $baud, ([IO.Ports.Parity]::None), 8, ([IO.Ports.StopBits]::One)
    $sp.Handshake = switch ($flusso) {
        'rtscts'  { [IO.Ports.Handshake]::RequestToSend }
        'xonxoff' { [IO.Ports.Handshake]::XOnXOff }
        default   { [IO.Ports.Handshake]::None }
    }
    $sp.DtrEnable    = $true    # molte stampanti (DTR/DSR) stampano solo con DTR attivo
    if ($flusso -ne 'rtscts') { $sp.RtsEnable = $true }
    $sp.WriteTimeout = 10000
    try {
        try { $sp.Open() } catch { throw "porta $com non disponibile (stampante scollegata o porta usata da un altro programma)" }
        $sp.Write($dati, 0, $dati.Length)
        # Aspetto che i byte siano davvero usciti dalla porta prima di chiuderla
        $fine = (Get-Date).AddSeconds(10)
        while ($sp.BytesToWrite -gt 0 -and (Get-Date) -lt $fine) { Start-Sleep -Milliseconds 50 }
        if ($sp.BytesToWrite -gt 0) { throw "la stampante su $com non riceve (spenta, senza carta o velocità sbagliata?)" }
        Start-Sleep -Milliseconds 200
    } finally {
        if ($sp.IsOpen) { $sp.Close() }
        $sp.Dispose()
    }
}

# Scontrino: invia l'XML al registratore telematico Epson e restituisce la sua risposta (XML)
function Invia-Cassa([string]$url, [byte[]]$xml) {
    $req = [Net.HttpWebRequest]::Create($url)
    $req.Method           = 'POST'
    $req.KeepAlive        = $false
    $req.Timeout          = 20000
    $req.ReadWriteTimeout = 20000
    $req.ContentType      = 'text/xml; charset=utf-8'
    $req.ContentLength    = $xml.Length
    try {
        $s = $req.GetRequestStream(); try { $s.Write($xml, 0, $xml.Length) } finally { $s.Close() }
        $risp = $req.GetResponse()
    } catch [Net.WebException] {
        if ($_.Exception.Response) { $risp = $_.Exception.Response } else { throw "cassa non raggiungibile ($($_.Exception.Message))" }
    }
    try { return (New-Object IO.StreamReader($risp.GetResponseStream(), [Text.Encoding]::UTF8)).ReadToEnd() }
    finally { $risp.Close() }
}

Write-Host "Ponte di stampa avviato - sito: $URL"
Write-Host 'Lascia aperta questa finestra (puoi ridurla a icona). Per fermare il ponte chiudila.'
Write-Host "Registro: $registro"
Write-Host ''
Scrivi "avviato ($URL)"

$avvisato = $false
$giri = 0
while ($true) {
    try {
        $risposta = Chiama 'prendi'
        if ($avvisato) { Scrivi 'collegamento al sito ripristinato' 'Green'; $avvisato = $false }
        if (($giri++ % 900) -eq 0) { Scrivi 'collegato al sito, in attesa di stampe' }   # circa ogni mezz'ora

        foreach ($lavoro in $risposta.lavori) {
            $ok = $true; $errore = ''

            # Scontrino per la Epson RT: un solo tentativo, la risposta della cassa torna al sito
            if ($lavoro.tipo -eq 'rt') {
                $testo = ''
                try {
                    $testo = Invia-Cassa $lavoro.url ([Convert]::FromBase64String($lavoro.dati))
                    $esito = if ($testo -match 'success="true"') { 'emesso' } else { 'rifiutato dalla cassa' }
                    Scrivi "scontrino $($lavoro.id): $esito" $(if ($esito -eq 'emesso') { 'Green' } else { 'Yellow' })
                } catch {
                    $ok = $false; $errore = $_.Exception.Message
                    Scrivi "scontrino $($lavoro.id) NON inviato: $errore" 'Yellow'
                }
                [void](Chiama 'esito' (@{ id = $lavoro.id; ok = $ok; errore = $errore; risposta = $testo } | ConvertTo-Json -Compress))
                continue
            }

            try {
                $byte = [Convert]::FromBase64String($lavoro.dati)
                if ($lavoro.collegamento -eq 'seriale') {
                    Invia-Seriale $lavoro.com $lavoro.baud $lavoro.flusso $byte
                    Scrivi "stampa $($lavoro.id) inviata a $($lavoro.com)"
                } else {
                    Invia-Stampante $lavoro.ip $lavoro.porta $byte
                    Scrivi "stampa $($lavoro.id) inviata a $($lavoro.ip)"
                }
            } catch {
                $ok = $false; $errore = $_.Exception.Message
                Scrivi "stampa $($lavoro.id) NON riuscita: $errore (riprovo)" 'Yellow'
            }
            [void](Chiama 'esito' (@{ id = $lavoro.id; ok = $ok; errore = $errore } | ConvertTo-Json -Compress))
        }
    } catch {
        if (-not $avvisato) {
            Scrivi "sito non raggiungibile: $($_.Exception.Message) - riprovo da solo" 'Red'
            $avvisato = $true
        }
        Start-Sleep -Seconds 5
    }
    Start-Sleep -Seconds 2
}
