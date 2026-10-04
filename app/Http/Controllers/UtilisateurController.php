<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreViewerRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UtilisateurController extends Controller
{
    public function index(): View
    {
        $viewers = User::query()->where('role', 'VIEWER')->latest()->paginate(15);

        return view('utilisateurs.index', compact('viewers'));
    }

    public function store(StoreViewerRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['role'] = 'VIEWER';
        $data['password'] = Hash::make($data['password']);
        User::create($data);

        return to_route('utilisateurs.index')->with('success', 'Viewer ajouté avec succès.');
    }

    public function destroy(User $utilisateur): RedirectResponse
    {
        abort_if($utilisateur->isAdmin() || $utilisateur->is(auth()->user()), 403);
        abort_unless($utilisateur->isViewer(), 404);
        $utilisateur->delete();

        return to_route('utilisateurs.index')->with('success', 'Viewer supprimé avec succès.');
    }
}
