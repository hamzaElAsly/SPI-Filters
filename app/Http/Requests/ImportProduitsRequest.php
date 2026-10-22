<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportProduitsRequest extends FormRequest
{
    protected $errorBag = 'importProduits';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'extensions:xlsx,xls', 'max:5120'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => 'fichier Excel'];
    }

    public function messages(): array
    {
        return [
            'required' => 'Le :attribute est obligatoire.',
            'file' => 'Le :attribute doit être un fichier valide.',
            'mimes' => 'Le fichier doit être au format .xlsx ou .xls.',
            'extensions' => 'Le fichier doit avoir une extension .xlsx ou .xls.',
            'max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return url('/produits?openModal=import');
    }
}
