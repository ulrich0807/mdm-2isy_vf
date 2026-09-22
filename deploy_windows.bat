@echo off
setlocal

:: S'assurer qu'on est dans le bon dossier
cd /d "%~dp0"

echo ==============================================
echo       DEPLOIEMENT MDM-2ISY (GIT)
echo ==============================================
echo.

:: Demander le message de commit
set /p commitMsg="Entrez le message de commit (ou appuyez sur Entree pour utiliser 'Mise a jour automatique') : "
if "%commitMsg%"=="" set commitMsg=Mise a jour automatique

echo.
echo [1/3] Ajout des fichiers a Git...
git add -A

echo [2/3] Commit des modifications...
git commit -m "%commitMsg%"

echo [3/3] Envoi vers GitHub (Push)...
git push origin master

echo.
echo ==============================================
echo Les modifications sont en ligne sur GitHub !
echo.
echo NOTE : La connexion SSH automatique a echoue (Port 22 bloque ou Cloudflare).
echo Veuillez vous connecter a votre serveur comme d'habitude et lancer :
echo cd /var/www/mdm-2isy_vf ^&^& bash deploy.sh
echo ==============================================
echo.

echo Termine ! Appuyez sur une touche pour quitter.
pause
