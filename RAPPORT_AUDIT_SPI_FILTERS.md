# Rapport d’audit technique — SPI FILTERS

Date de l’audit : 15 octobre 2026  
Périmètre : état du code présent dans le workspace. Aucun test applicatif, appel WooCommerce, migration ou synchronisation réelle n’a été exécuté.

## 1. Résumé exécutif

- L’application est une application Laravel 12 de gestion de produits, catégories et comptes `ADMIN`/`VIEWER`.
- Le CRUD produits, les images locales, la recherche, la pagination et le contrôle d’accès sont implémentés.
- WooCommerce est intégré par API REST avec synchronisation individuelle et en masse.
- Le code actuel construit les URL d’images sous la forme `APP_URL/storage/<chemin>` ; il ne génère pas `storage/app/public`.
- Le mapping WooCommerce est incomplet : `prix_achat` n’est pas transmis sous `_spi_prix_achat`, et `name` provient de la description plutôt que de `ref`.
- L’environnement local audité utilise `APP_URL=localhost`, le debug activé et un répertoire `public/storage` qui n’est pas un lien symbolique détecté ; cela ne prouve pas l’état de la production.
- Le seeder contient un identifiant administrateur et un mot de passe par défaut codé en dur : risque à traiter avant tout usage de `db:seed` en production.
- Les tests couvrent largement les droits et le service WooCommerce, mais leur exécution n’a pas été lancée pendant cet audit.

## 2. Stack technique

| Élément | Déclaré / observé |
|---|---|
| PHP | `^8.2` dans `composer.json`, PHP CLI 8.2.12 observé |
| Laravel | `^12.0`, Laravel Framework 12.69.3 observé |
| Base | MySQL dans l’environnement courant ; SQLite comme valeur de repli dans `config/database.php` |
| Frontend | Blade, Tailwind CSS 4, Alpine.js 3, Vite 7, Axios |
| Authentification | Guard session Laravel, provider Eloquent `User` |
| Fichiers | disque `public` dans `storage/app/public`, URL publique `/storage` |
| WooCommerce | API REST `wp-json/wc/v3`, Basic Auth, timeouts et retry configurables |
| Excel | Aucun package ni contrôleur/import Excel trouvé |

L’environnement retourné par `php artisan about` est `local`, avec debug activé, URL `localhost`, timezone UTC et locale `en`. Ces valeurs doivent être considérées comme la configuration du workspace, pas comme une preuve de la configuration de `stock.spifilters.com`.

## 3. Architecture

### Principaux composants

- `app/Models/Produit.php` : produit, casts des prix et de l’état WooCommerce, relation `belongsTo` vers `Categorie`.
- `app/Models/Categorie.php` : catégorie et relation `hasMany` vers les produits.
- `app/Models/User.php` : utilisateur authentifiable, rôles `ADMIN` et `VIEWER`.
- `app/Http/Controllers/ProduitController.php` : liste/recherche/pagination, création, modification, suppression et fichiers image.
- `app/Http/Controllers/CategorieController.php` : CRUD des catégories et interdiction de supprimer une catégorie utilisée.
- `app/Http/Controllers/UtilisateurController.php` : liste, création et suppression des Viewers.
- `app/Http/Controllers/WooCommerceSyncController.php` : test de connexion, synchronisation individuelle et bulk.
- `app/Services/WooCommerceService.php` : client HTTP, mapping, catégories, détection d’erreurs d’image et persistance des identifiants WooCommerce.
- `app/Http/Middleware/AdminMiddleware.php` : restriction des actions sensibles aux administrateurs.

### Routes principales

Les 18 routes applicatives sont définies dans `routes/web.php`. Les invités disposent de `/connexion`; les utilisateurs authentifiés peuvent consulter `/produits`; les routes d’écriture, de catégories, d’utilisateurs et de WooCommerce sont dans le groupe `admin`. La protection CSRF est utilisée par les formulaires POST/PUT/DELETE.

### Structure des données

- `users` : identité, email unique, mot de passe, rôle enum `ADMIN`/`VIEWER`, sessions et tokens de réinitialisation.
- `produits` : `ref` unique, `description`, `prix_achat`, `prix_vente`, `image`, `categorie_id`, `woocommerce_id`, `woocommerce_synced_at`.
- `categories` : `nom` unique et `woocommerce_id` indexé.
- Tables Laravel auxiliaires : cache et jobs.
- La clé étrangère `produits.categorie_id` est nullable et passe à `NULL` lors de la suppression d’une catégorie, mais le contrôleur refuse auparavant la suppression d’une catégorie utilisée.

## 4. Fonctionnalités existantes

