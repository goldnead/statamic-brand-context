# Changelog

## 1.2.2 — 2026-07-27

### Fixed — the brand was resolved after route-model binding, so bound routes 404'd

- **`SetBrandFromSession` ran at the end of the `statamic.cp` stack, behind `SubstituteBindings`.** Route-model binding resolves `{webhook}`, `{delivery}`, `{automation}` and friends through the query builder; with no brand current at that moment the fail-closed scope hid the record, the lookup found nothing and the request died as a 404. Every edit, delete, toggle and detail page in every addon with bound models was unreachable under multi-brand.
- `pushMiddlewareToGroup` always appends, which is how it ended up there. The group is now rebuilt with the middleware spliced in directly before `SubstituteBindings`, falling back to appending when that middleware is absent.
- **The isolation is unchanged**, and that was verified rather than assumed: with the owning brand active the bound route answers 200, and the same record requested under a different brand still answers 404.

### Notes

- Found in the hub QA run, where two addons independently reported every detail page as 404.
- Suite: **20 passed (33 assertions)**.
