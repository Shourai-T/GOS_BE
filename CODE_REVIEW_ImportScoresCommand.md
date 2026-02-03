# Production Code Review: ImportScoresCommand.php

**Reviewer**: Senior Backend Engineer  
**Date**: 2026-02-04  
**Overall Rating**: ⭐⭐⭐⭐ 7.5/10 (Good, needs improvements for production)

---

## ✅ Strengths (What's Done Well)

### 1. **Idempotency & File Locking** ✅

```php
// Lines 35-42: Prevents duplicate concurrent imports
$existingJob = ImportJob::where('file_hash', $fileHash)
    ->whereIn('status', [ImportJob::STATUS_PENDING, ImportJob::STATUS_PROCESSING])
    ->first();
```

**Rating**: 9/10  
**Why Good**: Prevents race conditions. Uses file hash for duplication detection.

### 2. **Batch Upsert (Performance)** ✅

```php
// Lines 254-258: Single query instead of N queries
DB::table('students')->upsert($values, ['sbd'], [...]);
```

**Rating**: 10/10  
**Why Good**: **20-30x faster** than loop with `updateOrCreate()`. Production-grade optimization.

### 3. **Error Logging with Context** ✅

```php
// Lines 158-162: Logs line number + raw data + reason
$this->errorLog[] = [
    'line_number' => $lineNumber,
    'raw_data' => implode(',', $row),
    'error_reason' => $e->getMessage(),
];
```

**Rating**: 9/10  
**Why Good**: Admin can debug and fix CSV manually without re-running entire import.

### 4. **Data Denormalization (Design)** ✅

```php
// Lines 196-203: Pre-calculate group_a_score
if ($toan !== null && $ly !== null && $hoa !== null) {
    $student['group_a_score'] = $toan + $ly + $hoa;
}
```

**Rating**: 10/10  
**Why Good**: Query optimization trade-off. Read-heavy systems benefit massively.

---

## ⚠️ Critical Issues (Production Blockers)

### 1. **❌ No Transaction Wrapper (Data Integrity Risk)**

**Severity**: CRITICAL  
**Line**: 142-179 (processChunk)

**Current**:

```php
private function processChunk(array $chunk, array $header): void
{
    // ... prepare data ...
    $this->upsertStudents($students);  // ← Query 1
    $this->upsertScores($scores);      // ← Query 2
    $this->job->increment('processed_rows'); // ← Query 3
}
```

**Problem**:  
If `upsertScores()` fails after `upsertStudents()` succeeds, you'll have:

- ✅ Students inserted
- ❌ Scores NOT inserted
- Result: **Inconsistent state**

**Fix**:

```php
private function processChunk(array $chunk, array $header): void
{
    DB::transaction(function () use ($chunk, $header) {
        $students = [];
        $scores = [];

        // ... validation logic ...

        if (count($students) > 0) {
            $this->upsertStudents($students);
        }

        if (count($scores) > 0) {
            $this->upsertScores($scores);
        }

        $this->job->increment('processed_rows', count($chunk));
    });

    $this->info("Processed chunk: {$this->job->processed_rows}/{$this->job->total_rows}");
}
```

**Impact**: If ANY part fails, entire chunk rolls back. Atomic operations.

---

### 2. **❌ Memory Leak Risk (Large Error Logs)**

**Severity**: HIGH  
**Line**: 18-19, 158-162

**Current**:

```php
private $errorLog = []; // ← Unbounded array in memory

// Lines 158-162
$this->errorLog[] = [...]; // ← Keep adding to memory
```

**Problem**:  
With 1M rows and 10% error rate = **100K errors in memory** = potential OOM crash.

**Fix**:

```php
private function logError(int $lineNumber, string $rawData, string $reason): void
{
    $errorFile = storage_path("logs/import_errors_{$this->job->id}.csv");

    // Append mode - write immediately, don't store in memory
    $handle = fopen($errorFile, 'a');

    if (ftell($handle) === 0) {
        fputcsv($handle, ['line_number', 'raw_data', 'error_reason']);
    }

    fputcsv($handle, [$lineNumber, $rawData, $reason]);
    fclose($handle);
}

// In processChunk (line 158)
catch (\Exception $e) {
    $this->logError($lineNumber, implode(',', $row), $e->getMessage());
    $this->job->increment('error_rows');
}

// Remove writeErrorLog() method (line 292-305) - no longer needed
```

**Impact**: Constant memory usage regardless of error count.

---

### 3. **❌ No Resume Capability (Incomplete Idempotency)**

**Severity**: MEDIUM  
**Line**: 45-50, 116-140

**Current**:  
If import crashes at row 500K, you have to **re-import from row 1**.

**Problem**:

- Job tracks `file_hash` but not `last_processed_line`
- Migration has `last_processed_line` field but **code doesn't use it**

**Fix**:

```php
private function processChunks(string $filePath, int $chunkSize): void
{
    $handle = fopen($filePath, 'r');
    $header = fgetcsv($handle);

    // Skip to last processed line
    $lineNumber = 1;
    $resumeFrom = $this->job->last_processed_line ?? 0;

    while ($lineNumber <= $resumeFrom && fgetcsv($handle) !== false) {
        $lineNumber++;
    }

    if ($resumeFrom > 0) {
        $this->info("Resuming from line {$resumeFrom}");
    }

    $chunk = [];

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        $chunk[] = ['line' => $lineNumber, 'data' => $row];

        if (count($chunk) >= $chunkSize) {
            $this->processChunk($chunk, $header);

            // Update resume point after successful chunk
            $this->job->update(['last_processed_line' => $lineNumber]);

            $chunk = [];
        }
    }

    // Process remaining + update final position
    if (count($chunk) > 0) {
        $this->processChunk($chunk, $header);
        $this->job->update(['last_processed_line' => $lineNumber]);
    }

    fclose($handle);
}
```

