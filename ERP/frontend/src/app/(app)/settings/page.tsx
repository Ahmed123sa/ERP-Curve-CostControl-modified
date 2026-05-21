'use client';
import { useState, useRef, useEffect } from 'react';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import toast from 'react-hot-toast';

export default function SettingsPage() {
  const [logoUrl, setLogoUrl] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [backupTab, setBackupTab] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  // Backup state
  const [backupSettings, setBackupSettings] = useState({
    local_path: '',
    email_enabled: false,
    email_to: '',
    google_drive_enabled: false,
    retention_days: 7,
  });
  const [backupLoading, setBackupLoading] = useState(false);
  const [runningBackup, setRunningBackup] = useState(false);

  useEffect(() => {
    api.get('/settings/logo').then(({ data }) => {
      if (data.logo_url) setLogoUrl(data.logo_url);
    }).catch(() => {});
    api.get('/settings/backup').then(({ data }) => {
      setBackupSettings({
        local_path: data.local_path || '',
        email_enabled: data.email_enabled || false,
        email_to: data.email_to || '',
        google_drive_enabled: data.google_drive_enabled || false,
        retention_days: data.retention_days || 7,
      });
    }).catch(() => {});
  }, []);

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const form = new FormData();
      form.append('logo', file);
      const { data } = await api.post('/settings/logo', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setLogoUrl(data.logo_url);
      toast.success('تم رفع الشعار بنجاح');
    } catch {
      toast.error('فشل رفع الشعار');
    } finally {
      setUploading(false);
    }
  };

  const handleDelete = async () => {
    try {
      await api.delete('/settings/logo');
      setLogoUrl(null);
      toast.success('تم حذف الشعار');
    } catch {
      toast.error('فشل حذف الشعار');
    }
  };

  const saveBackupSettings = async () => {
    setBackupLoading(true);
    try {
      await api.put('/settings/backup', backupSettings);
      toast.success('تم حفظ إعدادات النسخ الاحتياطي');
    } catch {
      toast.error('فشل الحفظ');
    } finally {
      setBackupLoading(false);
    }
  };

  const runBackup = async () => {
    setRunningBackup(true);
    try {
      const { data } = await api.post('/settings/backup/run');
      toast.success(data.message || 'تم إنشاء النسخة');
    } catch {
      toast.error('فشل إنشاء النسخة');
    } finally {
      setRunningBackup(false);
    }
  };

  return (
    <div>
      <PageHeader title="الإعدادات" subtitle="إعدادات الشعار والنسخ الاحتياطي" />
      <div className="p-6 max-w-xl space-y-6">
        {/* Tabs */}
        <div className="flex gap-2 border-b border-gray-100 pb-3">
          <button onClick={() => setBackupTab(false)} className={`px-4 py-2 text-sm font-medium rounded-t-lg ${!backupTab ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'}`}>
            الشعار
          </button>
          <button onClick={() => setBackupTab(true)} className={`px-4 py-2 text-sm font-medium rounded-t-lg ${backupTab ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'}`}>
            النسخ الاحتياطي
          </button>
        </div>

        {!backupTab ? (
          // Logo Section
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
            <h2 className="font-semibold text-gray-900">شعار الشركة</h2>
            <p className="text-sm text-gray-500">ادعم صورة JPEG, PNG, SVG, WebP بحجم أقصى 2 MB</p>

            {logoUrl && (
              <div className="relative inline-block">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={logoUrl} alt="الشعار" className="h-20 border border-gray-200 rounded-xl p-2" />
              </div>
            )}

            <div className="flex gap-3">
              <button
                onClick={() => fileRef.current?.click()}
                disabled={uploading}
                className="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-xl hover:bg-blue-700 disabled:opacity-50"
              >
                {uploading ? 'جاري الرفع...' : logoUrl ? 'تغيير الشعار' : 'رفع الشعار'}
              </button>
              {logoUrl && (
                <button
                  onClick={handleDelete}
                  className="px-4 py-2 bg-red-50 text-red-600 text-sm font-medium rounded-xl hover:bg-red-100"
                >
                  حذف الشعار
                </button>
              )}
            </div>
            <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={handleUpload} />
          </div>
        ) : (
          // Backup Section
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
            <h2 className="font-semibold text-gray-900">إعدادات النسخ الاحتياطي</h2>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">مسار الحفظ المحلي</label>
              <input value={backupSettings.local_path} onChange={(e) => setBackupSettings({ ...backupSettings, local_path: e.target.value })}
                className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400 font-mono text-xs"
                placeholder="مثال: D:\Backups\Curve" />
            </div>

            <div className="flex items-center gap-3">
              <input type="checkbox" id="email_enabled" checked={backupSettings.email_enabled}
                onChange={(e) => setBackupSettings({ ...backupSettings, email_enabled: e.target.checked })}
                className="rounded border-gray-300" />
              <label htmlFor="email_enabled" className="text-sm text-gray-700">إرسال نسخة عبر البريد الإلكتروني</label>
            </div>

            {backupSettings.email_enabled && (
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">البريد الإلكتروني</label>
                <input type="email" value={backupSettings.email_to} onChange={(e) => setBackupSettings({ ...backupSettings, email_to: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400"
                  placeholder="ahmed@example.com" />
              </div>
            )}

            <div className="flex items-center gap-3">
              <input type="checkbox" id="gd_enabled" checked={backupSettings.google_drive_enabled}
                onChange={(e) => setBackupSettings({ ...backupSettings, google_drive_enabled: e.target.checked })}
                className="rounded border-gray-300" />
              <label htmlFor="gd_enabled" className="text-sm text-gray-700">Google Drive (قريباً)</label>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">الاحتفاظ بالنسخ (أيام)</label>
              <input type="number" value={backupSettings.retention_days} onChange={(e) => setBackupSettings({ ...backupSettings, retention_days: parseInt(e.target.value) || 7 })}
                className="w-24 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400" min="1" max="365" />
            </div>

            <div className="flex gap-3 pt-2">
              <button onClick={saveBackupSettings} disabled={backupLoading}
                className="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-xl hover:bg-blue-700 disabled:opacity-50">
                {backupLoading ? 'جاري الحفظ...' : 'حفظ الإعدادات'}
              </button>
              <button onClick={runBackup} disabled={runningBackup}
                className="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-xl hover:bg-green-700 disabled:opacity-50">
                {runningBackup ? 'جاري...' : 'تشغيل نسخة الآن'}
              </button>
            </div>

            <p className="text-xs text-gray-400 pt-2">
              للجدولة التلقائية: أضف هذا الأمر إلى Windows Task Scheduler أو cron job:
              <code className="block mt-1 p-2 bg-gray-50 rounded-lg text-xs font-mono" dir="ltr">php artisan backup:run</code>
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
