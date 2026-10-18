<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CollectionsWorkbenchFilterRequest extends FormRequest
{
    /** Same bucket codes that FairCollectionsService writes to collection_cases. */
    public const BUCKETS = [
        'DPD_1_30'    => '1 to 30 days',
        'DPD_31_60'   => '31 to 60 days',
        'DPD_61_90'   => '61 to 90 days',
        'DPD_90_PLUS' => 'Over 90 days',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'bucket'   => ['nullable', 'string', Rule::in(array_keys(self::BUCKETS))],
            'cooldown' => ['nullable', 'string', Rule::in(['active', 'none'])],
            'ptp'      => ['nullable', 'string', Rule::in(['active', 'none'])],
        ];
    }
}