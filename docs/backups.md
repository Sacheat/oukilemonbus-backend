# Strategie de backups

Ce document decrit la V1 du module de backup.

Les archives sont chiffrees (`BackupEncryptor`, libsodium secretstream XChaCha20-Poly1305) et le backup tourne chaque nuit a 03:30. En production, un backup non chiffre est refuse. Copie hors VM : `deploy/backup-offsite.sh` (rclone), voir `deploy/README.md`.

## Objectif

Pouvoir creer un backup manuel ou planifie contenant :

- un dump SQL de la base de donnees ;
- les fichiers prives generes par l'application ;
- les fichiers publics de stockage Laravel ;
- un fichier `metadata.json` de description ;
- les logs uniquement si l'option explicite est activee.


```

## Format

La V1 genere des archives `.tar.gz`.

L'extension PHP `zip` n'est pas requise. Cela rend la commande plus portable sur Windows/Laragon.

Structure interne :

```text
metadata.json
database/
  dump.sql
files/
  private/
  public/
logs/
```

`logs/` est present uniquement si `--include-logs` est utilise.

## Emplacement

Par defaut :

```text
storage/app/private/backups
```

Cet emplacement est volontairement hors du dossier `public/`.

Configuration :

```env
BACKUP_PATH=
BACKUP_RETENTION_DAYS=14
BACKUP_ENCRYPTION_ENABLED=true
BACKUP_ENCRYPTION_KEY=base64:...   # php artisan backup:generate-key
```

## Chiffrement

Avec le chiffrement, l'archive `.tar.gz` est chiffree en `.tar.gz.enc` puis la version en clair est supprimee. Format : `BCBK1` + en-tete secretstream + blocs de 64 Ko authentifies ; une archive modifiee, tronquee ou dechiffree avec une autre cle est refusee.

Sans la cle, une archive est irrecuperable : la garder hors du serveur. Changer de cle rend les anciennes archives illisibles avec la nouvelle (garder l'ancienne tant que des archives chiffrees avec elle existent).

Une cle absente ou invalide fait echouer `backup:run` avant toute ecriture.

Si `BACKUP_PATH` est vide, Laravel utilise le chemin par defaut defini dans `config/backups.php`.

## Retention

Apres chaque backup reussi, les archives `.tar.gz` et `.tar.gz.enc` plus anciennes que `BACKUP_RETENTION_DAYS` sont supprimees.

Valeur recommandee de depart :

```env
BACKUP_RETENTION_DAYS=14
```

## Ce qui est sauvegarde

Backup complet :

- base MySQL ;
- `storage/app/private` ;
- `storage/app/public` ;
- metadata.

Les backups existants sont exclus pour eviter une recursion.

Les dossiers framework temporaires sont exclus :

- cache ;
- sessions ;
- testing ;
- vues compilees.

## Ce qui n'est pas sauvegarde

Par defaut :

- `.env` ;
- logs ;
- `vendor/` ;
- `node_modules/` ;
- cache framework ;
- fichiers temporaires de tests ;
- backups precedents.

Les logs peuvent etre ajoutes avec `--include-logs`, mais ils restent separes dans l'archive.

## Securite

Les backups sont tres sensibles. Ils peuvent contenir :

- utilisateurs ;
- organisations ;
- reservations ;
- decisions ;
- documents officiels ;
- historiques ;
- fichiers PDF.

Regles :

1. Ne jamais stocker les backups dans `public/`.
2. Ne jamais envoyer une archive non chiffree dans un espace public.
3. Limiter l'acces serveur aux administrateurs techniques.
4. Ne pas inclure `.env` dans l'archive.
5. Stocker une copie hors serveur en production.
6. Activer le chiffrement avant stockage distant non maitrise.

## Restauration

La V1 ne fournit pas de commande `backup:restore`.

C'est volontaire : une restauration automatique peut ecraser des donnees de production. La restauration doit rester une operation technique controlee.

Procedure generale :

1. Dechiffrer l'archive avec la meme `BACKUP_ENCRYPTION_KEY` : `php artisan backup:decrypt <archive>.tar.gz.enc`, puis extraire le `.tar.gz` obtenu. Supprimer ce fichier en clair une fois la restauration faite.
2. Restaurer la base :

```bash
mysql -u USER -p DATABASE < database/dump.sql
```

3. Recopier les fichiers :

```text
files/private/* -> storage/app/private/
files/public/*  -> storage/app/public/
```

4. Verifier les permissions fichiers.
5. Verifier la configuration `.env`.
6. Relancer les tests ou une verification fonctionnelle.

## Production

Un backup stocke sur le meme serveur protege contre une erreur applicative ou humaine.

Il ne protege pas contre :

- panne disque ;
- suppression serveur ;
- ransomware ;
- incident hebergeur ;
- incendie ou vol machine.

Pour la production, prevoir une copie supplementaire :

- disque externe ;
- NAS communal ;
- S3 compatible ;
- serveur distant securise.

## Evolution V1.1

Prochaine etape recommandee :

- notification en cas d'echec ;
- verification periodique d'une restauration.

Fait : chiffrement et integrite (BackupEncryptor), planification (03:30), copie hors serveur (`deploy/backup-offsite.sh`).

## RGPD

Une donnee effacee ou anonymisee (privacy:prune, anonymisation d'un compte) reste dans les archives jusqu'a leur expiration : 14 jours sur le serveur, `OFFSITE_RETENTION_DAYS` (30 par defaut) sur la copie distante. Une restauration doit donc etre suivie de `php artisan privacy:prune` et, si des comptes ont ete anonymises depuis l'archive, de leur nouvelle anonymisation.
