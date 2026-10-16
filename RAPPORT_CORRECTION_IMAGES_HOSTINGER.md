# Rapport — correction définitive de l’exposition des images

Date : 16 octobre 2026  
Projet : SPI Filters

## 1. Cause identifiée

La configuration Laravel est cohérente :

```text
disque public : storage/app/public
URL publique  : {APP_URL}/storage
lien attendu  : public/storage -> storage/app/public
```

Le parcours d’upload utilise bien le disque `public` et enregistre un chemin relatif, par exemple `produits/nom-genere.png`.

Le diagnostic concret du workspace local a toutefois montré :

- `public/storage` existe comme **dossier réel**, pas comme symlink ;
- il contient déjà des fichiers réels dans `public/storage/produits/` ;
- `storage/app/public/produits/` existe aussi et contient d’autres fichiers ;
- les deux emplacements sont donc distincts.

Cela explique le symptôme : Laravel écrit dans `storage/app/public/produits`, tandis que le serveur web peut servir le dossier réel `public/storage/produits`, qui n’est pas la même arborescence. La présence d’une ancienne image accessible ne prouve donc pas que les nouvelles images sont publiées.

Les chemins locaux résolus sont :

```text
public_path()  = D:\Hamza El Asly\Projets\Mostafa\spifilter\public
storage_path() = D:\Hamza El Asly\Projets\Mostafa\spifilter\storage
```

La configuration et le contenu de production Hostinger ne peuvent pas être vérifiés depuis ce workspace.

## 2. Corrections réalisées

### Contrôleur

`ProduitController` utilise `Storage::disk('public')->putFileAs(...)` et conserve uniquement le chemin relatif retourné par Laravel.

Lors d’un remplacement :

1. le nouveau fichier est validé et écrit ;
2. le produit est mis à jour en base ;
3. l’ancienne image est supprimée uniquement après succès ;
4. en cas d’échec, l’ancienne image reste conservée et le nouveau fichier est nettoyé.

Les erreurs indiquent l’étape, l’ID produit, le disque, le chemin relatif, la classe et le message de l’exception sans exposer de chemin interne à l’utilisateur.

### Vue Blade

Les URLs sont maintenant générées avec :

```php
Storage::disk('public')->url($produit->image)
```

La vue ne reconstruit donc plus manuellement l’URL avec `asset('storage/...')`. Elle utilise directement l’URL configurée pour le disque public.

## 3. Fichiers modifiés

- `app/Http/Controllers/ProduitController.php` ;
- `resources/views/produits/index.blade.php` ;
- `tests/Feature/ProductImageUploadTest.php`.

Les fichiers existants, les catégories et la synchronisation WooCommerce n’ont pas été modifiés dans cette correction.

## 4. Tests

Les tests utilisent `Storage::fake('public')` et ne touchent pas la production. Ils couvrent :

- création avec image ;
- remplacement d’image ;
- modification sans nouvelle image ;
- affichage avec l’URL du disque public ;
- format non autorisé ;
- fichier trop volumineux ;
- échec d’écriture ;
- conservation de l’ancienne image après échec ;
- échec base de données après upload et nettoyage du nouveau fichier.

Commandes exécutées après cette modification :

```bash
php artisan test tests/Feature/ProductImageUploadTest.php --no-coverage
Tests: 9 passed (45 assertions)

php artisan test --no-coverage
Tests: 41 passed (182 assertions)

vendor/bin/pint app/Http/Controllers/ProduitController.php
passed
```

Le build et les tests utilisent uniquement l’environnement local et `Storage::fake('public')` pour les tests d’écriture.

## 5. Réparation sûre sur Hostinger

Depuis la vraie racine Laravel, adapter uniquement le chemin de connexion :

```bash
cd /chemin/reel/de/laravel
TARGET="$PWD/storage/app/public"
LINK="$PWD/public/storage"
mkdir -p "$TARGET/produits"

php artisan about
ls -ld "$PWD/public" "$TARGET" "$TARGET/produits" "$LINK"
```

