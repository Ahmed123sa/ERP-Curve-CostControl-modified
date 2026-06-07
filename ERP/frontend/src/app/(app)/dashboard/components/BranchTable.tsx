'use client';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface BranchRow {
  name: string;
  out_qty: number;
  in: number;
  diff: number | string;
}

interface Props {
  data?: BranchRow[];
  isLoading?: boolean;
}

export default function BranchTable({ data, isLoading }: Props) {
  return (
    <Card className="border-gray-100 shadow-sm">
      <CardHeader className="pb-2 px-4 pt-4">
        <CardTitle className="text-xs font-medium text-gray-500">ملخص الفروع</CardTitle>
      </CardHeader>
      <CardContent className="px-0 pb-0">
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-gray-100">
                <th className="text-right px-3 py-2 font-medium text-gray-400">الفرع</th>
                <th className="text-right px-3 py-2 font-medium text-gray-400">صادر</th>
                <th className="text-right px-3 py-2 font-medium text-gray-400">وارد</th>
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
                  <td className="px-3 py-2 font-mono text-gray-600">{row.out_qty?.toLocaleString()}</td>
                  <td className="px-3 py-2 font-mono text-gray-600">{row.in?.toLocaleString()}</td>
                  <td className="px-3 py-2">
                    {typeof row.diff === 'string' ? (
                      <span className="text-gray-300">{row.diff}</span>
                    ) : (
                      <span className={`inline-block text-xs font-mono font-medium px-2 py-0.5 rounded border ${
                        row.diff >= 0
                          ? 'bg-green-50 text-green-700 border-green-200'
                          : 'bg-red-50 text-red-700 border-red-200'
                      }`}>
                        {row.diff.toLocaleString()}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  );
}
