# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Knowledge article product pages: authenticated index with category/status filters and detail view with safe markdown rendering, source links, freshness metadata, high-risk warnings, and missing-source fallback states.
- Knowledge nav item in sidebar.
- Browser smoke test covering guest redirects, authenticated page loads, and roadmap/intake flow.
- Tailwind Typography plugin for prose-styled markdown content.

### Fixed

- Pint `single_blank_line_at_eof` failures in 9 test files (auth, settings, unit example).
