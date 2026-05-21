<?php
namespace App\Console\Commands;

use App\Models\BackupSetting;
use Illuminate\Console\Command;

class BackupSyncSchedule extends Command
{
    protected $signature = 'backup:sync-schedule';
    protected $description = 'مزامنة جدولة النسخ الاحتياطي مع Windows Task Scheduler';

    public function handle(): int
    {
        $settings = BackupSetting::first();
        if (!$settings) {
            $this->warn('لا توجد إعدادات نسخ احتياطي');
            return 0;
        }

        $taskName = 'Curve Backup Daily';
        $artisan = escapeshellarg(base_path('artisan'));

        // Windows Task Scheduler
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            if ($settings->auto_backup_enabled) {
                $php = PHP_BINARY;
                $cmd = escapeshellarg($php) . ' ' . $artisan . ' backup:run';

                // Delete existing task first
                shell_exec("schtasks /delete /tn \"{$taskName}\" /f 2>nul");

                // Determine schedule
                [$hour, $min] = explode(':', $settings->auto_backup_time ?? '03:00');
                $freq = $settings->auto_backup_frequency ?? 'daily';

                if ($freq === 'weekly') {
                    $days = $settings->auto_backup_days ?? 'SUN';
                    $result = shell_exec("schtasks /create /tn \"{$taskName}\" /tr \"{$cmd}\" /sc weekly /d {$days} /st {$hour}:{$min} /f 2>&1");
                } else {
                    $result = shell_exec("schtasks /create /tn \"{$taskName}\" /tr \"{$cmd}\" /sc daily /st {$hour}:{$min} /f 2>&1");
                }

                if (str_contains($result ?? '', 'SUCCESS')) {
                    $this->info("✅ تم إنشاء مهمة مجدولة: كل {$freq} الساعة {$hour}:{$min}");
                } else {
                    $this->warn("⚠️ فشل إنشاء المهمة المجدولة: {$result}");
                    $this->warn('حاول تشغيل PowerShell كـ Administrator');
                }
            } else {
                shell_exec("schtasks /delete /tn \"{$taskName}\" /f 2>nul");
                $this->info('تم إلغاء الجدولة');
            }
        } else {
            // Linux — schedule handled by Laravel's console.php + cron
            if ($settings->auto_backup_enabled) {
                $this->info('Linux: تأكد من وجود cron job: * * * * * php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1');
            } else {
                $this->info('الجدولة معطلة');
            }
        }

        return 0;
    }
}
