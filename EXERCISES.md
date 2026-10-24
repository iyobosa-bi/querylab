# Tech-aid Query Lab: Exercises

41 exercises in 5 levels. Levels 1–3 have a checkable answer in `CHECK_VALUES.md` (don't open it until you've written your query). Levels 4–5 are measured: paste your numbers.

**"Today" is `2026-08-31 23:59:59`** (`config('lab.anchor_date')`). "Last 30 days" means relative to that, not `now()`, so answers never change.

## How to send me an answer to critique

Paste this into the chat:

```
Exercise: 12
Dataset: large
Code:
    (your Eloquent / Query Builder / SQL)
Result: 2461
Debugbar: 1 query, 38 ms SQL, 2.1 MB memory, 0 models
```

I'll check correctness, edge cases (NULLs, cancelled jobs, failed payments…), query count, index use and readability, and show a better version if there is one. Try each exercise in Eloquent first, then ask yourself whether the Query Builder or raw SQL would be clearer or faster.

## The schema in one picture

```
areas ─┬─< users (customers, technicians, 1 admin) ──< service_requests >── categories (self-ref parent_id)
       │        │                                        │    │    │
       └─< technicians >──< category_technician >────────┘    │    ├──< quotes  (technician bids)
                │                                             │    ├──< reviews (0 or 1 per request)
                └──────────── technician_id ──────────────────┘    └──< payments (success | failed | refunded)
settings (key/value)
```

- `service_requests.status`: `pending` (no technician), `accepted`, `in_progress`, `completed`, `cancelled` (with or without a technician).
- Revenue means `payments.status = 'success'`. Failed payments have `paid_at = NULL`; refunded ones are not revenue.
- Categories: 4 parents with subcategories (Plumbing, Electrical, Appliance repair, Computers & phones), 5 without, jobs only on the lowest level. "Generator repair" is inactive.

---

## Level 1: Warm-up

1. Count the technicians in **Ikeja** who are **verified and available**.
2. Count requests **per status** (one query, 5 rows).
3. The **10 most recently completed** jobs (by `completed_at`) with customer name, technician name and category name. Rule: **at most 5 queries**, then do it in **1**.
4. How many **customers** have **never posted** a request? (Two ways: `whereDoesntHave`, then `LEFT JOIN … WHERE … IS NULL`.)
5. How many **technicians have no reviews** at all?
6. List **all 20 categories** with their number of requests, **including zeros**. How many have zero?
7. Requests **created in 2025** with a budget **between ₦20,000 and ₦50,000** inclusive. Rule: no `whereYear()`; use a range that can use the index.
8. How many **users have no area**?

## Level 2: Aggregates and relationships

9. **Top 10 technicians by average rating**, only those with **at least 20 reviews**. Use `withAvg`/`withCount`. Think about where "at least 20" goes: `where`, `having`, or `has()`?
10. **Monthly revenue for 2025** (successful payments, grouped by `paid_at` month, 12 rows) using `selectRaw` + `date_trunc`. Also return the **2025 refunded total**. Bonus: all of it in one query with `FILTER`.
11. **Top 5 areas by completed jobs in the last 12 months** (by `completed_at`).
12. How many customers have **spent more than ₦1,000,000** in successful payments?
13. How many technicians have a skill under **Plumbing AND** a skill under **Electrical** (any subcategory)?
14. For each **top-level category**, total requests **including its subcategories**. (Self-join on `parent_id`.)
15. **Average hours from created to completed** per category (completed jobs only). Which category is slowest? (PostgreSQL: `EXTRACT(EPOCH FROM …)`.)
16. **Cancellation rate (%)** per area, 1 decimal place, only areas with **at least 100 requests** (small dataset: 10). Which area is highest?
17. What **percentage of completed jobs got a review**?

## Level 3: Subqueries and window functions

