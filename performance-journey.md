# 🚀 100 Million Row Challenge - Performance Journey

## Starting Point: Naive Implementation
- **Time:** ~50s+
- Single-threaded, using `parse_url()`, inefficient string handling

---

## Phase 1: Basic Optimizations

### String Parsing Optimizations
- Replaced `parse_url()` with `strpos()`/`substr()`
- Hardcoded known positions where possible
- **Result:** 46.68s (7% faster)

### Parallel Processing with `pcntl_fork()`
| Workers | Time | Improvement |
|---------|------|-------------|
| 2 | 42.20s | 16% faster |
| 4 | 24.18s | 52% faster |
| 8 | 16.32s | 67% faster |
| 16 | 10.92s | 78% faster |

### IPC Optimization with igbinary
| Metric | Text-based | igbinary | Improvement |
|--------|-----------|----------|-------------|
| IPC Size | ~330 MB | **36.5 MB** | **89% smaller** |
| Write time | ~40ms | **~20ms** | **50% faster** |
| Merge time | 1.37s | **0.53s** | **61% faster** |
| **TOTAL** | 10.92s | **9.73s** | **11% faster** |

---

## Phase 2: Hot Loop Optimizations

After profiling, we found the **parse phase was 92% of total time** (~9s out of 9.73s). We targeted micro-optimizations in the hot loop that processes 100M lines.

### Optimization #1: Eliminate `ftell()` calls
**Problem:** Calling `ftell($handle) < $end` on every iteration = 6.25M syscalls per worker!

**Solution:** Track bytes read manually:
```php
// Before: ftell() every iteration
while (ftell($handle) < $end && ($line = fgets($handle)) !== false)

// After: manual byte counting
$bytesRead = 0;
$bytesToRead = $end - $start;
while ($bytesRead < $bytesToRead && ($line = fgets($handle)) !== false) {
    $bytesRead += strlen($line);
    // ...
}
```

### Optimization #2: Calculate comma position instead of `strpos()`
**Problem:** `strpos($line, ',', 19)` searches through the string = 100M function calls

**Solution:** The line format is fixed! Date is always 25 chars + comma = 26 from end:
```php
// Before: search for comma
$commaPos = strpos($line, ',', 19);

// After: calculate position (date is fixed length)
$commaPos = strlen($line) - 26;
```

### Optimization #3: Use `++` increment with `isset` checks
**Problem:** `($result[$path][$date] ?? 0) + 1` does null coalescing + addition

**Solution:** Separate paths for new vs existing entries with pre-increment:
```php
if (!isset($result[$path])) {
    $result[$path] = [$date => 1];
} elseif (!isset($result[$path][$date])) {
    $result[$path][$date] = 1;
} else {
    ++$result[$path][$date];
}
```

### Results After Hot Loop Optimizations
| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Parse time (per worker) | ~9.0s | **~7.4s** | **18% faster** |
| **TOTAL** | 9.73s | **8.03s** | **17% faster** |

---

## Phase 3: C-Native Functions 🔥

The bottleneck was still I/O: **6.25M `fgets()` syscalls per worker**. We explored PHP's C-native functions.

### The Winner: `file_get_contents()` + `strtok()`

**Key insight:** Both functions are implemented in C and avoid PHP array overhead.

```php
// Read entire chunk - 1 syscall instead of 6.25M fgets() calls
$chunk = file_get_contents($inputPath, false, null, $start, $end - $start);

// strtok() tokenizes in place without creating an array
// (unlike explode() which creates 6.25M element array = ~300MB overhead)
$line = strtok($chunk, "\n");
while ($line !== false) {
    // process line...
    $line = strtok("\n");
}
```

**Why this works:**
- `file_get_contents()` - 1 syscall to read ~469MB chunk vs 6.25M `fgets()` calls
- `strtok()` - C function that tokenizes in place, no array allocation
- Memory efficient: doesn't create intermediate arrays like `explode()` would

### Results After C-Native Optimization
| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Parse time (per worker) | ~7.4s | **~4.8s** | **35% faster** |
| **TOTAL** | 8.03s | **5.44s** | **32% faster** |

---

## 🏆 Final Summary: 100M Rows Journey

| Approach | Time | vs Baseline |
|----------|------|-------------|
| Naive single-threaded | ~50s+ | baseline |
| Optimized single-threaded | 46.68s | 7% faster |
| Parallel 2 workers | 42.20s | 16% faster |
| Parallel 4 workers | 24.18s | 52% faster |
| Parallel 8 workers | 16.32s | 67% faster |
| Parallel 16 workers (text) | 10.92s | 78% faster |
| Parallel 16 workers (igbinary) | 9.73s | 80% faster |
| + Hot loop optimizations | 8.03s | 84% faster |
| **+ C-native strtok()** | **5.44s** | **89% faster** |

**We went from ~50s to ~5.4 seconds!** 🚀

---

## Current Breakdown (5.44s total)

| Phase | Time | % of Total |
|-------|------|------------|
| Parse (workers) | ~4.8s | 88% |
| Merge | 0.52s | 9.5% |
| Sort | 0.08s | 1.5% |
| JSON Write | 0.01s | 0.2% |

---

## What Made the Difference

1. **String parsing optimizations** - Hardcoded position, `strpos`/`substr` instead of `parse_url()`
2. **Early-init pattern** - Skip redundant operations for new paths
3. **Parallel processing** - 16 workers with `pcntl_fork()`
4. **Smart IPC** - `igbinary` for compact, fast serialization
5. **Buffered writes** - Single `file_put_contents()` instead of many `fwrite()`
6. **Eliminated `ftell()` syscalls** - Manual byte counting in hot loop
7. **Calculated comma position** - Fixed format means no string search needed
8. **Optimized increment pattern** - `isset` checks with `++` operator
9. **C-native `file_get_contents()` + `strtok()`** - Bulk read + in-place tokenization

## Memory Usage
Still only **~76 MB per worker** (plus ~469MB for the chunk) because we store only ~500K unique path×date combinations, not 100M rows!

## Failed Experiments
- **`preg_match_all()`** - Creates massive matches array (6.25M × ~200 bytes = 1.25GB)
- **`explode()`** - Creates array with 6.25M elements, huge memory overhead
- **Flat key `"$path|$date"`** - More memory than nested arrays (keys are longer)