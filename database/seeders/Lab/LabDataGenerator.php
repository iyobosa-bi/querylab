<?php

namespace Database\Seeders\Lab;

use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Builds the whole tech-aid dataset and hands rows to an insert callback in
 * chunks. It has no Laravel dependencies, so it is fast and deterministic:
 * the same seed and size always give exactly the same rows.
 *
 * Deliberate edge cases (the things your queries must handle):
 *  - ~20% of customers never post a request            (whereDoesntHave / LEFT JOIN)
 *  - unverified technicians never get jobs, so they have zero reviews (NULL averages)
 *  - a few technicians get most of the jobs             (top-N queries mean something)
 *  - pending requests have no technician (NULL)          (INNER vs LEFT JOIN totals)
 *  - some cancelled requests had a technician, some didn't
 *  - a few old requests are still "pending"              (stale-request clean-up)
 *  - failed payments have no paid_at, refunded ones are not revenue
 *  - the accepted quote is not always the cheapest one
 *  - some assigned jobs have no quote at all from the technician
 *  - "Generator repair" is inactive and only has requests before June 2025
 *  - "CCTV installation" has no requests at all; "Rumuola" has no requests, "Bodija" has no technicians
 *  - some users have no area; ~35% of reviews have no comment
 *  - requests grow over time and peak in December and in the hot season (Feb–Apr)
 */
final class LabDataGenerator
{
    public const SIZES = [
        'small' => ['customers' => 300, 'technicians' => 40, 'requests' => 600],
        'large' => ['customers' => 5000, 'technicians' => 500, 'requests' => 100000],
    ];

    private const CHUNK = 1000;

    private Randomizer $rng;

    private int $start;

    private int $anchor;

    /** @var array<string, list<array>> */
    private array $buffers = [];

    /** @var array<string, int> */
    private array $counts = [];

    private array $areas = [];

    private array $categories = [];

    private array $technicians = [];

    /** @var array<int, int> technician user id => created timestamp */
    private array $technicianCreated = [];

    /** @var array<int, int> customer id => created timestamp */
    private array $customerCreated = [];

    private int $quoteId = 0;

    private int $reviewId = 0;

    private int $paymentId = 0;

    /**
     * @param  Closure(string $table, list<array> $rows): void  $insert
     */
    public function __construct(
        private readonly Closure $insert,
        private readonly string $size,
        int $seed,
        string $anchorDate,
        private readonly string $passwordHash,
    ) {
        if (! isset(self::SIZES[$size])) {
            throw new \InvalidArgumentException("Unknown LAB_SIZE [{$size}]. Use small or large.");
        }

        $this->rng = new Randomizer(new Mt19937($seed));
        $this->start = (int) strtotime('2024-09-01 00:00:00 UTC');
        $this->anchor = (int) strtotime($anchorDate.' UTC');
    }

    /** @return array<string, int> rows inserted per table */
    public function run(): array
    {
        $size = self::SIZES[$this->size];

        $this->seedAreas();
        $this->seedCategories();
        $customerIds = $this->seedUsers($size['customers'], $size['technicians']);
        $this->seedTechnicians($size['customers'], $size['technicians']);
        $this->seedServiceRequests($size['requests'], $customerIds);
        $this->seedSettings();

        return $this->counts;
    }

    // ------------------------------------------------------------------
    //  Reference data
    // ------------------------------------------------------------------

    private function seedAreas(): void
    {
        // [name, state, request weight, technician weight]
        $areas = [
            ['Ikeja', 'Lagos', 14, 12], ['Lekki', 'Lagos', 13, 10], ['Yaba', 'Lagos', 10, 9],
            ['Surulere', 'Lagos', 8, 7], ['Victoria Island', 'Lagos', 6, 5], ['Ikoyi', 'Lagos', 4, 3],
            ['Ajah', 'Lagos', 7, 6], ['Gbagada', 'Lagos', 6, 5], ['Magodo', 'Lagos', 3, 2],
            ['Festac', 'Lagos', 4, 3], ['Ikorodu', 'Lagos', 5, 4], ['Maryland', 'Lagos', 3, 3],
            ['Wuse', 'FCT', 5, 5], ['Garki', 'FCT', 4, 4], ['Maitama', 'FCT', 3, 2],
            ['Gwarinpa', 'FCT', 4, 3], ['Kubwa', 'FCT', 2, 2],
            ['Port Harcourt GRA', 'Rivers', 3, 3],
            ['Rumuola', 'Rivers', 0, 1],   // technicians but no requests at all
            ['Bodija', 'Oyo', 2, 0],       // requests but no technicians live there
        ];

        $rows = [];
        foreach ($areas as $i => [$name, $state, $reqWeight, $techWeight]) {
            $id = $i + 1;
            $this->areas[$id] = ['req' => $reqWeight, 'tech' => $techWeight];
            $rows[] = [
                'id' => $id, 'name' => $name, 'state' => $state,
                'created_at' => $this->ts($this->start), 'updated_at' => $this->ts($this->start),
            ];
        }

        $this->bulk('areas', $rows);
    }

