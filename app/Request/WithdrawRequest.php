<?php

declare(strict_types=1);

namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

class WithdrawRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'method' => 'required|string|in:PIX',

            // Dinheiro: aceite string/num, mas valide formato e limite.
            // Ex: "10", "10.5", "10.50"
            'amount' => [
                'required',
                'regex:/^\d+(\.\d{1,2})?$/',
                'gt:0',
                'max:1000000', // limite defensivo
            ],

            'pix' => 'required|array',
            'pix.type' => 'required|string|in:email',
            'pix.key' => 'required|string|email:rfc',

            // schedule: null ou "YYYY-MM-DD HH:MM"
            'schedule' => [
                'nullable',
                'regex:/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'amount must be a decimal with up to 2 decimal places.',
            'schedule.regex' => 'schedule must be in format YYYY-MM-DD HH:MM.',
        ];
    }
}