| Fonctionnalité | État constaté |
|---|---|
| Création produit | Opérationnelle par `ProduitController@store`; validation serveur et upload disque `public`. |
| Modification produit | Opérationnelle; une nouvelle image remplace et supprime l’ancienne. |
| Suppression produit | Opérationnelle; supprime aussi le fichier local. Ne supprime pas le produit distant WooCommerce, conformément au README. |
| `ref` | Obligatoire, chaîne, unique en validation et en base. Sert de SKU WooCommerce. |
| `description` | Obligatoire et envoyée à WooCommerce; pas de longueur maximale applicative. |
| Prix | Obligatoires, numériques, minimum zéro, stockés en décimal(10,2). Aucune borne maximale de validation. |
| Catégories | CRUD admin, nom unique, filtre produits et relation Eloquent. |
| Images | JPG/JPEG/PNG/WEBP, maximum 5 Mo; nom de fichier généré par Laravel. |
| Recherche/pagination | Recherche `ref`/`description`, filtre catégorie, pagination de 15 lignes avec conservation des paramètres. |
| Import Excel | Non présent dans le code ou les dépendances inspectées. |
| Authentification | Connexion/déconnexion par session; pas d’inscription publique. |
| Autorisations | Viewers en lecture seule; actions d’écriture et WooCommerce réservées aux admins. |

## 5. Synchronisation WooCommerce

### Fonctionnement réel

`WooCommerceService` utilise `config/services.php`, Basic Auth, un timeout de connexion de 10 s, un timeout HTTP de 20 s et un retry configurable. Pour un produit :

1. Si `woocommerce_id` existe, tentative de mise à jour.
2. En cas de 404, l’identifiant local est effacé puis une recherche par SKU est réalisée.
3. Si le SKU existe déjà, l’identifiant distant est réutilisé; sinon le produit est créé.
4. Une catégorie locale est recherchée par nom puis créée ou mise à jour avant l’envoi du produit.
5. `woocommerce_id` et `woocommerce_synced_at` sont enregistrés après une réponse WooCommerce contenant un `id`.

La synchronisation en masse traite les produits par blocs de 50, mais reste synchrone dans la requête HTTP. Les erreurs sont comptées et journalisées sans arrêter les produits suivants. La journalisation conserve les IDs, SKU, statuts HTTP et classes d’exception, sans écrire les clés API.

### Mapping attendu / réel

| Laravel | Attendu | Réel dans `productPayload()` | État |
|---|---|---|---|
| `ref` | `name` + `sku` | `sku` ; `name` = description tronquée, ou `ref` si description vide | **Écart confirmé** (`app/Services/WooCommerceService.php:208-214`) |
| `description` | `description` | `description` | Conforme |
| `prix_achat` | `_spi_prix_achat` | Absent du payload | **Écart confirmé** |
| `prix_vente` | `regular_price` | `regular_price` formaté sur 2 décimales | Conforme |
| `categorie` | `categories` | `categories: [{id: woocommerce_id}]` | Conforme si la catégorie a un ID distant |
| `image` | `images` | `images: [{src: URL publique}]` si l’image est valide et accessible | Conforme sous réserve de configuration |

Les méthodes `createProduct`, `updateProduct`, `syncProduct`, `createCategory`, `updateCategory` et la gestion des erreurs API sont présentes. Les produits WooCommerce ne sont pas supprimés lors de la suppression locale. Les recherches par SKU limitent le risque de doublon, mais deux synchronisations concurrentes peuvent encore passer simultanément la recherche puis créer deux produits : **risque à vérifier/à durcir**.

## 6. Diagnostic des images

### Faits confirmés par le code

- `ProduitController` enregistre l’image sous `produits/<nom généré>` sur le disque `public` (`app/Http/Controllers/ProduitController.php:45-47, 56-59`).
- Ce disque pointe vers `storage/app/public` et sa base d’URL est `APP_URL/storage` (`config/filesystems.php:41-48`).
- Les vues utilisent `asset('storage/' . $produit->image)` (`resources/views/produits/index.blade.php:16, 113, 119`).
- WooCommerce utilise explicitement `APP_URL.'/storage/'.<chemin>` (`app/Services/WooCommerceService.php:236-261`).
- La chaîne `storage/app/public` n’est pas utilisée pour construire l’URL par le code courant.
- L’environnement local contient les fichiers dans `storage/app/public/produits` et dans `public/storage/produits`, mais `public/storage` apparaît comme un répertoire ordinaire, pas comme un lien symbolique détecté.

Ainsi, avec `APP_URL=https://stock.spifilters.com` et un lien public fonctionnel, le code courant doit produire `https://stock.spifilters.com/storage/produits/<fichier>`, qui correspond à l’URL publique annoncée comme fonctionnelle. L’URL observée contenant `/storage/app/public/` n’est **pas reproduite par le code actuel**.

### Hypothèses à vérifier