18. The **cheapest quote for each request**: once with `with('lowestQuote')`, once with `DISTINCT ON`. How many requests have at least one quote?
19. Every technician with the **date of their most recent completed job**, in **one query** (`addSelect` + subquery). How many have none?
20. **Rank technicians within their area** by completed jobs with `RANK() OVER (PARTITION BY …)`, including technicians with zero jobs. How many rows have rank 1? (Why can it be more than one per area?)
21. **Running total** of successful revenue by month across the whole dataset (`SUM() OVER (ORDER BY …)`). What's the final total?
22. **Month-over-month change** in requests with `LAG()`. Which month had the **biggest percentage drop**?
23. How many requests have an **accepted quote that was NOT the cheapest** one?
24. How many customers' **very first request** was **cancelled**?
25. **Stale requests**: still `pending` more than **60 days** after they were created. How many?

## Level 4: Performance (Debugbar, EXPLAIN, chunking)

Use the **large** dataset. Paste before/after numbers.

26. A page listing **50 verified technicians** with name, area, skills, average rating and review count. Rule: **≤ 5 queries**, no lazy loading. Then turn on `Model::preventLazyLoading()` and confirm nothing breaks.
27. Run lab 6 (`/lab/missing-index`) before and after `php artisan migrate --path=database/migrations/lab_optional`. Explain in your own words why the plan changed from `Seq Scan` to `Index Scan`, and why `ORDER BY scheduled_at LIMIT 50` benefits twice.
28. Write a migration with a **partial index** `WHERE status = 'pending'` (use `DB::statement`). Show a query that uses it and compare its size to a full index: `SELECT pg_size_pretty(pg_relation_size('index_name'))`.
29. Using `EXPLAIN ANALYZE`, prove that `quotes.technician_id` has no index (PostgreSQL doesn't index foreign keys for you). Fix it and show the new plan.
30. **Export** all completed requests (id, title, budget, completed_at) to a CSV in an artisan command with `lazyById()`. Keep peak memory **under 20 MB**. Then try `get()` and report what happens.
31. **Close stale requests** (exercise 25) by setting them to `cancelled`: (a) `chunkById()` + `save()`, (b) a single `update()`. Compare queries and time. Which one fires model events and updates `updated_at`? When would you choose (a)?
32. A **safe search endpoint**: `?search=&sort=&dir=`. Use bindings for values and a whitelist for columns. Explain why `orderByRaw($request->sort)` is dangerous even though it's "only sorting".
33. "Technicians with at least one **1-star review in the last 90 days**": write it with `whereHas`, `whereExists` and a `join` + `distinct`. Compare with `EXPLAIN ANALYZE`. Which would you ship?

## Level 5: Redis / Memurai

Keep `memurai-cli monitor` open while you do these.

34. Cache the **category tree** (parents with children) with `rememberForever`. Clear it from a `CategoryObserver`. Why `rememberForever` + observer here instead of a TTL?
35. **Search results cache** for `GET /technicians?area=&category=&min_rating=&page=`: design the key (normalise and sort the filters, then hash), choose a TTL, and decide how to invalidate: tag flush or short TTL? Defend your choice.
36. **Profile views**: count with `Cache::increment` per technician per day, then a scheduled command every 5 minutes moves the counts into `technicians.profile_views`. Make sure no view is lost between reading and resetting the counter.
37. **"New job near you" notifications** exactly once per request per technician using `Cache::add`, so the queued job can safely retry.
38. An **accept-job API endpoint** with `Cache::lock` + a conditional `UPDATE … WHERE technician_id IS NULL`, returning 409 when taken. Write a feature test. (`php artisan lab:race` shows the problem.) Then answer: if the conditional UPDATE already guarantees correctness, what does the lock add?
39. **"Top 10 technicians this month" leaderboard**: `remember` or `flexible`? Pick TTLs and justify them.
40. **Cache stampede**: explain what happens when a `remember()` key expires while 200 requests arrive at once. Show two fixes (`flexible()`, and `Cache::lock` around the rebuild).
41. Add an `update(int $id, array $data)` method to `CachedTechnicianRepository`. Should it `forget` the cache or `put` the fresh profile (write-through)? What goes wrong with `put` if two updates race?

---

When you finish a level, tell me and I'll give you a harder follow-up set built on your weak spots.
