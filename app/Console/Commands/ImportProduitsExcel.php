<?php

namespace App\Console\Commands;

use App\Services\ProduitExcelImportService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ImportProduitsExcel extends Command
{
    protected $signature = 'produits:import-excel
                            {file : Chemin vers le fichier .xlsx}
                            {--dry-run : Simuler l’import sans écrire en base}
                            {--duplicate=update : Comportement des références existantes : update, skip ou error}
                            {--batch=100 : Nombre de lignes traitées par transaction}';

    protected $description = 'Importe les produits depuis un fichier Excel .xlsx';

    public function handle(ProduitExcelImportService $importer): int
    {
        $duplicateMode = (string) $this->option('duplicate');
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if ($batchSize === false || $batchSize < 1) {
            $this->error('L’option --batch doit être un entier supérieur à zéro.');

            return self::INVALID;
        }

        try {
            $summary = $importer->import(
                (string) $this->argument('file'),
                (bool) $this->option('dry-run'),
                $duplicateMode,
                $batchSize,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('L’import Excel a échoué. Consultez les logs de l’application.');

            return self::FAILURE;
        }

        $this->info($this->option('dry-run')
            ? 'Simulation terminée : aucune donnée n’a été écrite.'
            : 'Import terminé.');
        $this->line('Lignes de données : '.$summary['rows']);
        $this->line('Produits importés : '.$summary['imported']);
        $this->line('Produits mis à jour : '.$summary['updated']);
        $this->line('Lignes ignorées : '.$summary['skipped']);
        $this->line('Erreurs : '.count($summary['errors']));

        foreach (array_slice($summary['errors'], 0, 20) as $error) {
            $this->error(sprintf('Ligne %d : %s', $error['row'], $error['message']));
        }

        if (count($summary['errors']) > 20) {
            $this->warn(sprintf('%d erreur(s) supplémentaires non affichées.', count($summary['errors']) - 20));
        }

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