- ancien code encore déployé ou cache de configuration non renouvelé;
- valeur `image` déjà enregistrée avec un préfixe `storage/app/public/`;
- `APP_URL` incorrect dans la configuration de production;
- lien `public/storage` absent, cassé ou non accessible depuis Internet;
- WooCommerce ne pouvant pas télécharger l’URL HTTPS, certificat, redirection ou restriction réseau.

Aucun accès HTTP à la production ni synchronisation réelle n’a été effectué; ces points restent donc **non vérifiés**.

## 7. Problèmes détectés

### Critique

- Aucun problème critique de production ne peut être confirmé sans accès à l’environnement de production.

### Haute priorité

- **Mapping métier incomplet — confirmé** : `prix_achat` n’est jamais envoyé sous `_spi_prix_achat`; `name` ne correspond pas à `ref` (`WooCommerceService.php:208-214`).
- **Identifiants de seed prévisibles — confirmé** : `database/seeders/ProduitsSeeder.php:17-24` crée/met à jour un admin avec des valeurs codées en dur, dont un mot de passe par défaut. Ne pas exécuter ce seeder en production tel quel.
- **Configuration image dépendante de l’installation — confirmé localement, production non vérifiée** : lien public et `APP_URL` doivent être contrôlés; le workspace courant est en `localhost` et debug actif.
- **Bulk potentiellement long — confirmé par conception** : synchronisation HTTP synchrone par blocs de 50 (`WooCommerceSyncController.php:35-65`), avec risque de timeout et de reprise difficile sur un catalogue important.

### Priorité moyenne

- **Doublons concurrents possibles — risque confirmé par lecture du flux** : recherche par SKU puis création sans verrou ni unicité distante garantie (`WooCommerceService.php:151-175`).
- Validation des prix sans `max` alors que la base est limitée à `decimal(10,2)` (`StoreProduitRequest.php:18-24`, migration produits:13-16).
- Aucun mécanisme de limitation explicite des tentatives de connexion et aucune route de réinitialisation de mot de passe malgré la table prévue.
- La migration `add_role_to_users_table` est redondante avec la migration initiale qui crée déjà `role` (`0001_01_01_000000...:14-21`, `2026_10_04_000002...:11-15`).

### Priorité basse

- Locale applicative par défaut `en` et timezone `UTC`, alors que l’interface est française.
- Pas d’import Excel malgré la fonctionnalité envisagée dans le besoin.
- Les tests d’intégration n’exécutent que des appels HTTP simulés; ils ne valident pas l’accessibilité réelle des images depuis WooCommerce.

## 8. Plan d’action — cinq interventions recommandées

1. Vérifier en préproduction puis en production `APP_URL`, le lien `public/storage`, les droits de lecture, le certificat HTTPS et l’absence de préfixe `storage/app/public` dans les valeurs `image`.
2. Formaliser le contrat WooCommerce et corriger le payload pour transmettre exactement `ref` dans `name` et `prix_achat` dans le champ/meta attendu `_spi_prix_achat`, avec tests de non-régression.
3. Rendre la synchronisation idempotente et résistante aux concurrents : verrou, stratégie de recherche/création atomique, reprise et traitement asynchrone pour le bulk.
4. Retirer les identifiants de seed codés en dur, imposer une configuration admin sûre et vérifier `APP_DEBUG=false`, secrets, permissions et rotation des clés en production.
5. Compléter les tests : URL publique réellement accessible, remplacement/suppression d’images, mapping complet, limites de prix, erreurs réseau et scénario de déploiement avec `storage:link`; exécuter ensuite la suite dans une base de test dédiée.

## 9. Fichiers importants

- `app/Services/WooCommerceService.php` — mapping, URL image, appels API et gestion d’erreurs.
- `app/Http/Controllers/WooCommerceSyncController.php` — routes de synchronisation individuelle et en masse.
- `app/Http/Controllers/ProduitController.php` — CRUD et stockage des images.
- `app/Http/Requests/StoreProduitRequest.php` et `UpdateProduitRequest.php` — validation des produits.
- `resources/views/produits/index.blade.php` — affichage des images, formulaires et boutons WooCommerce.
- `config/services.php` — URL, identifiants et timeouts WooCommerce.
- `config/filesystems.php` — disque public, URL `/storage` et lien symbolique.
- `routes/web.php` — protection et liste des routes.
- `database/migrations/2026_10_04_000001_create_produits_table.php` et `2026_10_04_000004_add_woocommerce_fields_to_produits_table.php` — schéma produits.
- `database/migrations/2026_10_04_000003_create_categories_table.php` — schéma catégories.
- `database/seeders/ProduitsSeeder.php` et `AdminSeeder.php` — données initiales et risque de seed en production.
- `tests/Feature/AccessAndPermissionsTest.php` — accès, CRUD, rôles et upload.
- `tests/Feature/WooCommerceServiceTest.php` — mapping actuel, erreurs API, images et bulk simulés.

