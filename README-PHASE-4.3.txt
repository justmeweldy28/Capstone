TRACEGRAD PHASE 4.3 — DEMOGRAPHICS ANALYTICS

WHAT THIS UPDATE DOES
- Rebuilds Demographics Analytics from the current Graduate Tracer Survey Version 1 only.
- Uses the Phase 4.2 canonical filter engine for both Super Admin and Department Admin.
- Department Admin scope remains forced server-side.
- Implements the six Phase 4.1 approved demographic metrics:
  * sex_distribution — Q6
  * civil_status_distribution — Q5
  * age_band_distribution — Q7 birthday + survey submission timestamp
  * region_origin_distribution — Q8
  * province_distribution — Q9
  * residence_type_distribution — Q10
- Calculates age at the date of submission, never at the current date.
- Does not expose raw birthdays.
- Adds matching CSV export with the same Filter ID.
- Removes the old visible Alumni Analytics prototype path and old Department Program Analytics presentation while their later Phase 4 replacements are still pending.
- Does NOT use legacy exact salary, employment geography, graduate-profile demographics, or composite scores.

DATABASE MIGRATION
None.

INSTALL
Copy the included TRACEGRADSS folder over the existing installation and replace files.

VERIFY
Open:
http://localhost/TRACEGRADSS/tools/verify-phase-4.3-demographics-analytics.php

Expected:
PASS — Version 1 Demographics Analytics is ready.

MANUAL TEST
1. Super Admin → Analytics → Demographics Analytics.
2. Apply Department, Program, Batch, Version, Campaign, Employment, and Date filters.
3. Confirm all charts identify Q5/Q6/Q7/Q8/Q9/Q10 only.
4. Export CSV and confirm the Filter ID matches the page.
5. Department Admin → Demographics Analytics. Confirm Department is fixed.
6. Try adding another college_id in the URL; results must remain locked to the Department Admin college.
7. Confirm age appears only in bands and is described as age at survey submission.
