## Task 1: Diagnose the broken Calculator page
- [ ] Reproduce the failure on the Calculator route and record the exact error (HTTP status, stack trace, or blank view) in the task notes.
- [ ] Locate the Calculator route, controller, and Blade view(s) in `src/` and `resources/views/`.
- [ ] Identify the root cause and note the smallest change that restores correct rendering and calculation output.
- [ ] Confirm whether the failure is a stale reference from a previous release (missing view, renamed class, changed config key).

## Task 2: Fix the Calculator page
dependsOn: [1]
- [ ] Apply the minimal fix to the Calculator controller and/or view identified in Task 1.
- [ ] Verify the calculation flow produces correct results for at least one known input set.
- [ ] Confirm the page renders without console/Blade errors and preserves existing form fields and defaults.
- [ ] Run the plugin test suite (`composer test`) and confirm no regressions in Calculator-related tests.

## Task 3: Remove obsolete elements from the Structures page
dependsOn: [1]
- [ ] Audit the Structures page view(s) and controller for dead or superseded UI elements, fields, and links.
- [ ] Remove or replace the obsolete elements, keeping any still-referenced data intact.
- [ ] Verify the Structures page renders correctly and no remaining view references the removed items.
- [ ] Run `composer test` to confirm the Structures changes introduce no regressions.

## Task 4: Add dashboard metrics for running jobs and monthly job costs
dependsOn: [1]
- [ ] Identify the existing models/queries available for industry jobs and job cost data in the plugin.
- [ ] Implement the aggregation for currently running jobs and total job costs for the current month.
- [ ] Expose the metrics to the dashboard controller and render them in the dashboard view.
- [ ] Handle the empty-data and no-permission cases so the dashboard does not error when there are no jobs.
- [ ] Verify the displayed values match the underlying data for a sample period.

## Task 5: General plugin polish pass
dependsOn: [2, 3, 4]
- [ ] Audit the main plugin views for inconsistent styling, stale labels, and leftover debug markup.
- [ ] Apply consistent formatting and labels across Calculator, Structures, and Dashboard views.
- [ ] Remove any commented-out or unreachable markup encountered during the audit.
- [ ] Confirm navigation and links between the affected pages resolve correctly.

## Task 6: Regression checks and smoke test
dependsOn: [2, 3, 4, 5]
- [ ] Run `composer test` and confirm the full available suite passes.
- [ ] Perform a manual smoke test covering Calculator, Structures, and Dashboard (load, calculate, refresh, empty state).
- [ ] Record any residual findings and confirm none are blocking.
- [ ] Update the plugin changelog or release notes to describe the Calculator fix, Structures cleanup, and dashboard metrics.
