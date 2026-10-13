# SPI FILTERS

Application interne Laravel de gestion des produits et des comptes Viewer.

## Fonctionnalités

- `ADMIN` : gestion complète des produits et des comptes Viewer.
- `VIEWER` : consultation, recherche et détails des produits uniquement.
- Ajout, modification, détails et confirmations de suppression dans des modals Alpine.js.
- Upload d’images dans `storage/app/public/produits`.
- Interface française, responsive et basée sur l’identité SPI FILTERS.

## Prérequis

PHP 8.2+, Composer, Node.js/npm et MySQL.

## Installation

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Configurer MySQL et le premier administrateur dans `.env` :

```dotenv
ADMIN_NAME="Administrateur"
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD="un-mot-de-passe-solide"
```

Puis exécuter :

```bash
php artisan migrate
php artisan db:seed
php artisan storage:link
npm install
npm run build
php artisan serve
```

`db:seed` crée ou met à jour l’Admin avec `ADMIN_NAME`, `ADMIN_EMAIL` et `ADMIN_PASSWORD`. Le mot de passe est haché par Laravel et aucun mot de passe réel n’est versionné.

## Routes

- `/connexion` : connexion et déconnexion, sans inscription publique.
- `/produits` : liste, recherche, pagination et détails pour les deux rôles.
- `/utilisateurs` : gestion des Viewers, Admin uniquement.

Les écritures sont protégées par authentification, middleware `admin`, validation serveur et CSRF. Les Viewers qui forcent une route réservée reçoivent une réponse 403.

## Tests

```bash
php artisan test
```

## WooCommerce

### Synchronisation WooCommerce

Laravel reste la source principale des produits. WooCommerce reçoit les produits et catégories envoyés par synchronisation via l'API REST WooCommerce.

Ajouter les paramètres suivants dans `.env` sans les exposer au frontend ni les versionner :

```dotenv
APP_URL=https://plateforme.example.com
WOOCOMMERCE_URL=
WOOCOMMERCE_CONSUMER_KEY=
WOOCOMMERCE_CONSUMER_SECRET=
```

Créer dans WooCommerce des clés REST API avec les droits Lecture/Écriture, puis renseigner ces variables dans `.env`. `APP_URL` doit être l’URL publique de Laravel : WooCommerce doit pouvoir ouvrir les images servies par `/storage` sans authentification.

Laravel reste la source principale. Depuis Produits, un administrateur peut tester la connexion, synchroniser un produit ou lancer la synchronisation globale. La synchronisation est manuelle et la suppression d’un produit Laravel ne supprime pas le produit WooCommerce.

Après configuration, exécuter `php artisan storage:link`, puis utiliser **Synchroniser WooCommerce** depuis la page Produits.

## Build frontend

```bash
npm install
npm run build
```
