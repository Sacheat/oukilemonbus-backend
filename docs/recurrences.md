# Récurrences de réservation

Ce document décrit le système de réservations récurrentes : création, prévisualisation, règles d'éligibilité, auto-confirmation et annulation.

---

## Principe

Une récurrence génère automatiquement plusieurs réservations selon une fréquence et une plage de dates. Chaque occurrence est une réservation indépendante reliée à la même `Recurrence` parente.

Seules les organisations dont le type est configuré dans `reservations.recurrence_allowed_types` peuvent utiliser ce module. Par défaut : `ecole_communale`.

---

## Fréquences supportées

| `frequency` | Description |
|---|---|
| `hebdomadaire` | Une occurrence par semaine |
| `bihebdomadaire` | Deux occurrences par semaine (jours configurables) |
| `mensuel` | Une occurrence par mois |

Le champ `days_of_week` (ex: `"1,3"` pour lundi et mercredi) précise les jours pour les fréquences hebdomadaires et bihebdomadaires.

---

## Prévisualisation

Avant de créer une récurrence, le frontend appelle l'endpoint de prévisualisation pour afficher le nombre et les dates des occurrences générées :

```
GET /api/recurrences/preview
```

Paramètres : `organization_id`, `frequency`, `days_of_week`, `start_date`, `end_date`, `departure_time`, `return_time`, etc.

Retourne :
- La liste des dates qui seraient générées
- Le nombre d'occurrences valides
- Le nombre d'occurrences ignorées (dates fermées, weekends, hors plage)

---

## Création

```
POST /api/recurrences
```

Les occurrences sont générées en une seule transaction. Chaque occurrence est une réservation à part entière avec :
- Son propre cycle de vie (statut, conflits, affectation)
- Un lien vers la `Recurrence` parente via `reservation.recurrence_id`

### Dates exclues automatiquement

- Weekends (samedi, dimanche)
- Dates fermées (`reservations.closed_dates` dans la config)
- Dates hors de la plage `start_date` → `end_date`

### Auto-confirmation pour les écoles communales

Pour les organisations dont le type est configuré comme auto-approuvé (`reservations.auto_approve_types`), les occurrences passent directement en `CONFIRME` à la création, sans passer par `EN_ATTENTE`.

---

## Annulation

```
PATCH /api/recurrences/{id}/cancel
```

Permission requise : `recurrences.cancel` (tous les rôles sauf `chauffeur`).

L'annulation est en cascade : toutes les réservations de la récurrence dont le statut est `EN_ATTENTE`, `APPROUVE` ou `CONFIRME` sont annulées. Les réservations déjà `REFUSE` ou `ANNULE` ne sont pas touchées.

Le nombre de réservations effectivement annulées est retourné dans la réponse.

---

## Listing et détail

```
GET /api/recurrences          Liste des récurrences (filtrées selon le rôle)
GET /api/recurrences/{id}     Détail d'une récurrence avec ses occurrences
```

---

## Schéma de la table `recurrences`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `frequency` | varchar(50) | `hebdomadaire`, `bihebdomadaire`, `mensuel` |
| `interval_value` | int | Nombre de jours entre occurrences |
| `days_of_week` | varchar(50) nullable | Ex: `"1,3"` pour lundi et mercredi |
| `start_date` | date | Début de la plage |
| `end_date` | date | Fin de la plage |
| `occurrence_count` | int nullable | Nombre max d'occurrences (optionnel) |
| `is_active` | boolean | false si annulée |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

La table `recurrences` est autonome (pas de FK sur `reservations`). C'est `reservations.recurrence_id` qui porte la relation.

---

## Règles métier importantes

- `interval_value > 0`
- `end_date >= start_date`
- `occurrence_count IS NULL OR occurrence_count > 0`
- Le nombre d'occurrences générées peut être inférieur au nombre théorique si des dates sont exclues
- Chaque occurrence peut avoir ses propres conflits (indépendamment des autres)
- Une occurrence en `EN_CONFLIT` n'annule pas les autres occurrences de la récurrence

---

## Services impliqués

| Service | Rôle |
|---|---|
| `RecurrenceService` | Création, prévisualisation, annulation en cascade |
| `ReservationService` | Création de chaque occurrence individuelle |
| `DomainLogger` | Trace `recurrenceCreated` et `recurrenceCancelled` |