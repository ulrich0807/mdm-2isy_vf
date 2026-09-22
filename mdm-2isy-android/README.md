# Agent Android MDM 2ISY

Agent natif Kotlin pour Android 13 et versions supérieures. Il communique avec `mdm-2isy-api`, applique les politiques Device Owner et remonte l'inventaire et l'état du terminal.

## Fonctions prises en charge

- enrôlement par jeton à usage unique et stockage chiffré du jeton appareil ;
- inventaire, batterie, stockage, dernière connexion et géolocalisation ;
- exécution idempotente des commandes avec ACK, résultat persistant et reprise réseau ;
- verrouillage et effacement à distance réservés au Device Owner ;
- installation, mise à jour et désinstallation d'APK avec résultat réel de `PackageInstaller` ;
- liste blanche/noire, kiosque mono ou multi-application ;
- restrictions caméra, USB et Bluetooth ;
- politique de PIN numérique complexe d'au moins six caractères ;
- notification FCM et polling de secours ;
- reprise après redémarrage.

## Versions et prérequis

| Élément | Version |
|---|---:|
| Android Gradle Plugin | `9.3.1` |
| Gradle wrapper | `9.5.0` |
| Java | `17` |
| `minSdk` | `33` |
| `compileSdk` | `37` |
| `targetSdk` | `36` |

Version actuelle de l’agent : `0.1.1` (`versionCode` 2).

Le wrapper Gradle est versionné. Utiliser le JDK intégré à Android Studio ou un JDK 17 et installer Android SDK Platform 37.

```powershell
cd mdm-2isy-android
.\gradlew.bat :app:testDebugUnitTest
.\gradlew.bat :app:assembleDebug
.\gradlew.bat :app:lintDebug
```

L'APK de développement est produit dans `app/build/outputs/apk/debug/app-debug.apk`. Les tests JVM couvrent les modèles réseau, délais, URL, commandes, préconditions Device Owner et preuves de résultat.

## URL de l'API

La valeur embarquée est `https://api.mdm-2isy.com/api/v1/device`. Elle peut être remplacée dans l'écran d'enrôlement par une autre URL HTTPS autorisée.

Le manifeste principal refuse le trafic HTTP en clair. Le manifeste debug l'autorise uniquement pour les essais locaux, par exemple `http://10.0.2.2:8000/api/v1/device` depuis un émulateur.

Routes utilisées :

```text
POST  {base}/enroll
POST  {base}/heartbeat
GET   {base}/commands?limit=10
POST  {base}/commands/{public_id}/ack
POST  {base}/commands/{public_id}/result
```

## Signature et publication d'une release

La clé privée de signature ne doit jamais être déposée dans Git. Définir ces variables dans le coffre-fort du poste ou de la CI :

```powershell
$env:MDM_ANDROID_KEYSTORE_FILE = 'C:\chemin-securise\mdm-2isy.jks'
$env:MDM_ANDROID_KEYSTORE_PASSWORD = '...'
$env:MDM_ANDROID_KEY_ALIAS = 'mdm-2isy'
$env:MDM_ANDROID_KEY_PASSWORD = '...'
.\build_release.ps1
```

Le script exécute les tests, construit la release signée puis publie l'APK dans `mdm-2isy-api/public/apk/mdm-agent.apk` et affiche son SHA-256. La compilation de release échoue explicitement si un secret ou le keystore manque. Archiver le keystore et ses accès dans deux emplacements sécurisés : sans cette clé, les mises à jour de l'agent installé ne sont plus possibles.

## Provisionnement Device Owner

L'enrôlement API et le rôle Android Device Owner sont distincts. En production, provisionner un appareil réinitialisé pendant l'assistant initial au moyen du QR Android Enterprise. L'installation de l'APK seule ne peut pas accorder ce rôle.

Pour un laboratoire uniquement, sur un terminal vierge et sans compte :

```powershell
adb shell dpm set-device-owner "com.mdm2isy.agent.debug/com.mdm2isy.agent.admin.MdmDeviceAdminReceiver"
adb shell dpm list owners
```

La release utilise le package `com.mdm2isy.agent` au lieu de `com.mdm2isy.agent.debug`.

## Sécurité des commandes

Une commande suit la séquence locale suivante :

```text
RECEIVED -> ACKNOWLEDGED -> EXECUTING -> RESULT_PENDING -> FINAL
```

La commande est persistée avant l'ACK et le résultat avant son envoi. Une coupure réseau rejoue la transmission sans réexécuter l'action. `wipe_started=true` n'est enregistré qu'après l'appel Android d'effacement.

> L'effacement réinitialise réellement l'appareil. Le tester uniquement en dernier sur un émulateur jetable ou un Blackview de laboratoire explicitement autorisé.

## Recette matérielle

La validation logicielle locale ne remplace pas la recette constructeur. Exécuter [`docs/PLAN_DE_RECETTE.md`](../docs/PLAN_DE_RECETTE.md) sur un Blackview Rock 1 Pro Android 13+ avant la mise en production, en particulier pour les restrictions matérielles, le kiosque, la reprise après redémarrage et l'effacement.
