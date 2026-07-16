# Mentrovia V1 Working Tickets

Date: 2026-07-08
Source plan: `docs/v1-working-plan.md`

These tickets are ordered to finish the current V1 plan with the least churn. Each ticket should keep changes scoped, add or update tests, and leave `php artisan test` and `vendor/bin/phpstan analyse --error-format=table` green. When PHP files are changed, run Pint before handoff.

Standing UI rule (2026-07-09): `livewire/flux-pro` stays installed during development, but any view that uses a Flux Pro component must ship an equivalent flux-free fallback in the same change, gated by `flux_ui_kit` (`config/flux-ui.php`, `App\Enums\FluxUiKit`). Tests for Pro-enhanced screens must cover both kits. FOSS installs without a license remove the Pro package per the README.

## Ticket 0 - Baseline Verification Cleanup

Priority: P0
Phase: Beta hardening
Dependencies: none

### Goal

Make the repo's standard verification commands usable again and document the local frontend runtime path.

### Scope

- Fix Pint `single_blank_line_at_eof` failures in existing auth/settings tests.
- Confirm `composer test` reaches PHPStan and Pest.
- Resolve local Node/Vite build path issue, or document the exact local command/runtime needed.
- Add a lightweight browser smoke checklist or Pest browser smoke test plan after build is working.

### Acceptance Criteria

- `composer test` passes.
- `npm run build` passes from the documented local environment.
- `php artisan route:list --except-vendor` still shows expected app routes.
- No product behavior changes.

### Suggested Tests

- `composer test`
- `npm run build`

### Completion Notes

Completed 2026-07-08.

- Fixed Pint `single_blank_line_at_eof` failures in 9 test files (auth, settings, unit example).
- Confirmed `composer test` reaches PHPStan and Pest.
- Resolved local Node/Vite build path issue; `npm run build` passes.
- Added Pest browser smoke test covering guest redirects, authenticated page loads, and roadmap/intake flow.
- `php artisan route:list --except-vendor` shows expected app routes.
- No product behavior changes.

Verification:

- `composer test` passed.
- `npm run build` passed.

## Ticket 1 - Knowledge Article Product Pages

Priority: P1
Phase: Compliance Knowledge Cache
Dependencies: Ticket 0

### Goal

Expose the seeded Texas compliance knowledge in-product with source and freshness metadata.

### Scope

- Add authenticated knowledge/article routes and controllers.
- Add article index with category/status filters.
- Add article detail page rendering markdown safely.
- Show:
  - title
  - jurisdiction
  - category
  - risk level
  - last verified date
  - next review date
  - source summary
  - source links
  - standard disclaimer.
- Add sidebar/nav entry if this becomes user-facing in V1.

### Acceptance Criteria

- Users can browse and read seeded articles.
- High-risk article pages visibly show freshness and professional review caveats.
- Missing/stale source metadata has a visible fallback state.

### Suggested Tests

- Guest redirect.
- Authenticated article index renders categories.
- Article detail renders source links and verified dates.
- Unknown slug returns 404.

### Completion Notes

Completed 2026-07-08.

- Added authenticated knowledge article routes and `ArticleController` with index and show methods.
- Article index shows categories, jurisdictions, risk badges, and status badges with category/status filters.
- Article detail renders markdown safely via CommonMark GFM converter with escaped HTML and unsafe links disabled.
- Detail page shows title, jurisdiction, category, risk level, last verified date, next review date, source summary, source links, and standard advisory disclaimer.
- High-risk articles show a prominent warning banner.
- Missing/stale source metadata shows a visible fallback state.
- Added Knowledge nav item in sidebar.
- Added Tailwind Typography plugin for prose-styled markdown content.
- Code-review pass completed; no issues found.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/Knowledge/ArticleTest.php` passed.
- `php artisan test --compact tests/Feature/KnowledgeSeederTest.php` passed.

## Ticket 2 - Knowledge Admin CRUD

Priority: P1
Phase: Compliance Knowledge Cache / Beta hardening
Dependencies: Ticket 1

### Goal

Add a lightweight admin workflow for maintaining cached compliance articles and source metadata.

### Scope

- Define admin authorization approach for V1.
- Add admin article CRUD:
  - title
  - slug
  - jurisdiction
  - category
  - body markdown
  - source summary
  - risk level
  - last verified date
  - next review date
  - status
  - version.
- Add nested source CRUD.
- Add "mark stale" and "request revalidation" placeholders, even before validation pipeline exists.

### Acceptance Criteria

- Admin can create/update/archive articles and sources.
- Non-admin users cannot access admin screens.
- Source metadata remains required for published high-risk content.

### Suggested Tests

- Admin access allowed.
- Non-admin access forbidden.
- Article create/update/archive.
- Source create/update/delete.
- Published high-risk article validation.

### Completion Notes

Completed 2026-07-08.

- Added `is_admin` boolean column to users table with migration.
- Added `IsAdmin` middleware and registered `admin` alias in `bootstrap/app.php`.
- Added `KnowledgeArticlePolicy` enforcing admin-only authorization for all CRUD actions.
- Added admin knowledge routes behind `auth`, `verified`, and `admin` middleware.
- Created `ArticleIndex` Livewire component with search, status filter, pagination, and archive/mark-stale/request-revalidation actions.
- Created `ArticleForm` Livewire component with all article fields and nested source CRUD (add/remove/update sources inline).
- Published high-risk articles require at least one source (validated in `save()`).
- Added admin Knowledge nav item in sidebar (conditional on `is_admin`).
- Code-review pass completed; fixed authorization ordering bug (`authorize('create')` moved before `KnowledgeArticle::create()`).
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/Admin/Knowledge/ArticleAdminTest.php` passed: 16 tests covering admin access, non-admin forbidden, CRUD, source CRUD, and high-risk validation.

## Ticket 3 - Stale Knowledge UX

Priority: P1
Phase: Compliance Knowledge Cache / Beta hardening
Dependencies: Ticket 1, Ticket 2

### Goal

Represent article freshness consistently before the LLM validation pipeline is built.

### Scope

- Add freshness status helper on `KnowledgeArticle`.
- Statuses can be deterministic for now:
  - fresh
  - review soon
  - stale
  - missing sources.
- Show freshness labels on article index/detail.
- Add warnings for stale or missing-source articles.
- Add dashboard/roadmap hooks only if low-risk and simple.

### Acceptance Criteria

- Users can tell when compliance content is stale or missing sources.
- Stale high-risk articles are not presented as current guidance.

### Suggested Tests

- Freshness helper unit/feature coverage.
- Detail page warning for stale high-risk article.
- Missing-source warning.

### Completion Notes

Completed 2026-07-08.

