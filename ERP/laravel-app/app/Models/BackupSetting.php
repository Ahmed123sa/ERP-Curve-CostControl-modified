<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupSetting extends Model
{
    protected $fillable = [
        'local_path', 'email_enabled', 'email_to',
        'google_drive_enabled', 'google_drive_token', 'retention_days',
        'auto_backup_enabled', 'auto_backup_time', 'auto_backup_frequency', 'auto_backup_days',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'google_drive_enabled' => 'boolean',
        'retention_days' => 'integer',
        'auto_backup_enabled' => 'boolean',
    ];
}
