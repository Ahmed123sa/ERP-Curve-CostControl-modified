'use client';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface WhRow {
  name: string;
  purchases: number;
  out_qty: number;
  diff: number;
}

interface Props {
  data?: WhRow[];
  isLoading?: boolean;
}

function DiffBadge({ diff }: { diff: number }) {
  const absVal = Math.abs(diff ?? 0);
  const isNeg = (diff ?? 0) < 0;
  let color = 'bg-green-50 text-green-700 border-green-200';
  if (isNeg && absVal > 10000) color = 'bg-red-50 text-red-700 border-red-200';
  else if (isNeg && absVal > 1000) color = 'bg-amber-50 text-amber-700 border-amber-200';

  return (
    <span className={`inline-block text-xs font-mono font-medium px-2 py-0.5 rounded border ${color}`}>
      {diff.toLocaleString()}
    </span>
  );
}

export default function WarehouseTable({ data, isLoading }: Props) {
  return (
    <Card className="border-gray-100 shadow-sm">
      <CardHeader className="pb-2 px-4 pt-4">
        <CardTitle className="text-xs font-medium text-gray-500">ملخص المخازن</CardTitle>
      </CardHeader>
      <CardContent className="px-0 pb-0">
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-gray-100">
                <th className="text-right px-3 py-2 font-medium text-gray-400">المخزن</th>
                <th className="text-right px-3 py-2 font-medium text-gray-400">مشتريات</th>
                <th className="text-right px-3 py-2 font-medium text-gray-400">منصرف</th>
                <th className="text-right px-3 py-2 font-medium text-gray-400">الفرق</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <tr><td colSpan={4} className="text-center py-6 text-gray-300">جارٍ التحميل...</td></tr>
              ) : !data?.length ? (
                <tr><td colSpan={4} className="text-center py-6 text-gray-300">لا توجد بيانات</td></tr>
              ) : data.map((row, i) => (
                <tr key={i} className="border-b border-gray-50 hover:bg-gray-50/50 transition-colors">
                  <td className="px-3 py-2 font-medium text-gray-700">{row.name}</td>
                  <td className="px-3 py-2 font-mono text-gray-600">{row.purchases?.toLocaleString()}</td>
                  <td className="px-3 py-2 font-mono text-gray-600">{row.out_qty?.toLocaleString()}</td>
                  <td className="px-3 py-2"><DiffBadge diff={row.diff} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  );
}
