<?php

namespace App\Http\Controllers;

use App\Services\ProduitExcelImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProduitImportController extends Controller
{
    public function store(Request $request, ProduitExcelImportService $importer): RedirectResponse
    {
        $validated = $request->validate([
            'fichier' => ['required', 'file', 'extensions:xlsx', 'max:10240'],
            'duplicate' => ['required', 'in:update,skip,error'],
            'dry_run' => ['nullable', 'boolean'],
        ], [
            'fichier.required' => 'Veuillez choisir un fichier Excel.',
            'fichier.extensions' => 'Le fichier doit être au format .xlsx.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        $dryRun = $request->boolean('dry_run');

        // Le service vérifie l’extension .xlsx : on la garde.
        $path = $request->file('fichier')->storeAs('imports', Str::uuid() . '.xlsx', 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            @set_time_limit(300);
            $summary = $importer->import($fullPath, $dryRun, $validated['duplicate']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['fichier' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['fichier' => 'L’import Excel a échoué. Consultez les logs.']);
        } finally {
            Storage::disk('local')->delete($path);
        }

        $message = sprintf( '%s %d créé(s), %d mis à jour, %d ignoré(s), %d erreur(s).',
            $dryRun ? 'Simulation terminée :' : 'Import terminé :',
            $summary['imported'],
            $summary['updated'],
            $summary['skipped'],
            count($summary['errors'])
        );

        return to_route('produits.index')
            ->with($summary['errors'] === [] ? 'success' : 'warning', $message)
            ->with('import_summary', $summary + ['dry_run' => $dryRun]);
    }
}
