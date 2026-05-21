<?php
namespace App\Console\Commands;

use App\Mail\BackupMail;
use App\Models\BackupSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

class DatabaseBackup extends Command
{
    protected $signature = 'backup:run {--local-only : Skip email/Google Drive}';
    protected $description = 'إنشاء نسخة احتياطية من قاعدة البيانات';

    public function handle(): int
    {
        $settings = BackupSetting::first();
        if (!$settings) {
            $this->error('لم يتم إعداد النسخ الاحتياطي بعد');
            return 1;
        }

        $db = config('database.connections.mysql');
        $filename = 'db_' . now()->format('Y-m-d_His') . '.sql.gz';
        $localPath = $settings->local_path ?: storage_path('backups');

        // Ensure directory exists
        File::ensureDirectoryExists($localPath);

        $filePath = $localPath . DIRECTORY_SEPARATOR . $filename;

        // Build mysqldump command
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s %s | gzip > %s',
            escapeshellarg($db['host']),
            escapeshellarg($db['port'] ?? '3306'),
            escapeshellarg($db['username']),
            $db['password'] ? '-p' . escapeshellarg($db['password']) : '',
            escapeshellarg($db['database']),
            escapeshellarg($filePath)
        );

        $this->info('جاري إنشاء النسخة الاحتياطية...');
        $output = null;
        $resultCode = null;
        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {
            $this->error('فشل إنشاء النسخة الاحتياطية');
            return 1;
        }

        $this->info("تم حفظ النسخة: {$filePath}");

        // Send via email if enabled
        if (!$this->option('local-only') && $settings->email_enabled && $settings->email_to) {
            try {
                Mail::to($settings->email_to)->send(new BackupMail($filePath, $filename));
                $this->info('تم إرسال النسخة إلى البريد: ' . $settings->email_to);
            } catch (\Exception $e) {
                $this->warn('فشل إرسال البريد: ' . $e->getMessage());
            }
        }

        // TODO: Google Drive integration - requires OAuth setup

        // Clean old backups
        $this->cleanOldBackups($localPath, $settings->retention_days);

        $this->info('✅ تم الانتهاء من النسخ الاحتياطي');
        return 0;
    }

    private function cleanOldBackups(string $path, int $days): void
    {
        $cutoff = now()->subDays($days);
        $count = 0;

        foreach (File::glob($path . DIRECTORY_SEPARATOR . 'db_*.sql.gz') as $file) {
            if (File::lastModified($file) < $cutoff->timestamp) {
                File::delete($file);
                $count++;
            }
        }

        if ($count > 0) {
            $this->info("تم حذف {$count} نسخة قديمة");
        }
    }
}
