@echo off
rem ============================================================
rem  POS a tutto schermo + ponte di stampa
rem  1. avvia il ponte di stampa (ridotto a icona; se e' gia' acceso non ne parte un altro)
rem  2. apre il POS a tutto schermo ("chiosco", senza barre del browser)
rem  Mettilo sul Desktop del PC della cassa. Per uscire dal tutto schermo: Alt+F4.
rem  Firefox: chiudi prima tutte le finestre di Firefox, altrimenti si apre normale.
rem ============================================================
set URL={{URL}}

rem --- 1. Ponte di stampa: quello installato (installa-ponte.bat) o quello accanto a questo file
set PONTE=
if exist "%LOCALAPPDATA%\PontePOS\ponte.ps1" set PONTE=%LOCALAPPDATA%\PontePOS\ponte.ps1
if not defined PONTE if exist "%~dp0ponte.ps1" set PONTE=%~dp0ponte.ps1
if defined PONTE (
  start "Ponte di stampa POS" /min powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Minimized -File "%PONTE%"
) else (
  echo ATTENZIONE: ponte di stampa non trovato, le comande non verranno stampate.
  echo Scarica ponte.ps1 da Impostazioni ^> Stampanti e mettilo accanto a questo file.
  timeout /t 8
)

rem --- 2. POS a tutto schermo
for %%B in ("%ProgramFiles%\Google\Chrome\Application\chrome.exe" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" "%LocalAppData%\Google\Chrome\Application\chrome.exe") do (
  if exist %%B ( start "" %%B --kiosk --new-window %URL% & exit /b )
)
for %%B in ("%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe") do (
  if exist %%B ( start "" %%B --kiosk %URL% --edge-kiosk-type=fullscreen & exit /b )
)
for %%B in ("%ProgramFiles%\Mozilla Firefox\firefox.exe" "%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe") do (
  if exist %%B ( start "" %%B -kiosk %URL% & exit /b )
)
echo Nessun browser trovato (Chrome, Edge o Firefox).
pause
