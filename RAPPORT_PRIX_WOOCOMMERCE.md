# Rapport de correction — prix et synchronisation WooCommerce

Date : 16 octobre 2026  
Projet : SPI Filters

## 1. Périmètre et diagnostic

L’analyse a porté sur le modèle `Produit`, les migrations, les Form Requests, le contrôleur, le formulaire Blade, le JavaScript, `WooCommerceService` et les tests existants.

Les anomalies confirmées étaient les suivantes :

- la table ne possédait pas encore de colonne `old_prix` ;
- aucun calcul serveur centralisé ne garantissait la cohérence de l’ancien prix ;
- le payload WooCommerce utilisait la description comme nom et envoyait `prix_vente` comme `regular_price` ;
- `sale_price` et la métadonnée privée `_spi_prix_achat` n’étaient pas envoyés ;
- la gestion d’upload image contournait le disque Laravel `public`, alors que la configuration et les tests utilisent `storage/app/public` ;
- la suite de tests démontrait que cette divergence empêchait de simuler correctement une erreur d’écriture.

La recherche d’un SKU existant, la conservation des identifiants WooCommerce, la synchronisation des catégories et le repli sans image en cas d’erreur HTTP ont été conservés.

## 2. Fichiers créés ou modifiés

Créés :

- `database/migrations/2026_10_16_000005_add_old_prix_to_produits_table.php` ;
- `app/Console/Commands/BackfillOldPrices.php` ;
- `tests/Feature/ProductPriceTest.php` ;
- `RAPPORT_PRIX_WOOCOMMERCE.md`.

Modifiés :

- `app/Models/Produit.php` ;
- `app/Services/WooCommerceService.php` ;
- `app/Http/Controllers/ProduitController.php` ;
- `resources/views/produits/index.blade.php` ;
- `resources/js/app.js` ;
- `tests/Feature/WooCommerceServiceTest.php` ;
- `tests/Feature/ProductImageUploadTest.php`.

Les Form Requests existants conservent les règles `numeric|min:0` pour `prix_achat` et `prix_vente`, ainsi que les règles d’image JPG/JPEG/PNG/WEBP avec une limite de 5 Mo. `old_prix` n’est volontairement pas accepté comme donnée métier du formulaire.

## 3. Calcul de `old_prix`

Le calcul est centralisé dans `Produit::calculateOldPrice()` :

```text
old_prix = round(prix_vente × 1,35, 2 décimales)
```

Les événements Eloquent `saving` recalculent cette valeur à chaque création et modification, y compris lors d’une modification de plusieurs champs. Une valeur `old_prix` envoyée par le navigateur est donc ignorée ou écrasée côté serveur.

Les valeurs nulles, non numériques, négatives ou non finies ne produisent pas de prix dérivé. Les validations HTTP empêchent ces valeurs dans le parcours normal.

La colonne est ajoutée en `DECIMAL(12,2) NULL` pour permettre une migration progressive. La cohérence des produits existants est assurée par la commande de backfill ci-dessous.

## 4. Backfill des produits existants

La commande est :

```bash
php artisan products:backfill-old-prices --dry-run
php artisan products:backfill-old-prices
```

Elle lit les produits par lots de 500 et met à jour uniquement `old_prix` avec le Query Builder. Elle ne déclenche donc pas d’événement Eloquent ni de synchronisation WooCommerce. L’opération normale est transactionnelle : si un prix de vente invalide est trouvé, les mises à jour partielles sont annulées. La vérification finale refuse également de laisser des `old_prix` nuls.

Le test d’intégration vérifie que `prix_achat`, `prix_vente`, `ref`, description et image restent inchangés. Un test séparé vérifie qu’un prix invalide ne valide pas une mise à jour partielle.

## 5. Formulaire

Le formulaire affiche `old_prix` comme valeur calculée et non modifiable. Le JavaScript met à jour l’affichage immédiatement lorsque `prix_vente` change, mais cette valeur est uniquement indicative : le serveur recalcule toujours le montant final.

Les formulaires conservent la protection CSRF, `multipart/form-data`, le champ fichier `image`, les messages de validation et les sacs d’erreurs existants.

## 6. Mapping WooCommerce final

| Laravel | WooCommerce |
|---|---|
| `ref` | `name` et `sku` |
| `description` | `description` |
| `prix_achat` | `meta_data: [{ key: "_spi_prix_achat", value: "..." }]` |
| `old_prix` | `regular_price` |
| `prix_vente` | `sale_price` |
| catégorie Laravel | ID WooCommerce existant ou synchronisé |
| `image` | image principale, avec le repli existant si l’URL est inaccessible |

