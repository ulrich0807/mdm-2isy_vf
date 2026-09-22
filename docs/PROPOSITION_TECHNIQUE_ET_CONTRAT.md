# Proposition technique et cadre contractuel — MDM 2ISY

## 1. Objet

La présente proposition couvre la fourniture, l’hébergement, la maintenance et le support de la plateforme MDM 2ISY destinée aux terminaux Android durcis Blackview Rock 1 Pro, sous Android 13 ou supérieur.

## 2. Périmètre fonctionnel livré

- Enrôlement sécurisé par jeton à usage unique et QR code.
- Inventaire matériel, système, batterie, stockage et applications installées.
- Organisations, groupes de terminaux et rôles super-administrateur, administrateur, opérateur et lecteur.
- Politiques caméra, USB, Bluetooth, Wi-Fi, données mobiles, mode avion, PIN, kiosque mono/multi-applications et listes applicatives.
- Verrouillage, localisation, effacement, installation, mise à jour et désinstallation à distance.
- Historique GPS, état de connexion, commandes et journal d’audit.
- Alertes automatiques de connexion, batterie, stockage, licence et échec de commande.
- Déploiement Cloud avec API Laravel, interface Angular et agent Android Device Owner.

## 3. Hébergement et sécurité

Le mode recommandé est un hébergement Cloud Linux isolé, avec TLS obligatoire, base MySQL privée, sauvegardes chiffrées et stockage privé des APK. Les secrets sont fournis par variables d’environnement et ne doivent jamais être enregistrés dans Git. Les mots de passe sont hachés, les jetons d’enrôlement et d’appareil ne sont conservés que sous forme d’empreinte, et les accès sont cloisonnés par organisation.

Objectifs de sauvegarde proposés : sauvegarde quotidienne, conservation 30 jours, copie hebdomadaire hors site, RPO maximal 24 heures et RTO cible 4 heures. Un test de restauration doit être effectué au moins chaque trimestre.

## 4. Formation et documentation

- Session administrateurs : 4 heures, jusqu’à 8 participants.
- Session opérateurs/utilisateurs : 2 heures, jusqu’à 15 participants.
- Remise du guide administrateur, du guide d’exploitation et de la procédure d’enrôlement.
- Une session de transfert de compétences technique pour l’équipe d’exploitation.

## 5. Support et SLA proposés

| Priorité | Exemple | Prise en charge | Objectif de rétablissement |
|---|---|---:|---:|
| P1 critique | Service indisponible, commandes de sécurité impossibles | 30 min | 4 h |
| P2 majeure | Fonction importante indisponible sans contournement | 2 h | 8 h ouvrées |
| P3 normale | Anomalie avec contournement | 4 h ouvrées | 3 jours ouvrés |
| P4 mineure | Question, amélioration cosmétique | 1 jour ouvré | Prochaine version planifiée |

Support standard proposé : du lundi au vendredi, de 08 h 00 à 18 h 00, hors jours fériés. Une astreinte P1 24/7 peut faire l’objet d’une option contractuelle. Les canaux sont l’e-mail, le téléphone et la prise en main à distance ; l’intervention sur site est planifiée séparément.

## 6. Maintenance et garantie

- Maintenance corrective et mises à jour de sécurité incluses pendant la période contractuelle.
- Maintenance préventive mensuelle : contrôle des sauvegardes, certificats, files de tâches, stockage et journaux.
- Maintenance évolutive sur devis ou enveloppe annuelle convenue.
- Garantie de bon fonctionnement de 90 jours après recette, couvrant la correction sans surcoût des anomalies reproductibles du périmètre livré.
- En cas d’incident majeur, restauration de la dernière sauvegarde valide ou bascule vers une instance restaurée selon le plan de continuité.

## 7. Confidentialité et données

Chaque partie protège les informations confidentielles reçues et limite leur accès aux personnes autorisées. Les données client restent la propriété du client. 2ISY conserve la propriété de ses composants génériques, bibliothèques et savoir-faire antérieurs ; les développements spécifiques et leurs droits d’utilisation sont définis dans le bon de commande. Les obligations de protection des données applicables doivent être précisées selon les pays de déploiement et les catégories de données collectées.

## 8. Recette

La recette porte sur les scénarios du cahier des charges, exécutés sur un terminal Blackview représentatif provisionné en Device Owner. Une anomalie bloquante empêche la recette ; les réserves non bloquantes sont consignées avec une date de correction. La recette est réputée acquise après signature du procès-verbal ou expiration du délai contractuel de vérification sans réserve bloquante.

## 9. Accompagnement

Le projet prévoit un point de suivi hebdomadaire jusqu’à la mise en production, une période de surveillance renforcée de deux semaines après lancement, puis un comité de service mensuel. Les futures évolutions sont qualifiées, estimées, testées sur préproduction et déployées selon une procédure de changement validée.

## 10. Réserves contractuelles

Ce document constitue un projet de cadre contractuel à compléter avec les prix, dates, volumes de terminaux, juridiction, responsabilités, plafond d’indemnisation, conditions de résiliation et coordonnées officielles des parties. Une validation juridique est recommandée avant signature.
