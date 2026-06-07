'use client';
import {
  PieChart, Pie, Cell, Tooltip, ResponsiveContainer,
  BarChart, Bar, XAxis, YAxis, CartesianGrid,
  LineChart, Line, Legend,
} from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const PIE_COLORS = ['#3b82f6', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];

interface DiffPieProps {
  data?: { name: string; value: number }[];
  isLoading?: boolean;
}

export function DiffPieChart({ data, isLoading }: DiffPieProps) {
  if (isLoading) return <CardSkeleton />;
  if (!data?.length) return <EmptyCard title="توزيع الفروق حسب المخازن" />;

  return (
    <Card className="border-gray-100 shadow-sm">
      <CardHeader className="pb-2 px-4 pt-4">
        <CardTitle className="text-xs font-medium text-gray-500">توزيع الفروق حسب المخازن</CardTitle>
      </CardHeader>
      <CardContent className="px-2 pb-2">
        <ResponsiveContainer width="100%" height={200}>
          <PieChart>
            <Pie data={data} cx="50%" cy="50%" innerRadius={50} outerRadius={80}
              dataKey="value" nameKey="name" paddingAngle={2}>
              {data.map((_, i) => (
                <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
              ))}
            </Pie>
            <Tooltip formatter={(v: number) => v.toLocaleString()} />
          </PieChart>
        </ResponsiveContainer>
        <div className="flex flex-wrap gap-2 px-2 mt-1">
          {data.map((d, i) => (
            <div key={d.name} className="flex items-center gap-1 text-[10px] text-gray-500">
              <span className="w-2 h-2 rounded-full" style={{ backgroundColor: PIE_COLORS[i % PIE_COLORS.length] }} />
              {d.name}
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

interface TopItemsProps {
  data?: { item_name: string; diff_value: number }[];
  isLoading?: boolean;
}

export function TopDiffItems({ data, isLoading }: TopItemsProps) {
  if (isLoading) return <CardSkeleton />;
  if (!data?.length) return <EmptyCard title="أعلى 10 أصناف في الفروق" />;

  const maxVal = Math.max(...data.map(d => Math.abs(d.diff_value)));

  return (
    <Card className="border-gray-100 shadow-sm">
      <CardHeader className="pb-2 px-4 pt-4">
        <CardTitle className="text-xs font-medium text-gray-500">أعلى 10 أصناف في الفروق</CardTitle>
      </CardHeader>
      <CardContent className="px-2 pb-2">
        <div className="space-y-1.5">
          {data.map((d) => {
            const pct = (Math.abs(d.diff_value) / maxVal) * 100;
            const isNeg = d.diff_value < 0;
            return (
              <div key={d.item_name} className="flex items-center gap-2">
                <span className="text-[11px] text-gray-600 w-24 truncate text-right">{d.item_name}</span>
                <div className="flex-1 bg-gray-100 rounded-full h-2">
                  <div
                    className={`h-2 rounded-full ${isNeg ? 'bg-red-400' : 'bg-green-400'}`}
                    style={{ width: `${Math.max(pct, 3)}%` }}
                  />
                </div>
                <span className={`text-[11px] font-mono w-16 text-left ${isNeg ? 'text-red-600' : 'text-green-600'}`}>
                  {d.diff_value.toLocaleString()}
                </span>
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}

interface TrendChartProps {
  data?: { month: string; purchases: number; dispatched: number; diffs: number }[];
  isLoading?: boolean;
}

export function TrendChart({ data, isLoading }: TrendChartProps) {
  if (isLoading) return <CardSkeleton />;
  if (!data?.length) return <EmptyCard title="اتجاه شهري" />;

  return (
    <Card className="border-gray-100 shadow-sm col-span-full">
      <CardHeader className="pb-2 px-4 pt-4">
        <CardTitle className="text-xs font-medium text-gray-500">اتجاه شهري (آخر 6 أشهر)</CardTitle>
      </CardHeader>
      <CardContent className="px-2 pb-2">
        <ResponsiveContainer width="100%" height={220}>
          <LineChart data={data}>
            <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
            <XAxis dataKey="month" tick={{ fontSize: 10 }} />
            <YAxis tick={{ fontSize: 10 }} />
            <Tooltip />
            <Legend iconType="circle" iconSize={8} />
            <Line type="monotone" dataKey="purchases" name="مشتريات" stroke="#3b82f6" strokeWidth={2} dot={{ r: 3 }} />
            <Line type="monotone" dataKey="dispatched" name="منصرف" stroke="#6b7280" strokeWidth={2} dot={{ r: 3 }} />
            <Line type="monotone" dataKey="diffs" name="فروق" stroke="#ef4444" strokeWidth={2} dot={{ r: 3 }} />
          </LineChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

export function CardSkeleton() {
  return (
    <Card className="border-gray-100 shadow-sm animate-pulse">
      <CardContent className="p-4">
        <div className="h-4 bg-gray-100 rounded w-1/2 mb-2" />
        <div className="h-32 bg-gray-50 rounded" />
      </CardContent>
    </Card>
  );
}

export function EmptyCard({ title }: { title: string }) {
  return (
    <Card className="border-gray-100 shadow-sm">
      <CardHeader className="pb-2 px-4 pt-4">
        <CardTitle className="text-xs font-medium text-gray-500">{title}</CardTitle>
      </CardHeader>
      <CardContent className="px-4 pb-4">
        <div className="text-center text-gray-300 text-sm py-6">لا توجد بيانات كافية</div>
      </CardContent>
    </Card>
  );
}
