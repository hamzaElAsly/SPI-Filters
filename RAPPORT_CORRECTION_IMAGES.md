# Rapport de diagnostic et correction — upload des images

Date : 15 octobre 2026  
Périmètre : upload des images des produits, sans modification de WooCommerce, du mapping, des données de production ou des migrations.

## 1. Diagnostic du parcours

### Formulaires

Les deux formulaires de `resources/views/produits/index.blade.php` sont correctement configurés :

- création : `method="POST"`, `enctype="multipart/form-data"`, champ `name="image"` (`:200-205`) ;
- modification : `method="POST"` avec `@method('PUT')`, `enctype="multipart/form-data"`, champ `name="image"` (`:240-245`) ;
- l’action de modification est construite par JavaScript, mais le formulaire est soumis normalement et aucun code JavaScript ne supprime ou ne transforme le fichier ;
- l’attribut `accept` limite l’interface aux formats JPG, PNG et WEBP, mais la validation serveur reste la protection effective.

Avant correction, les erreurs du sac `storeProduit`/`updateProduit` n’étaient pas affichées sous les champs image. Le fichier peut donc être refusé sans explication visible dans la modale.

### Contrôleur et stockage

Avant correction, `ProduitController` appelait bien `store('produits', 'public')`, ce qui produit normalement un chemin relatif comme `produits/nom-genere.png`. Le disque `public` est défini dans `config/filesystems.php` avec la racine `storage/app/public` et une URL `/storage`.

L’anomalie confirmée était la gestion des retours d’échec :

- `store()` ne vérifiait pas que l’écriture retournait un chemin valide ; un retour `false` pouvait être enregistré dans `produits.image` ;
- `update()` supprimait l’ancienne image avant d’avoir réussi à écrire la nouvelle et à mettre à jour la base ;
- une erreur d’écriture pouvait donc supprimer l’ancien fichier, laisser la base incohérente ou créer un fichier orphelin ;
- les exceptions d’upload n’étaient ni journalisées au niveau métier ni présentées clairement à l’utilisateur.

La cause Hostinger elle-même n’est pas démontrée depuis le workspace : permissions, lien symbolique et configuration effective du serveur de production restent à vérifier sur SSH ou dans hPanel.

## 2. Corrections réalisées

### `app/Http/Controllers/ProduitController.php`

- Ajout d’une méthode d’écriture centralisée qui exige un chemin relatif commençant par `produits/`.
- Création : si l’écriture ou la création en base échoue, le produit n’est pas considéré comme créé, le fichier temporaire est nettoyé et une erreur générique est renvoyée dans le sac `storeProduit`.
- Modification : l’ordre est désormais strictement :

  1. validation FormRequest ;
  2. écriture de la nouvelle image ;
  3. mise à jour du produit en base ;
  4. suppression de l’ancienne image.

- Si l’écriture échoue, l’ancienne image et sa valeur en base restent intactes.
- Si la base échoue après l’écriture, le nouveau fichier est supprimé et l’ancienne image reste conservée.
- Si la suppression de l’ancienne image échoue après une mise à jour réussie, l’événement est journalisé et un avertissement utilisateur est affiché.
- Les logs incluent l’étape, l’ID produit, le disque, le chemin relatif, la classe et le message de l’exception. Aucun contenu d’image ni secret n’est journalisé.

### `resources/views/produits/index.blade.php`

Les messages d’erreur du champ image sont maintenant affichés dans les modales de création et de modification, sans exposer de chemin interne du serveur.

### `tests/Feature/ProductImageUploadTest.php`

Ajout de huit tests avec `Storage::fake('public')` et base SQLite mémoire :

- création avec image et chemin relatif ;
- remplacement d’image et suppression de l’ancienne après succès ;
- modification sans image et conservation de l’existante ;
- format non autorisé ;
- image dépassant 5 Mo ;
- échec d’écriture du disque ;
- conservation de l’ancienne image lors d’un échec d’écriture ;
- échec de base après upload, nettoyage du nouveau fichier et conservation de l’ancien.

Les tests n’utilisent aucun fichier réel de production ni aucune base de production.

## 3. Validation effectuée

Commandes exécutées :

```text
php artisan test tests/Feature/ProductImageUploadTest.php --no-coverage
Tests: 8 passed (43 assertions)

php artisan test --no-coverage
Tests: 33 passed (148 assertions)

vendor/bin/pint --test app/Http/Controllers/ProduitController.php tests/Feature/ProductImageUploadTest.php
passed
```

La suite complète couvre également les contrôles d’accès et les tests WooCommerce existants. Aucun appel WooCommerce réel n’a été déclenché.

## 4. Vérifications Hostinger nécessaires

Ces vérifications n’ont pas été exécutées : le workspace ne fournit pas d’accès SSH à Hostinger. Depuis la racine réelle du projet, avec le compte de déploiement, demander ou exécuter les commandes suivantes sans utiliser `chmod 777` :

```bash
cd <RACINE_DU_PROJET>
php artisan about
ls -ld storage storage/app storage/app/public storage/app/public/produits public public/storage
test -L public/storage && readlink -f public/storage || echo "public/storage n'est pas un lien symbolique"
php -r 'var_dump(is_dir("storage/app/public/produits"), is_writable("storage/app/public/produits"));'
curl -I https://stockfilter.spifilters.com/storage/produits/3dDxzcUffvJJXHU1t3AFsHaWTWS7UhLFxEE0iU3D.png
```

À confirmer côté production :

1. `APP_URL` doit être `https://stockfilter.spifilters.com` dans la configuration effective ;
2. `config('filesystems.disks.public.root')` doit viser `storage/app/public` ;
3. `public/storage` doit pointer vers `storage/app/public`, ou être configuré équivalemment par Hostinger ;
4. le compte PHP/FPM doit pouvoir écrire dans `storage/app/public/produits` et le serveur web doit pouvoir lire `public/storage/produits` ;
5. après déploiement, le cache de configuration ne doit pas conserver une ancienne valeur d’`APP_URL` ;
6. la valeur enregistrée dans `produits.image` doit rester relative, par exemple `produits/nom.png`, jamais une URL complète ni `storage/app/public/produits/...`.

Le fichier déjà accessible manuellement prouve uniquement que cette URL précise est servie. Il ne prouve pas que le processus PHP de production peut écrire dans le répertoire cible.

## 5. État final et limites

Le code local corrige les anomalies confirmées du parcours d’upload et la suite de tests est verte. Aucun changement n’a été apporté à `WooCommerceService`, au mapping, aux migrations ou aux données.

Reste non vérifié : permissions effectives Hostinger, lien `/storage` en production, valeur runtime d’`APP_URL`, configuration PHP d’upload (`upload_max_filesize`/`post_max_size`) et accessibilité réelle d’une image nouvellement uploadée depuis Internet. Ces points nécessitent un contrôle sur le serveur de production, idéalement avec un produit de test non sensible.