    private function seedCategories(): void
    {
        // [id, parent_id, name, is_active, request weight, budget min, budget max, job titles]
        $defs = [
            [1, null, 'Plumbing', true, 0, 0, 0, []],
            [2, 1, 'Leak repair', true, 9, 5000, 45000, ['Kitchen sink leaking', 'Leaking pipe under bathroom', 'Water dripping from ceiling', 'Toilet cistern leaking']],
            [3, 1, 'Pipe installation', true, 4, 20000, 250000, ['Install new water pipes', 'Replace rusted pipes', 'Connect borehole to overhead tank']],
            [4, 1, 'Water heater repair', true, 3, 8000, 60000, ['Water heater not heating', 'Install new water heater', 'Heater tripping the breaker']],
            [5, null, 'Electrical', true, 0, 0, 0, []],
            [6, 5, 'House wiring', true, 6, 15000, 400000, ['Rewire 3-bedroom flat', 'Sockets not working', 'Install new light fittings', 'Frequent power trips']],
            [7, 5, 'Inverter & solar', true, 5, 30000, 900000, ['Inverter not charging', 'Install 5kVA solar system', 'Replace inverter batteries', 'Solar panels not charging']],
            [8, null, 'Appliance repair', true, 0, 0, 0, []],
            [9, 8, 'AC repair', true, 14, 10000, 150000, ['AC not cooling', 'AC leaking water', 'Install split AC', 'AC gas refill', 'AC making noise']],
            [10, 8, 'Fridge repair', true, 7, 8000, 80000, ['Fridge not cooling', 'Freezer icing up', 'Fridge compressor noise']],
            [11, 8, 'Washing machine repair', true, 4, 8000, 70000, ['Washing machine not spinning', 'Washer leaking', 'Washer not draining']],
            [12, null, 'Computers & phones', true, 0, 0, 0, []],
            [13, 12, 'Laptop repair', true, 10, 8000, 200000, ['Laptop not powering on', 'Replace laptop screen', 'Laptop overheating', 'Upgrade laptop RAM and SSD']],
            [14, 12, 'Phone repair', true, 11, 5000, 120000, ['Cracked phone screen', 'Phone not charging', 'Replace phone battery', 'Phone water damage']],
            [15, 12, 'Networking & Wi-Fi', true, 3, 10000, 150000, ['Set up office Wi-Fi', 'Weak Wi-Fi signal', 'Run network cables']],
            [16, null, 'Cleaning', true, 6, 10000, 120000, ['Post-construction cleaning', 'Deep clean 2-bedroom flat', 'Office cleaning', 'Sofa and rug cleaning']],
            [17, null, 'Carpentry', true, 4, 10000, 300000, ['Fix broken wardrobe door', 'Build kitchen cabinets', 'Repair wooden bed frame']],
            [18, null, 'Painting', true, 4, 40000, 700000, ['Paint 3-bedroom flat', 'Repaint office', 'Exterior wall painting']],
            [19, null, 'Generator repair', false, 8, 8000, 150000, ['Generator not starting', 'Generator servicing', 'Generator overheating', 'Change generator carburetor']],
            [20, null, 'CCTV installation', true, 0, 50000, 500000, ['Install 4 CCTV cameras']],
        ];

        $rows = [];
        foreach ($defs as [$id, $parent, $name, $active, $weight, $min, $max, $titles]) {
            $this->categories[$id] = [
                'weight' => $weight, 'min' => $min, 'max' => $max, 'titles' => $titles,
                'leaf' => $titles !== [],
            ];
            $rows[] = [
                'id' => $id, 'parent_id' => $parent, 'name' => $name,
                'slug' => strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-')),
                'is_active' => $active,
                'created_at' => $this->ts($this->start), 'updated_at' => $this->ts($this->start),
            ];
        }

        $this->bulk('categories', $rows);
    }

