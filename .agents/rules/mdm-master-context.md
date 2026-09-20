
# Contexte Maître du Projet MDM-2ISY

Ce fichier sert de mémoire vive pour tout assistant travaillant sur ce projet. Il contient les informations architecturales critiques et les spécificités du système.

## Architecture Globale
- **Frontend** : Angular (`mdm-2isy-front`)
- **Backend API** : Laravel (`mdm-2isy-api`)
- **Agent Android** : Kotlin (`mdm-2isy-android`), application "Device Owner".

## Spécificités Android & MDM
1. **Désinstallation des Apps** : L`'`API standard d`'`Android ne peut pas désinstaller les apps système (ex: YouTube, TikTok). L`'`Agent MDM gère cela de manière transparente en utilisant `DevicePolicyManager.setApplicationHidden(admin, packageName, true)` pour les masquer et les désactiver de force.
2. **Filtrage de l`'`Inventaire** : Pour l`'`affichage dans le Dashboard, seules les applications NON-SYSTÈME **OU** les applications ayant une icône de lancement (ex: Google Chrome, TikTok) sont renvoyées par le `InventoryCollector`.
3. **Autonomie en Arrière-plan (Le plus critique)** : Les OS Android modifiés (XOS de Tecno, MIUI, etc.) tuent agressivement l`'`agent MDM. L`'`agent utilise impérativement **FCM (Firebase Cloud Messaging)** via le `MdmFirebaseMessagingService` pour être réveillé. L`'`agent envoie son jeton FCM dans le `HeartbeatRequest` et Laravel envoie une notification push "Data" silencieuse pour forcer l`'`exécution d`'`une commande (ex: Localiser, Désinstaller).
4. **AppInstaller** : Utilise toujours `PendingIntent.FLAG_IMMUTABLE` pour être compatible avec Android 12+.

## Serveur et Déploiement
- **Serveur Cible** : `mdm-server`
- **Chemin du projet sur le serveur** : `/var/www/mdm-2isy_vf`
- **Mot de passe Root (mdm-server)** : `Jui08071995@`
- **Déploiement Angular** : Un script de déploiement `deploy.sh` est présent sur le serveur. La structure de build dans `angular.json` doit absolument respecter les attentes de Nginx sur le serveur (dossier `/dist/mdm-2isy-front/browser`). Ne pas modifier le `outputPath` d`'`Angular à la légère.

## Règles de comportement pour l`'`IA
- **Ne pas suggérer de recréer l`'`architecture FCM** : L`'`architecture actuelle basée sur FCM fonctionne et est requise pour passer outre les restrictions de batterie OEM.
- **Rétrocompatibilité** : Toujours tester ou vérifier les imports `android.app.admin.DevicePolicyManager` (vérifier que c`'`est bien le bon `adminComponent` passé en paramètre).


## État d'avancement par rapport au CDC
Le projet est en grande partie conforme au CDC. L'installation silencieuse, le wipe, le lock, le kiosk et FCM sont opérationnels.

**Les 4 tâches prioritaires restantes pour finaliser la conformité au CDC :**
1. **Restrictions Réseau (Déconnexion)** : Empêcher l'utilisateur de couper le WiFi/Données/Mode Avion. Nécessite l'ajout de lock_wifi, lock_data, etc. dans les Profils Laravel, le Dashboard Angular, et leur application via DevicePolicyManager.addUserRestriction() dans l'agent Android.
2. **Historique des positions** : Créer une table LocationHistory dans Laravel (actuellement, on ne garde que la dernière position dans 	erminals) et l'affichage Angular associé.
3. **Liste Blanche d'Applications** : Compléter le système de lacklist_apps par une véritable logique de liste blanche (cacher tout le reste sans forcer un Kiosque strict).
4. **Responsivité Dashboard** : Optimiser le CSS de l'interface Angular pour les petits écrans.

