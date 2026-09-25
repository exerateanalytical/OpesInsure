# Work-in-progress patches (agents stopped by the weekly usage limit, 2026-09-25 ~21:50 UTC)
Each patch is the uncommitted work of one cloud agent, diffed against its base commit:
- base cbcbecb: B1-regulatory, B2-kpis, B3-devplatform, B5-legacy-migration, B6-ops, B7-security, V1-vehicle-fiscal, F1-finance-subledger
- base ef6b557: WAFIX-integration
Resume: `git checkout -B <name> <base> && git apply docs/wip/<name>.patch`, then finish the task. These patches are NOT merged into the branch.
