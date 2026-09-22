# Plan de recette MDM 2ISY

Ce document couvre la recette avant mise en production. Les tests automatisés valident le logiciel ; les lignes « matériel » doivent être exécutées sur un Blackview Rock 1 Pro réinitialisé et réservé aux essais.

## Préconditions

- environnement HTTPS de recette avec certificat valide ;
- application web et API déployées avec les variables de production ;
- APK de release signé avec la clé 2ISY et empreinte SHA-256 archivée ;
- Blackview Rock 1 Pro Android 13 ou supérieur, sans compte personnel ;
- profils `administrateur`, `opérateur` et `consultation` disponibles ;
- sauvegarde et procédure de restauration testables.

## Recette fonctionnelle

| Domaine | Vérification | Résultat attendu |
|---|---|---|
| Enrôlement | Générer un QR puis provisionner un terminal réinitialisé | Terminal rattaché au bon client, jeton à usage unique invalidé |
| Inventaire | Déclencher un heartbeat | Modèle, Android, série, IMEI autorisé, batterie et stockage visibles |
| Groupes/profils | Affecter puis modifier un profil | Politique synchronisée sur le terminal |
| Applications | Installer, mettre à jour puis désinstaller une APK métier | Commandes tracées et états finaux cohérents |
| Liste blanche | Autoriser deux applications | Les autres applications utilisateur sont masquées |
| Kiosque | Tester mono-app puis multi-app | Seules les applications configurées sont accessibles |
| Restrictions | USB, Bluetooth et caméra selon le profil | Chaque restriction correspond exactement au profil actif |
| Mot de passe | Appliquer la politique PIN fort | PIN numérique complexe d'au moins six caractères exigé |
| Localisation | Demander la position puis couper le réseau | Dernière position conservée, état hors ligne après le seuil |
| Sécurité | Verrouiller le terminal | Verrouillage exécuté et journalisé |
| Effacement | Lancer sur l'appareil de laboratoire en dernier | Réinitialisation déclenchée, audit conservé côté serveur |
| Supervision | Simuler batterie faible, stockage faible et terminal hors ligne | Alertes dédupliquées, sévérité correcte, résolution possible |
| Licences | Tester une licence expirante puis expirée | Alerte préalable puis blocage conforme à la politique retenue |
| Rôles | Rejouer les opérations avec les trois rôles | Consultation sans mutation ; opérateur sans effacement ; admin complet sur son client |
| Multi-client | Accéder aux ressources d'un autre client | Accès refusé et aucune fuite dans listes, exports ou journaux |

## Recette d'exploitation

1. Restaurer une sauvegarde récente sur un environnement isolé et mesurer le temps de reprise.
2. Vérifier l'exécution planifiée de `alerts:check`, la rotation des journaux et la surveillance des erreurs.
3. Simuler une indisponibilité API, puis confirmer la reprise des heartbeats et des résultats de commandes.
4. Révoquer un compte administrateur et vérifier que ses sessions ne permettent plus d'agir.
5. Renouveler le certificat TLS en recette et vérifier la connexion des terminaux.
6. Conserver le procès-verbal, les versions web/API/agent et l'empreinte de l'APK livré.

## Critères d'acceptation

- aucun défaut bloquant ou critique ouvert ;
- aucune fuite entre organisations ;
- toutes les opérations destructives nécessitent le rôle administrateur ;
- sauvegarde restaurée avec succès ;
- parcours complet validé sur le modèle Blackview cible ;
- réserves mineures documentées avec responsable et date de correction.

## Procès-verbal

| Champ | Valeur |
|---|---|
| Version API / Web / Agent | À compléter |
| Terminal et version Android | À compléter |
| Date de recette | À compléter |
| Représentant 2ISY | À compléter |
| Représentant client | À compléter |
| Décision | Accepté / Accepté avec réserves / Refusé |
| Réserves | À compléter |
