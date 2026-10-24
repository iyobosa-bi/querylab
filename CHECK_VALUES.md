# Check values (spoilers)

Only open this after writing your query. These are answers, not queries: send me your code and I'll critique it.

The seeder is deterministic (`LAB_SEED=2026`), so your numbers should match exactly. **First confirm your row counts** match. If they do but one answer differs, the difference is in your query (usually a NULL, a status, or a date boundary).

| Table | small | large |
|---|---:|---:|
| users | 341 | 5,501 |
| technicians | 40 | 500 |
| service_requests | 600 | 100,000 |
| quotes | 1,522 | 295,311 |
| reviews | 297 | 52,213 |
| payments | 502 | 88,601 |

| # | small | large |
|---|---|---|
| 1 | 4 | 36 |
| 2 | accepted 3, cancelled 100, completed 456, in_progress 8, pending 33 | accepted 669, cancelled 14,851, completed 80,407, in_progress 1,100, pending 2,973 |
| 3 | first row: request #593 (customer Grace Ogunleye) | first row: request #99016 (customer Blessing Adewale) |
| 4 | 127 | 1,013 |
| 5 | 6 | 71 |
| 6 | 5 (the 4 parents with subcategories + CCTV installation) | 5 |
| 7 | 61 | 12,612 |
| 8 | 17 | 266 |
| 9 | #1 is technician 26: 4.32 from 22 reviews | #1 is technician 311: 4.71 from 49 reviews |
| 10 | Dec 2025: ₦1,877,700.00 · 2025 total: ₦21,913,500.00 · 2025 refunded: ₦526,500.00 | Dec 2025: ₦564,644,100.00 · 2025 total: ₦4,377,246,700.00 · 2025 refunded: ₦146,134,700.00 |
| 11 | #1 Ikeja, 37 | #1 Ikeja, 6,281 |
| 12 | 10 | 2,461 |
| 13 | 10 | 107 |
| 14 | Appliance repair 173, Computers & phones 155, Plumbing 116, Electrical 55 | Appliance repair 27,055, Computers & phones 26,055, Plumbing 17,472, Electrical 11,906 |
| 15 | Inverter & solar, 159.9 h | Generator repair, 129.5 h |
| 16 | Surulere, 26.8% (min 10 requests) | Maryland, 15.7% (min 100 requests) |
| 17 | 65.1% | 64.9% |
| 18 | 575 | 99,109 |
| 19 | 6 | 71 |
| 20 | 15 | 19 |
| 21 | ₦51,625,100.00 | ₦9,170,064,900.00 |
| 22 | 2025-01, −60.0% | 2026-01, −36.6% |
| 23 | 242 | 42,352 |
| 24 | 24 | 561 |
| 25 | 23 | 1,834 |

Boundaries used: exercise 11 is `completed_at > today − 12 months AND completed_at <= today`; exercise 25 is `created_at < today − 60 days`. Exercise 10 groups by `paid_at`.
