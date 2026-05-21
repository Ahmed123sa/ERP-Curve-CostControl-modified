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

        $mysqldump = $this->findMysqldump();
        if (!$mysqldump) {
            $this->error('mysqldump غير موجود. قم بتثبيت MySQL Client أو أضف مساره في PATH');
            return 1;
        }

        $db = config('database.connections.mysql');
        $filename = 'db_' . now()->format('Y-m-d_His') . '.sql';
        $gzFilename = $filename . '.gz';
        $localPath = $settings->local_path ?: storage_path('backups');

        File::ensureDirectoryExists($localPath);

        $sqlPath = $localPath . DIRECTORY_SEPARATOR . $filename;
        $gzPath = $sqlPath . '.gz';

        // Build mysqldump command
        $passwordArg = $db['password'] ? '-p' . escapeshellarg($db['password']) : '';
        $command = sprintf(
            '"%s" --host=%s --port=%s --user=%s %s --routines --single-transaction %s',
            $mysqldump,
            escapeshellarg($db['host']),
            escapeshellarg($db['port'] ?? '3306'),
            escapeshellarg($db['username']),
            $passwordArg,
            escapeshellarg($db['database'])
        );
        $redirectCmd = $command . ' > ' . escapeshellarg($sqlPath) . ' 2>&1';

        $this->info('جاري إنشاء النسخة الاحتياطية...');
        exec($redirectCmd, $output, $resultCode);

        if ($resultCode !== 0 || !file_exists($sqlPath) || filesize($sqlPath) === 0) {
            $this->error('فشل إنشاء النسخة via mysqldump، جرب طريقة PHP البديلة...');
            return $this->phpDumpFallback($settings, $localPath, $filename, $gzFilename, $gzPath);
        }

        // Compress with PHP gzopen streaming (memory-safe)
        $this->info('جاري ضغط الملف...');
        $gzHandle = gzopen($gzPath, 'w9');
        $handle = fopen($sqlPath, 'rb');
        while (!feof($handle)) {
            gzwrite($gzHandle, fread($handle, 8192));
        }
        fclose($handle);
        gzclose($gzHandle);
        unlink($sqlPath);

        $this->info("تم حفظ النسخة: {$gzPath}");

        // Send via email if enabled
        if (!$this->option('local-only') && $settings->email_enabled && $settings->email_to) {
            $this->sendEmail($settings, $gzPath, $gzFilename);
        }

        $this->cleanOldBackups($localPath, $settings->retention_days);
        $this->info('✅ تم الانتهاء من النسخ الاحتياطي');
        return 0;
    }

    private function findMysqldump(): ?string
    {
        // Check PATH first
        $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'which';
        $path = trim(shell_exec("$which mysqldump 2>nul"));
        if ($path && file_exists($path)) {
            return $path;
        }

        // Try to find via MySQL basedir
        try {
            $pdo = new \PDO('mysql:host=127.0.0.1', config('database.connections.mysql.username'), config('database.connections.mysql.password'));
            $stmt = $pdo->query("SELECT @@basedir AS base");
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['base'])) {
                $candidates = [
                    $row['base'] . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
                    $row['base'] . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump',
                ];
                foreach ($candidates as $c) {
                    if (file_exists($c)) return $c;
                }
            }
        } catch (\Exception $e) {}

        // Common install paths
        $commonPaths = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql5.7.\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];
        foreach ($commonPaths as $p) {
            if (file_exists($p)) return $p;
        }

        return null;
    }

    private function phpDumpFallback($settings, string $localPath, string $filename, string $gzFilename, string $gzPath): int
    {
        $this->warn('استخدام طريقة PHP البديلة (أبطأ ولكن لا تحتاج mysqldump)...');

        try {
            $pdo = new \PDO(
                'mysql:host=' . config('database.connections.mysql.host') . ';dbname=' . config('database.connections.mysql.database'),
                config('database.connections.mysql.username'),
                config('database.connections.mysql.password')
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $dbName = config('database.connections.mysql.database');

            $sql = "-- Curve Backup - {$dbName}\n";
            $sql .= "-- Generated: " . now() . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                $this->line("  تصدير {$table}...");

                // Create table
                $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                $sql .= "\nDROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $create['Create Table'] . ";\n\n";

                // Data
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
                if (empty($rows)) continue;

                $columns = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $columns) . '`';

                $stmt = $pdo->prepare("SELECT * FROM `{$table}`");
                $stmt->execute();

                $batchSize = 500;
                $batch = [];
                $counter = 0;

                foreach ($stmt as $row) {
                    $values = [];
                    foreach ($columns as $col) {
                        $val = $row[$col];
                        if ($val === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $pdo->quote((string) $val);
                        }
                    }
                    $batch[] = '(' . implode(', ', $values) . ')';
                    $counter++;

                    if ($counter % $batchSize === 0) {
                        $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n";
                        $batch = [];
                    }
                }

                if (!empty($batch)) {
                    $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n";
                }
            }

            $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

            file_put_contents($gzPath, gzencode($sql, 9));
            // For large datasets, this might hit memory. If so, switch to streaming:
            // $gz = gzopen($gzPath, 'w9'); gzwrite($gz, $sql); gzclose($gz);
            $this->info("تم حفظ النسخة (PHP method): {$gzPath}");

            if (!$this->option('local-only') && $settings->email_enabled && $settings->email_to) {
                $this->sendEmail($settings, $gzPath, $gzFilename);
            }

            $this->cleanOldBackups($localPath, $settings->retention_days);
            $this->info('✅ تم الانتهاء من النسخ الاحتياطي');
            return 0;

        } catch (\Exception $e) {
            $this->error('فشل الطريقتين: ' . $e->getMessage());
            return 1;
        }
    }

    private function sendEmail($settings, string $filePath, string $filename): void
    {
        try {
            Mail::to($settings->email_to)->send(new BackupMail($filePath, $filename));
            $this->info('تم إرسال النسخة إلى البريد: ' . $settings->email_to);
        } catch (\Exception $e) {
            $this->warn('فشل إرسال البريد: ' . $e->getMessage());
            $this->warn('تأكد من إعدادات SMTP في ملف .env');
        }
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
