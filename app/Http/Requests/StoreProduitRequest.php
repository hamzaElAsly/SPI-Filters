<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProduitRequest extends FormRequest
{
    protected $errorBag = 'storeProduit';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'ref' => ['required', 'string', 'max:255', 'unique:produits,ref'],
            'description' => ['required', 'string'],
            'prix_achat' => ['required', 'numeric', 'min:0'],
            'prix_vente' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'categorie_id' => ['nullable', 'integer', 'exists:categories,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'ref' => 'référence', 'description' => 'description',
            'prix_achat' => "prix d'achat", 'prix_vente' => 'prix de vente', 'image' => 'image', 'categorie_id' => 'catégorie',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'ref.unique' => 'Cette référence existe déjà.',
            'numeric' => 'Le champ :attribute doit être un nombre.',
            'min' => 'Le champ :attribute doit être supérieur ou égal à :min.',
            'image' => 'Le fichier doit être une image valide.',
            'image.mimes' => 'L’image doit être au format JPG, PNG ou WEBP.',
            'image.max' => 'L’image ne doit pas dépasser 5 Mo.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return url('/produits?openModal=add');
    }
}
