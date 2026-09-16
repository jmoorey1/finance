/*
 * Backfill source-reviewed historical Payroll Equity / RSU reporting scopes.
 *
 * These classifications were reviewed manually against the Payroll records
 * and saved through the Payroll UI.
 *
 * This migration deliberately targets exact payslip/line ID pairs.
 *
 * It does NOT infer Equity from:
 *   - description text
 *   - category
 *   - Notional status
 *   - payment timing
 *   - broker/share-account activity
 *
 * Notional and reporting_scope remain independent concepts.
 *
 * The complete reviewed set represented here is:
 *   67 lines across 11 India Moorey RSU payslips.
 *
 * Payslip #194 was already classified by
 * 20260826_124500_add_payroll_rsu_reporting_semantics.sql.
 * It is included again here so this migration documents the complete
 * reviewed historical Equity set in one place; setting it to Equity again
 * is intentionally idempotent.
 */

UPDATE payroll_line_items

SET reporting_scope = 'equity'

WHERE reporting_scope <> 'equity'
  AND (
        /*
         * #166 · 2022-05-31
         */
        (
            payslip_id = 166
            AND id IN (
                1551,
                1552,
                1553,
                1554,
                1555,
                1904,
                2297,
                2298
            )
        )

        OR

        /*
         * #173 · 2022-11-30
         */
        (
            payslip_id = 173
            AND id IN (
                1591,
                1592,
                1593,
                1594,
                1595,
                1905,
                2299,
                2300
            )
        )

        OR

        /*
         * #180 · 2023-05-31
         */
        (
            payslip_id = 180
            AND id IN (
                1647,
                1648,
                1649,
                1650,
                1906,
                2296
            )
        )

        OR

        /*
         * #187 · 2023-11-30
         */
        (
            payslip_id = 187
            AND id IN (
                1703,
                1704,
                1705,
                1706,
                1907,
                2295
            )
        )

        OR

        /*
         * #194 · 2024-05-31
         */
        (
            payslip_id = 194
            AND id IN (
                1755,
                1756,
                1757,
                1758,
                1759,
                1908,
                2089
            )
        )

        OR

        /*
         * #201 · 2024-11-29
         */
        (
            payslip_id = 201
            AND id IN (
                1820,
                1821,
                1822,
                1823,
                1909
            )
        )

        OR

        /*
         * #213 · 2024-11-29
         */
        (
            payslip_id = 213
            AND id IN (
                1912,
                1913,
                1914,
                1915,
                1916
            )
        )

        OR

        /*
         * #208 · 2025-05-30
         */
        (
            payslip_id = 208
            AND id IN (
                1869,
                1870,
                1871,
                1872,
                1910,
                2294
            )
        )

        OR

        /*
         * #212 · 2025-08-29
         */
        (
            payslip_id = 212
            AND id IN (
                1900,
                1901,
                1902,
                1903,
                1911
            )
        )

        OR

        /*
         * #344 · 2025-11-28
         */
        (
            payslip_id = 344
            AND id IN (
                2247,
                2248,
                2249,
                2250,
                2251,
                2252
            )
        )

        OR

        /*
         * #347 · 2026-02-27
         */
        (
            payslip_id = 347
            AND id IN (
                2271,
                2272,
                2273,
                2274,
                2275
            )
        )
    );
