# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Knowledge article admin CRUD: Livewire-based admin index with search/status filter, create/edit form with all article fields and nested source CRUD, archive, mark stale, and request revalidation actions.
- Admin authorization: `is_admin` column on users, `IsAdmin` middleware, `KnowledgeArticlePolicy` for admin-only access.
- Admin Knowledge nav item in sidebar (conditional on `is_admin`).
- Published high-risk articles require at least one source.
- Knowledge article product pages: authenticated index with category/status filters and detail view with safe markdown rendering, source links, freshness metadata, high-risk warnings, and missing-source fallback states.
- Knowledge nav item in sidebar.
- Browser smoke test covering guest redirects, authenticated page loads, and roadmap/intake flow.
- Tailwind Typography plugin for prose-styled markdown content.

### Fixed

- Pint `single_blank_line_at_eof` failures in 9 test files (auth, settings, unit example).
