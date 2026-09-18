# Contrat d'enrôlement de l'agent Android

Ce document décrit le contrat HTTP déjà disponible pour le futur agent Android MDM.
Toutes les communications doivent utiliser HTTPS hors développement local.

## Cycle de vie

1. Un administrateur crée une invitation depuis `POST /api/device-enrollments`.
2. Le secret d'enrôlement est affiché une seule fois et transmis au terminal.
3. L'agent génère un UUID stable `device_uid`, collecte son inventaire puis appelle
   `POST /api/v1/device/enroll`.
4. Le serveur consomme définitivement l'invitation et renvoie un `device_token`.
5. L'agent conserve ce secret dans le stockage sécurisé Android et l'utilise comme
   Bearer token pour `POST /api/v1/device/heartbeat`.
6. Un administrateur peut révoquer l'identité avec
   `POST /api/terminals/{id}/revoke-credential`.

Les secrets ne sont jamais stockés en clair dans la base. Une invitation expirée,
révoquée ou déjà utilisée ne peut pas être rejouée.

## Créer une invitation

Requête authentifiée avec le jeton administrateur :

```http
POST /api/device-enrollments
Authorization: Bearer <admin-token>
Content-Type: application/json

{
  "organization_id": 2,
  "device_group_id": 7,
  "label": "Blackview livreur 24",
  "expires_in_minutes": 60
}
```

`organization_id` est requis pour un `super_admin` et déduit de la session pour un
administrateur client. La durée acceptée est comprise entre 5 et 10 080 minutes.

La réponse `201` contient `data.enrollment_token` et
`data.enrollment_payload`. Le secret ne sera plus présent dans les réponses de
liste ultérieures.

## Enrôler un terminal

```http
POST /api/v1/device/enroll
Content-Type: application/json

{
  "enrollment_token": "<one-time-token>",
  "device_uid": "00000000-0000-4000-8000-000000000000",
  "imei": null,
  "serial_number": "<serial-if-available>",
  "manufacturer": "Blackview",
  "model": "Rock 1",
  "android_version": "13",
  "android_build": "<build-id>",
  "agent_version": "0.1.0",
  "battery_level": 84,
  "storage_total_mb": 128000,
  "storage_free_mb": 96000
}
```

`device_uid` est obligatoire. L'IMEI est volontairement optionnel, car il n'est
pas garanti qu'Android autorise sa lecture. Une réponse `201` fournit
`data.device_id` et `data.device_token`. Le secret appareil n'est renvoyé qu'à
ce moment.

## Heartbeat et inventaire

```http
POST /api/v1/device/heartbeat
Authorization: Bearer <device-token>
Content-Type: application/json

{
  "battery_level": 82,
  "storage_total_mb": 128000,
  "storage_free_mb": 95000,
  "android_version": "13",
  "agent_version": "0.1.0",
  "latitude": 5.3599,
  "longitude": -4.0083
}
```

Les deux coordonnées doivent toujours être envoyées ensemble. L'organisation,
le groupe et le `device_uid` ne peuvent pas être modifiés par un heartbeat.
`last_seen_at` est mis à jour par le serveur ; un terminal est considéré en ligne
pendant cinq minutes après son dernier heartbeat.

## Limites de cette version

- Aucun agent Android n'est encore implémenté.
- La rotation contrôlée d'un secret appareil et le ré-enrôlement restent à ajouter.
- L'attestation matérielle et le certificate pinning ne sont pas encore intégrés.
- Le heartbeat ne distribue pas encore les commandes MDM ; le moteur de commandes
  sera la prochaine couche fonctionnelle.