- Added `FreshnessStatus` enum with four deterministic states: fresh, review soon, stale, missing sources.
- Added `freshnessStatus()` and `isStale()` helpers on `KnowledgeArticle` model.
- Freshness logic: missing sources takes priority, then stale (past review date or null), then review soon (within 14 days), then fresh.
- Added freshness badges to article index and detail pages.
- Added stale content and missing sources warning banners on article detail page.
- Replaced old "Due for review" badge with freshness status badge in Freshness sidebar.
- Eager-loaded `sources` relation in `ArticleController::index` to prevent N+1 queries.
- Dashboard/roadmap hooks skipped per ticket's "only if low-risk and simple" wording.
- Code-review pass completed; no issues found.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/Knowledge/FreshnessTest.php` passed: 9 tests covering all 4 freshness states, index badge, stale + high-risk dual warning, missing sources warning, and fresh article negative assertions.
- `php artisan test --compact tests/Feature/Knowledge/ArticleTest.php` passed: updated stale and missing-sources tests.

## Ticket 4 - Recurring Task Schema And Templates

Priority: P1
Phase: Recurring Task System
Dependencies: Ticket 0

### Goal

Create the persistent foundation for weekly/monthly/quarterly/yearly recurring business tasks.

### Scope

- Add task template model/schema.
- Add generated business task model/schema.
- Add task occurrence/completion history if separate occurrences are needed.
- Include fields for:
  - title
  - description
  - category
  - frequency
  - applies-to stage/entity/employees/sales-tax/contractors
  - due rule
  - source article
  - confidence
  - professional review flag.
- Seed initial templates from existing weekly/monthly/quarterly/yearly knowledge articles.

### Acceptance Criteria

- Task templates are seeded idempotently.
- Templates can express profile applicability.
- Generated tasks can be related to a business and completed later.

### Suggested Tests

- Migration/model tests.
- Seeder idempotence.
- Applicability rule coverage.

### Completion Notes

Completed 2026-07-08.

- Added `RecurringTaskTemplate`, `BusinessTask`, and `TaskCompletion` schemas/models/factories.
- Added `TaskCategory`, `TaskFrequency`, and `TaskConfidence` enums.
- Seeded 11 idempotent recurring task templates from weekly/monthly/quarterly/yearly recurring knowledge articles.
- Templates support profile applicability rules for employees, sales tax exposure, contractors, stages, and legal structures.
- Added source article links, confidence, due rules, and professional review flags.
- Code-review pass completed; no issues found for the schema/template path.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/RecurringTaskTemplateTest.php` passed.

## Ticket 5 - Recurring Task Generator

Priority: P1
Phase: Recurring Task System
Dependencies: Ticket 4

### Goal

Generate business-specific recurring tasks from the user's profile.

### Scope

- Add service that maps a `Business` to applicable task templates.
- Generate/update business tasks after intake save.
- Make regeneration idempotent.
- Handle profile changes, e.g. employee count changes from 0 to 1.
- Avoid deleting completed history.

### Acceptance Criteria

- Saving intake creates applicable recurring tasks.
- Changing profile adds newly applicable tasks.
- Completed task history remains intact.

### Suggested Tests

- Starting-from-scratch task set.
- Sales-tax-exposed task set.
- Employee/payroll task set.
- Contractor task set.
- Idempotent regeneration.

### Completion Notes

Completed 2026-07-08.

- Added `RecurringTaskGenerator` service to generate/update applicable business tasks from active templates.
- Wired generator into business intake save after stage classification.
- Regeneration is idempotent by business/template and preserves task completion state/history.
- Profile changes add newly applicable employee, contractor, and sales-tax tasks.
- Code-review pass completed; no issues found for generator/intake integration.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/RecurringTaskGeneratorTest.php` passed.
- `php artisan test --compact tests/Feature/Business/IntakeTest.php` passed.

## Ticket 6 - Recurring Task UI

Priority: P1
Phase: Recurring Task System
Dependencies: Ticket 5

### Goal

Give users a task calendar/list with completion tracking.

### Scope

- Add Tasks nav item and routes.
- Add views/tabs for:
  - this week
  - this month
  - this quarter
  - this year
  - all tasks.
- Show task title, frequency, due date, why it matters, related module/source, completion checkbox, and notes.
- Add dashboard "upcoming tasks" section.

### Acceptance Criteria

- User can view generated tasks grouped by timeframe.
- User can complete/uncomplete tasks and add notes.
- Dashboard shows upcoming tasks.

### Suggested Tests

- Guest redirect.
- Task list visibility scoped to user.
- Complete/uncomplete.
- Dashboard upcoming tasks.

### Completion Notes

Completed 2026-07-08.

- Added authenticated task routes: `tasks.index` and `tasks.update`.
- Added Tasks sidebar navigation.
- Added task list UI with this week/month/quarter/year/all tabs.
- Tasks show title, frequency, due date, category, source article link, why-it-matters copy, review badge, completion checkbox, and notes.
- Added scoped task update authorization via `UpdateTaskRequest`.
- Added complete/uncomplete behavior with notes and completion-history records.
- Added dashboard upcoming tasks section.
- Code-review pass completed; fixed boolean completion parsing to use Laravel request boolean handling.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/TaskUiTest.php tests/Feature/DashboardTest.php` passed.
- `php artisan test --compact tests/Feature/RecurringTaskTemplateTest.php tests/Feature/RecurringTaskGeneratorTest.php tests/Feature/TaskUiTest.php tests/Feature/Business/IntakeTest.php tests/Feature/DashboardTest.php` passed: 27 tests, 120 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `php artisan route:list --except-vendor` shows the task routes.
- Full `php artisan test --compact` passed: 222 tests, 877 assertions.

## Ticket 7 - Text AI Provider Roles

Priority: P1
Phase: LLM Validation Pipeline / Branding / Advisor Q&A
Dependencies: Ticket 0

### Goal

Create a reusable text-generation provider role system before building validation, advisor, brand, or ad text features.

### Scope

- Reference `/home/brian/www/ChapterEcho/docs/writing-assist-design.md`.
- Add config-driven text roles:
  - classifier
  - validator factual
  - validator contradiction
  - validator user fit
  - final judge
  - advisor answer
  - brand copy
  - ad copy.
- Add provider/model selection helper or manager.
- Include fallback behavior and test fakes.
- Include human voice / avoid-AI-writing guidance for marketing outputs, referencing `/home/brian/www/ChapterEcho/resources/writing/guidelines/avoid-ai-writing.md`.

### Acceptance Criteria

- Text features can request a role without hard-coding model names.
- Tests can fake role responses.
- Provider config is environment-driven.

### Suggested Tests

- Role resolution.
- Missing role/config failure.
- Fallback behavior.
- Fake provider response.

### Completion Notes

Completed 2026-07-08.