    // ------------------------------------------------------------------
    //  Users and technicians
    // ------------------------------------------------------------------

    /** @return list<array{0:int,1:int}> [id, created_at] of customers who will post requests, oldest first */
    private function seedUsers(int $customers, int $technicians): array
    {
        $first = ['Adebayo', 'Chinedu', 'Ngozi', 'Tunde', 'Aisha', 'Emeka', 'Funke', 'Ibrahim', 'Kemi', 'Oluwaseun',
            'Chiamaka', 'Yusuf', 'Bola', 'Ifeanyi', 'Zainab', 'Segun', 'Amaka', 'Musa', 'Tolu', 'Obinna',
            'Halima', 'Femi', 'Nkechi', 'Sani', 'Yemi', 'Uche', 'Fatima', 'Kunle', 'Adaeze', 'Bello',
            'Iyobosa', 'Osaro', 'Efosa', 'Ese', 'Tobi', 'Damilola', 'Chidi', 'Hauwa', 'Gbenga', 'Ifeoma',
            'Abdul', 'Blessing', 'Samuel', 'Grace', 'David', 'Esther', 'Daniel', 'Joy', 'Michael', 'Peace'];
        $last = ['Okafor', 'Adeyemi', 'Bello', 'Eze', 'Ogunleye', 'Mohammed', 'Nwosu', 'Balogun', 'Okonkwo', 'Afolabi',
            'Usman', 'Obi', 'Adewale', 'Ibekwe', 'Lawal', 'Chukwu', 'Olawale', 'Abubakar', 'Nnamdi', 'Oyelaran',
            'Isibor', 'Osagie', 'Igbinedion', 'Ehigiator', 'Omoregie', 'Danjuma', 'Ajayi', 'Okeke', 'Salami', 'Ekwueme'];

        $areaPick = $this->weighted(array_map(fn ($a) => $a['req'] + 1, $this->areas));

        $rows = [];
        $total = 1 + $customers + $technicians;
        for ($id = 1; $id <= $total; $id++) {
            if ($id === 1) {
                $name = 'Lab Admin';
                $email = 'admin@techaid.test';
                $role = 'admin';
            } else {
                $f = $this->pick($first);
                $l = $this->pick($last);
                $name = "{$f} {$l}";
                $email = strtolower("{$f}.{$l}.{$id}@example.com");
                $role = $id <= 1 + $customers ? 'customer' : 'technician';
            }

            $created = $role === 'technician'
                ? $this->start - $this->int(60, 400) * 86400 + $this->int(0, 86399)
                : $this->start - 90 * 86400 + $this->int(0, (int) (($this->anchor - $this->start) * 0.9) + 90 * 86400);
            if ($role === 'technician') {
                $this->technicianCreated[$id] = $created;
            } elseif ($role === 'customer') {
                $this->customerCreated[$id] = $created;
            }
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'email_verified_at' => $this->chance(0.85) ? $this->ts($created + 600) : null,
                'password' => $this->passwordHash,
                'role' => $role,
                'phone' => $this->chance(0.9) ? '080'.str_pad((string) $id, 8, '0', STR_PAD_LEFT) : null,
                'area_id' => $this->chance(0.05) ? null : $areaPick(),
                'remember_token' => null,
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($created),
            ];
            $this->push('users', $rows);
        }
        $this->flush('users', $rows);

        // ~20% of customers never post anything.
        $active = [];
        for ($id = 2; $id <= 1 + $customers; $id++) {
            if ($this->chance(0.8)) {
                $active[] = [$id, $this->customerCreated[$id]];
            }
        }
        usort($active, fn ($a, $b) => $a[1] <=> $b[1] ?: $a[0] <=> $b[0]);

