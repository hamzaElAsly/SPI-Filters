<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreViewerRequest extends FormRequest
{
    protected $errorBag = 'storeViewer';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nom', 'email' => 'email', 'password' => 'mot de passe', 'password_confirmation' => 'confirmation du mot de passe'];
    }

    public function messages(): array
    {
        return ['required' => 'Le champ :attribute est obligatoire.', 'email' => 'Veuillez saisir une adresse email valide.', 'unique' => 'Cet email est déjà utilisé.', 'confirmed' => 'La confirmation du mot de passe ne correspond pas.', 'password.min' => 'Le mot de passe doit contenir au moins :min caractères.'];
    }

    protected function getRedirectUrl(): string
    {
        return url('/utilisateurs?openModal=add');
    }
}
