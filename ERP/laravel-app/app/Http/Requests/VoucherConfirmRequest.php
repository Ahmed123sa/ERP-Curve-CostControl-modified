<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoucherConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vouchers'                              => 'required|array|min:1',
            'vouchers.*.date'                       => 'required|date',
            'vouchers.*.type'                       => 'required|in:purchase,dispatch,transfer,withdrawal,external_sale,production,return,adjustment,opening',
            'vouchers.*.warehouse_id'               => 'nullable|uuid',
            'vouchers.*.branch_id'                  => 'nullable|uuid',
            'vouchers.*.lines'                      => 'required|array|min:1',
            'vouchers.*.lines.*.item_id'            => 'required|uuid',
            'vouchers.*.lines.*.warehouse_id'       => 'nullable|uuid',
            'vouchers.*.lines.*.qty'                => 'required|numeric',
            'vouchers.*.lines.*.cost'               => 'nullable|numeric|min:0',
        ];
    }
}