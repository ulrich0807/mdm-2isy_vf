# Mise à jour distante de l'agent MDM

## Publication d'une version

1. Augmenter `versionCode` et `versionName` dans l'application Android.
2. Construire et signer l'APK avec **la même clé** que la version déjà installée.
3. Publier l'APK dans `mdm-2isy-api/public/apk/mdm-agent.apk`.
4. Depuis la racine du dépôt, générer le manifeste et l'artefact immuable :

   ```powershell
   .\publish_agent_release.ps1
   ```

5. Versionner ensemble l'APK, `mdm-agent.json` et le fichier
   `public/apk/releases/<sha256>.apk`.

L'API refuse de planifier une mise à jour si le manifeste, la taille ou le
SHA-256 ne correspondent pas à l'APK. Une version destinée à amorcer les
agents 0.1.9 doit rester inférieure ou égale à 100 Mio.

## Déploiement

Seul le super administrateur voit l'action **Mettre à jour l'agent** dans la
page **Flotte de terminaux**.

1. Sélectionner l'organisation.
2. Lancer un pilote sur un seul terminal en ligne.
3. Attendre son retour avec la version cible affichée dans la flotte.
4. Lancer ensuite un ou plusieurs lots de 100 terminaux maximum.

Les commandes restent en attente pendant sept jours pour les appareils hors
ligne. Elles utilisent une URL content-addressée : publier une nouvelle
version ne modifie jamais le fichier téléchargé par une vague déjà lancée.

Le chemin générique de déploiement d'applications refuse volontairement le
package `com.mdm2isy.agent`. Il faut toujours passer par l'action dédiée.
