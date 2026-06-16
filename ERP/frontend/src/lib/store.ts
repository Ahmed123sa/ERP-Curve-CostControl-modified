// src/lib/store.ts
// Global state — Auth + العميل الحالي

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { api } from './api';

interface Client {
  id: string;
  name: string;
  slug: string;
}

interface User {
  id: string;
  name: string;
  email: string;
  role: 'admin' | 'cost_controller' | 'viewer' | 'client';
  portal?: 'internal' | 'client';
  permissions?: string[];
  clients: Client[];
  current_client_id: string;
}

interface AuthStore {
  user: User | null;
  token: string | null;
  currentClient: Client | null;

  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  switchClient: (clientId: string) => Promise<void>;
  setUser: (user: User) => void;
}

export const useAuthStore = create<AuthStore>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      currentClient: null,

      login: async (email, password) => {
        const { data } = await api.post('/auth/login', { email, password });
        localStorage.setItem('erp_token', data.token);
        const currentClient = data.user.clients.find(
          (c: Client) => c.id === data.user.current_client_id,
        ) ?? data.user.clients[0];
        set({ user: data.user, token: data.token, currentClient });
      },

      logout: async () => {
        await api.post('/auth/logout').catch(() => {});
        localStorage.removeItem('erp_token');
        set({ user: null, token: null, currentClient: null });
      },

      switchClient: async (clientId) => {
        await api.post(`/auth/switch-client/${clientId}`);
        const client = get().user?.clients.find((c) => c.id === clientId);
        if (client) {
          const user = get().user;
          if (user) set({ user: { ...user, current_client_id: clientId }, currentClient: client });
        }
      },

      setUser: (user) => set({ user }),
    }),
    { name: 'erp-auth', partialize: (s) => ({ user: s.user, token: s.token, currentClient: s.currentClient }) },
  ),
);
