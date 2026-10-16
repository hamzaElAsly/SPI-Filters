<?php

namespace App\Console\Commands;

use App\Models\Produit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class BackfillOldPrices extends Command
{
    protected $signature = 'products:backfill-old-prices
                            {--dry-run : Vérifier les valeurs sans modifier la base}';

    protected $description = 'Calcule old_prix pour tous les produits sans synchroniser WooCommerce';

    public function handle(): int
    {
        $updated = 0;
        $invalidIds = [];
        $dryRun = (bool) $this->option('dry-run');

        $scan = function (bool $write) use (&$updated, &$invalidIds): void {
            DB::table('produits')
                ->select(['id', 'prix_vente', 'old_prix'])
                ->orderBy('id')
                ->chunkById(500, function ($produits) use ($write, &$updated, &$invalidIds): void {
                    foreach ($produits as $produit) {
                        $oldPrice = Produit::calculateOldPrice($produit->prix_vente);

                        if ($oldPrice === null) {
                            $invalidIds[] = $produit->id;

                            continue;
                        }

                        if ((string) $produit->old_prix !== $oldPrice) {
                            $updated++;

                            if ($write) {
                                DB::table('produits')
                                    ->where('id', $produit->id)
                                    ->update(['old_prix' => $oldPrice]);
                            }
                        }
                    }
                });
        };

        try {
            if ($dryRun) {
                $scan(false);
            } else {
                DB::transaction(function () use ($scan, &$invalidIds): void {
                    $scan(true);

                    if ($invalidIds !== []) {
                        throw new InvalidArgumentException(sprintf(
                            '%d produit(s) ont un prix de vente invalide : %s.',
                            count($invalidIds),
                            implode(', ', array_slice($invalidIds, 0, 20)),
                        ));
                    }
                });
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Le backfill a échoué : '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($invalidIds !== []) {
            $this->error(sprintf(
                '%d produit(s) ont un prix de vente invalide : %s.',
                count($invalidIds),
                implode(', ', array_slice($invalidIds, 0, 20)),
            ));

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info(sprintf('%d produit(s) seraient mis à jour.', $updated));

            return self::SUCCESS;
        }

        $remaining = DB::table('produits')->whereNull('old_prix')->count();
        if ($remaining > 0) {
            $this->error(sprintf('%d produit(s) n’ont pas de old_prix après le backfill.', $remaining));

            return self::FAILURE;
        }

        $this->info(sprintf('%d produit(s) mis à jour. Vérification old_prix : OK.', $updated));

        return self::SUCCESS;
    }
}
