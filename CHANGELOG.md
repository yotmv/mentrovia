# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Text AI provider roles with config-driven provider/model selection, fallback generation, test fakes, and human-voice guidance for brand/ad copy.
- Recurring task foundation: template, generated task, and completion-history schemas with seeded weekly/monthly/quarterly/yearly task templates linked to knowledge articles.
- Recurring task generator that creates and refreshes business-specific tasks from intake profile changes while preserving completion state and history.
- Recurring task UI with timeframe tabs, completion notes, task-source links, scoped updates, and dashboard upcoming tasks.
- Knowledge article admin CRUD: Livewire-based admin index with search/status filter, create/edit form with all article fields and nested source CRUD, archive, mark stale, and request revalidation actions.
- Admin authorization: `is_admin` column on users, `IsAdmin` middleware, `KnowledgeArticlePolicy` for admin-only access.
- Admin Knowledge nav item in sidebar (conditional on `is_admin`).
- Published high-risk articles require at least one source.
- Freshness status helper on KnowledgeArticle model with four deterministic states: fresh, review soon, stale, missing sources.
- Freshness badges on article index and detail pages.
- Stale content and missing sources warning banners on article detail page.
- Compliance validation schema with validation run/vote models, enum casts, factories, and article/user/business query relationships.
- Compliance validation pipeline with multi-role validator prompts, final judge aggregation, deterministic guardrails, stored votes, and conservative high-risk stale-content blocking.
- Knowledge article product pages: authenticated index with category/status filters and detail view with safe markdown rendering, source links, freshness metadata, high-risk warnings, and missing-source fallback states.
- Knowledge nav item in sidebar.
- Browser smoke test covering guest redirects, authenticated page loads, and roadmap/intake flow.
- Tailwind Typography plugin for prose-styled markdown content.

### Changed

- Documented the real Photo Studio E2E review, runtime requirements, and credential blockers for ticket 8.

### Fixed

- Pint `single_blank_line_at_eof` failures in 9 test files (auth, settings, unit example).
