# API du moteur de commandes MDM

Ce document décrit le contrat entre la console d'administration et le futur
agent Android. L'agent Android n'est pas encore présent dans ce dépôt : les
commandes restent donc en file jusqu'à ce qu'un agent les récupère.

## Cycle de vie

Une commande traverse uniquement les états suivants :

```text
queued -> sent -> acknowledged -> succeeded
              \                 -> failed
               \-> succeeded | failed

queued | sent | acknowledged -> expired
```

- `queued` : la commande a été créée par un administrateur.
- `sent` : elle a été remise au moins une fois à l'agent.
- `acknowledged` : l'agent confirme l'avoir reçue avant son exécution.
- `succeeded` / `failed` : l'agent a fourni le résultat final.
- `expired` : aucun résultat final n'est arrivé avant l'échéance.

Une lecture répétée par l'agent peut renvoyer une commande déjà `sent`, après
un délai minimal de 15 secondes et au maximum 20 fois. C'est volontaire :
l'agent doit mémoriser le `public_id` et exécuter chaque commande une seule
fois, même après une perte réseau ou un redémarrage. Les nouvelles commandes
sont servies avant les redélivrances et, à priorité égale, dans l'ordre
`wipe`, `lock`, puis `locate`.

## Authentification

- Les routes d'administration utilisent un jeton Sanctum d'un utilisateur de
  rôle `admin` ou `super_admin`.
- Les routes `/api/v1/device/*` utilisent exclusivement le jeton permanent de
  l'appareil obtenu lors de l'enrôlement.
- Un appareil ne voit et ne modifie que ses propres commandes.
- Les quotas sont calculés par identité appareil, afin que des terminaux
  derrière la même adresse NAT ne se bloquent pas mutuellement.
- Une commande ne peut être créée que pour un terminal enrôlé dont
  l'identité appareil n'a pas été révoquée.
- Une organisation désactivée ne peut plus utiliser son identité appareil.

## Créer une commande depuis la console

```http
POST /api/terminals/{terminal_public_id}/commands
Authorization: Bearer <admin-token>
Idempotency-Key: <uuid-client>
Content-Type: application/json

{
  "type": "locate"
}
```

Les types disponibles dans cette première version sont `locate`, `lock` et
`wipe`. `Idempotency-Key` évite de créer deux commandes lors d'un double clic
ou d'une nouvelle tentative HTTP. Réutiliser la même clé pour une autre cible
ou un autre type est refusé avec un conflit HTTP 409. Le serveur ne conserve
pas la clé brute : il stocke un condensat SHA-256 lié à l'administrateur, avec
une comparaison sensible à la casse.

L'effacement requiert une confirmation renforcée :

```json
{
  "type": "wipe",
  "confirmation": "<public_id exact du terminal>",
  "current_password": "<mot de passe courant de l'administrateur>"
}
```

Le mot de passe sert uniquement à valider la requête. Il n'est jamais copié
dans la commande, les événements d'audit ou le résultat.

Les raccourcis historiques suivants créent une commande avec les mêmes règles
de sécurité :

```text
POST /api/terminals/{id}/locate
POST /api/terminals/{id}/lock
POST /api/terminals/{id}/wipe
```

## Consulter l'historique

```http
GET /api/terminals/{terminal_public_id}/commands
Authorization: Bearer <admin-token>
```

La réponse est limitée au périmètre d'organisation de l'administrateur et
contient les horodatages, l'état courant et les événements d'audit. Un
`super_admin` doit transmettre le contexte d'organisation demandé lorsque
l'endpoint le prévoit.

## Récupérer les commandes sur l'appareil

```http
GET /api/v1/device/commands?limit=10
Authorization: Bearer <device-token>
```

L'appel remet les commandes non expirées de cet appareil et fait passer les
nouvelles commandes de `queued` à `sent`. La limite côté serveur empêche un
agent défaillant de demander un volume non borné.

Après persistance locale, l'agent peut acquitter la réception :

```http
POST /api/v1/device/commands/{command_public_id}/ack
Authorization: Bearer <device-token>
```

Puis il publie un résultat final :

```http
POST /api/v1/device/commands/{command_public_id}/result
Authorization: Bearer <device-token>
Content-Type: application/json

{
  "status": "succeeded",
  "result": {}
}
```

En cas d'échec :

```json
{
  "status": "failed",
  "error_code": "DEVICE_POLICY_REJECTED",
  "error_message": "La politique Android a refusé l'opération."
}
```

Pour `locate`, un succès doit inclure `lat` et `lng`; le serveur valide leurs
bornes avant de mettre à jour la dernière position connue. Un succès `lock`
doit confirmer `locked: true` avant de placer l'état de gestion à `locked`.
Un succès `wipe` doit confirmer `wipe_started: true`; il place alors l'état à
`wiped`, révoque immédiatement l'identité de l'appareil et expire ses autres
commandes encore en attente. L'état `wiped` ne peut pas être écrasé par le
résultat concurrent d'une ancienne commande de verrouillage.

Les appels d'acquittement et de résultat sont idempotents. Un résultat final
identique peut être rejoué, tandis qu'un second résultat contradictoire est
refusé.

## Expiration et exploitation

L'expiration est vérifiée pendant les lectures et les transitions. En
production, le scheduler Laravel doit également être exécuté chaque minute :

```shell
php artisan schedule:run
```

Cette première version utilise du polling authentifié. Une notification FCM
pour réveiller l'agent pourra être ajoutée plus tard sans changer la machine
d'états ni le contrat d'idempotence.
