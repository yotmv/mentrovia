# Mentrovia V1 Working Plan

Date: 2026-07-08
Scope: documentation and scan pass only. No application code was changed.

## Current Read

The original V1 plan in `docs/v1-beta-agent-plan.md` defines 8 build phases and 10 agent tickets. The current codebase is not simply "done through Phase 2"; it has meaningful Phase 3 groundwork and a partial Phase 7/image-pipeline branch in progress. The main missed areas are still Phase 4, Phase 5, Phase 6, and most productized Phase 7/8 work.

The worktree is active and includes many untracked or modified files. Treat this document as a status snapshot, not a commit boundary.

## Phase Status

| Phase | Status | What is done | What remains |
| --- | --- | --- | --- |
| Phase 1 - Foundation | Mostly complete | Laravel app, Fortify auth, business intake schema, dashboard, controller/Blade page routing, static roadmap, tests. | Fix Pint blocker; keep Stream A regression tests green after later changes. |
| Phase 2 - Compliance Knowledge Cache | Partial | `KnowledgeArticle` / `KnowledgeSource` schema, 20 seeded Texas articles, source metadata, verification timestamps, disclaimer tests. | No CRUD/admin UI, no public article display, no validation status UI, no stale-source workflow. |
| Phase 3 - Personalized Roadmap | Partial | `StageClassifier`, `BusinessHealth`, roadmap grouping, sales tax/banking/accounting/payroll triggers, owner-pay item. | Entity classifier is implicit, owner-pay routing is only a roadmap item, roadmap is still a static template, no source freshness on roadmap items. |
| Phase 4 - Recurring Task System | Not started | Weekly/monthly/quarterly/yearly concepts exist as seed articles and one roadmap item. | Task templates, generated user tasks, due rules, dashboard reminders, completion tracking. |
| Phase 5 - LLM Validation Pipeline | Not started for compliance text | `laravel/ai` is installed, AI conversation tables exist, image chooser has partial arbiter code. | OpenRouter text validation roles, reviewer prompts, final judge, validation logs, cache approval statuses, admin review queue. Reference ChapterEcho writing-assist provider selection before designing text generation. |
| Phase 6 - Advisor Q&A | Not started | `agent_conversations` migration exists. | Ask Advisor UI, article retrieval, profile retrieval, validation gate, session history, answer rendering. |
| Phase 7 - Branding and Advertising | Split/partial | Seed articles for brand kit and first 30 days advertising; image pipeline port is partially present. | Text-based brand/ad generators, brand kit storage, UI, image project UI, share/download flows, working provider registration. |
| Phase 8 - Beta Hardening | Partial | Disclaimers appear in key pages; some jobs log warnings. | Error states, feedback button, stale-answer handling, admin dashboard, full production verification, queue/runtime docs. |

## Ticket Status

| Ticket | Status | Notes |
| --- | --- | --- |
| 1 - Business Intake and Profile Schema | Mostly complete | Intake flow, models, factories, classifier, tests exist. |
| 2 - Texas Knowledge Article Cache | Partial | Seed/cache model exists; CRUD/admin missing. |
| 3 - Personalized Roadmap Generator | Partial | Static but profile-aware roadmap exists. |
| 4 - Recurring Task System | Not started | Needs new task tables/models/services/UI. |
| 5 - OpenRouter Validation Pipeline | Not started | Keep separate from the image chooser. |
| 6 - Advisor Q&A Interface | Not started | AI conversation tables alone are not enough. |
| 7 - Owner Pay Decision Module | Not started | Current owner-pay work is roadmap/article content only. |
| 8 - Banking Setup Module | Not started as module | Current work is risk flags, article content, and roadmap item. |
| 9 - Branding Kit Generator | Not started | Use `docs/sample-static-site` for UI/brand examples. |
| 10 - Advertising Generator | Partial via image pipeline, not productized | Image pipeline is incomplete; ad text generation not implemented. |

## ✅ Looks Good