- Added `TextGenerationRole` enum with classifier, validator factual, validator contradiction, validator user fit, final judge, advisor answer, brand copy, and ad copy roles.
- Added config-driven text role profiles in `config/text-generation.php` with environment-driven provider/model/timeout settings and per-role fallback provider/model settings.
- Added `TextRoleManager` service with role resolution, Laravel AI anonymous-agent prompting, provider/model fallback, and structured result metadata.
- Added `TextRoleGenerator` contract under the text AI domain and bound it to `TextRoleManager`.
- Added typed request/result/profile objects and role-specific configuration exceptions.
- Added `TextRoleManager::fake()` and `FakeTextRoleGenerator` for role response fakes and assertions in tests.
- Added human-voice guidance for brand/ad copy outputs using a local prompt asset "avoid-AI-writing" guidance.
- Code-review pass completed; fixed contract placement to avoid introducing a new base `app/Contracts` folder.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/TextRoleManagerTest.php` passed: 5 tests, 15 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.

## Ticket 8 - Real Photo Studio E2E Hardening

Priority: P0
Phase: Photo Studio / Beta hardening
Dependencies: Ticket 0 preferred, real bucket credentials/provider keys

### Goal

Prove the finished Photo Studio works outside fakes with the real S3-compatible bucket, providers, queue worker, signed URLs, and derivative runtime.

### Scope

- Configure private local env with real bucket name/credentials and provider keys.
- Verify S3-compatible `temporaryUrl()` support and `AWS_URL` fallback.
- Verify Node/Sharp derivative generation on the actual runtime that queue workers will use.
- Verify queue worker timeout is greater than image generation timeout.
- Run the full manual E2E:
  - create project
  - upload captioned photo
  - upload uncaptioned photo
  - derivatives generated
  - auto-caption lands
  - generate top 3
  - gallery variants render
  - downloads work
  - share read-only
  - verify read-only restrictions.
- Decide whether `photos:image-chooser --image-input --count=3` should be fake-friendly or remain a real-config diagnostic.

### Acceptance Criteria

- Real E2E flow succeeds and screenshots/notes are added to the ticket/PR summary.
- `photos:image-chooser --image-input --count=3` behavior is intentionally documented.
- Queue/runtime notes are added to deployment docs or the working plan.

### Suggested Tests

- Existing photo tests.
- Manual queue worker E2E with real services.
- `php artisan photos:image-chooser --image-input --count=3`

### Review Notes

Reviewed 2026-07-09.

Status: blocked on real bucket credentials, provider keys, and a production-like queue runtime. Do not mark complete until the manual E2E flow runs against real services.

What is already implemented and covered:

- Private photo storage is wired through `photostudio.disk`, with uploaded/generated prefixes and S3 fallback URL behavior in `Photo::url()` / `Photo::downloadUrl()`.
- The project upload flow queues derivative generation, then auto-captioning for uncaptioned uploads after normalized LLM input exists.
- The generation flow analyzes selected uploads, chooses best-value models, fans out generation jobs, records model/cost metadata, queues derivatives, and supports model fallback.
- The derivative service runs the configured Node/Sharp worker, stores variants beside the source image, records dimensions/sizes, and leaves failed photos retryable.
- Project sharing tests cover read-only restrictions for generated-photo deletion.
- `photos:image-chooser --image-input --count=3` is intentionally a real-config diagnostic, not fake-friendly. With blank local provider keys it fails with: "No profiled image model satisfies these requirements. Check provider API keys and thresholds."

Runtime notes for the real E2E pass:

- Populate private local env with `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT` or native S3 settings, `AWS_URL` when a bucket needs public URL fallback, and the provider keys needed by the selected models.
- Verify `temporaryUrl()` support first; if the S3-compatible provider cannot sign private GETs, verify `AWS_URL` fallback behavior before sharing the environment.
- Run the queue worker with a timeout greater than image-generation HTTP timeout (`GeneratePhotoWithModel` uses 300 seconds) and keep the queue connection `retry_after` greater than the worker timeout to avoid duplicate processing.
- Verify the worker runtime resolves `PHOTOSTUDIO_NODE_BINARY` to the same Node binary used to install `sharp`.
- Run the manual E2E checklist from this ticket with a real queue worker, not the sync queue.

Verification:

- `php artisan test --compact tests/Feature/Ai/ImageModelChooserTest.php tests/Feature/PhotoGenerationPipelineTest.php tests/Feature/PhotoDerivativesTest.php tests/Feature/PhotoUploadTest.php tests/Feature/GeneratedPhotoGalleryTest.php tests/Feature/Projects/PhotoCaptionTest.php tests/Feature/Projects/ProjectSharingTest.php` passed: 59 tests, 190 assertions.
- `php artisan photos:image-chooser --image-input --count=3` ran and failed intentionally under current local config because provider keys are blank.
- Code-review pass completed; no code changes made.
- Document-code pass completed; `CHANGELOG.md` updated.

## Ticket 9 - Compliance Validation Schema

Priority: P1
Phase: LLM Validation Pipeline
Dependencies: Ticket 7

### Goal

Persist validation runs, votes, verdicts, model responses, and audit metadata for compliance guidance.

### Scope

- Add validation run schema.
- Add validation vote schema.
- Include:
  - knowledge article
  - business/user context
  - normalized request JSON
  - model role
  - provider/model
  - vote
  - confidence
  - flags/concerns
  - raw response JSON
  - aggregate decision.
- Add enums/value objects for validation status.

### Acceptance Criteria

- Validation records can be stored and queried by article/user/business.
- Article/admin pages can show validation history later.

### Suggested Tests

- Model relationships.
- Casts/enums.
- Factory states.

### Completion Notes

Completed 2026-07-09.

- Added `validation_runs` schema for article/user/business-scoped compliance validation audits.
- Added `validation_votes` schema for per-model reviewer decisions and raw response metadata.
- Added `ValidationRunStatus` enum for run lifecycle state.
- Added `ValidationDecision` enum for reviewer votes and aggregate decisions.
- Added `ValidationRun` and `ValidationVote` models with JSON, enum, integer, and datetime casts.
- Added validation history relationships on `KnowledgeArticle`, `User`, and `Business`.
- Added factories with pending/running/completed/failed run states and reviewer/final-judge vote states.
- Added feature coverage for model relationships, casts/enums, factory states, and querying validation records by article/user/business.
- Code-review pass completed; no issues found.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/ComplianceValidationSchemaTest.php` passed: 4 tests, 40 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `php artisan test --compact` passed: 231 tests, 932 assertions.

## Ticket 10 - Compliance Validation Pipeline

Priority: P1
Phase: LLM Validation Pipeline
Dependencies: Ticket 7, Ticket 9, Ticket 3

### Goal

Run multi-model validation for high-risk or stale compliance guidance.

### Scope

- Add validator agents/prompts for:
  - factual reviewer
  - contradiction reviewer
  - user-fit reviewer.
- Add final judge agent/prompt.
- Add aggregator service.
- Add deterministic guardrail pass for:
  - legal/tax/payroll certainty
  - unsupported deadlines/rates/thresholds
  - missing disclaimers
  - stale sources.
- Store all votes and aggregate decision.

### Acceptance Criteria

- Pipeline returns a structured status:
  - approved current
  - approved with caveats
  - needs source refresh
  - needs professional review
  - conflicting sources
  - not enough information
  - admin review required.
- High-risk stale content cannot be silently approved.

### Suggested Tests

- All agents faked with `preventStrayPrompts`.
- Approve path.
- Caveat path.
- Conflict path.
- Stale-source block path.
- Guardrail catches unsupported threshold/deadline language.

### Completion Notes

Completed 2026-07-09.

