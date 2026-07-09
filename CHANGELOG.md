# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Advertising generator: versioned `advertising_kits` schema scoped to user/business, and an `AdvertisingKitGenerator` service that produces ad angles, Facebook/Instagram ad copy, Google ad concepts, social posts, flyer copy, ad image prompts, a landing page outline, and a first-30-days marketing plan through the `ad_copy` text role with human-voice guardrails; generations reuse the latest brand kit's names, tone, and palette when one exists and record which brand kit version grounded them.
- Advertising UI: authenticated `/advertising` page with Advertising sidebar nav, generate/new-version actions, version switching, copy buttons for ads, posts, flyer copy, and image prompts, per-section empty fallbacks, and empty/loading/error states including a "generate a brand kit first" hint.
- `flux_ui_kit` setting (`config/flux-ui.php`, `FLUX_UI_KIT` env): defaults to `flux-free` and auto-switches to `flux-pro` when a licensed `livewire/flux-pro` install is detected, so open-source installs without a Flux Pro license keep a fully working UI.
- Brand board prompt on brand kits: one production-ready 4K image prompt (two marketing-page mockups plus a typography/color design rail) generated per the brand-kit board structure, regenerable as its own section, with copy affordance in the Branding UI.
- Hierarchical brand kit color palettes: entries now carry a role label and dominant/supporting prominence; the Branding page renders dominant colors as large swatches and supporting accents as smaller chips.
- Flux Pro tabbed layout on the Branding page (Identity / Design system / Assets) with a stacked flux-free fallback layout sharing the same section partials.
- Brand kit UI: authenticated `/branding` page with Branding sidebar nav, generate/new-version actions, per-section regeneration, version switching, tap-to-save preferred name/tagline/primary color, copy buttons for image prompts and social bios, and polished empty/loading/error states.
- Brand kit data model and generator: versioned `brand_kits` schema scoped to user/business, and a `BrandKitGenerator` service that produces name ideas, taglines, positioning, tone/voice, color palette, font notes, logo/image prompts, and social bios through the `brand_copy` text role with human-voice guardrails and test-fakeable providers.
- Advisor Q&A hardening: guarded refusals for unsupported deadline/rate/threshold/local claims, necessary follow-up prompts, per-user quota, answer flagging, polished source citations, and `/advisor/history`.
- Advisor Q&A MVP with authenticated navigation, profile-scoped question input, structured source-aware answers, validation for stale/missing-source/high-risk knowledge, professional-review flags, and persisted conversation history.
- Banking setup module: authenticated `/banking-setup` checklist with profile-aware bank documents, tax/sales-tax/payroll reserve guidance, completion tracking, and dashboard/roadmap links.
- Owner pay module: authenticated `/owner-pay` guide comparing draws, distributions, guaranteed payments, W-2 salary, dividends, retained earnings, and accountable-plan reimbursements, tailored to the business's legal/tax structure with caveats, CPA questions, and related knowledge article links.
- Undecided legal structure on the owner pay page prompts profile clarification and professional review with a neutral method overview.
- Roadmap items can carry a link; the owner-pay roadmap item links to the owner pay guide.
- Validation admin review queue for stale articles, failed validations, conflicting sources, admin review decisions, validation votes, final judge results, review notes, and triage actions.
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

- Documented the Flux UI editions policy: `livewire/flux-pro` stays in `composer.json` during development, FOSS installs without a license remove it (`composer remove livewire/flux-pro`), and every Flux Pro component usage must ship a flux-free fallback gated by `flux_ui_kit` (README, contributing guide, V1 working plan and tickets).
- Documented the real Photo Studio E2E review, runtime requirements, and credential blockers for ticket 8.

### Fixed

- Pint `single_blank_line_at_eof` failures in 9 test files (auth, settings, unit example).
