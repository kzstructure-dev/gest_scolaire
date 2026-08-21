@echo off
setlocal
cd /d "%~dp0"

echo === Gest Scolaire : installation et demarrage local ===

where php >nul 2>nul
if errorlevel 1 (
  echo PHP est introuvable. Ajoutez le dossier php de WAMP au PATH, par exemple :
  echo   set PATH=C:\wamp64\bin\php\php8.4.0;%%PATH%%
  pause
  exit /b 1
)

if not exist composer.phar (
  echo Telechargement de Composer...
  php -r "copy('https://getcomposer.org/composer.phar', 'composer.phar');"
)

echo Installation des dependances...
php composer.phar install --no-interaction || goto :erreur

echo Creation de la base SQLite...
php bin/console app:db:init || goto :erreur

set /p EMAIL=Adresse e-mail du compte administrateur : 
set /p MOTDEPASSE=Mot de passe : 
php bin/console app:user:create "%EMAIL%" "%MOTDEPASSE%" Administrateur Admin Ecole

echo.
echo Ouvrez http://127.0.0.1:8000/login puis connectez-vous avec %EMAIL%.
echo Appuyez sur Ctrl+C pour arreter le serveur.
php -S 127.0.0.1:8000 -t public
goto :fin

:erreur
echo Une erreur est survenue, lisez le message ci-dessus.
pause

:fin
endlocal
