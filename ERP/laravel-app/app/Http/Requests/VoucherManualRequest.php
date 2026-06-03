<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoucherManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'         => 'required|in:purchase,dispatch,transfer,withdrawal,production,opening,adjustment',
            'date'         => 'required|date',
            'warehouse_id' => 'nullable|uuid',
            'branch_id'    => 'nullable|uuid',
            'lines'        => 'required|array|min:1',
            'lines.*.item_id'      => 'required|uuid',
            'lines.*.warehouse_id' => 'nullable|uuid',
            'lines.*.qty'          => 'required|numeric',
            'lines.*.cost'         => 'nullable|numeric|min:0',
        ];
    }
}