# Sécurité du dépôt

## Secrets

- Aucun mot de passe, jeton appareil, clé de service Firebase ou clé privée ne
  doit être versionné.
- Les secrets de production doivent être fournis par l'environnement ou par le
  gestionnaire de secrets de l'hébergeur.
- `mdm-2isy-api/.env` et `mdm-2isy-api/firebase-auth.json` restent ignorés
  par Git.
- `google-services.json` contient la configuration cliente Firebase. La clé API
  associée doit être restreinte dans Google Cloud au projet, aux API nécessaires
  et aux applications Android signées attendues.

## Actions immédiates après ce correctif

Des identifiants ont été présents dans des fichiers suivis par Git. Leur retrait
du dernier état du dépôt ne les invalide pas et ne les efface pas de l'historique.

1. Changer les mots de passe serveur et base de données concernés.
2. Vérifier les journaux d'accès.
3. Purger les anciennes valeurs de l'historique Git avec une procédure validée.
4. Informer les personnes possédant déjà un clone du dépôt.
5. Tester le déploiement avec les nouveaux secrets avant de révoquer les derniers
   accès de secours.

## Signalement

Les vulnérabilités ne doivent pas être décrites dans une issue publique. Utiliser
le canal de support privé défini dans le contrat d'exploitation.
