<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProduitRequest extends FormRequest
{
    protected $errorBag = 'updateProduit';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        $produit = $this->route('produit');
        $id = is_object($produit) ? $produit->id : $produit;

        return [
            'ref' => ['required', 'string', 'max:255', Rule::unique('produits', 'ref')->ignore($id)],
            'description' => ['required', 'string'],
            'prix_achat' => ['required', 'numeric', 'min:0'],
            'prix_vente' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function attributes(): array
    {
        return ['ref' => 'référence', 'description' => 'description', 'prix_achat' => "prix d'achat", 'prix_vente' => 'prix de vente', 'image' => 'image'];
    }

    public function messages(): array
    {
        return ['required' => 'Le champ :attribute est obligatoire.', 'ref.unique' => 'Cette référence existe déjà.', 'numeric' => 'Le champ :attribute doit être un nombre.', 'min' => 'Le champ :attribute doit être supérieur ou égal à :min.', 'image' => 'Le fichier doit être une image valide.', 'image.mimes' => 'L’image doit être au format JPG, PNG ou WEBP.', 'image.max' => 'L’image ne doit pas dépasser 5 Mo.'];
    }

    protected function getRedirectUrl(): string
    {
        $produit = $this->route('produit');
        $id = is_object($produit) ? $produit->id : $produit;
        return url('/produits?openModal=edit&edit='.$id);
    }
}
