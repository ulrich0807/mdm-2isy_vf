# Guide administrateur MDM 2ISY

## Rôles

- Super-administrateur : gestion multi-client et création des administrateurs.
- Administrateur : configuration complète de son organisation et gestion des opérateurs/lecteurs.
- Opérateur : supervision et commandes non destructives.
- Lecteur : consultation uniquement.

## Mise en service d’un terminal

1. Créer ou sélectionner l’organisation et le groupe.
2. Générer un jeton d’enrôlement à durée limitée.
3. Réinitialiser le terminal neuf et provisionner l’agent comme Device Owner.
4. Scanner le QR code ou saisir le code d’enrôlement.
5. Vérifier le modèle, l’IMEI, la version Android, la batterie et la dernière connexion.
6. Activer et affecter une licence, puis attribuer un profil de sécurité.

## Politiques

Créer un profil, choisir les restrictions et définir les paquets Android du kiosque ou des listes applicatives. Toute modification d’un profil affecté demande automatiquement une synchronisation des terminaux connectés.

## Applications

Ajouter le nom, le package, la version et l’APK signé. Le bouton de déploiement permet de sélectionner jusqu’à 100 terminaux. Chaque appareil remonte séparément le succès ou l’échec réel communiqué par Android. Remplacer l’APK et incrémenter la version pour une mise à jour.

## Commandes sensibles

Le verrouillage est disponible aux opérateurs. L’effacement est réservé aux administrateurs et exige le mot de passe courant ainsi que la confirmation exacte de l’identifiant du terminal. Vérifier toujours la cible avant validation.

## Alertes et audit

Le centre d’alertes regroupe connexion, batterie, stockage, licence et commandes. Les alertes techniques se résolvent automatiquement lorsque la condition disparaît. Les actions administratives sont enregistrées sans conserver les mots de passe ni les secrets.