Les prix envoyés sont formatés avec deux décimales. Une valeur incohérente de `old_prix` par rapport à `prix_vente` bloque la synchronisation au lieu d’envoyer un prix faux. WooCommerce reçoit donc par exemple :

```json
{
  "name": "REF-001",
  "sku": "REF-001",
  "description": "Description du produit",
  "regular_price": "270.00",
  "sale_price": "200.00",
  "meta_data": [
    {"key": "_spi_prix_achat", "value": "120.00"}
  ]
}
```

Le service réutilise l’identifiant WooCommerce enregistré. Si celui-ci est absent ou renvoie 404, il recherche le SKU avant de créer un produit ; cela évite les doublons. Aucun identifiant existant n’a été modifié par les tests.

## 7. Stockage des images

L’upload utilise maintenant `Storage::disk('public')` et enregistre un chemin relatif tel que :

```text
storage/app/public/produits/nom-genere.png
```

La valeur en base reste `produits/nom-genere.png`. Le remplacement suit l’ordre : validation, écriture de la nouvelle image, mise à jour de la base, puis suppression de l’ancienne image. En cas d’échec, l’ancienne image et son chemin restent conservés, et le nouveau fichier est supprimé si nécessaire.

Les erreurs journalisent l’étape, l’ID éventuel, le disque, le chemin relatif, la classe et le message d’exception, sans secret ni contenu de fichier.

## 8. Tests exécutés

Résultats locaux obtenus :

- `php artisan test --no-coverage` : **40 tests passés, 180 assertions** ;
- tests prix seuls : **6 tests passés, 30 assertions** ;
- tests WooCommerce : **15 tests passés, 62 assertions** ;
- tests upload/permissions : **17 tests passés, 85 assertions** ;
- `npm.cmd run build` : **build Vite réussi** ;
- Pint ciblé sur les fichiers PHP modifiés : **réussi**.

`vendor/bin/pint --test` lancé sur tout le dépôt signale aussi des fichiers non concernés par cette correction (`WooCommerceException.php`, `UpdateProduitRequest.php`, `bootstrap/app.php` et `DatabaseSeeder.php`). Ils n’ont pas été modifiés pour éviter une dérive hors périmètre.

Le lancement direct de `products:backfill-old-prices --dry-run` sur la base locale configurée a été refusé car cette base n’avait pas encore la nouvelle colonne. Le backfill a toutefois été exécuté et vérifié dans les tests avec `RefreshDatabase`, migration et SQLite de test. Aucune base de production n’a été utilisée.

## 9. Déploiement Hostinger

Depuis la racine réelle de l’application, après sauvegarde de la base et déploiement des fichiers :

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan products:backfill-old-prices --dry-run
php artisan products:backfill-old-prices
```

Vérifications SQL en lecture seule :

```sql
SELECT COUNT(*) AS sans_old_prix
FROM produits
WHERE old_prix IS NULL;

SELECT COUNT(*) AS incoherents
FROM produits
WHERE prix_vente IS NULL
   OR old_prix <> ROUND(prix_vente * 1.35, 2);
```

Le premier résultat doit être `0`. Les produits avec `prix_vente` invalide doivent être corrigés manuellement puis le backfill relancé ; aucune valeur arbitraire ne doit être inventée.

Pour le stockage, vérifier sur le serveur, sans `chmod 777` :

```bash
ls -ld storage storage/app storage/app/public storage/app/public/produits public public/storage
test -L public/storage && readlink -f public/storage || echo "public/storage n'est pas un lien symbolique"
php -r 'var_dump(is_dir("storage/app/public/produits"), is_writable("storage/app/public/produits"));'
curl -I https://stockfilter.spifilters.com/storage/produits/3dDxzcUffvJJXHU1t3AFsHaWTWS7UhLFxEE0iU3D.png
```

À confirmer également dans l’environnement effectif : `APP_URL`, la racine du disque `public`, les permissions du compte PHP/FPM, le lien `public/storage` et les limites PHP `upload_max_filesize`/`post_max_size`. Ces vérifications nécessitent SSH ou hPanel et n’ont pas été effectuées depuis ce workspace.

## 10. État final et limites

Les corrections sont testées localement, avec une base de test et des réponses WooCommerce simulées. Aucun appel réel WooCommerce, aucune migration de production et aucun backfill de production n’ont été exécutés. La production ne doit donc pas être déclarée corrigée avant l’exécution contrôlée des commandes ci-dessus et un test fonctionnel avec un produit non sensible.
