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

  const [backupSettings, setBackupSettings] = useState({
    local_path: '',
    email_enabled: false,
    email_to: '',
    google_drive_enabled: false,
    retention_days: 7,
    auto_backup_enabled: false,
    auto_backup_time: '03:00',
    auto_backup_frequency: 'daily',
    auto_backup_days: 'SUN',
  });
  const [backupLoading, setBackupLoading] = useState(false);
  const [runningBackup, setRunningBackup] = useState(false);

  useEffect(() => {
    api.get('/settings/logo').then(({ data }) => {
      if (data.logo_url) setLogoUrl(data.logo_url);
    }).catch(() => {});
    loadBackupSettings();
  }, []);

  const loadBackupSettings = async () => {
    try {
      const { data } = await api.get('/settings/backup');
      setBackupSettings({
        local_path: data.local_path || '',
        email_enabled: data.email_enabled || false,
        email_to: data.email_to || '',
        google_drive_enabled: data.google_drive_enabled || false,
        retention_days: data.retention_days || 7,
        auto_backup_enabled: data.auto_backup_enabled || false,
        auto_backup_time: data.auto_backup_time || '03:00',
        auto_backup_frequency: data.auto_backup_frequency || 'daily',
        auto_backup_days: data.auto_backup_days || 'SUN',
      });
    } catch {}
  };

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
      const { data } = await api.put('/settings/backup', backupSettings);
      setBackupSettings(prev => ({ ...prev, ...data.settings }));
      toast.success('تم حفظ الإعدادات' + (backupSettings.auto_backup_enabled ? ' وتم تحديث الجدولة' : ''));
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
        <div className="flex gap-2 border-b border-gray-100 pb-3">
          <button onClick={() => setBackupTab(false)}
            className={`px-4 py-2 text-sm font-medium rounded-t-lg ${!backupTab ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'}`}>
            الشعار
          </button>
          <button onClick={() => setBackupTab(true)}
            className={`px-4 py-2 text-sm font-medium rounded-t-lg ${backupTab ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'}`}>
            النسخ الاحتياطي
          </button>
        </div>

        {!backupTab ? (
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
              <button onClick={() => fileRef.current?.click()} disabled={uploading}
                className="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-xl hover:bg-blue-700 disabled:opacity-50">
                {uploading ? 'جاري الرفع...' : logoUrl ? 'تغيير الشعار' : 'رفع الشعار'}
              </button>
              {logoUrl && (
                <button onClick={handleDelete} className="px-4 py-2 bg-red-50 text-red-600 text-sm font-medium rounded-xl hover:bg-red-100">
                  حذف الشعار
                </button>
              )}
            </div>
            <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={handleUpload} />
          </div>
        ) : (
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
            <h2 className="font-semibold text-gray-900">إعدادات النسخ الاحتياطي</h2>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">مسار الحفظ المحلي</label>
              <input value={backupSettings.local_path} onChange={(e) => setBackupSettings({ ...backupSettings, local_path: e.target.value })}
                className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400 font-mono text-xs" />
            </div>

            {/* Schedule */}
            <div className="flex items-center gap-3 pt-2 border-t border-gray-100">
              <input type="checkbox" id="auto_backup" checked={backupSettings.auto_backup_enabled}
                onChange={(e) => setBackupSettings({ ...backupSettings, auto_backup_enabled: e.target.checked })}
                className="rounded border-gray-300" />
              <label htmlFor="auto_backup" className="text-sm font-medium text-gray-700">تشغيل النسخ الاحتياطي التلقائي</label>
            </div>

            {backupSettings.auto_backup_enabled && (
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">الوقت</label>
                  <input type="time" value={backupSettings.auto_backup_time}
                    onChange={(e) => setBackupSettings({ ...backupSettings, auto_backup_time: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">التكرار</label>
                  <select value={backupSettings.auto_backup_frequency}
                    onChange={(e) => setBackupSettings({ ...backupSettings, auto_backup_frequency: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400">
                    <option value="daily">يومي</option>
                    <option value="weekly">أسبوعي</option>
                  </select>
                </div>
                {backupSettings.auto_backup_frequency === 'weekly' && (
                  <div className="col-span-2">
                    <label className="block text-sm font-medium text-gray-700 mb-1">اليوم</label>
                    <select value={backupSettings.auto_backup_days}
                      onChange={(e) => setBackupSettings({ ...backupSettings, auto_backup_days: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400">
                      <option value="SUN">الأحد</option>
                      <option value="MON">الإثنين</option>
                      <option value="TUE">الثلاثاء</option>
                      <option value="WED">الأربعاء</option>
                      <option value="THU">الخميس</option>
                      <option value="FRI">الجمعة</option>
                      <option value="SAT">السبت</option>
                    </select>
                  </div>
                )}
              </div>
            )}

            {/* Email */}
            <div className="flex items-center gap-3 pt-2 border-t border-gray-100">
              <input type="checkbox" id="email_enabled" checked={backupSettings.email_enabled}
                onChange={(e) => setBackupSettings({ ...backupSettings, email_enabled: e.target.checked })}
                className="rounded border-gray-300" />
              <label htmlFor="email_enabled" className="text-sm text-gray-700">إرسال نسخة عبر البريد الإلكتروني</label>
            </div>
            {backupSettings.email_enabled && (
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">البريد الإلكتروني</label>
                <input type="email" value={backupSettings.email_to}
                  onChange={(e) => setBackupSettings({ ...backupSettings, email_to: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400" />
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
              <input type="number" value={backupSettings.retention_days}
                onChange={(e) => setBackupSettings({ ...backupSettings, retention_days: parseInt(e.target.value) || 7 })}
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

            {backupSettings.auto_backup_enabled && (
              <p className="text-xs text-green-600 bg-green-50 p-2 rounded-lg">
                ✓ الجدولة التلقائية نشطة — سيتم إنشاء نسخة احتياطية {backupSettings.auto_backup_frequency === 'daily' ? 'يومياً' : 'أسبوعياً'} الساعة {backupSettings.auto_backup_time}
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