        return $active;
    }

    private function seedTechnicians(int $customers, int $count): void
    {
        $areaPick = $this->weighted(array_map(fn ($a) => $a['tech'], $this->areas));
        $leafIds = array_keys(array_filter($this->categories, fn ($c) => $c['leaf']));
        $bios = ['Certified and punctual. I bring my own tools.', 'Over a decade fixing homes across the city.',
            'Fast response, fair prices, clean work.', 'Trained by the manufacturer. Warranty on every job.',
            'Weekend and emergency call-outs available.'];

        $rows = [];
        $pivot = [];
        for ($i = 1; $i <= $count; $i++) {
            $userId = 1 + $customers + $i;
            $verified = $this->chance(0.85);
            $years = $this->int(0, 25);
            $created = $this->technicianCreated[$userId];
            $bad = $this->chance(0.08);

            $this->technicians[$i] = [
                'verified' => $verified,
                'area' => $areaPick(),
                // Popularity: a handful of technicians get most of the work.
                'popularity' => $verified ? 1 / ($this->int(10, 1000) / 10) : 0,
                'quality' => $bad ? 2.4 : 3.8 + $this->float() * 1.0,
                'categories' => [],
            ];

            $rows[] = [
                'id' => $i,
                'user_id' => $userId,
                'area_id' => $this->technicians[$i]['area'],
                'bio' => $this->chance(0.8) ? $this->pick($bios) : null,
                'hourly_rate' => $this->int(4, 30) * 500,
                'years_experience' => $years,
                'is_available' => $this->chance(0.7),
                'is_verified' => $verified,
                'verified_at' => $verified ? $this->ts($created + $this->int(1, 30) * 86400) : null,
                'profile_views' => $this->int(0, 4000),
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($created),
            ];

            // 1–3 skills; roughly every 6th technician does both plumbing and electrical work.
            $skills = [];
            if ($i % 6 === 0) {
                $skills = [$this->pick([2, 3, 4]), $this->pick([6, 7])];
            }
            $want = $this->int(1, 3);
            while (count($skills) < $want) {
                $skills[] = $this->pick($leafIds);
                $skills = array_values(array_unique($skills));
            }

            foreach ($skills as $categoryId) {
                $this->technicians[$i]['categories'][] = $categoryId;
                $pivot[] = [
                    'technician_id' => $i,
                    'category_id' => $categoryId,
                    'years_in_skill' => $this->int(1, max(1, $years)),
                    'created_at' => $this->ts($created),
                    'updated_at' => $this->ts($created),
                ];
            }
        }

        $this->bulk('technicians', $rows);
        $this->bulk('category_technician', $pivot);
    }

    // ------------------------------------------------------------------
    //  Requests, quotes, reviews, payments
    // ------------------------------------------------------------------

    private function seedServiceRequests(int $count, array $customers): void
    {

        $areaPick = $this->weighted(array_map(fn ($a) => $a['req'], $this->areas));
        $categoryWeights = array_map(fn ($c) => $c['weight'], $this->categories);
        $categoryPick = $this->weighted($categoryWeights);
        $noGeneratorPick = $this->weighted(array_replace($categoryWeights, [19 => 0]));
        $dayPick = $this->dayPicker();

        // Ids follow time: request #1 is the oldest, like a real table.
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $times[] = min($this->start + $dayPick() * 86400 + $this->int(7 * 3600, 21 * 3600), $this->anchor - 60);
        }
        sort($times);
        $eligible = 0; // customers[0..eligible-1] had signed up before the current request

        // Candidate technicians per category (and per category + area), weighted by popularity.
        $byCategory = [];
        $byCategoryArea = [];
        $allByCategory = [];
        foreach ($this->technicians as $techId => $t) {
            foreach ($t['categories'] as $c) {
                $allByCategory[$c][] = $techId;
                if ($t['verified']) {
                    $byCategory[$c][$techId] = $t['popularity'];
                    $byCategoryArea[$c][$t['area']][$techId] = $t['popularity'];
                }
            }
        }
        $pickers = [];
        $techFor = function (int $category, int $area) use ($byCategory, $byCategoryArea, &$pickers): ?int {
            if (isset($byCategoryArea[$category][$area]) && $this->chance(0.7)) {
                $key = "{$category}:{$area}";
                $pickers[$key] ??= $this->weighted($byCategoryArea[$category][$area]);

                return $pickers[$key]();
            }
            if (! isset($byCategory[$category])) {
                return null;
            }
            $pickers[$category] ??= $this->weighted($byCategory[$category]);

            return $pickers[$category]();
        };

        $fillers = ['Please come with the right tools.', 'I work from home so any time after 10am is fine.',
            'This has happened twice this month.', 'Kindly call before coming, the estate gate is strict.',
            'I need it fixed before the weekend.', 'Previous technician did not finish the job.',
            'Parking is available inside the compound.', 'I can send pictures on WhatsApp if needed.'];

        $generatorCutoff = (int) strtotime('2025-06-01 00:00:00 UTC');
        $rows = [];

        for ($id = 1; $id <= $count; $id++) {
            $created = $times[$id - 1];
            while ($eligible < count($customers) && $customers[$eligible][1] < $created) {
                $eligible++;
            }
            $category = $categoryPick();
            if ($category === 19 && $created >= $generatorCutoff) {
                $category = $noGeneratorPick();
            }
            $cat = $this->categories[$category];
            $area = $areaPick();
            // Skewed pick: long-time customers post far more jobs than new ones.
            $customerId = $customers[(int) floor(max(1, $eligible) * ($this->float() ** 1.6))][0];

            $status = $this->statusFor(($this->anchor - $created) / 86400);
            $budget = $this->int((int) ($cat['min'] / 500), (int) ($cat['max'] / 500)) * 500;
            if ($this->chance(0.002)) {
                $budget *= 10; // rare outliers
            }

            $scheduled = $this->chance(0.1) ? null : $created + $this->int(1, 10) * 86400 + $this->int(0, 8) * 3600 - ($created % 86400) + 9 * 3600;

            $technicianId = null;
            if (in_array($status, ['accepted', 'in_progress', 'completed'], true)
                || ($status === 'cancelled' && $this->chance(0.4))) {
                $technicianId = $techFor($category, $area);
                if ($technicianId === null) {
                    $status = 'pending';
                }
            }

            $accepted = $technicianId ? min($created + $this->int(600, 2 * 86400), $this->anchor) : null;
            $completed = null;
            if ($status === 'completed') {
                $completed = max($scheduled ?? $accepted + 86400, $accepted + 3600) + $this->int(1, 8) * 3600;
                if ($completed > $this->anchor) {
                    $status = 'in_progress';
                    $completed = null;
                }
            }
            $cancelled = $status === 'cancelled' ? $created + $this->int(3600, 5 * 86400) : null;
            $cancelled = $cancelled ? min($cancelled, $this->anchor) : null;
            if ($accepted && $cancelled && $accepted > $cancelled) {
                $accepted = $cancelled - 60;
            }
            $updated = max($created, $accepted ?? 0, $completed ?? 0, $cancelled ?? 0);

            $title = $this->pick($cat['titles']);
            $description = $title.'. '.implode(' ', array_map(fn () => $this->pick($fillers), range(1, $this->int(1, 6))));

            $rows[] = [
                'id' => $id,
                'customer_id' => $customerId,
                'technician_id' => $technicianId,
                'category_id' => $category,
                'area_id' => $area,
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'budget' => $budget,
                'scheduled_at' => $this->ts($scheduled),
                'accepted_at' => $this->ts($accepted),
                'completed_at' => $this->ts($completed),
                'cancelled_at' => $this->ts($cancelled),
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($updated),
            ];

            $finalQuote = $this->makeQuotes($id, $status, $technicianId, $budget, $created, $accepted, $allByCategory[$category] ?? []);

            if ($status === 'completed') {
                $this->makeReview($id, $customerId, $technicianId, $completed);
                $this->makePayments($id, $customerId, $finalQuote ?? $budget, $completed, false);
            } elseif ($status === 'cancelled' && $technicianId && $this->chance(0.3)) {
                // Customer paid a deposit, then cancelled: the deposit was refunded.
                $this->makePayments($id, $customerId, round($budget * 0.2, -2), $accepted, true);
            }

            if (count($rows) >= self::CHUNK) {
                $this->flushRequestChunk($rows);
            }
        }

        $this->flushRequestChunk($rows);
    }

    /** Requests must be inserted before the rows that reference them. */
    private function flushRequestChunk(array &$rows): void
    {
        $this->flush('service_requests', $rows);
        foreach (['quotes', 'reviews', 'payments'] as $table) {
            $pending = $this->buffers[$table] ?? [];
            $this->flush($table, $pending);
            $this->buffers[$table] = [];
        }
    }

    private function statusFor(float $ageDays): string
    {
        $table = match (true) {
            $ageDays < 3 => ['pending' => 60, 'accepted' => 25, 'in_progress' => 10, 'cancelled' => 5],
            $ageDays < 14 => ['pending' => 25, 'accepted' => 20, 'in_progress' => 25, 'completed' => 20, 'cancelled' => 10],
            $ageDays < 45 => ['pending' => 8, 'accepted' => 5, 'in_progress' => 12, 'completed' => 62, 'cancelled' => 13],
            default => ['pending' => 2, 'completed' => 83, 'cancelled' => 15],
        };

        $roll = $this->int(1, 100);
        foreach ($table as $status => $weight) {
            if (($roll -= $weight) <= 0) {
                return $status;
            }
        }

        return 'completed';
    }

    /** @return float|null the accepted quote amount, if any */
    private function makeQuotes(int $requestId, string $status, ?int $technicianId, int $budget, int $created, ?int $accepted, array $candidates): ?float
    {
        $n = $status === 'pending' ? ($this->chance(0.3) ? 0 : $this->int(1, 3)) : $this->int(1, 5);
        $n = min($n, count($candidates));

        $chosen = [];
        // 5% of assigned jobs were booked directly, without a quote from the technician.
        if ($technicianId && $this->chance(0.95)) {
            $chosen[] = $technicianId;
        }
        $tries = 0;
        while (count($chosen) < $n && $tries++ < 20) {
            $chosen[] = $this->pick($candidates);
            $chosen = array_values(array_unique($chosen));
        }

        $acceptedAmount = null;
        $latest = $accepted ?? min($created + 3 * 86400, $this->anchor);
        foreach ($chosen as $techId) {
            $amount = round($budget * (0.7 + $this->float() * 0.6), -2);
            $isWinner = $techId === $technicianId;
            $quoteStatus = match (true) {
                $isWinner && $status !== 'cancelled' => 'accepted',
                $status === 'pending' => 'pending',
                default => 'rejected',
            };
            if ($isWinner) {
                $acceptedAmount = $amount;
            }
            $at = min($created + $this->int(300, max(301, $latest - $created - 60)), $this->anchor);

            $this->buffers['quotes'][] = [
                'id' => ++$this->quoteId,
                'service_request_id' => $requestId,
                'technician_id' => $techId,
                'amount' => $amount,
                'message' => $this->chance(0.6) ? 'I can do this. Price includes transport.' : null,
                'status' => $quoteStatus,
                'created_at' => $this->ts($at),
                'updated_at' => $this->ts($at),
            ];
        }

        return $acceptedAmount;
    }

    private function makeReview(int $requestId, int $customerId, int $technicianId, int $completed): void
    {
        if (! $this->chance(0.65)) {
            return;
        }
        $at = $completed + $this->int(3600, 7 * 86400);
        if ($at > $this->anchor) {
            return;
        }

        $quality = $this->technicians[$technicianId]['quality'];
        $rating = (int) max(1, min(5, round($quality + $this->normal() * 0.8)));
        $comments = [
            1 => ['Did not show up on time and the problem came back.', 'Very poor work. Would not recommend.'],
            2 => ['Fixed it but left a mess.', 'Overcharged for a small job.'],
            3 => ['Okay job, a bit slow.', 'Work was fine, communication could be better.'],
            4 => ['Good job, arrived on time.', 'Solid work, fair price.'],
            5 => ['Excellent! Very professional.', 'Fixed it in minutes. Highly recommended!', 'Best technician I have used.'],
        ];

        $this->buffers['reviews'][] = [
            'id' => ++$this->reviewId,
            'service_request_id' => $requestId,
            'customer_id' => $customerId,
            'technician_id' => $technicianId,
            'rating' => $rating,
            'comment' => $this->chance(0.65) ? $this->pick($comments[$rating]) : null,
            'created_at' => $this->ts($at),
            'updated_at' => $this->ts($at),
        ];
    }

    private function makePayments(int $requestId, int $customerId, float $amount, int $when, bool $refunded): void
    {
        $method = $this->pick(['transfer', 'transfer', 'transfer', 'card', 'card', 'wallet', 'cash']);

        $paidAt = min($when + $this->int(600, 2 * 86400), $this->anchor);

        // 8% of customers had a failed attempt first (no paid_at on failed rows).
        if (! $refunded && $this->chance(0.08)) {
            $failedAt = $paidAt - $this->int(60, 3600);
            $this->buffers['payments'][] = $this->paymentRow($requestId, $customerId, $amount, 'card', 'failed', null, $failedAt);
        }

        $status = $refunded || $this->chance(0.03) ? 'refunded' : 'success';
        $this->buffers['payments'][] = $this->paymentRow($requestId, $customerId, $amount, $method, $status, $paidAt, $paidAt);
    }

    private function paymentRow(int $requestId, int $customerId, float $amount, string $method, string $status, ?int $paidAt, int $created): array
    {
        $id = ++$this->paymentId;

        return [
            'id' => $id,
            'service_request_id' => $requestId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'method' => $method,
            'status' => $status,
            'reference' => 'TXN-'.str_pad((string) $id, 10, '0', STR_PAD_LEFT),
            'paid_at' => $this->ts($paidAt),
            'created_at' => $this->ts($created),
            'updated_at' => $this->ts($paidAt ?? $created),
        ];
    }

    private function seedSettings(): void
    {
        $settings = [
            'platform_fee_percent' => '10', 'min_budget' => '5000', 'max_quotes_per_request' => '5',
            'support_email' => 'support@techaid.test', 'support_phone' => '08000000000',
            'otp_ttl_minutes' => '5', 'maintenance_mode' => 'false', 'currency' => 'NGN',
            'featured_category_ids' => '9,13,14', 'referral_bonus' => '1000',
        ];

        $rows = [];
        $id = 0;
        foreach ($settings as $key => $value) {
            $rows[] = ['id' => ++$id, 'key' => $key, 'value' => $value,
                'created_at' => $this->ts($this->start), 'updated_at' => $this->ts($this->start)];
        }

        $this->bulk('settings', $rows);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Day offsets weighted for growth over time plus seasonality. */
    private function dayPicker(): Closure
    {
        $days = intdiv($this->anchor - $this->start, 86400) + 1;
        $season = [1 => 0.9, 2 => 1.2, 3 => 1.35, 4 => 1.25, 5 => 1.0, 6 => 0.85, 7 => 0.8, 8 => 0.85,
            9 => 0.95, 10 => 1.0, 11 => 1.1, 12 => 1.45];
        $weights = [];
        for ($d = 0; $d < $days; $d++) {
            $month = (int) gmdate('n', $this->start + $d * 86400);
            $weights[$d] = (1 + 1.2 * $d / $days) * $season[$month];
        }

        return $this->weighted($weights);
    }

    /**
     * Returns a closure that picks a key from $weights proportionally to its weight.
     *
     * @param  array<int, float|int>  $weights
     */
    private function weighted(array $weights): Closure
    {
        $keys = [];
        $cumulative = [];
        $total = 0.0;
        foreach ($weights as $key => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $total += $weight;
            $keys[] = $key;
            $cumulative[] = $total;
        }

        return function () use ($keys, $cumulative, $total) {
            $r = $this->float() * $total;
            $lo = 0;
            $hi = count($cumulative) - 1;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($cumulative[$mid] < $r) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            return $keys[$lo];
        };
    }

    private function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    private function float(): float
    {
        return $this->rng->getInt(0, 999_999_999) / 1_000_000_000;
    }

    private function chance(float $p): bool
    {
        return $this->float() < $p;
    }

    private function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    private function normal(): float
    {
        $u1 = max($this->float(), 1e-9);
        $u2 = $this->float();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    private function ts(?int $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function push(string $table, array &$rows): void
    {
        if (count($rows) >= self::CHUNK) {
            $this->flush($table, $rows);
        }
    }

    private function flush(string $table, array &$rows): void
    {
        if ($rows === []) {
            return;
        }
        ($this->insert)($table, $rows);
        $this->counts[$table] = ($this->counts[$table] ?? 0) + count($rows);
        $rows = [];
    }

    private function bulk(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->flush($table, $chunk);
        }
    }
}
