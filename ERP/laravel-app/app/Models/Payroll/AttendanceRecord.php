<?php

namespace App\Models\Payroll;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use HasTenant;

    protected $table = 'attendance_records';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'employee_id', 'date', 'shift_start', 'shift_end',
        'total_hours', 'overtime_minutes', 'is_double_shift', 'notes',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'shift_start' => 'datetime:H:i',
        'shift_end' => 'datetime:H:i',
        'total_hours' => 'float',
        'overtime_minutes' => 'float',
        'is_double_shift' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'employee_id');
    }
}