- Added `ValidationPipeline` service that runs factual, contradiction, user-fit, and final judge text roles through the existing `TextRoleGenerator` contract.
- Added structured model response parsing for JSON decisions, confidence, flags, concerns, raw response payloads, provider/model metadata, and parser-failure escalation.
- Added deterministic guardrails for stale or missing sources, high-risk non-fresh content, missing disclaimer language, unsupported deadline/rate/threshold/dollar claims, and overly certain legal/tax/payroll/accounting wording.
- Persisted validation runs, reviewer votes, final judge metadata, aggregate decision, confidence, flags, concerns, context snapshot, and normalized request payloads using the Ticket 9 schema.
- Added conservative aggregation so the highest-risk guardrail or model decision wins; high-risk stale content cannot be approved current even when all model votes approve it.
- Added failure handling that reports exceptions, stores failed runs as `admin_review_required`, and avoids persisting raw exception messages in audit JSON.
- Code-review pass completed; hardened user/business association and failure audit payloads.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/ComplianceValidationPipelineTest.php tests/Feature/ComplianceValidationSchemaTest.php` passed: 10 tests, 72 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.

## Ticket 11 - Validation Admin Review Queue

Priority: P1
Phase: LLM Validation Pipeline / Admin
Dependencies: Ticket 10, Ticket 2

### Goal

Expose validation results and admin review actions.

### Scope

- Add admin review queue for:
  - stale articles
  - failed validations
  - conflicting sources
  - admin review required.
- Show validation votes and final judge decision.
- Allow admin to:
  - approve current content
  - mark stale
  - request revalidation
  - archive article
  - add review notes.

### Acceptance Criteria

- Admin can triage articles that need review.
- Review actions affect article status/freshness visibly.

### Suggested Tests

- Queue listing.
- Approve action.
- Mark stale action.
- Revalidation request action.
- Non-admin forbidden.

### Completion Notes

Completed 2026-07-09.

- Added authenticated admin validation review queue at `admin.knowledge.reviews.index`.
- Queue includes stale articles, failed latest validation runs, conflicting-source latest runs, and admin-review-required latest runs.
- Added admin sidebar entry for Review Queue alongside Knowledge Admin.
- Review rows show article freshness/status, review reasons, validation votes, and final judge decision.
- Added admin actions to approve current content, mark stale, request revalidation, archive, and save review notes.
- Added durable `admin_review_notes`, `admin_reviewed_at`, and `revalidation_requested_at` article fields.
- Approval updates article freshness/status without rewriting validation run audit decisions.
- Existing Knowledge Admin mark-stale action now makes freshness visibly stale; revalidation now stores request timestamp.
- Code-review pass completed; fixed validation audit-trail mutation before handoff.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact` passed.
- `php artisan test --compact tests/Feature/Admin/Knowledge/ValidationReviewQueueTest.php` passed.
- `php artisan test --compact tests/Feature/Admin/Knowledge/ArticleAdminTest.php` passed.
- `php artisan test --compact tests/Feature/ComplianceValidationSchemaTest.php` passed.
- `php artisan route:list --except-vendor --path=admin --no-interaction` showed the new review route.
- `vendor/bin/pint --dirty --format agent` passed.
- `vendor/bin/phpstan analyse --error-format=table` passed.

## Ticket 12 - Advisor Q&A MVP

Priority: P1
Phase: Advisor Q&A
Dependencies: Ticket 7, Ticket 10

### Goal

Build the first Ask Advisor interface that answers from business profile + cached knowledge, with validation when needed.

### Scope

- Add Advisor nav item/routes/UI.
- Add question input and answer display.
- Retrieve:
  - authenticated user's business profile
  - relevant knowledge articles
  - source metadata.
- Run validation if article is stale or high-risk.
- Return structured answer:
  - direct answer
  - checklist
  - caveats
  - confidence
  - source freshness
  - professional review flags.
- Persist session/message history using existing AI conversation tables or app-specific advisor tables.

### Acceptance Criteria

- User can ask a question and receive a source-aware answer.
- Answers are scoped to the user's business.
- High-risk answers display disclaimer, freshness, and professional review language.

### Suggested Tests

- Guest redirect.
- No business profile prompts intake.
- Low-risk answer path.
- High-risk validation path.
- Stale article path.
- Session history persists.

### Completion Notes

Completed 2026-07-09.

- Added authenticated Advisor route/page and sidebar nav item.
- Added Livewire Advisor Q&A UI with profile-required empty state, question input, structured answer display, checklist, caveats, confidence, source freshness, and professional-review flags.
- Added `AdvisorAnswerService` that retrieves the authenticated user's business profile, relevant non-archived knowledge articles, source metadata, recent Advisor history, and runs the existing validation pipeline for stale, missing-source, or high-risk article context.
- Persisted Advisor session and message history through the existing Laravel AI conversation tables via app models for `agent_conversations` and `agent_conversation_messages`.
- Answers are scoped to the authenticated user's business context and message history is explicitly user-scoped.
- Code-review pass completed; fixed public Livewire `conversationId` history scoping before handoff.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/AdvisorTest.php` passed: 7 tests, 22 assertions.
- `php artisan test --compact` passed: 251 tests, 1018 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.
- `php artisan route:list --except-vendor --path=advisor --no-interaction` showed the new Advisor route.
- `npm run build` was attempted but blocked by the existing local Windows/WSL Node native-binding mismatch (`@rolldown/binding-win32-x64-msvc` missing from the current `node_modules` install); no dependency reinstall was performed.

## Ticket 13 - Advisor Q&A Hardening

Priority: P2
Phase: Advisor Q&A / Beta hardening
Dependencies: Ticket 12

### Goal

Make Advisor Q&A safe enough for beta usage.

### Scope

- Add refusal/escalation behavior for insufficient facts.
- Add "ask follow-up question" behavior only when necessary.
- Add rate limiting or quota guardrails.
- Add user feedback/report answer action.
- Add source/citation UI polish.
- Add answer history page.

### Acceptance Criteria

- Advisor does not invent local requirements, filing deadlines, rates, or thresholds.
- User can flag a bad answer.
- High-cost actions have limits.

### Suggested Tests

- Insufficient facts.
- Unsupported deadline/rate claim blocked.
- Feedback submission.
- Rate limit/quota path.

### Completion Notes

Completed 2026-07-09.

- Added deterministic Advisor answer hardening for insufficient profile facts, unsupported filing deadline/rate/threshold/dollar/local claims, and validation escalation decisions.
- Added necessary follow-up prompts for under-specified sales-tax, owner-pay/legal-structure, and local permit/license questions.
- Added per-user Advisor quota guardrail of 6 questions per hour before high-cost answer generation.
- Added user-facing answer flagging stored in assistant message metadata.
- Polished Advisor source citations with freshness, risk, source count, and review dates.
- Added authenticated Advisor history page at `/advisor/history`, scoped to the current user's assistant answers.
- Added Advisor history link and active sidebar state for Advisor subpages.
- Added feature coverage for insufficient facts, unsupported deadline/rate claims, feedback submission, quota blocking, and history scoping.
- Code-review pass completed; no issues found.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/AdvisorTest.php` passed: 12 tests, 41 assertions.
- `php artisan test --compact` passed: 268 tests, 1085 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.
- `php artisan route:list --except-vendor --path=advisor --no-interaction` showed `advisor` and `advisor.history`.
- `npm run build` > build
> vite build

