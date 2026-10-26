@echo off
rem ============================================================
rem  POS a tutto schermo
rem  Apre il POS a tutto schermo ("chiosco", senza barre del browser).
rem  Mettilo sul Desktop del PC. Per uscire dal tutto schermo: Alt+F4.
rem  Firefox: chiudi prima tutte le finestre di Firefox, altrimenti si apre normale.
rem ============================================================
set URL={{URL}}

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
