'use client';
import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/ui/AppShell';
import { useAuthStore } from '@/lib/store';
import toast from 'react-hot-toast';

interface Client {
  id: string; name: string;
}

interface UserItem {
  id: string; name: string; email: string; role: string;
  roles: string[]; permissions: string[];
  clients: Client[]; current_client_id: string | null;
}

export default function UsersPage() {
  const { user: me } = useAuthStore();
  const [users, setUsers] = useState<UserItem[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'cost_controller', client_ids: [] as string[] });

  const fetchUsers = async () => {
    const { data } = await api.get('/users');
    setUsers(data);
  };

  const fetchClients = async () => {
    const { data } = await api.get('/clients');
    setClients(data);
  };

  useEffect(() => { Promise.all([fetchUsers(), fetchClients()]).finally(() => setLoading(false)); }, []);

  const openCreate = () => {
    setEditId(null);
    setForm({ name: '', email: '', password: '', role: 'cost_controller', client_ids: [] });
    setModalOpen(true);
  };

  const openEdit = (u: UserItem) => {
    setEditId(u.id);
    setForm({
      name: u.name, email: u.email, password: '',
      role: u.role, client_ids: u.clients.map((c) => c.id),
    });
    setModalOpen(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      if (editId) {
        await api.put(`/users/${editId}`, { ...form, password: form.password || undefined });
        toast.success('تم تحديث المستخدم');
      } else {
        await api.post('/users', form);
        toast.success('تم إنشاء المستخدم');
      }
      setModalOpen(false);
      fetchUsers();
    } catch { toast.error('فشل الحفظ'); }
  };

  const handleDelete = async (id: string) => {
    if (!confirm('تأكيد حذف المستخدم؟')) return;
    try {
      await api.delete(`/users/${id}`);
      toast.success('تم الحذف');
      fetchUsers();
    } catch { toast.error('فشل الحذف'); }
  };

  if (me?.role !== 'admin' && !me?.permissions?.includes('users')) {
    return <div className="p-6 text-gray-500">ليس لديك صلاحية</div>;
  }

  return (
    <div>
      <PageHeader title="المستخدمين" actions={
        <button onClick={openCreate} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-xl hover:bg-blue-700">
          إضافة مستخدم
        </button>
      } />
      <div className="p-6">
        {loading ? <p className="text-gray-500">جاري التحميل...</p> : (
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-gray-600">
                <tr>
                  <th className="text-right px-4 py-3">الاسم</th>
                  <th className="text-right px-4 py-3">البريد</th>
                  <th className="text-right px-4 py-3">الدور</th>
                  <th className="text-right px-4 py-3">الشركات</th>
                  <th className="text-left px-4 py-3"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {users.map((u) => (
                  <tr key={u.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium">{u.name}</td>
                    <td className="px-4 py-3 text-gray-600">{u.email}</td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                        u.role === 'admin' ? 'bg-purple-100 text-purple-700' :
                        u.role === 'cost_controller' ? 'bg-blue-100 text-blue-700' :
                        'bg-gray-100 text-gray-600'
                      }`}>
                        {u.role === 'admin' ? 'مدير' : u.role === 'cost_controller' ? 'كوست كنترول' : 'مشاهد'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-gray-600">
                      {u.clients.map((c) => c.name).join(', ') || '—'}
                    </td>
                    <td className="px-4 py-3 text-left">
                      <button onClick={() => openEdit(u)} className="text-blue-600 hover:text-blue-800 ml-3">تعديل</button>
                      <button onClick={() => handleDelete(u.id)} className="text-red-500 hover:text-red-700">حذف</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {modalOpen && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50" onClick={() => setModalOpen(false)}>
          <div className="bg-white rounded-2xl shadow-lg p-6 w-full max-w-md mx-4" onClick={(e) => e.stopPropagation()}>
            <h2 className="font-semibold text-gray-900 mb-4">{editId ? 'تعديل' : 'إضافة'} مستخدم</h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">الاسم</label>
                <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400" required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">البريد</label>
                <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400" required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">كلمة المرور {editId && '(اتركه فارغاً بدون تغيير)'}</label>
                <input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400"
                  {...(editId ? {} : { required: true })} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">الدور</label>
                <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400">
                  <option value="admin">مدير (Super Admin)</option>
                  <option value="cost_controller">كوست كنترول</option>
                  <option value="viewer">مشاهد</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">الشركات المسموح بها</label>
                <div className="space-y-2 max-h-40 overflow-y-auto border border-gray-200 rounded-xl p-3">
                  {clients.map((c) => (
                    <label key={c.id} className="flex items-center gap-2 text-sm">
                      <input type="checkbox" checked={form.client_ids.includes(c.id)}
                        onChange={(e) => {
                          if (e.target.checked) setForm({ ...form, client_ids: [...form.client_ids, c.id] });
                          else setForm({ ...form, client_ids: form.client_ids.filter((id) => id !== c.id) });
                        }}
                        className="rounded border-gray-300" />
                      {c.name}
                    </label>
                  ))}
                  {clients.length === 0 && <p className="text-gray-400 text-xs">لا توجد شركات</p>}
                </div>
              </div>
              <div className="flex gap-2 pt-2">
                <button type="submit" className="flex-1 py-2 bg-blue-600 text-white text-sm font-medium rounded-xl hover:bg-blue-700">
                  {editId ? 'تحديث' : 'إضافة'}
                </button>
                <button type="button" onClick={() => setModalOpen(false)} className="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-xl hover:bg-gray-200">
                  إلغاء
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
