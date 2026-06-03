# Fix: Branches client_id mismatch

## Problem
- Branches were originally under ZAHRA's **old** UUID (`9a4eec9b-33d3-46d3-af20-1b373af24bcc`).
- First migration moved them to ZAHRA's **current** UUID (`9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5`) — **correct**.
- But I mistakenly reverted them to MR MIX UUID (`a5ac9226-0afc-44fc-9fb4-1893ce591086`) — **wrong**.

## Fix
Run a single PHP UPDATE to move all 9 branches back to ZAHRA's current client_id.

```sql
UPDATE branches SET client_id = '9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5' 
WHERE client_id = 'a5ac9226-0afc-44fc-9fb4-1893ce591086';
```

Or via PHP:
```php
\App\Models\Branch::where('client_id', 'a5ac9226-0afc-44fc-9fb4-1893ce591086')
    ->update(['client_id' => '9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5']);
```

## Verification
After fix:
- ZAHRA (`9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5`) → 9 branches
- MR MIX (`a5ac9226-0afc-44fc-9fb4-1893ce591086`) → 0 branches
