# Matrice de conformité au cahier des charges MDM 2ISY

État de référence : code `master` incluant l'agent Android 0.1.4. Cette matrice
distingue ce qui est livré par le logiciel de ce qui doit obligatoirement être
validé ou configuré dans l'environnement de production.

## Fonctionnalités

| Exigence du CDC | État | Preuve ou réserve |
|---|---|---|
| Enrôlement Android | Livré | Invitation à usage unique, QR, identité appareil et révocation du secret |
| Gestion centralisée et inventaire | Livré | Parc, IMEI si accessible, série, modèle, Android, version agent, batterie et stockage |
| Organisation par groupes | Livré | Groupes et affectation des profils aux terminaux |
| Déploiement, installation, mise à jour et désinstallation d'applications | Livré | APK privé, commandes suivies, contrôle du nom de package et résultat idempotent |
| Liste noire et liste blanche | Livré | Règles de profil et liste noire du catalogue fusionnées à chaque synchronisation |
| Verrouillage à distance | Livré | Commande Device Owner journalisée |
| Effacement et réinitialisation | Livré | Commande destructive limitée aux rôles autorisés ; recette matérielle obligatoire |
| Politiques de sécurité et mots de passe | Livré | PIN fort, caméra, USB, Bluetooth, Wi-Fi, données mobiles et mode avion |
| Kiosque mono-application et multi-applications | Livré | Lock task et liste d'applications autorisées |
| Localisation, historique, dernière connexion et état | Livré | Coordonnées, historique, last seen et calcul en ligne/hors ligne |
| Supervision batterie, stockage, Android et état | Livré | Tableau de bord et fiche terminal |
| Alertes d'anomalie | Livré | Batterie, stockage, hors ligne, licence et commandes échouées ; planificateur requis |
| Multi-utilisateurs, rôles et profils | Livré | Super-administrateur, administrateur, opérateur et consultation |
| Journal des actions | Livré | Audit central avec isolation par organisation |
| Android 13 et supérieur | Livré, recette requise | API et politiques compatibles ; validées sur un TECNO Android récent |
| Blackview Rock 1 Pro | Recette requise | Aucun résultat matériel Blackview ne doit être déclaré avant exécution du plan de recette |
| Hébergement Cloud | Livré | Configuration Nginx, HTTPS, déploiement, sauvegarde et restauration documentés |
| Évolutivité | Livré | API séparée, frontend modulaire, agent à commandes et isolation multi-organisation |

## Prestations et contrat

| Exigence du CDC | État | Document ou action |
|---|---|---|
| Formation administrateurs et utilisateurs | Cadre livré | Programme et livrables dans la proposition ; dates à contractualiser |
| Documentation et guides d'exploitation | Livré | Guides administrateur, exploitation et plan de recette |
| Support téléphone, e-mail, distant et sur site | Cadre livré | Canaux, horaires et SLA à finaliser avec les coordonnées contractuelles |
| Maintenance corrective, préventive et évolutive | Cadre livré | Modalités décrites dans le projet de contrat |
| Garantie de bon fonctionnement | Cadre livré | Garantie et restauration décrites ; durée à signer |
| Sécurité, sauvegarde et restauration | Livré, exercice requis | Script de sauvegarde et procédure ; test réel de restauration à consigner |
| Confidentialité et propriété intellectuelle | Cadre livré | Clauses proposées ; validation juridique requise |
| Suivi, mise en production et évolutions | Cadre livré | Gouvernance proposée ; interlocuteurs et calendrier à compléter |

## Travaux restant avant réception définitive

1. Déployer le dernier `master` sur le serveur puis vérifier les versions web,
   API et agent réellement servies.
2. Produire un APK **release** signé avec la clé 2ISY, archiver son empreinte et
   remplacer l'APK debug utilisé pendant la mise au point.
3. Configurer et contrôler les identifiants Firebase de production, puis tester
   le réveil FCM avec le polling de secours.
4. Vérifier que le planificateur Laravel s'exécute chaque minute et que les
   alertes automatiques sont effectivement créées en production.
5. Tester une sauvegarde puis une restauration complète sur un environnement
   isolé et consigner le RPO/RTO observé.
6. Exécuter tout le plan de recette sur un Blackview Rock 1 Pro Android 13 ou
   supérieur, notamment kiosque, restrictions OEM, installation et effacement.
7. Maintenir les audits de dépendances PHP et JavaScript dans la procédure de
   maintenance ; les audits de la version de référence ne signalent aucune
   vulnérabilité connue.
8. Faire valider juridiquement et commercialement les prix, volumes, dates,
   juridiction, responsabilité, résiliation, durée de garantie et coordonnées du
   support.
9. Faire tourner les secrets qui ont pu être exposés dans l'historique Git, puis
   traiter cet historique selon la procédure de sécurité.

La réception ne doit être prononcée qu'après signature du procès-verbal figurant
dans `PLAN_DE_RECETTE.md` et clôture de toute anomalie critique ou bloquante.
