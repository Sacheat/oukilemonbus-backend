# Tiers et sous-traitants (RGPD)

Base technique pour le registre des traitements (art. 30) et les contrats de sous-traitance (art. 28) : ce que
l'application envoie hors du serveur, à qui et pourquoi. Les colonnes « Hébergement » et « Contrat » sont **à
confirmer** par la commune et son DPO auprès de chaque fournisseur ; rien ici ne remplace leur vérification.

À tenir à jour : tout nouvel appel sortant (`Http::`, nouveau canal de notification, nouveau stockage) s'ajoute ici.

---

## Sous-traitants (reçoivent des données de façon automatique)

| Tiers | Code | Données envoyées | Finalité | Hébergement | Contrat |
|---|---|---|---|---|---|
| **OpenRouteService** (HeiGIT) | `LocationService`, `TripRouteService`, `ReservationPreviewService` ; `ORS_BASE_URL` | Texte d'adresse saisi (autocomplétion, géocodage), coordonnées départ/destination (distance, durée, tracé). **Aucun nom, email ni téléphone.** Une adresse de domicile reste une donnée personnelle | Géocodage, calcul des trajets | à confirmer (Allemagne annoncée) | Conditions du service et DPA à vérifier ; clé API au nom de la commune |
| **Brevo** (SMTP) | `MAIL_*` ; toutes les notifications email | Email et prénom du destinataire ; contenu des notifications : trajets (date, lieux, organisation, passagers), motifs de refus ou d'annulation, liens signés vers les documents ; récapitulatifs ; avertissement d'inactivité | Envoi des emails | à confirmer | DPA Brevo à signer ; domaine d'envoi authentifié (SPF, DKIM) |
| **GeoNames** | `GeoNamesClient` ; `GEONAMES_BASE_URL` (HTTPS) | Nom ou code postal d'une commune, identifiant du compte GeoNames. **Aucune donnée d'utilisateur** | Liste des localités d'une commune | à confirmer | Conditions d'usage du service gratuit |
| **Destination des backups** (choisie par la commune) | `deploy/backup-offsite.sh` (rclone) | Archives **chiffrées** de la base et des fichiers ; la clé ne quitte pas la commune | Copie hors serveur | selon le choix (NAS communal = pas de sous-traitant) | DPA si fournisseur externe |
| **Hébergeur de la VM** | — | Tout (base, fichiers, logs, backups locaux) | Hébergement | à confirmer | Contrat d'hébergement avec clause RGPD |

## Destinataires sans compte (envoi voulu par la commune)

| Destinataire | Code | Données | Condition |
|---|---|---|---|
| **Contact de garde** | `DriverAlertService`, `DriverAlertOnCallMail` | Téléphone du chauffeur, sa position (ou le dernier lieu connu), son message, le trajet et les passagers prévus | Seulement si `on_call_email` est rempli (Communes) ; le vider coupe l'envoi. Voir `docs/driver_journey.md` |

## Envois déclenchés par l'utilisateur (pas de transfert automatique)

| Tiers | Où | Données |
|---|---|---|
| Google Maps, OpenStreetMap | Liens « carte » et « itinéraire » (alertes, feuilles de route, Ma journée) ; `config/driver_journey.php` | Les coordonnées du lien, envoyées par le navigateur **au clic seulement** |

## Désactivés par défaut

| Tiers | Réglage | Remarque |
|---|---|---|
| Slack (logs critiques) | canal `slack` de `config/logging.php`, absent de `LOG_STACK` | Les logs ne contiennent que des identifiants (pas de nom ni d'email) ; à déclarer si activé |
| Postmark, Resend, SES | `config/services.php` | Non utilisés ; à déclarer si l'un remplace Brevo |

## Hors de l'application

L'application ne fait appel à aucun outil d'analyse d'audience, de suivi d'erreurs externe (Sentry…) ni de
publicité. La SPA (dépôt séparé) a ses propres tiers éventuels (polices, CDN) à recenser de son côté.