- Stream A controller conversion exists for dashboard, roadmap, business intake, and settings pages.
- Existing HTTP/Livewire tests pass directly: 86 tests, 472 assertions.
- Business intake is Texas-guarded, validates step-by-step, persists profile data, and classifies stages.
- Roadmap covers all 10 planned roadmap phases and reacts to profile fields for EIN, payroll, sales tax, banking, bookkeeping, and owner pay.
- Knowledge seeding is idempotent, source-backed, and covered by tests that enforce official HTTPS sources and standard disclaimers.
- `job_batches` exists and the current database reports the project/photo migrations as run.
- No React or TypeScript surface was found, so React/hooks and TS-specific review items are not applicable yet.

## ⚠️ Issues Found

- **CRITICAL** `app/Models/Photo.php:8` and `app/Models/PhotoGenerationBatch.php:8` - Pipeline jobs call constants, relationships, casts, helpers, and factory states that these models do not define.
  - Fix: Complete `Photo` and `PhotoGenerationBatch` per Stream B5 before wiring UI or running queues.

- **CRITICAL** `app/Jobs/GeneratePhotoWithModel.php:120` and `app/Jobs/GeneratePhotoWithModel.php:124` - Generated photo creation uses old Keystone column names (`prompt`, `estimated_cost_usd`) while the Mentrovia migration has `text`, `text_source`, and `cost_usd`.
  - Fix: Map generated prompt to `text`, `PhotoTextSource::Auto`, and `cost_usd`/`cost_source`.

- **CRITICAL** `app/Jobs/DescribeUploadedPhoto.php:31` and `app/Jobs/DescribeUploadedPhoto.php:47` - Auto-captioning reads/writes `description` and `description_source`, but the accepted schema is `text` and `text_source`.
  - Fix: Rename to the Mentrovia fields and use `PhotoTextSource::Auto`; parse `AgentResponse` through its structured output API.

- **HIGH** `app/Providers/AppServiceProvider.php:16` and `config/ai.php:135` - Custom AI providers/gateways are present, but `Ai::extend(...)` registration and `replicate` / `stability` config stanzas are missing.
  - Fix: Register the ported providers and add config/env entries before any real image generation test.

- **HIGH** `package.json:9` and `resources/js/image-processing/create-portfolio-derivatives.mjs:1` - The derivative worker imports `sharp`, but `sharp` is not listed in dependencies.
  - Fix: Add `sharp` only when dependency changes are approved.

- **HIGH** `app/Policies/ProjectPolicy.php:14` - The generated policy currently denies every project action.
  - Fix: Implement owner/read/write/share/delete rules before adding project routes or UI.

- **HIGH** `routes/web.php:10` and `app/Http/Controllers/ProjectController.php:5` - Project routes and controller actions are not implemented.
  - Fix: Add `projects.index` and `projects.show` routes/controllers only after models/policy are complete.

- **HIGH** `app/Livewire/Projects` - Missing entirely.
  - Fix: Build `Projects\Index` and `Projects\Show` after the model layer is stable.

- **HIGH** `config/livewire.php` - Missing, so the planned 25 MB temp upload override is not in place.
  - Fix: Publish Livewire config and raise `temporary_file_upload.rules` to include the 25 MB max.

- **HIGH** `app/Ai/Gateway/ReplicateImageGateway.php:138` and `app/Ai/Gateway/StabilityImageGateway.php:53` - PHPStan reports calls to undefined `Laravel\Ai\Files\Image::content()`.
  - Fix: Re-check laravel/ai 0.9 file APIs and update the gateway port before gateway tests.

- **MEDIUM** `.env.example:59` - Missing required AI/photo env placeholders (`AWS_URL`, `AWS_ENDPOINT`, `OPENROUTER_API_KEY`, `REPLICATE_API_TOKEN`, `STABILITY_API_KEY`, `PHOTOSTUDIO_*`, `AI_IMAGE_CHOOSER_*`).
  - Fix: Add placeholders when the image pipeline implementation ticket resumes.

- **MEDIUM** `routes/console.php:1` - `PrunePhotoOriginalsCommand` exists but is not scheduled.
  - Fix: Add the daily schedule after `Photo` model helpers/constants exist.

