# Add Pagination to Voucher History Page

## Problem

صفحة `vouchers/history` بتجيب أول 100 أمر فقط (default per_page=100) ومفيش طريقة للوصول للصفحات التانية.

## Changes Required

**File:** `ERP/frontend/src/app/(app)/vouchers/history/page.tsx`

### 1. Add `page` to URL params and state

- Read `page` from searchParams: `const page = parseInt(searchParams.get('page') ?? '1', 10);`
- Add `page` to `filters` object: `const filters = { date_from: dateFrom, date_to: dateTo, type, page };`
- Include `page` in queryKey

### 2. Pass page to API and read pagination data

```tsx
const { data: vouchers, isLoading } = useQuery({
    queryKey: ['vouchers', filters],
    queryFn: () => api.get('/vouchers', { params: filters }).then((r) => r.data),
    enabled: showTable,
});

const list  = vouchers?.data ?? [];
const total = vouchers?.total ?? 0;
const currentPage = vouchers?.current_page ?? 1;
const lastPage = vouchers?.last_page ?? 1;
```

### 3. Add `updateUrl` to reset page when filters change

في دوال تغيير الفلاتر (`handleMonthChange`, onChange بتوع date inputs, select type)، أضيف `page: '1'` أو `page: ''` عشان يرجع لأول صفحة.

### 4. Add Pagination component after the table (after `</table>`)

```tsx
{/* Pagination */}
{lastPage > 1 && (
  <div className="flex items-center justify-between px-4 py-3 bg-white border-t border-gray-100" dir="ltr">
    <div className="text-xs text-gray-400">
      الصفحة {currentPage} من {lastPage} ({total} إذن)
    </div>
    <div className="flex gap-1">
      <button
        onClick={() => updateUrl({ page: String(currentPage - 1) })}
        disabled={currentPage <= 1}
        className="px-3 py-1.5 text-sm rounded-lg border border-gray-200 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-gray-50 transition-colors"
      >
        السابق
      </button>
      {Array.from({ length: lastPage }, (_, i) => i + 1)
        .filter(p => Math.abs(p - currentPage) <= 2 || p === 1 || p === lastPage)
        .map((p, idx, arr) => (
          <React.Fragment key={p}>
            {idx > 0 && arr[idx-1] !== p-1 && <span className="px-1 self-center text-gray-300">...</span>}
            <button
              onClick={() => updateUrl({ page: String(p) })}
              className={`px-3 py-1.5 text-sm rounded-lg border transition-colors ${
                p === currentPage
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'border-gray-200 hover:bg-gray-50 text-gray-600'
              }`}
            >
              {p}
            </button>
          </React.Fragment>
        ))}
      <button
        onClick={() => updateUrl({ page: String(currentPage + 1) })}
        disabled={currentPage >= lastPage}
        className="px-3 py-1.5 text-sm rounded-lg border border-gray-200 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-gray-50 transition-colors"
      >
        التالي
      </button>
    </div>
  </div>
)}
```

### 5. Add `import React from 'react';` at the top (needed for `React.Fragment`)

### 6. Update `handleMonthChange` and filter onChange handlers to reset page

When any filter changes (month, date_from, date_to, type), set `page: ''` or `page: '1'` in the `updateUrl` call.

### 7. Update `PageHeader` subtitle to show page info

```tsx
subtitle={showTable ? `صفحة ${currentPage} من ${lastPage} — ${total} إذن` : 'اختر الشهر أولاً لعرض الحركات'}
```

## Testing

1. افتح صفحة history بشهر فيه أكتر من 100 أمر
2. تأكد إن pagination ظهر تحت الجدول
3. جرب التبديل بين الصفحات — الـ URL يتغير والبيانات تتغير
4. جرب تغيير الفلتر (شهر/تاريخ/نوع) — يرجع لأول صفحة
5. جرب حذف مجموعة والتنقل بين الصفحات
