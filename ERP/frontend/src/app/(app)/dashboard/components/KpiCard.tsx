'use client';
import { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Area, AreaChart, ResponsiveContainer } from 'recharts';

interface KpiCardProps {
  icon: ReactNode;
  iconBg: string;
  iconColor: string;
  label: string;
  value: number | string | undefined | null;
  unit: string;
  change: number | undefined | null;
  changeLabel?: string;
  sparklineData?: { value: number }[];
  isLoading?: boolean;
}

export default function KpiCard({
  icon, iconBg, iconColor, label, value, unit, change, changeLabel, sparklineData, isLoading,
}: KpiCardProps) {
  const val = typeof value === 'number' ? value.toLocaleString('en-US') : (value ?? '—');
  const changeVal = change ?? 0;
  const isUp = changeVal >= 0;

  return (
    <Card className="overflow-hidden border-gray-100 shadow-sm hover:shadow-md transition-shadow">
      <CardContent className="p-4">
        <div className="flex items-start justify-between">
          <div className={`w-9 h-9 rounded-lg flex items-center justify-center ${iconBg} ${iconColor}`}>
            {icon}
          </div>
          {change !== undefined && change !== null && (
            <span className={`flex items-center gap-0.5 text-xs font-medium px-2 py-0.5 rounded-full ${
              isUp ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'
            }`}>
              <span className="text-[10px]">{isUp ? '▲' : '▼'}</span>
              {Math.abs(changeVal).toFixed(1)}%
            </span>
          )}
        </div>
        <div className="mt-3">
          <div className="text-xs text-gray-500">{label}</div>
          <div className="text-xl font-bold mt-0.5 font-mono tracking-tight">
            {isLoading ? (
              <span className="text-gray-300">...</span>
            ) : (
              <>{val} <span className="text-xs font-normal text-gray-400 mr-0.5">{unit}</span></>
            )}
          </div>
        </div>
        {sparklineData && sparklineData.length > 0 && (
          <div className="mt-2 h-8 -mx-1">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={sparklineData}>
                <defs>
                  <linearGradient id={`grad-${label}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={isUp ? '#16a34a' : '#dc2626'} stopOpacity={0.15} />
                    <stop offset="100%" stopColor={isUp ? '#16a34a' : '#dc2626'} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <Area
                  type="monotone"
                  dataKey="value"
                  stroke={isUp ? '#16a34a' : '#dc2626'}
                  strokeWidth={1.5}
                  fill={`url(#grad-${label})`}
                  dot={false}
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
