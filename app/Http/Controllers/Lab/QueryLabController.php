<?php

namespace App\Http\Controllers\Lab;

use App\Enums\ServiceRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Support\Lab\QueryMeter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use ReflectionFunction;

/**
 * Each lab answers ONE question in several ways ("variants"), so you can open
 * them one after another and compare the numbers in Debugbar.
 *
 * A variant returns ['rows' => iterable, 'explain' => a query builder (optional), 'note' => string (optional)].
 * Everything inside the variant closure is measured; EXPLAIN runs afterwards so it doesn't count.
 */
class QueryLabController extends Controller
{
    public function __construct(private readonly QueryMeter $meter) {}

    public function index(): View
    {
        return view('lab.index', ['labs' => $this->labs()]);
    }

    public function show(Request $request, string $slug): View
    {
        $labs = $this->labs();
        abort_unless(isset($labs[$slug]), 404);

        $lab = $labs[$slug];
        $variantKey = (string) $request->query('v', array_key_first($lab['variants']));
        abort_unless(isset($lab['variants'][$variantKey]), 404);
        $variant = $lab['variants'][$variantKey];

        // "prepare" runs setup work that shouldn't be measured (e.g. finding a cursor position).
        $args = isset($variant['prepare']) ? ($variant['prepare'])() : [];
        $result = $this->meter->measure(fn () => ($variant['run'])(...$args));
        $output = $result['value'];

        return view('lab.show', [
            'slug' => $slug,
            'lab' => $lab,
            'variantKey' => $variantKey,
            'variant' => $variant,
            'result' => $result,
            'rows' => $this->toRows($output['rows'] ?? []),
            // A note may be a closure so its own queries (e.g. checking pg_indexes) aren't measured.
            'note' => ($output['note'] ?? null) instanceof Closure ? $output['note']() : ($output['note'] ?? null),
            'plan' => isset($output['explain']) ? $this->explain($output['explain']) : null,
            'code' => $this->sourceOf($variant['run']),
        ]);
    }