**Impact**: Can resume failed imports instead of full re-run.

---

### 4. **❌ No Rate Limiting / Backpressure**

**Severity**: MEDIUM  
**Line**: 116-140

**Current**:  
Code processes chunks as fast as possible. If Supabase has **connection limits** (100 concurrent), this can cause:

- Connection pool exhaustion
- Database throttling
- Failed queries

**Fix**:

```php
private function processChunk(array $chunk, array $header): void
{
    $maxRetries = 3;
    $attempt = 0;

    while ($attempt < $maxRetries) {
        try {
            DB::transaction(function () use ($chunk, $header) {
                // ... existing logic ...
            });

            break; // Success

        } catch (\Illuminate\Database\QueryException $e) {
            $attempt++;

            if ($attempt >= $maxRetries) {
                throw $e; // Give up
            }

            // Exponential backoff: 1s, 2s, 4s
            $waitTime = pow(2, $attempt);
            $this->warn("Query failed, retrying in {$waitTime}s... (Attempt {$attempt}/{$maxRetries})");
            sleep($waitTime);
        }
    }

    // Small delay to prevent overwhelming database
    usleep(10000); // 10ms between chunks
}
```

**Impact**: Handles transient database errors gracefully.

---

## 🟡 Medium Priority Issues

### 5. **Hardcoded Subject Keys** (Maintainability)

**Severity**: MEDIUM  
**Line**: 207

**Current**:

```php
foreach (['toan', 'ngu_van', 'ngoai_ngu', ...] as $subjectKey) {
```

**Problem**: If subjects change, must update code.

**Fix**:

```php
// Use database-driven subject list
foreach ($this->subjectMap as $subjectKey => $subjectId) {
    if (isset($data[$subjectKey])) {
        // ...
    }
}
```

---

### 6. **Missing created_at in Upsert** (Audit Trail)

**Severity**: LOW  
**Line**: 245-252, 267-278

**Current**:

```php
'updated_at' => $now,
// → created_at is NULL for new records!
```

**Fix**:

```php
$values = array_map(function($student) use ($now) {
    return [
        'sbd' => $student['sbd'],
        'ma_ngoai_ngu' => $student['ma_ngoai_ngu'],
        'group_a_score' => $student['group_a_score'],
        'created_at' => $now,  // ← Add this
        'updated_at' => $now,
    ];
}, $students);
```

Laravel's `upsert()` **won't auto-set created_at** if using raw DB::table().

---

### 7. **No Progress Bar** (UX)

**Severity**: LOW  
**Line**: 178

**Current**: Only text output

```
Processed chunk: 5000/1061605
```

**Fix**:

```php
use Symfony\Component\Console\Helper\ProgressBar;

private function processChunks(string $filePath, int $chunkSize): void
{
    // ... setup ...

    $progressBar = $this->output->createProgressBar($this->job->total_rows);
    $progressBar->start();

    while (($row = fgetcsv($handle)) !== false) {
        // ... chunk logic ...

        if (count($chunk) >= $chunkSize) {
            $this->processChunk($chunk, $header);
            $progressBar->advance(count($chunk));
            $chunk = [];
        }
    }

    $progressBar->finish();
    $this->newLine();
}
```

---

## 📊 Production Readiness Checklist

| Category            | Status | Score | Notes                       |
| ------------------- | ------ | ----- | --------------------------- |
| **Performance**     | ✅     | 10/10 | Batch upsert excellent      |
| **Idempotency**     | ⚠️     | 6/10  | File locking ✅, Resume ❌  |
| **Error Handling**  | ⚠️     | 6/10  | Logging ✅, Retries ❌      |
| **Data Integrity**  | ❌     | 4/10  | Transactions MISSING        |
| **Memory Safety**   | ❌     | 5/10  | Error log unbounded         |
| **Observability**   | ⚠️     | 7/10  | Logs OK, Metrics missing    |
| **Maintainability** | ⚠️     | 7/10  | Clean code, some hardcoding |
| **Security**        | ✅     | 9/10  | Validation solid            |

**Overall**: 7.5/10 - **Good foundation, needs critical fixes**

---

## 🚀 Recommended Apply Order

### Priority 1 (Before Production)

1. ✅ **Add DB transactions** (Lines 142-179)
2. ✅ **Fix memory leak** (Error log streaming)
3. ✅ **Add retry logic** (Database failures)

### Priority 2 (Nice to Have)

4. ⚠️ **Resume capability** (Use last_processed_line)
5. ⚠️ **Fix created_at** (Audit trail)
6. ⚠️ **Remove hardcoded subjects** (Use subjectMap)

### Priority 3 (Enhancement)

7. 💡 **Progress bar** (Better UX)
8. 💡 **Monitoring metrics** (Prometheus/Datadog)

---

## 📝 Final Verdict

**Current State**: Functional and fast, but has **data integrity risks** in edge cases.

**Production Ready**: ❌ **NO** (without transactions)  
**Production Ready After Fixes**: ✅ **YES** (with Priority 1 fixes)

**Estimated Fix Time**: 2-3 hours for all Priority 1 items

**Key Strengths**:

- Batch performance (20-30x improvement)
- Error logging with context
- File locking prevents duplicates

**Critical Gaps**:

- No transactional safety
- Memory leak risk with errors
- No retry mechanism for DB failures

---

**Recommendation**: Apply **Priority 1 fixes** before deploying to production. The current code will work 95% of the time, but the 5% edge cases (network blips, OOM with many errors) will cause data corruption or crashes in production.
