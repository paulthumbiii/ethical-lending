<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\BorrowerKycDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(array_keys(BorrowerKycDocument::TYPES))],
            'document'      => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Choose which document you are uploading.',
            'document_type.in'       => 'Choose which document you are uploading.',
            'document.required'      => 'Choose a photo or PDF to upload.',
            'document.max'           => 'The file is too large. The limit is 5 MB.',
            'document.mimes'         => 'Upload a JPG, PNG or PDF file.',
        ];
    }
}