- **MEDIUM** `tests/Feature/Auth/*` and `tests/Feature/Settings/*` - `composer test` is blocked by Pint `single_blank_line_at_eof` failures in existing tests.
  - Fix: Run Pint in a formatting ticket; do not mix it into feature work unless intentionally touching those files.

- **MEDIUM** `tests/Feature/Ai` / `tests/Feature/Projects` / `tests/Feature/Photos` - No image pipeline, chooser, gateway, project UI, sharing, upload, caption, or derivative tests are present.
  - Fix: Port/add the planned test suites before declaring Stream B complete.

- **LOW** `resources/js/image-processing/create-portfolio-derivatives.mjs:82` - The script prints JSON with `console.log`. This is expected CLI stdout, not browser debug logging.
  - Fix: Leave as-is unless the worker protocol changes.

## Verification Snapshot

- `php artisan test` - passed, 86 tests / 472 assertions.
- `composer test` - failed before Pest because Pint check reported missing final blank lines in existing test files.
- `vendor/bin/phpstan analyse --error-format=table` - failed with 50 reported errors, concentrated in the incomplete image pipeline port.
- `php artisan route:list --except-vendor` - 8 app routes; no project routes.
- `php artisan photos:image-chooser --image-input --count=3` - failed with no profiled model satisfying requirements/API-key state.
- Boost database schema/status - core, knowledge, AI conversation, project, photo, and pivot migrations are currently marked as run.

## Recommended Next Plan

### Step 0 - Freeze the baseline

1. Decide whether the current image-pipeline work is owned by the other agent or by this thread before editing overlapping files.
2. Run Pint intentionally in a small formatting-only commit/ticket to unblock `composer test`.
3. Keep `php artisan test`, PHPStan, route list, and the image chooser command as the checkpoint set for each later phase boundary.

### Step 1 - Finish and stabilize Stream B foundation

1. Complete `Photo`, `PhotoGenerationBatch`, `Project`, `User` relationships, factories, and `ProjectPolicy`.
2. Align every job with the Mentrovia schema (`text`, `text_source`, `cost_usd`) and enums.
3. Add provider registration/config/env placeholders.
4. Publish Livewire config for 25 MB uploads.
5. Add `sharp` only with dependency-change approval.
6. Add/port model, policy, gateway, chooser, job, derivative, and command tests.

### Step 2 - Build Projects UI

1. Add project routes/controllers.
2. Build `Livewire\Projects\Index`.
3. Build `Livewire\Projects\Show` for upload, generation, gallery, polling, sharing, download, retry.
4. Add sidebar navigation.
5. Verify with feature tests and one end-to-end local queue run using fakes first, then real bucket/keys when available.

### Step 3 - Return to missed V1 core phases

1. Phase 4 recurring tasks: tables, templates, generator, completion tracking, dashboard reminders.
2. Phase 5 compliance text validation: model roles, validators, final judge, validation logs, approval statuses, stale handling.
3. Phase 6 advisor Q&A: retrieve business context and cached articles before answering; persist sessions.
4. Reference `/home/brian/www/ChapterEcho/docs/writing-assist-design.md` and `/home/brian/www/ChapterEcho/resources/writing/guidelines/avoid-ai-writing.md` for text provider selection, pass orchestration, fallback design, and prose quality guardrails.

### Step 4 - Product modules

1. Owner pay decision module.
2. Banking setup module.
3. Branding kit generator.
4. Advertising generator.
5. Use `docs/sample-static-site` as the visual/content reference when building UI/UX for brand-facing pages.

### Step 5 - Beta hardening

1. Admin knowledge CRUD and review queue.
2. Stale answer handling and visible source freshness.
3. User feedback/reporting.
4. Queue timeout/runtime documentation.
5. Full `composer test` green, PHPStan green, image chooser command green, and browser smoke pass.

## Open Decisions / Blockers

- New photo bucket name and credentials are still required before real E2E image generation.
- Dependency approval is needed for `sharp`.
- Ownership of the current image pipeline branch/workstream should be clarified to avoid agents overwriting each other.
- Text-generation model selection should be planned from ChapterEcho's writing assistant/provider manager pattern instead of starting from scratch.