    /**
     * @return array<string, array{title:string, question:string, look:string, variants:array<string, array{label:string, run:Closure}>}>
     */
    private function labs(): array
    {
        $today = CarbonImmutable::parse(config('lab.anchor_date'));

        return [

            'n-plus-one' => [
                'title' => '1. N+1 queries',
                'question' => 'Show the latest 100 completed jobs with the customer, technician and category names.',
                'look' => 'Queries tab: count the queries. The lazy version runs the same SELECT hundreds of times with different ids. Also compare "Models".',
                'variants' => [
                    'lazy' => ['label' => 'Lazy loading (N+1)', 'run' => function () {
                        $requests = ServiceRequest::where('status', ServiceRequestStatus::Completed)
                            ->latest('id')->limit(100)->get();

                        // Each ->relation below runs its own query, for every row.
                        return ['rows' => $requests->map(fn ($r) => [
                            'id' => $r->id,
                            'title' => $r->title,
                            'customer' => $r->customer->name,
                            'technician' => $r->technician->user->name,
                            'category' => $r->category->name,
                        ])];
                    }],
                    'eager' => ['label' => 'Eager loading with()', 'run' => function () {
                        $requests = ServiceRequest::where('status', ServiceRequestStatus::Completed)
                            ->with(['customer:id,name', 'technician:id,user_id', 'technician.user:id,name', 'category:id,name'])
                            ->latest('id')->limit(100)->get();

                        return ['rows' => $requests->map(fn ($r) => [
                            'id' => $r->id,
                            'title' => $r->title,
                            'customer' => $r->customer->name,
                            'technician' => $r->technician->user->name,
                            'category' => $r->category->name,
                        ])];
                    }],
                    'join' => ['label' => 'One query with joins', 'run' => function () {
                        return ['rows' => DB::table('service_requests as sr')
                            ->join('users as c', 'c.id', '=', 'sr.customer_id')
                            ->join('technicians as t', 't.id', '=', 'sr.technician_id')
                            ->join('users as tu', 'tu.id', '=', 't.user_id')
                            ->join('categories as cat', 'cat.id', '=', 'sr.category_id')
                            ->where('sr.status', 'completed')
                            ->orderByDesc('sr.id')
                            ->limit(100)
                            ->get(['sr.id', 'sr.title', 'c.name as customer', 'tu.name as technician', 'cat.name as category'])];
                    }],
                ],
            ],

            'aggregates' => [
                'title' => '2. Aggregates in PHP vs SQL',
                'question' => 'Average rating and number of reviews for every technician, best first.',
                'look' => 'Models and Memory: the PHP version loads every review row as a model just to average them. Also notice NULLS LAST: technicians with no reviews have a NULL average.',
                'variants' => [
                    'php' => ['label' => 'with(\'reviews\') then average in PHP', 'run' => function () {
                        $technicians = Technician::with('reviews')->get();

                        return ['rows' => $technicians->map(fn ($t) => [
                            'id' => $t->id,
                            'reviews' => $t->reviews->count(),
                            'avg_rating' => $t->reviews->isEmpty() ? null : round($t->reviews->avg('rating'), 2),
                        ])->sortByDesc('avg_rating')->values()];
                    }],
                    'sql' => ['label' => 'withCount() + withAvg()', 'run' => function () {
                        $technicians = Technician::query()
                            ->select('id')
                            ->withCount('reviews')
                            ->withAvg('reviews', 'rating')
                            ->orderByRaw('reviews_avg_rating DESC NULLS LAST')
                            ->get();

                        return ['rows' => $technicians->map(fn ($t) => [
                            'id' => $t->id,
                            'reviews' => $t->reviews_count,
                            'avg_rating' => $t->reviews_avg_rating === null ? null : round((float) $t->reviews_avg_rating, 2),
                        ])];
                    }],
                ],
            ],

            'select-columns' => [
                'title' => '3. SELECT * vs only the columns you need',
                'question' => 'Load the 20,000 newest requests for a table showing id, title, status, budget and date.',
                'look' => 'Memory and SQL time. `description` is a long text column you never display.',
                'variants' => [
                    'all' => ['label' => 'get() — every column', 'run' => function () {
                        return ['rows' => ServiceRequest::latest('id')->limit(20000)->get()];
                    }],
                    'select' => ['label' => 'select() only needed columns', 'run' => function () {
                        return ['rows' => ServiceRequest::select(['id', 'title', 'status', 'budget', 'created_at'])
                            ->latest('id')->limit(20000)->get()];
                    }],
                    'tobase' => ['label' => 'select() + toBase() (no models)', 'run' => function () {
                        return ['rows' => ServiceRequest::select(['id', 'title', 'status', 'budget', 'created_at'])
                            ->latest('id')->limit(20000)->toBase()->get()];
                    }],
                ],
            ],

            'revenue-report' => [
                'title' => '4. Grouping in PHP vs selectRaw + GROUP BY',
                'question' => 'Monthly revenue for 2025: successful payments, refunds and failed attempts per month.',
                'look' => 'Models, Memory and SQL time. The FILTER version computes all three numbers in one pass, with bound parameters.',
                'variants' => [
                    'php' => ['label' => 'Load all payments, group in PHP', 'run' => function () {
                        $payments = Payment::whereBetween('created_at', ['2025-01-01', '2025-12-31 23:59:59'])->get();

                        return ['rows' => $payments
                            ->groupBy(fn ($p) => $p->created_at->format('Y-m'))
                            ->map(fn ($month, $key) => [
                                'month' => $key,
                                'revenue' => $month->where('status', \App\Enums\PaymentStatus::Success)->sum('amount'),
                                'refunded' => $month->where('status', \App\Enums\PaymentStatus::Refunded)->sum('amount'),
                                'failed_attempts' => $month->where('status', \App\Enums\PaymentStatus::Failed)->count(),
                            ])->sortKeys()->values()];
                    }],
                    'filter' => ['label' => 'selectRaw with FILTER (PostgreSQL)', 'run' => function () {
                        $query = DB::table('payments')
                            ->selectRaw("to_char(date_trunc('month', created_at), 'YYYY-MM') AS month")
                            ->selectRaw('SUM(amount) FILTER (WHERE status = ?) AS revenue', ['success'])
                            ->selectRaw('SUM(amount) FILTER (WHERE status = ?) AS refunded', ['refunded'])
                            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS failed_attempts', ['failed'])
                            ->where('created_at', '>=', '2025-01-01')
                            ->where('created_at', '<', '2026-01-01')
                            ->groupByRaw('1')
                            ->orderByRaw('1');

                        return ['rows' => $query->get(), 'explain' => $query];
                    }],
                ],
            ],

            'date-filters' => [
                'title' => '5. whereDate() vs a date range',
                'question' => 'How many requests were created on 24 December 2025?',
                'look' => 'The EXPLAIN plan below the results. whereDate() wraps the column in a cast, so the index on created_at can\'t be used and PostgreSQL scans the whole table.',
                'variants' => [
                    'wheredate' => ['label' => 'whereDate()', 'run' => function () {
                        $query = ServiceRequest::whereDate('created_at', '2025-12-24');

                        return ['rows' => [['requests' => $query->count()]], 'explain' => $query->select('id')];
                    }],
                    'range' => ['label' => 'Range: >= start AND < next day', 'run' => function () {
                        $query = ServiceRequest::where('created_at', '>=', '2025-12-24')
                            ->where('created_at', '<', '2025-12-25');

                        return ['rows' => [['requests' => $query->count()]], 'explain' => $query->select('id')];
                    }],
                ],
            ],

            'missing-index' => [
                'title' => '6. Missing indexes',
                'question' => 'Jobs scheduled in the 7 days after "today", and all quotes one busy technician sent.',
                'look' => 'The plan says "Seq Scan" (reads every row). Run `php artisan migrate --path=database/migrations/lab_optional`, reload, and it becomes an Index/Bitmap scan. Roll back to compare again.',
                'variants' => [
                    'scheduled' => ['label' => 'Upcoming scheduled jobs', 'run' => function () use ($today) {
                        $query = ServiceRequest::select(['id', 'title', 'status', 'scheduled_at'])
                            ->whereBetween('scheduled_at', [$today, $today->addDays(7)])
                            ->orderBy('scheduled_at')
                            ->limit(50);

                        return ['rows' => $query->get(), 'explain' => $query, 'note' => fn () => $this->indexNote('service_requests', 'scheduled_at')];
                    }],
                    'quotes' => ['label' => 'Quotes by one technician', 'run' => function () {
                        $technicianId = 1;
                        $query = Quote::select(['id', 'service_request_id', 'amount', 'status'])
                            ->where('technician_id', $technicianId)
                            ->latest('id')
                            ->limit(50);

                        return ['rows' => $query->get(), 'explain' => $query, 'note' => fn () => $this->indexNote('quotes', 'technician_id')];
                    }],
                ],
            ],

            'exists-vs-join' => [
                'title' => '7. whereHas vs join vs whereIn',
                'question' => 'How many customers have had at least one completed AC repair?',
                'look' => 'Compare the plans and SQL time. In PostgreSQL all three usually become a similar semi-join, so measure before believing "whereHas is slow" (that advice mostly comes from older MySQL versions).',
                'variants' => [
                    'wherehas' => ['label' => 'whereHas()', 'run' => function () {
                        $query = User::whereHas('serviceRequests', fn ($q) => $q->where('status', 'completed')->where('category_id', 9));

                        return ['rows' => [['customers' => $query->count()]], 'explain' => $query->select('users.id')];
                    }],
                    'join' => ['label' => 'join + distinct', 'run' => function () {
                        $query = User::join('service_requests as sr', 'sr.customer_id', '=', 'users.id')
                            ->where('sr.status', 'completed')
                            ->where('sr.category_id', 9);

                        return ['rows' => [['customers' => $query->distinct()->count('users.id')]], 'explain' => $query->select('users.id')->distinct()];
                    }],
                    'wherein' => ['label' => 'whereIn(subquery)', 'run' => function () {
                        $query = User::whereIn('id', ServiceRequest::select('customer_id')
                            ->where('status', 'completed')
                            ->where('category_id', 9));

                        return ['rows' => [['customers' => $query->count()]], 'explain' => $query->select('id')];
                    }],
                ],
            ],

            'deep-pagination' => [
                'title' => '8. OFFSET pagination vs cursor pagination',
                'question' => 'Show a page 90% of the way through all requests (newest first).',
                'look' => 'paginate() runs a COUNT(*) plus an OFFSET query that must walk past every earlier row. cursorPaginate() jumps straight there with WHERE id < ?.',
                'variants' => [
                    'paginate' => ['label' => 'paginate() — count + offset', 'prepare' => fn () => [$this->deepPage()], 'run' => function (int $page) {
                        $results = ServiceRequest::select(['id', 'title', 'status', 'created_at'])
                            ->orderByDesc('id')
                            ->paginate(15, ['*'], 'page', $page);

                        return ['rows' => $results->items(), 'note' => "Page {$page} of {$results->lastPage()}"];
                    }],
                    'simple' => ['label' => 'simplePaginate() — offset, no count', 'prepare' => fn () => [$this->deepPage()], 'run' => function (int $page) {
                        $results = ServiceRequest::select(['id', 'title', 'status', 'created_at'])
                            ->orderByDesc('id')
                            ->simplePaginate(15, ['*'], 'page', $page);

                        return ['rows' => $results->items(), 'note' => "Page {$page}"];
                    }],
                    'cursor' => ['label' => 'cursorPaginate() — keyset', 'prepare' => fn () => [$this->deepPageBoundaryId()], 'run' => function (int $lastSeenId) {
                        // In a real app the cursor comes from the previous page's "next" link.
                        // Here we build one pointing at the same position as the other variants.
                        $results = ServiceRequest::select(['id', 'title', 'status', 'created_at'])
                            ->orderByDesc('id')
                            ->cursorPaginate(15, ['*'], 'cursor', new Cursor(['id' => $lastSeenId]));

                        return ['rows' => $results->items(), 'note' => "Rows after id {$lastSeenId}"];
                    }],
                ],
            ],

            'top-per-group' => [
                'title' => '9. Top row per group',
                'question' => 'The cheapest quote for each of the 500 newest requests.',
                'look' => 'Query count for the loop; SQL time for the rest. DISTINCT ON is PostgreSQL-only and very fast; ROW_NUMBER() works in every modern database. Notice the loop and ofMany versions list requests with no quotes (NULL), while the SQL versions leave them out, like INNER vs LEFT JOIN.',
                'variants' => [
                    'loop' => ['label' => 'Loop: one query per request', 'run' => function () {
                        $requests = ServiceRequest::select('id')->latest('id')->limit(500)->get();

                        return ['rows' => $requests->map(function ($r) {
                            $quote = $r->quotes()->orderBy('amount')->orderBy('id')->first();

                            return ['service_request_id' => $r->id, 'quote_id' => $quote?->id, 'amount' => $quote?->amount];
                        })];
                    }],
                    'ofmany' => ['label' => 'with(\'lowestQuote\') — ofMany', 'run' => function () {
                        $requests = ServiceRequest::select('id')->with('lowestQuote')->latest('id')->limit(500)->get();

                        return ['rows' => $requests->map(fn ($r) => [
                            'service_request_id' => $r->id,
                            'quote_id' => $r->lowestQuote?->id,
                            'amount' => $r->lowestQuote?->amount,
                        ])];
                    }],
                    'distinct-on' => ['label' => 'DISTINCT ON (PostgreSQL)', 'run' => function () {
                        $newest = DB::table('service_requests')->select('id')->orderByDesc('id')->limit(500);

                        $query = DB::table('quotes')
                            ->selectRaw('DISTINCT ON (service_request_id) service_request_id, id AS quote_id, amount')
                            ->whereIn('service_request_id', $newest)
                            ->orderBy('service_request_id')
                            ->orderBy('amount')
                            ->orderBy('id');

                        return ['rows' => $query->get(), 'explain' => $query];
                    }],
                    'window' => ['label' => 'ROW_NUMBER() window function', 'run' => function () {
                        $newest = DB::table('service_requests')->select('id')->orderByDesc('id')->limit(500);

                        $ranked = DB::table('quotes')
                            ->select(['service_request_id', 'id as quote_id', 'amount'])
                            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY service_request_id ORDER BY amount, id) AS rn')
                            ->whereIn('service_request_id', $newest);

                        $query = DB::query()->fromSub($ranked, 'ranked')->where('rn', 1)->orderBy('service_request_id');

                        return ['rows' => $query->get(), 'explain' => $query];
                    }],
                ],
            ],

            'bulk-update' => [
                'title' => '10. Updating in a loop vs one UPDATE',
                'question' => 'Give a 10% discount on 1,000 pending quotes. (Runs inside a transaction that is rolled back, so data never changes.)',
                'look' => 'Queries: the loop runs one UPDATE per row. The single UPDATE does the maths inside PostgreSQL. Note that the loop fires model events and the single UPDATE does not.',
                'variants' => [
                    'loop' => ['label' => 'Loop with save()', 'run' => function () {
                        return $this->rolledBack(function () {
                            $quotes = Quote::where('status', 'pending')->orderBy('id')->limit(1000)->get();
                            foreach ($quotes as $quote) {
                                $quote->amount = round($quote->amount * 0.9, 2);
                                $quote->save();
                            }

                            return ['rows' => $quotes->take(15)->map->only(['id', 'amount'])];
                        });
                    }],
                    'single' => ['label' => 'One UPDATE with DB::raw()', 'run' => function () {
                        return $this->rolledBack(function () {
                            $ids = Quote::where('status', 'pending')->orderBy('id')->limit(1000)->pluck('id');
                            $updated = Quote::whereIn('id', $ids)->update(['amount' => DB::raw('ROUND(amount * 0.9, 2)')]);

                            return [
                                'rows' => Quote::whereIn('id', $ids->take(15))->orderBy('id')->get(['id', 'amount']),
                                'note' => "{$updated} rows updated in one statement (then rolled back).",
                            ];
                        });
                    }],
                ],
            ],

            'hydration' => [
                'title' => '11. Eloquent vs Query Builder vs raw SQL',
                'question' => 'Fetch 30,000 rows (same 5 columns) three ways.',
                'look' => 'Wall time and Memory. SQL time is almost the same; the difference is PHP building 30,000 model objects.',
                'variants' => [
                    'eloquent' => ['label' => 'Eloquent get()', 'run' => function () {
                        return ['rows' => ServiceRequest::select(['id', 'title', 'status', 'budget', 'created_at'])
                            ->orderBy('id')->limit(30000)->get()];
                    }],
                    'builder' => ['label' => 'DB::table()', 'run' => function () {
                        return ['rows' => DB::table('service_requests')
                            ->select(['id', 'title', 'status', 'budget', 'created_at'])
                            ->orderBy('id')->limit(30000)->get()];
                    }],
                    'raw' => ['label' => 'DB::select() raw SQL', 'run' => function () {
                        return ['rows' => DB::select(
                            'SELECT id, title, status, budget, created_at FROM service_requests ORDER BY id LIMIT ?',
                            [30000],
                        )];
                    }],
                ],
            ],

            'raw-safety' => [
                'title' => '12. Raw expressions safely',
                'question' => 'Search titles and sort by a column the user picks: /lab/raw-safety?search=AC&sort=budget&dir=desc',
                'look' => 'The "SQL sent" panel: values travel as bindings (?), never inside the SQL string. Column names can\'t be bound, so they are checked against a whitelist.',
                'variants' => [
                    'safe' => ['label' => 'Bindings + whitelist', 'run' => function () {
                        $request = request();
                        $search = (string) $request->query('search', 'AC');

                        // Identifiers (column names, directions) can't be bound: whitelist them.
                        $sortable = ['budget', 'created_at', 'title'];
                        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'created_at';
                        $direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';

                        $query = ServiceRequest::query()
                            ->select(['id', 'title', 'budget', 'created_at'])
                            ->selectRaw('ROUND(budget / ?, 2) AS budget_usd', [1500])
                            // ILIKE is PostgreSQL's case-insensitive LIKE. Escape % and _ typed by the user.
                            ->whereRaw('title ILIKE ?', ['%'.addcslashes($search, '%_\\').'%'])
                            ->orderBy($sort, $direction)
                            ->limit(20);

                        return ['rows' => $query->get(), 'note' => 'SQL sent: '.$query->toSql()."\nBindings: ".json_encode($query->getBindings())];
                    }],
                    'unsafe' => ['label' => 'String interpolation (NOT executed)', 'run' => function () {
                        $search = (string) request()->query('search', "AC' OR 1=1 --");
                        // Never do this. We only build the string to show what would reach the database.
                        $sql = "SELECT id, title FROM service_requests WHERE title ILIKE '%{$search}%'";

                        return ['rows' => [], 'note' => "This is the SQL an attacker controls:\n{$sql}\n\nTry ?search=' OR 1=1 --"];
                    }],
                ],
            ],

        ];
    }

    /** Page number 90% of the way through the table (so the offset is large). */
    private function deepPage(): int
    {
        return max(1, (int) floor(ServiceRequest::max('id') / 15 * 0.9));
    }

    /** The last id shown on the page before the deep page (found outside the measurement). */
    private function deepPageBoundaryId(): int
    {
        $offset = ($this->deepPage() - 1) * 15;

        return (int) (ServiceRequest::orderByDesc('id')->offset(max(0, $offset - 1))->value('id') ?? 0);
    }

    private function rolledBack(Closure $work): array
    {
        DB::beginTransaction();

        try {
            return $work();
        } finally {
            DB::rollBack();
        }
    }

    private function indexNote(string $table, string $column): string
    {
        $exists = DB::table('pg_indexes')
            ->where('tablename', $table)
            ->where('indexdef', 'like', "%({$column})%")
            ->exists();

        return $exists
            ? "An index on {$table}.{$column} EXISTS. Roll back the lab_optional migration to compare."
            : "There is NO index on {$table}.{$column}. Run the lab_optional migration and reload.";
    }

    /** EXPLAIN (ANALYZE, BUFFERS) runs the query for real and reports what PostgreSQL actually did. */
    private function explain(BuilderContract $query): string
    {
        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$query->toSql(), $query->getBindings());

        return collect($plan)->map(fn ($row) => $row->{'QUERY PLAN'})->implode("\n");
    }

    /** @return list<array<string, mixed>> the first 15 rows, as plain arrays */
    private function toRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= 15) {
                break;
            }
            $row = $row instanceof \Illuminate\Database\Eloquent\Model ? $row->attributesToArray() : (array) $row;
            $out[] = array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $row);
        }

        return $out;
    }

    /** Shows the variant's own source code on the page, so what you read is what ran. */
    private function sourceOf(Closure $closure): string
    {
        $reflection = new ReflectionFunction($closure);
        $lines = array_slice(
            file($reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        );

        // Indentation of the closure body (ignoring blank lines and the closing brace line).
        $indent = min(array_map(
            fn ($line) => strlen($line) - strlen(ltrim($line)),
            array_filter(array_slice($lines, 1, -1), fn ($line) => trim($line) !== ''),
        ) ?: [0]);

        $first = preg_replace("/^.*?'run' => /", '', trim($lines[0]));
        $body = array_map(
            fn ($line) => trim($line) === '' ? "\n" : '    '.substr($line, min($indent, strlen($line) - strlen(ltrim($line)))),
            array_slice($lines, 1),
        );
        $body[count($body) - 1] = ltrim($body[count($body) - 1]);
        $body[count($body) - 1] = preg_replace('/\}\],\s*$/', '}', $body[count($body) - 1]);

        return $first."\n".implode('', $body);
    }
}
