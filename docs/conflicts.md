# Conflits de réservation

Ce document décrit le système de détection, de classification et de résolution des conflits de réservation.

---

## Principe général

Lorsqu'une réservation est créée, le service `ReservationPreviewService` évalue si le créneau est faisable. Si des problèmes sont détectés, ils sont classés en deux catégories :

- **Hard-block** : création impossible (422). Le conflit est physiquement bloquant et ne peut pas être résolu par un agent.
- **Soft conflict** : création autorisée mais la réservation passe en statut `EN_CONFLIT`. Un agent peut résoudre le conflit manuellement ou il peut se résorber automatiquement.

---

## Les 12 types de conflits

Définis dans l'enum `App\Enums\ReservationConflictType` :

| Type | Label | Nature |
|---|---|---|
| `schedule_overlap` | Chevauchement de planning | Soft si chevauchement avec `EN_ATTENTE`, hard-block si avec `APPROUVE`/`CONFIRME` |
| `distance_not_calculated` | Distance non calculée | Soft — ORS et fallback Haversine ont tous deux échoué |
| `departure_before_08h` | Départ avant 08h00 | Soft |
| `return_after_17h` | Retour après 17h00 | Soft |
| `departure_before_allowed_time` | Départ avant l'heure autorisée | Soft |
| `return_after_allowed_time` | Retour après l'heure autorisée | Avertissement dérogation uniquement |
| `impossible_time_window` | Créneau impossible | Hard-block — aller-retour incohérent |
| `duration_over_allowed_limit` | Durée maximale dépassée | Soft |
| `quota_exceeded` | Quota annuel dépassé | Soft |
| `no_driver_available` | Aucun chauffeur disponible | Soft |
| `no_vehicle_available` | Aucun véhicule disponible | Soft |
| `vehicle_capacity_unavailable` | Capacité insuffisante | Soft |

---

## Hard-block vs soft conflict : le cas du chevauchement

Le type `schedule_overlap` est le seul à pouvoir être un hard-block :

```
Réservation B crée un chevauchement avec la réservation A

A.status = EN_ATTENTE   → B est créée en EN_CONFLIT (soft conflict, les deux coexistent)
A.status = APPROUVE     → B est rejetée avec 422 (hard-block)
A.status = CONFIRME     → B est rejetée avec 422 (hard-block)
```

La raison : un créneau `APPROUVE` ou `CONFIRME` est physiquement réservé au bus. Il ne peut pas être partagé.

---

## Statut de la réservation en conflit

Si au moins un soft conflict est détecté à la création :

- La réservation est créée avec le statut `EN_CONFLIT`
- Chaque conflit est persisté dans `reservation_conflicts` avec `is_resolved = false`
- La réservation reste bloquée tant qu'au moins un conflit reste non résolu

Dès que tous les conflits sont résolus (auto ou manuellement) :
- La réservation tente une transition automatique vers son statut logique (`EN_ATTENTE` ou `APPROUVE`)

---

## Résolution automatique

La résolution automatique ne concerne que les conflits de type `schedule_overlap`.

**Déclencheur** : lorsqu'une réservation A est annulée ou refusée, le système recherche toutes les réservations B qui avaient un conflit `schedule_overlap` lié à A.

Pour chaque conflit lié :
1. Le conflit est marqué `is_resolved = true`
2. Si la réservation B n'a plus aucun conflit non résolu → elle passe automatiquement en `EN_ATTENTE`

Les autres types de conflits (`distance_not_calculated`, `quota_exceeded`, etc.) ne sont **jamais** résolus automatiquement.

---

## Résolution manuelle

Un agent (permission `reservations.manage-conflicts`) peut résoudre un conflit manuellement :

```
PATCH /api/reservations/{id}/conflicts/{conflictId}/resolve
```

La résolution déclenche le même mécanisme de transition automatique que la résolution auto.

---

## Listing des conflits

```
GET /api/reservations/{id}/conflicts
```

Retourne la liste des conflits (résolus et non résolus) pour une réservation donnée.

---

## Schéma de la table `reservation_conflicts`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `reservation_id` | bigint FK | Réservation concernée |
| `type` | varchar(80) | Valeur de `ReservationConflictType` |
| `message` | varchar(500) | Message lisible |
| `related_reservation_id` | bigint FK nullable | Réservation en chevauchement (pour `schedule_overlap`) |
| `is_resolved` | boolean | false tant que non résolu |
| `resolved_by_user_id` | bigint FK nullable | Agent qui a résolu (null si auto) |
| `resolved_at` | timestamp nullable | |
| `created_at` | timestamp | Immuable — pas de `updated_at` |

---

## Services impliqués

| Service | Rôle |
|---|---|
| `ReservationPreviewService` | Détecte les conflits avant création, calcule le statut initial |
| `ReservationService` | Orchestre la création, persiste les conflits, déclenche la résolution auto |
| `ReservationConflictResolutionService` | Résolution auto (à l'annulation d'une réservation bloquante) |
| `LocationService` | Calcul de distance ORS + fallback Haversine |
| `ReservationOccupationService` | Calcul de la fenêtre d'occupation réelle (départ → retour + buffer) |