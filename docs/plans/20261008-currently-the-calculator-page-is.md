## Task 1: Diagnose the broken Calculator page
- [x] Reproduce the failure on the Calculator route and record the exact error (HTTP status, stack trace, or blank view) in the task notes.
- [x] Locate the Calculator route, controller, and Blade view(s) in `src/` and `resources/views/`.
- [x] Identify the root cause and note the smallest change that restores correct rendering and calculation output.
- [x] Confirm whether the failure is a stale reference from a previous release (missing view, renamed class, changed config key).

## Task 2: Fix the Calculator page
dependsOn: [1]
- [x] Apply the minimal fix to the Calculator controller and/or view identified in Task 1.
- [x] Verify the calculation flow produces correct results for at least one known input set.
- [x] Confirm the page renders without console/Blade errors and preserves existing form fields and defaults.
- [x] Run the plugin test suite (`composer test`) and confirm no regressions in Calculator-related tests.

## Task 3: Remove obsolete elements from the Structures page
dependsOn: [1]
- [x] Audit the Structures page view(s) and controller for dead or superseded UI elements, fields, and links.
- [x] Remove or replace the obsolete elements, keeping any still-referenced data intact.
- [x] Verify the Structures page renders correctly and no remaining view references the removed items.
- [x] Run `composer test` to confirm the Structures changes introduce no regressions.

## Task 4: Add dashboard metrics for running jobs and monthly job costs
dependsOn: [1]
- [x] Identify the existing models/queries available for industry jobs and job cost data in the plugin.
- [x] Implement the aggregation for currently running jobs and total job costs for the current month.
- [x] Expose the metrics to the dashboard controller and render them in the dashboard view.
- [x] Handle the empty-data and no-permission cases so the dashboard does not error when there are no jobs.
- [x] Verify the displayed values match the underlying data for a sample period.

## Task 5: General plugin polish pass
dependsOn: [2, 3, 4]
- [x] Audit the main plugin views for inconsistent styling, stale labels, and leftover debug markup.
- [x] Apply consistent formatting and labels across Calculator, Structures, and Dashboard views.
- [x] Remove any commented-out or unreachable markup encountered during the audit.
- [x] Confirm navigation and links between the affected pages resolve correctly.

## Task 6: Regression checks and smoke test
dependsOn: [2, 3, 4, 5]
- [ ] Run `composer test` and confirm the full available suite passes.
- [ ] Perform a manual smoke test covering Calculator, Structures, and Dashboard (load, calculate, refresh, empty state).
- [ ] Record any residual findings and confirm none are blocking.
- [ ] Update the plugin changelog or release notes to describe the Calculator fix, Structures cleanup, and dashboard metrics.