vite v8.1.3 building client environment for production...
✓ 3 modules transformed.
Succesful.

## Ticket 14 - Owner Pay Module

Priority: P2
Phase: Owner Operations
Dependencies: Ticket 1, Ticket 7 optional

### Goal

Turn owner-pay article/roadmap content into a guided decision module.

### Scope

- Add route/page for owner pay.
- Show comparison for:
  - owner draws
  - distributions
  - retained earnings/profit
  - guaranteed payments
  - W-2 salary
  - dividends
  - reimbursements/accountable plan.
- Tailor options to legal/tax structure.
- Add "questions for CPA" section.
- Link from roadmap item.
- Optionally use Advisor validation for generated summaries.

### Acceptance Criteria

- User sees only relevant owner-pay methods for their profile, plus caveats.
- Undecided entity status prompts profile clarification/professional review.

### Suggested Tests

- Sole proprietor options.
- Partnership options.
- S corp options.
- C corp options.
- Unknown structure warning.

### Completion Notes

Completed 2026-07-09.

- Added authenticated `owner-pay` route and single-action `OwnerPayController`.
- Added `OwnerPayGuide` service with `OwnerPayAdvice`/`OwnerPayOption` value objects and `OwnerPayMethod`/`OwnerPayFit` enums; static v1 rules sourced from the seeded owner-pay knowledge articles (no AI generation, so the optional Advisor validation was not needed).
- Guide compares owner draws, distributions, guaranteed payments, W-2 salary, dividends, retained earnings, and accountable-plan reimbursements, tailored by legal structure; LLCs branch on owner count (single-member as sole proprietor with an election question, multi-member as partnership).
- Page shows methods that fit with caveat lists, dimmed "not options for your setup" with reasons, a "Questions for your CPA" section per structure, and related knowledge article links.
- Undecided entity status (not started/unsure) shows a warning callout prompting profile clarification with an intake link and professional review, plus a neutral method overview.
- Added optional `href`/`hrefLabel` to `RoadmapItem`; the owner-pay roadmap item now links to the guide.
- Added owner-pay coverage to the browser smoke test and rebuilt Vite assets for the new page classes.
- Code-review pass completed; added a direct profile-update link to the undecided-structure callout.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/OwnerPayTest.php` passed: 11 tests, 45 assertions.
- Full `php artisan test --compact` passed: 249 tests, 1012 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --format agent` passed on changed files.
- `php artisan route:list --except-vendor` shows the `owner-pay` route.
- `npm run build` passed.

## Ticket 15 - Banking Setup Module

Priority: P2
Phase: Owner Operations
Dependencies: Ticket 1

### Goal

Turn banking article/risk-flag content into a practical setup checklist.

### Scope

- Add route/page for banking setup.
- Show:
  - dedicated checking
  - tax reserve
  - sales tax reserve when applicable
  - payroll reserve when applicable
  - separate card
  - merchant services/payment processor
  - what to bring to the bank by entity/profile.
- Link from dashboard risk flags and roadmap.

### Acceptance Criteria

- Checklist adapts to entity, EIN, DBA, sales-tax, payroll, and bank-account status.
- User can mark banking checklist items done if task system is available.

### Suggested Tests

- No EIN/business bank path.
- DBA path.
- LLC path.
- Sales-tax reserve visibility.
- Payroll reserve visibility.

### Completion Notes

Completed 2026-07-09.

- Added authenticated `banking-setup` route and `BankingSetupController` with a profile-scoped PATCH endpoint for checklist completion.
- Added `BankingSetupGuide` with `BankingSetupAdvice`, `BankingChecklistItem`, and `BankingDocumentItem` value objects.
- Banking checklist covers dedicated checking, tax reserve, separate business card, merchant services, and conditionally shows sales-tax and payroll reserve items based on profile answers.
- Dedicated checking completion updates `businesses.has_business_bank`; non-core banking checklist completion is stored in `business_profiles` under `banking_setup.*` keys.
- Bank-visit document guidance adapts to EIN, DBA/assumed-name, LLC/partnership/formal-entity, sales-tax, and payroll profile state.
- Dashboard risk flags for banking-adjacent issues link to the banking checklist; banking roadmap items now link to the banking setup page.
- Added related knowledge article links and the standard advisory disclaimer.
- Code-review pass completed; no unresolved issues found.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/BankingSetupTest.php` passed: 10 tests, 39 assertions.
- `php artisan test --compact tests/Feature/BankingSetupTest.php tests/Feature/DashboardTest.php tests/Feature/RoadmapTest.php tests/Feature/OwnerPayTest.php` passed: 32 tests, 111 assertions.
- Full `php artisan test --compact` passed: 278 tests, 1124 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.
- `php artisan route:list --except-vendor --path=banking-setup --no-interaction` shows the GET and PATCH banking setup routes.
- `npm run build` could not run in this WSL session because only Windows Node/npm are on PATH; Windows npm rejects the UNC working directory, and Windows Node cannot load the Linux-installed Rolldown optional binding from `node_modules`.

## Ticket 16 - Brand Kit Data Model And Generator

Priority: P2
Phase: Branding and Advertising
Dependencies: Ticket 7

### Goal

Create the data model and generator service for V1 brand kits.

### Scope

- Add brand kit schema/model.
- Generate structured output:
  - business name ideas
  - tagline options
  - positioning
  - tone/voice
  - color palette
  - font notes
  - logo/image prompt pack
  - social bios.
- Use `docs/sample-static-site` as visual/content reference.
- Apply avoid-AI-writing guardrail for marketing prose.

### Acceptance Criteria

- User can generate and persist a brand kit.
- Output is structured and regenerable.
- Provider calls are test-fakeable.

### Suggested Tests

- Brand kit generation with fake provider.
- Persistence.
- User scoping.
- Regeneration creates new version or updates intentionally.

### Completion Notes

Completed 2026-07-09.

- Added versioned `brand_kits` schema/model/factory scoped to user and business, with a unique business/version constraint and `brandKits()` relationships on `User` and `Business`.
- Added `BrandKitGenerator` service that generates structured output through the existing `brand_copy` text role: name ideas, tagline options, positioning, tone/voice, color palette, font notes, logo/image prompt pack, and social bios.
- The generation prompt uses `docs/sample-static-site` as the visual/content style reference (palette, warm/grounded visual direction, plainspoken copy direction) and grounds ideas in the business profile without inventing credentials or claims.
- The `brand_copy` role's human-voice / avoid-AI-writing guardrail applies automatically via `TextRoleManager` for marketing prose.
- Regeneration is intentional versioning: each generate call persists a new version and keeps earlier versions.
- Model responses are sanitized into safe structured values (string lists, validated hex color entries, platform/bio pairs); unstructured non-JSON responses throw `BrandKitGenerationException` without persisting anything.
- Provider/model/config-version metadata and the decoded raw response are stored on each kit for audit and later UI display.
- No UI in this ticket (Ticket 17); design/ideas skills were not needed.
- Repaired a pre-existing dev-DB migration tracking gap: `validation_votes` existed with the correct schema and zero rows but was unrecorded in `migrations`; recorded it as run, then migrated `brand_kits`.
- Code-review pass completed; mirrored the `version` column default in the model's `$attributes` per existing `ValidationRun` convention.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/BrandKitGeneratorTest.php` passed: 6 tests, 32 assertions covering fake-provider generation, fenced-JSON parsing, persistence, user/business scoping, regeneration versioning, unstructured-response failure, and malformed-section coercion.
- Full `php artisan test --compact` passed: 284 tests, 1156 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.
- `php artisan migrate --no-interaction` created `brand_kits` on the dev MariaDB.

