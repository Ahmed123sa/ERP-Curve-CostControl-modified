'use client';
export function Skeleton({ className = '', rows = 5 }: { className?: string; rows?: number }) {
  return (
    <div className={`animate-pulse ${className}`}>
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex gap-4 mb-3">
          <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-full" />
        </div>
      ))}
    </div>
  );
}

export function TableSkeleton({ cols = 5, rows = 6 }: { cols?: number; rows?: number }) {
  return (
    <div className="animate-pulse">
      <div className="flex gap-4 mb-4 px-4">
        {Array.from({ length: cols }).map((_, i) => (
          <div key={i} className="h-4 bg-gray-200 dark:bg-gray-700 rounded flex-1" />
        ))}
      </div>
      {Array.from({ length: rows }).map((_, r) => (
        <div key={r} className="flex gap-4 mb-3 px-4">
          {Array.from({ length: cols }).map((_, c) => (
            <div key={c} className={`h-3 bg-gray-100 dark:bg-gray-800 rounded ${c === 0 ? 'flex-[2]' : 'flex-1'}`} />
          ))}
        </div>
      ))}
    </div>
  );
}