Vérifier le lien existant avant toute action :

```bash
if [ -L "$LINK" ]; then
    echo "Symlink actuel : $(readlink -f "$LINK")"
    echo "Cible attendue : $(readlink -f "$TARGET")"
elif [ -e "$LINK" ]; then
    echo "ATTENTION : public/storage est un dossier ou fichier réel"
    find "$LINK" -mindepth 1 -maxdepth 3 -print
else
    echo "public/storage est absent"
fi
```

Si `public/storage` est absent, ou si c’est un symlink incorrect, le réparer ainsi après vérification :

```bash
BACKUP="$LINK.backup.$(date +%Y%m%d%H%M%S)"
test ! -e "$BACKUP" || { echo "Sauvegarde déjà existante : $BACKUP"; exit 1; }

if [ -L "$LINK" ] || [ -e "$LINK" ]; then
    mv "$LINK" "$BACKUP"
fi

php artisan storage:link
test "$(readlink -f "$LINK")" = "$(readlink -f "$TARGET")"
```

Si `public/storage` est un dossier réel contenant des images utiles, ne pas le supprimer. Le conserver dans `$BACKUP`, puis examiner les fichiers avant une migration contrôlée vers la cible canonique :

```bash
rsync -a -n --ignore-existing "$BACKUP/" "$TARGET/"
```

Après contrôle du résultat de la simulation, utiliser éventuellement :

```bash
rsync -a --ignore-existing "$BACKUP/" "$TARGET/"
```

Cette copie est une étape de migration contrôlée ; elle ne doit pas devenir un double stockage permanent. Le lien symbolique doit ensuite être la seule exposition publique.

Vérifier ensuite :

```bash
readlink -f "$LINK"
test -d "$TARGET/produits" && test -w "$TARGET/produits"
find "$TARGET/produits" -maxdepth 1 -type f -printf '%M %u:%g %s %p\n' | head
php artisan optimize:clear
```

Ne pas utiliser `chmod 777`. Si l’écriture échoue, relever le propriétaire, le groupe et les permissions avec `ls -ld`, puis appliquer les droits adaptés au compte PHP/FPM fourni par Hostinger.

## 6. Vérification HTTP en production

Créer ou modifier un produit de test, puis récupérer dans la base la valeur `image`. Elle doit être uniquement relative :

```text
produits/nom-genere.png
```

Tester ensuite l’URL correspondante :

```bash
curl -I "https://stockfilter.spifilters.com/storage/produits/nom-genere.png"
```

Le résultat attendu est HTTP 200, sans 403 ni 404. Vérifier également une ancienne image et une image remplacée.

Pour contrôler les chemins runtime :

```bash
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; echo $app->publicPath(), PHP_EOL; echo $app->storagePath(), PHP_EOL;'
php artisan tinker --execute="dump(config('filesystems.disks.public.root'), config('filesystems.disks.public.url'));"
```

Ces commandes et le test HTTP doivent être exécutés sur Hostinger. Ils n’ont pas été exécutés en production depuis ce workspace.

## 7. Alternative si les symlinks sont interdits

Ne pas l’activer sans confirmer le refus de `php artisan storage:link` et les chemins réels du document root. L’alternative consiste à configurer explicitement la racine du disque `public` vers un répertoire réellement servi par Hostinger, puis à conserver `Storage::disk('public')->url(...)`. Cette option nécessite une modification de configuration et un test HTTP ; elle n’est pas appliquée par défaut afin de préserver la convention Laravel recommandée.

## 8. Conclusion

La cause structurelle est démontrée localement : `public/storage` n’est pas le lien attendu et contient une arborescence indépendante. Le code Laravel écrit désormais sur le disque public configuré et la vue utilise son URL officielle. La résolution effective en production dépend encore de la réparation contrôlée du lien Hostinger et d’une vérification HTTP réelle.