### Adjustment Notes

Adjusted 2026-07-09 per `.agents/skills/brand-kit`.

- Color palette entries now carry `role` (background/foreground/primary/surface/border/signal/accent) and `prominence` (`dominant` for the 2-3 load-bearing colors, `supporting` for occasional accents); the sanitizer coerces invalid prominence values to `supporting` and older kits without the fields keep rendering.
- Typography guidance is now framed as typeface directions covering display/headline, body, and UI/label choices, with an explicit instruction not to default to Inter, Roboto, Arial, or system fonts.
- Added nullable `brand_kits.brand_board_prompt`: one complete production-ready image-generation prompt for a single 3840 x 2160 16:9 brand board (homepage mockup left 40%, supporting page middle 40%, typography-and-color design rail right 20%, dominant colors as large swatches and supporting accents as chips), regenerable as its own section.
- The full-kit generation prompt is now built from the shared `BrandKitGenerator::Sections` spec map so full generation and per-section regeneration cannot drift.
- Verification after adjustment: `php artisan test --compact tests/Feature/BrandKitGeneratorTest.php` passed (7 tests, 35 assertions); full suite passed (303 tests, 1216 assertions); PHPStan and Pint passed; migration applied to dev MariaDB.

## Ticket 17 - Brand Kit UI

Priority: P2
Phase: Branding and Advertising
Dependencies: Ticket 16

### Goal

Build a usable brand kit review/edit/save UI.

### Scope

- Add Branding nav/route/page.
- Show generated names, taglines, voice, palette, social bios, and prompts.
- Allow selecting preferred name/tagline/palette.
- Allow regenerate sections.
- Add export/copy affordances if simple.

### Acceptance Criteria

- User can generate, review, save, and revisit a brand kit.
- Empty/loading/error states are polished.

### Suggested Tests

- Page renders.
- Generate action persists kit.
- Select/save preferred options.
- User cannot view another user's kit.

### Completion Notes

Completed 2026-07-09.

