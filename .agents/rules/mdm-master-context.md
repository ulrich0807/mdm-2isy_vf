# Contexte maitre du projet MDM-2ISY

Ce fichier decrit les contraintes techniques stables du projet. Aucun identifiant,
mot de passe, jeton ou secret d'infrastructure ne doit y etre stocke.

## Architecture

- Frontend : Angular (`mdm-2isy-front`).
- Backend API : Laravel (`mdm-2isy-api`).
- Agent Android : Kotlin (`mdm-2isy-android`), prevu comme Device Owner.
- Production : frontend sur `mdm-2isy.com`, API HTTPS sur `api.mdm-2isy.com`.

## Contraintes Android et MDM

1. Les applications systeme non desinstallables sont masquees avec
   `DevicePolicyManager.setApplicationHidden()`.
2. L'inventaire conserve les applications utilisateur et les applications systeme
   possedant une activite de lancement.
3. FCM reveille l'agent sur les ROM OEM agressives ; le polling authentifie reste
   le mecanisme de recuperation fiable des commandes.
4. Les operations sensibles doivent toujours utiliser le bon composant
   `MdmDeviceAdminReceiver` et verifier le statut Device Owner.
5. Toute installation ou desinstallation doit publier un resultat idempotent et
   compatible avec le contrat Laravel.

## Deploiement

- Chemin cible : `/var/www/mdm-2isy_vf`.
- Le build Angular attendu est `mdm-2isy-front/dist/mdm-dashboard/browser`.
- Les secrets sont fournis par l'environnement ou un gestionnaire de secrets.
- Le scheduler Laravel doit etre execute chaque minute.
- Ne jamais versionner les mots de passe serveur, base de donnees ou cles FCM.

## Priorites produit

- Isolation stricte des organisations pour licences, profils, applications et logs.
- Finalisation de l'installation, mise a jour et desinstallation d'applications.
- Gestion complete des politiques de mot de passe et du kiosque multi-application.
- Deploiement Cloud reproductible, sauvegardes et restauration testees.
- Validation reelle sur Blackview Rock 1 Pro avec Android 13 et versions superieures.
- Documentation, formation, SLA et livrables contractuels du CDC.

