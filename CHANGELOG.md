# Changelog

All notable changes to the Industry Manager plugin are recorded here.

## [Unreleased]

### Fixed

- **Calculator renders again.** Every Calculator page load threw
  `Illuminate\View\ViewException: syntax error, unexpected token "endif"` because the
  structure `<option>` line put two Blade directives back to back (`@endif@if(...)`);
  Blade only compiled the second `@if` as literal text and left an orphan `endif`.
  A separator between the directives restores rendering and calculation output.

### Changed

- **Structures page cleanup.** Removed the superseded "coming soon" copy from the
  dashboard's Structures quicklink and from the controller's sprint status notes, and
  dropped the dead `access_denied`, `security_band` and top-level `source` fields from
  `StructureService::forUser()`. The unreachable `blueprints/detail` placeholder view
  and its scaffold language strings are gone; the route still redirects to the
  Calculator so old links keep working.
- **Dashboard job metrics.** Added "Running Jobs" (jobs SeAT reports as `active`) and
  "Job Costs · <month>" (sum of ESI's reported install cost for jobs started in the
  current calendar month) stat cards. Both are scoped to the same entitlement set as
  the jobs list and fall back to a "No industry jobs synced yet." empty state when
  nothing is synced or no characters are linked.

### Tests

- Added `tests/Feature/PageSmokeTest.php`, an end-to-end smoke test that drives the
  real Calculator, Structures and Dashboard controller actions (load, calculate,
  refresh and empty state) with a signed-out user.