- Added authenticated `branding` route, `pages.branding` view, Branding sidebar nav item, and `App\Livewire\Branding\Index` Livewire component.
- Page shows all generated sections: name ideas, tagline options, positioning, tone and voice, color palette swatches, font notes, logo/image prompts, and social bios.
- Added tap-to-select preferred name, tagline, and primary palette color; picks toggle on/off, save automatically to a new nullable `brand_kits.preferences` JSON column, and surface in a "Your picks" summary strip.
- Added per-section regeneration: `BrandKitGenerator::regenerateSection()` regenerates one section in place (same version), sanitizes it with the Ticket 16 coercers, refuses empty/unstructured model output without saving, and prunes saved picks that no longer exist in the regenerated options.
- "New version" action generates a whole new kit version; a version switcher lets the user revisit earlier versions, satisfying generate/review/save/revisit.
- Added copy-to-clipboard affordances (with "Copied" feedback) for image prompts and social bios.
- Empty/loading/error states: intake prompt when no profile, dashed empty state with AI-cost hint before the first kit, wire:loading button/dim states, a danger callout when generation fails (nothing persisted), and per-section fallbacks when a section came back unusable.
- UI work followed the `.agents/skills/design` guideline system (surfaces, typography, buttons, form controls, dark mode, responsive rules) on top of the app's existing Flux/zinc conventions; the ui.sh picker from `.agents/skills/ideas` was not used because the page follows the app's established design system with no competing visual directions to compare — a picker round can be run on request.
- Code-review pass completed; added missing empty-state fallbacks for tone/voice, font notes, image prompts, and social bios sections.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/BrandingUiTest.php` passed: 10 tests, 31 assertions covering guest redirect, no-profile prompt, empty state, generate persistence, failure error state, preference save/toggle, section regeneration with pick pruning, new-version regeneration, and cross-user scoping.
- `php artisan test --compact tests/Feature/BrandingUiTest.php tests/Feature/BrandKitGeneratorTest.php` passed after review fixes: 16 tests, 63 assertions.
- Full `php artisan test --compact` passed: 294 tests, 1187 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.
- `php artisan migrate --no-interaction` applied the preferences migration on the dev MariaDB.
- `php artisan route:list --except-vendor --path=branding --no-interaction` shows the branding route.
- `npm run build` passed (run inside WSL via an nvm-sourced script).

### Adjustment Notes

Adjusted 2026-07-09 per `.agents/skills/brand-kit` and the Flux Pro license addition.

- Added `flux_ui_kit` setting in `config/flux-ui.php` with `FluxUiKit` enum: values `flux-free`/`flux-pro`, defaulting to `flux-free` unless a licensed `livewire/flux-pro` install is detected via Composer; `FLUX_UI_KIT` pins it explicitly. Unknown values fall back to `flux-free` so FOSS installs always render.
- Split the Branding page into shared section partials (`resources/views/livewire/branding/partials/`); with `flux-pro` the page renders a Flux Pro tabbed layout (Identity / Design system / Assets), and with `flux-free` the same partials render in the original stacked layout.
- Palette now renders as a design-system rail: dominant colors as large selectable swatches with role labels, supporting accents as smaller selectable chips; kits without prominence metadata render in a single equal grid.
- Added a Brand board prompt card with copy affordance, scrollable prompt well, per-section regenerate, and empty fallback.
- "Font notes" heading renamed to "Typography" to match the typeface-direction framing.
- Decision (2026-07-09): `livewire/flux-pro` intentionally stays in `composer.json` during development; the README documents that FOSS installs without a license must remove it (`composer remove livewire/flux-pro`), and the standing UI rule requires every Pro component usage to ship a flux-free fallback.
- Verification after adjustment: `php artisan test --compact tests/Feature/BrandingUiTest.php tests/Feature/FluxUiKitTest.php tests/Feature/BrandKitGeneratorTest.php` passed (25 tests, 92 assertions); full suite passed (303 tests, 1216 assertions); PHPStan and Pint passed; `npm run build` passed.

## Ticket 18 - Advertising Generator

Priority: P2
Phase: Branding and Advertising
Dependencies: Ticket 7, Ticket 16 preferred

### Goal

Create ad copy and campaign prompt generation using business profile and brand kit context.

### Scope

- Add advertising schema/model if persistence is needed.
- Generate:
  - ad angles
  - Facebook/Instagram copy
  - Google ad text concepts
  - social posts
  - flyer copy
  - image prompt variants
  - landing page outline
  - first 30 days marketing plan.
- Reuse brand kit context where available.
- Optionally hand image prompts to Photo Studio later, but do not couple them tightly in V1.

### Acceptance Criteria

- User can generate and save starter ad outputs.
- Output is scoped to business profile and brand kit.
- Generated copy follows human-voice guardrails.

### Suggested Tests

- Generation with fake provider.
- Brand kit context included when present.
- Persistence and user scoping.

### Completion Notes

Completed 2026-07-09.

- Added versioned `advertising_kits` schema/model/factory scoped to user and business, with a unique business/version constraint, `advertisingKits()` relationships on `User` and `Business`, and a nullable `brand_kit_id` reference (kept with a nulled reference if the brand kit is later deleted).
- Added `AdvertisingKitGenerator` service that generates structured output through the existing `ad_copy` text role: ad angles, Facebook/Instagram ad copy (headline/body/cta), Google ad concepts (headline/description), organic social posts, flyer copy (headline/subheadline/bullets/call to action), ad image prompt variants, a landing page outline, and a first-30-days week-by-week marketing plan.
- The `ad_copy` role's human-voice / avoid-AI-writing guardrail applies automatically via `TextRoleManager`; the prompt forbids invented discounts, prices, credentials, or review counts.
- Brand kit context is reused when available: the latest brand kit's preferences, name ideas, taglines, positioning, tone/voice, and palette are passed as generation context, the prompt directs consistency with them, and the kit records which brand kit version grounded it. Without a brand kit, generation still works with neutral naming.
- Image prompts are surfaced with copy buttons and a "paste into an image generator or a Photo Studio project" hint - no tight Photo Studio coupling, per the ticket.
- Model responses are sanitized into safe structured values; unstructured non-JSON responses and structured responses with no usable sections both throw `AdvertisingKitGenerationException` without persisting anything.
- Regeneration is intentional versioning: each generate call persists a new version, keeps earlier versions, and a version switcher lets the user revisit them.
- Added authenticated `advertising` route, `pages.advertising` view, Advertising sidebar nav item (megaphone icon), and `App\Livewire\Advertising\Index` Livewire component.
- Empty/loading/error states: intake prompt when no profile, dashed empty state with AI-cost hint plus a "generate a brand kit first" link when none exists, wire:loading button/dim states, a danger callout when generation fails (nothing persisted), and per-section fallbacks when a section came back unusable.
- UI work followed the `.agents/skills/design` guideline system (surfaces, dividers, dark mode, responsive text sizing, copywriting, list roles) on top of the app's existing Flux/zinc conventions using flux-free components only, so no `flux_ui_kit` gating was needed; the ui.sh picker from `.agents/skills/ideas` was not used because the page mirrors the established Branding page design system with no competing visual directions - a picker round can be run on request.
- Code-review pass completed; added an all-sections-empty guard so a structured-but-unusable model response fails without burning a kit version.
- Document-code pass completed; `CHANGELOG.md` updated.
- Note: a pre-existing uncommitted local change to `config/text-generation.php` (default model `openrouter/auto` -> `deepseek/deepseek-v4-pro`) was found in the working tree and intentionally left as-is; it is not part of this ticket.

Verification:

- `php artisan test --compact tests/Feature/AdvertisingGeneratorTest.php tests/Feature/AdvertisingUiTest.php` passed: 18 tests, 72 assertions covering fake-provider generation, brand-kit context inclusion and recording, persistence, user/business scoping, versioning, unstructured-response failure, empty-kit failure, malformed-section coercion, brand-kit deletion behavior, guest redirect, no-profile prompt, empty states with and without a brand kit, generate persistence, failure error state, new-version switching, empty-section fallbacks, and cross-user scoping.
- Full `php artisan test --compact` passed: 321 tests, 1288 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.
- `php artisan migrate --no-interaction` created `advertising_kits` on the dev MariaDB.
- `php artisan route:list --except-vendor --path=advertising --no-interaction` shows the advertising route.
- `npm run build` passed (run inside WSL via an nvm-sourced script).

## Ticket 19 - Navigation And Information Architecture Pass

Priority: P2
Phase: Beta hardening
Dependencies: Tickets 6, 12, 14, 15, 17, 18

### Goal

Make the expanded V1 app navigable without turning the sidebar into clutter.

### Scope

- Revisit sidebar grouping.
- Decide final V1 nav labels:
  - Dashboard
  - Company Profile
  - Roadmap
  - Tasks
  - Advisor
  - Knowledge
  - Projects
  - Branding
  - Advertising
  - Settings.
- Add cross-links from dashboard/roadmap/risk flags into modules.
- Ensure mobile nav remains usable.

### Acceptance Criteria

- Major V1 workflows are discoverable.
- Sidebar remains scannable on desktop and mobile.
- No broken route links.

### Suggested Tests

- Route/link smoke tests.
- Browser smoke once frontend build works.

### Completion Notes

Completed 2026-07-09.

- Regrouped the sidebar from a single "Platform" list into three scannable groups in the final V1 order: Overview (Dashboard, Company Profile), Guidance (Roadmap, Tasks, Advisor, Knowledge), and Marketing (Projects, Branding, Advertising). The Admin group is unchanged.
- Settings keeps its existing home in the user menu (desktop sidebar profile dropdown and mobile header dropdown) rather than becoming a tenth sidebar item; all ten final V1 nav labels from the ticket are present.
- Owner Pay and Banking Setup intentionally stay out of the sidebar (they are not in the ticket's final label list) and remain reachable through roadmap, dashboard, and risk-flag cross-links.
- Added `RiskFlag::actionUrl()` / `actionLabel()` so every dashboard risk flag links to the module that resolves it: banking-adjacent flags keep the banking checklist link, no-bookkeeping links to accounting knowledge, unclear structure links to the company profile, and operating-without-entity links to formation knowledge. Added an "Ask the Advisor" hint under the risk flag list.
- Added roadmap cross-links into modules: name/brand/social items link to Branding, the 30-day marketing plan links to Advertising, the compliance rhythm links to Tasks, licenses/permits links to the Advisor, and legal structure, sales tax, franchise tax, bookkeeping, payroll, contractor, and professional support items link to category-filtered Knowledge views.
- Dashboard next actions now render each roadmap item's module link under the title.
- Mobile nav is unchanged structurally (`flux:sidebar collapsible="mobile"` plus the mobile header user menu); the new groups render inside the same collapsible sidebar.
- UI work followed the `.agents/skills/design` guideline system (navigation, copywriting, interactivity, flexbox rules) using only the flux-free sidebar components already in place, so no `flux_ui_kit` gating was needed; the ui.sh picker from `.agents/skills/ideas` was not used because the regrouping follows the ticket's prescribed labels/order with no competing visual directions - a picker round can be run on request.
- Code-review pass completed; aligned `RiskFlag::actionLabel()` string style with the enum's existing untranslated `label()`/`description()` convention.
- Document-code pass completed; `CHANGELOG.md` updated.

Verification:

- `php artisan test --compact tests/Feature/NavigationTest.php tests/Feature/DashboardTest.php tests/Feature/RoadmapTest.php tests/Feature/BankingSetupTest.php tests/Feature/BrowserSmokeTest.php` passed: 35 tests, 143 assertions.
- Full `php artisan test --compact` passed: 332 tests, 1348 assertions.
- `vendor/bin/phpstan analyse --error-format=table` passed.
- `vendor/bin/pint --dirty --format agent` passed.
- `npm run build` passed (run inside WSL via an nvm-sourced script).
- New `tests/Feature/NavigationTest.php` covers sidebar labels/order, per-link route resolution, page loads for every sidebar destination, admin-only nav visibility, and roadmap/dashboard cross-links.

## Ticket 20 - Beta UX Hardening

Priority: P2
Phase: Beta hardening
Dependencies: Major feature tickets complete

### Goal

Polish user-facing states across V1 workflows.

### Scope

- Audit empty/loading/error/success states across:
  - dashboard
  - intake
  - roadmap
  - tasks
  - advisor
  - knowledge
  - projects
  - branding
  - advertising.
- Add user feedback/report issue action.
- Add stale-answer warning components.
- Add cost/AI action warnings where appropriate.
- Add responsive QA pass.
- Audit every Flux Pro component usage for a working flux-free fallback per the standing UI rule, in both kits' rendered output.

### Acceptance Criteria

- No major workflow has a dead empty state.
- Errors give a next action.
- AI actions communicate processing state and cost/risk where appropriate.

### Suggested Tests

- Feature tests for key empty/error states.
- Browser smoke/manual responsive checklist.

### Completion Notes

Completed 2026-07-10.

- Audited the established empty, loading, error, success, and next-action states across the dashboard, intake, roadmap, tasks, Advisor, knowledge, projects, Branding, and Advertising flows; existing workflow-specific states remain in place.
- Added authenticated user feedback reporting for product issues, guidance/source feedback, feature ideas, and other beta feedback. The action is reachable from desktop and mobile user menus and stores the submitting user, category, originating page, and message.
- Added a prominent Advisor callout when an answer relies on stale or missing-source knowledge, directing the user to verify against an official source or qualified professional before acting.
- Added a pre-submit Photo Studio notice that generation starts paid AI work, produces up to three images, and is bounded by the configured per-image estimate before provider billing variance.
- Added a 320 px / 768 px / desktop, light/dark manual QA checklist to `docs/deployment-runtime.md`; direct interactive viewport automation was unavailable in this workspace, so the checklist is the release gate for a browser-equipped beta environment.
- Audited Flux UI kit usage: Branding's gated tab layout remains the only Flux Pro path, and expanded coverage confirms both kits render the same Brand Kit sections through the Flux Pro tabs and flux-free stacked fallback.

Verification:

- `php artisan test --compact tests/Feature/UserFeedbackTest.php tests/Feature/DeploymentRuntimeTest.php tests/Feature/AdvisorTest.php tests/Feature/BrandingUiTest.php tests/Feature/BrowserSmokeTest.php tests/Feature/PhotoGenerationPipelineTest.php` passed: 51 tests, 187 assertions.
- `php artisan config:show queue.connections.database --no-interaction` and `php artisan config:show queue.connections.redis --no-interaction` confirmed 390-second retry windows.
- `composer test` passed: Pint, PHPStan, and 339 Pest tests with 1,387 assertions.
- `npm run build` passed using the Linux Node 24.15.0 runtime.

## Ticket 21 - Deployment And Runtime Notes

Priority: P2
Phase: Beta hardening
Dependencies: Ticket 8, Ticket 20

### Goal

Document the runtime requirements needed for beta.

### Scope

- Queue worker settings:
  - timeout greater than generation HTTP timeout
  - retry/backoff expectations
  - scheduler enabled.
- Node/Sharp runtime requirement.
- S3-compatible bucket requirements:
  - signed URL support
  - `AWS_URL` fallback
  - path-style setting.
- Provider key requirements.
- Recommended verification checklist before deploy.

### Acceptance Criteria

- A deployer can configure queues, scheduler, storage, Node/Sharp, and provider keys without reading code.
- Real Photo Studio E2E checklist is included.

### Suggested Tests

- Documentation review.
- Manual production-like smoke checklist.

### Completion Notes

Completed 2026-07-10.

- Added `docs/deployment-runtime.md` with environment, provider-key, Node/Sharp, private S3-compatible storage, signed URL / `AWS_URL` fallback, queue-worker, retry, scheduler, and real Photo Studio E2E instructions.
- Documented a safe worker contract for the 300-second image-provider request: a 330-second job timeout, a supervised 360-second worker timeout, and 390-second database/Redis/Beanstalkd retry windows. SQS visibility must also exceed the worker timeout.
- Added 3-attempt, 10/60-second generation-job retry behavior and set queue retry-window defaults to 390 seconds.
- Documented scheduler operation for daily `photos:prune-originals`, including the required cron entry and safe dry-run check.
- Linked the guide from the README.

Verification:

- `php artisan migrate --no-interaction` applied the feedback table migration on the dev MariaDB.
- `php artisan schedule:list --no-interaction` shows the daily `photos:prune-originals` command.
- `php artisan route:list --except-vendor --path=feedback --no-interaction` shows the authenticated feedback GET and POST routes.
- `composer test` passed: Pint, PHPStan, and 339 Pest tests with 1,387 assertions.
- `npm run build` passed using the Linux Node 24.15.0 runtime.

## Suggested Execution Order

1. Ticket 0 - Baseline Verification Cleanup
2. Ticket 1 - Knowledge Article Product Pages
3. Ticket 2 - Knowledge Admin CRUD
4. Ticket 3 - Stale Knowledge UX
5. Ticket 4 - Recurring Task Schema And Templates
6. Ticket 5 - Recurring Task Generator
7. Ticket 6 - Recurring Task UI
8. Ticket 7 - Text AI Provider Roles
9. Ticket 8 - Real Photo Studio E2E Hardening
10. Ticket 9 - Compliance Validation Schema
11. Ticket 10 - Compliance Validation Pipeline
12. Ticket 11 - Validation Admin Review Queue
13. Ticket 12 - Advisor Q&A MVP
14. Ticket 13 - Advisor Q&A Hardening
15. Ticket 14 - Owner Pay Module
16. Ticket 15 - Banking Setup Module
17. Ticket 16 - Brand Kit Data Model And Generator
18. Ticket 17 - Brand Kit UI
19. Ticket 18 - Advertising Generator
20. Ticket 19 - Navigation And Information Architecture Pass
21. Ticket 20 - Beta UX Hardening
22. Ticket 21 - Deployment And Runtime Notes

## Parallelization Notes

- Tickets 1-3 can run before or alongside Tickets 4-6, as long as navigation changes are coordinated.
- Ticket 7 should happen before any text-generation feature work.
- Ticket 8 should happen after Ticket 7 and before Ticket 9.
- Tickets 14 and 15 can run after Ticket 1 and do not need to wait for Advisor Q&A.
- Tickets 16-18 should wait for Ticket 7.
- Tickets 19-21 should be near the end, after most user-facing modules exist.

## Status Notes

See individual ticket Completion Notes sections above.
