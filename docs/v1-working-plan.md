# Mentrovia V1 Working Plan

> Historical implementation snapshot. For current feature reality, client-journey findings, and delivery order, use [Feature Audit and Delivery Roadmap](feature-audit-roadmap.md).

Date: 2026-07-08
Scope: comprehensive documentation and scan pass. No application code changes in this pass.

## Current Read

The codebase is now substantially beyond the previous snapshot. The photo pipeline/Projects pass has landed: routes, controllers, Livewire UI, models, policies, migrations, provider registration, image gateways, chooser/arbiter, jobs, derivative worker, env placeholders, and test coverage are all present.

The core V1 business-advisor product is still incomplete in the same broad areas as before: recurring tasks, compliance-text LLM validation, Advisor Q&A, admin knowledge workflows, and text-based branding/advertising generation. The photo studio is a strong Phase 7-adjacent branch, but it does not replace the original brand/ad text generator work.

Git status was clean at the start of this scan.

## UI Kit Policy

Added 2026-07-09. `livewire/flux-pro` stays in `composer.json` during development, but Mentrovia is open source and must remain fully usable on the free edition of Flux UI:

- Every view that uses a Flux Pro component must ship an equivalent flux-free fallback in the same change, gated by the `flux_ui_kit` setting (`config/flux-ui.php`, `App\Enums\FluxUiKit`).
- `flux_ui_kit` defaults to `flux-free` and auto-switches to `flux-pro` when a licensed install is detected; `FLUX_UI_KIT` pins it.
- FOSS installs without a license remove the Pro package (`composer remove livewire/flux-pro`); the README documents this.
- UI tests for Pro-enhanced screens must cover both kits by setting `config(['flux-ui.kit' => ...])`.

## Verification Snapshot

| Check | Result | Notes |
| --- | --- | --- |
| `php artisan test` | Pass | 167 tests, 703 assertions. |
| `vendor/bin/phpstan analyse --error-format=table` | Pass | 0 errors. |
| `composer test` | Fails at Pint | Stops before tests because existing auth/settings tests need `single_blank_line_at_eof` formatting. |
| `php artisan route:list --except-vendor` | Pass | 10 app routes: home, dashboard, intake, roadmap, projects index/show, settings pages. |
| `php artisan photos:image-chooser --image-input --count=3` | Fails locally | No profiled model satisfies current real config/key/threshold state; tests cover chooser behavior with fake config/keys. |
| `npm run build` | Not verified | WSL resolves `npm` to Windows Node, which fails on UNC path/native Rolldown binding. Needs a Linux Node runtime or refreshed install under the runtime used for builds. |
| Static debug scan | Mostly clean | Only expected CLI `console.log(JSON.stringify(...))` in the Sharp worker; no React/TypeScript surface found. |

## Completed

### Foundation / Auth / Layout

- Laravel 13 app with Fortify auth, email verification, password confirmation, password reset, profile update, password update, 2FA, and account deletion tests.
- App layout, sidebar navigation, Flux UI settings pages, mobile/desktop user menus.
- Stream A controller conversion is in place:
  - `DashboardController`
  - `RoadmapController`
  - `Business\IntakeController`
  - settings controllers
  - Blade page wrappers that embed Livewire only where needed.
- Route names used by navigation and redirects are stable.

### Business Intake / Profile

- `businesses` and `business_profiles` schema exists.
- Business intake is a 5-step Livewire wizard with Texas-only guardrail.
- Intake persists structured profile fields for stage, entity, location, taxable sales, EIN, banking, bookkeeping, payroll, revenue ranges, first sale/employee dates, and confidence.
- `StageClassifier` deterministically maps users into the four V1 stages.
- Business model casts core intake fields to enums and exposes helper methods such as `displayName()`, `isOperating()`, and `mayHaveTaxableSales()`.
- Tests cover guest redirect, wizard render, step validation, Texas guardrail, save/update, hydration, and stage classification.

### Dashboard / Roadmap / Risk Flags

- Dashboard shows setup score, risk flags, missing setup items, next actions, and disclaimer language.
- `BusinessHealth` computes current setup score and risk flags without persisting stale derived state.
- `RoadmapBuilder` generates a static but profile-aware roadmap across all planned roadmap phases:
  - Foundation
  - Legal setup
  - Taxes
  - Banking
  - Accounting
  - Payroll
  - Owner pay
  - Branding
  - Advertising
  - Growth readiness
- Roadmap logic reacts to business profile fields for EIN, DBA, formal entity status, sales tax exposure, banking, bookkeeping, employees/payroll, contractors, and filing confidence.
- Tests cover roadmap route access, phase rendering, item status logic, next actions, and priority ordering.

### Compliance Knowledge Cache

- `knowledge_articles` and `knowledge_sources` schema exists.
- `KnowledgeArticle` / `KnowledgeSource` models, factories, enums, and casts exist.
- `KnowledgeArticleSeeder` loads YAML-front-matter markdown articles idempotently.
- 20 Texas-focused seed articles exist, matching the original V1 seed list:
  - starting a Texas business
  - sole proprietor vs LLC
  - DBA/assumed name
  - sales tax permit
  - franchise tax
  - first employee
  - banking separation
  - bookkeeping
  - owner pay variants
  - contractor/1099
  - weekly/monthly/quarterly/yearly task articles
  - brand kit starter
  - first 30 days advertising
  - when to hire professionals
- Tests enforce official HTTPS sources, disclaimers, verification timestamps, idempotence, and no hard-coded percentages/dollar thresholds in articles.

### Photo Projects / AI Photo Studio

- Project/photo schema exists:
  - `projects`
  - `project_user`
  - `photo_generation_batches`
  - `photos`
- Models and factories exist for `Project`, `Photo`, and `PhotoGenerationBatch`.
- `ProjectPolicy` gates view, create, update, share, and delete:
  - owners can view/edit/share/delete
  - read-share users can view
  - write-share users can view/edit/generate/upload but not share/delete.
- `User` has project/shared-project relationships.
- Project routes and controller actions exist:
  - `projects.index`
  - `projects.show`
- Sidebar includes Projects navigation.
- `Livewire\Projects\Index` supports search, pagination, and project creation.
- `Livewire\Projects\Show` supports:
  - multi-upload
  - 25 MB Livewire temporary upload allowance
  - image type/dimension validation
  - optional user notes
  - background derivative processing
  - auto-captioning for uncaptioned uploads
  - uploaded-photo selection
  - generation batch creation
  - top-3 model fan-out
  - polling while batches/photos are processing
  - read/write sharing by email
  - unsharing
  - generated-photo gallery
  - derivative variant selection
  - download URLs
  - deletion of generated image sets
  - retrying derivative processing.
- Photo storage config uses separate `uploaded_` and `generated_` prefixes.
- `.env.example` includes S3 endpoint/url placeholders, provider keys, and photostudio/image chooser config.
- `config/livewire.php` is published and raises temporary upload max to `26624`.
- `sharp` is listed in `package.json`.
- Node/Sharp derivative worker exists at `resources/js/image-processing/create-portfolio-derivatives.mjs`.
- `PhotoDerivativeService` normalizes images, writes derivatives, records metadata, and leaves failed photos retryable.
- `PrunePhotoOriginalsCommand` exists and is scheduled daily.

### Image AI Pipeline

- Custom AI image providers are registered in `AppServiceProvider`:
  - OpenRouter override with cost-aware image gateway
  - Replicate
  - Stability
- `config/ai.php` includes `replicate` and `stability` provider stanzas.
- `config/photostudio.php` includes:
  - storage prefixes
  - provider/model controls
  - processing settings
  - analysis model
  - chooser weights and requirements
  - LLM arbiter settings
  - BYOK external keys
  - image model catalog.
- AI image components exist:
  - `ImageModelCatalog`
  - `ImageModelChooser`
  - `ImageModelArbiter`
  - `ImageModelCandidate`
  - `ImageRequirements`
  - typed exceptions
  - cost-aware response wrapper.
- Vision agents exist:
  - `PhotoBatchAnalyst`
  - `PhotoDescriber`.
- Jobs exist:
  - `RunPhotoGenerationBatch`
  - `GeneratePhotoWithModel`
  - `GeneratePhotoDerivatives`
  - `DescribeUploadedPhoto`.
- CLI debug tool exists:
  - `photos:image-chooser`.
- Tests cover:
  - chooser ranking and hard filters
  - arbiter behavior
  - OpenRouter/Replicate/Stability gateways
  - batch analysis, retry/fallback, mode selection, fan-out
  - generated photo persistence and derivative dispatch
  - upload validation and captioning behavior
  - derivative worker service with fake process
  - generated gallery behavior
  - project creation/search/access
  - project sharing permissions.

## Phase Status

| Phase | Status | Completed | Remaining |
| --- | --- | --- | --- |
| Phase 1 - Foundation | Mostly complete | App, auth, intake, dashboard, controller/Blade routing, static roadmap, seed knowledge, tests. | Fix Pint formatting so `composer test` is green; add browser smoke once Node/build runtime works. |
| Phase 2 - Compliance Knowledge Cache | Partial | Models, sources, seed articles, source metadata, verification timestamps, disclaimer coverage. | CRUD/admin UI, public/article display, stale-source states, validation status display, manual refresh/review workflow. |
| Phase 3 - Personalized Roadmap | Partial | Stage classifier, profile-aware roadmap, risk flags, setup score, trigger logic for sales tax/banking/accounting/payroll/contractors/owner pay. | Roadmap is still static-template driven; no persisted/generated roadmap items, no source freshness on items, no deeper module routing beyond page sections/items. |
| Phase 4 - Recurring Task System | Not started | Weekly/monthly/quarterly/yearly task content exists as seed articles and a roadmap item. | Task templates, business-specific task generation, recurrence rules, dashboard reminders, completion tracking, notes, due dates. |
| Phase 5 - LLM Validation Pipeline | Partial | Laravel AI installed, provider groundwork exists, AI conversation tables exist, image chooser uses an arbiter pattern, text role system exists, compliance validation schema exists, multi-role validation pipeline now stores votes and aggregate decisions with deterministic guardrails. | Admin review queue, validation status display, manual refresh/review workflow, Advisor Q&A validation gate integration. |
| Phase 6 - Advisor Q&A | Not started | AI conversation tables exist. | Ask Advisor UI, profile/article retrieval, validation gate, structured answer generation, session history, source/caveat display. |
| Phase 7 - Branding and Advertising | Partial / split | Seed articles and full AI Photo Studio/Projects pipeline exist. | Text brand kit generator, name/tagline/voice/color/social bio generation, ad copy/social post/landing page generation, brand/ad storage and UI. |
| Phase 8 - Beta Hardening | Partial | Disclaimers, many tests, PHPStan clean, logging in AI/photo jobs, error recording for processing failures. | Full `composer test`, frontend build, browser smoke, user feedback button, stale-answer UX, admin dashboard, deployment/runtime docs, real-provider/bucket E2E. |

## Ticket Status

| Ticket | Status | Notes |
| --- | --- | --- |
| 1 - Business Intake and Profile Schema | Mostly complete | Intake/schema/classification are implemented and tested. |
| 2 - Texas Knowledge Article Cache | Partial | Cache + seeding done; CRUD/admin/public display missing. |
| 3 - Personalized Roadmap Generator | Partial | Profile-aware static roadmap done; deeper dynamic/persistent roadmap not done. |
| 4 - Recurring Task System | Not started | No task tables/services/UI yet. |
| 5 - OpenRouter Validation Pipeline | Partial | Text roles, validation schema, multi-role compliance pipeline, final judge aggregation, stored votes, and deterministic guardrails are implemented; admin review queue and display workflow remain. |
| 6 - Advisor Q&A Interface | Not started | Conversation tables exist only. |
| 7 - Owner Pay Decision Module | Not started as module | Article + roadmap item exist; no decision flow/comparison UI. |
| 8 - Banking Setup Module | Not started as module | Article + roadmap/risk logic exist; no banking checklist flow. |
| 9 - Branding Kit Generator | Not started | Use `docs/sample-static-site` for visual/content examples; reference ChapterEcho text pipeline patterns. |
| 10 - Advertising Generator | Partial | Photo Studio covers image asset generation; ad text/social/landing page generation missing. |

## Remaining Work

### 0. Baseline / Verification Cleanup

1. Run Pint or otherwise fix final blank lines in the listed auth/settings tests so `composer test` can reach PHPStan/Pest.
2. Install or expose a Linux Node runtime in WSL, or reinstall `node_modules` under the runtime used for builds, then rerun `npm run build`.
3. Add a lightweight browser smoke pass once the frontend build works:
   - home
   - dashboard
   - intake
   - roadmap
   - projects index
   - project show
   - settings pages.
4. Keep the current green checks as the floor:
   - `php artisan test`
   - `vendor/bin/phpstan analyse --error-format=table`.

### 1. Real Photo Studio E2E

1. Add real bucket name/credentials in local/private env.
2. Verify signed URLs against the actual S3-compatible endpoint.
3. Verify `AWS_URL` fallback behavior for stores that do not support presigned GETs.
4. Run queue worker with timeout greater than generation HTTP timeout.
5. Run the complete flow with real keys:
   - create project
   - upload captioned photo
   - upload uncaptioned photo
   - derivative generation
   - auto-caption
   - generate top 3
   - gallery variants
   - download
   - share read-only
   - verify read-only restrictions.
6. Decide whether `photos:image-chooser --image-input --count=3` should work without real provider keys or remain a real-config diagnostic.

### 2. Compliance Knowledge Productization

1. Add knowledge article index/detail UI.
2. Add source display and last-verified display wherever content is shown.
3. Add admin CRUD for knowledge articles and sources.
4. Add stale/needs-review state handling.
5. Add manual "mark stale" and "request revalidation" actions.
6. Add tests for source/caveat display and stale article UX.

### 3. Recurring Task System

1. Create task template schema for weekly/monthly/quarterly/yearly tasks.
2. Create generated business task schema.
3. Add recurrence/due-rule model.
4. Generate tasks from business profile:
   - entity type
   - employees/payroll
   - sales tax exposure
   - contractors
   - banking/bookkeeping maturity.
5. Add dashboard reminders and upcoming tasks.
6. Add completion tracking, notes, and history.
7. Add tests for generation, due dates, completion, and profile changes.

### 4. Compliance Text LLM Validation Pipeline

1. Design model/provider roles from ChapterEcho's writing-assist/provider-manager pattern instead of starting from scratch.
2. Keep this distinct from the image chooser:
   - low-cost factual reviewer
   - contradiction reviewer
   - user-fit reviewer
   - final judge.
3. Add validation-run and validation-vote schema.
4. Store prompts, model names, raw structured responses, aggregate verdicts, confidence, and concerns.
5. Add strict structured outputs for validators and judge.
6. Add deterministic guardrail pass for legal/tax/payroll claims.
7. Add cache approval statuses:
   - approved current
   - approved with caveats
   - needs source refresh
   - needs professional review
   - conflicting sources
   - not enough information
   - admin review required.
8. Add tests using Laravel AI agent fakes and `preventStrayPrompts`.

### 5. Advisor Q&A

1. Build Ask Advisor interface.
2. Retrieve the authenticated user's business profile.
3. Retrieve matching knowledge articles and sources.
4. Route high-risk/stale content through validation before answering.
5. Generate structured answer with:
   - direct answer
   - checklist
   - caveats
   - confidence
   - source freshness
   - professional review flags.
6. Persist session/message history.
7. Add refusal/escalation behavior for insufficient facts.
8. Add tests for retrieval, validation gate, answer shape, and user scoping.

### 6. Owner Pay Module

1. Build owner-pay comparison UI for:
   - draws
   - distributions
   - retained earnings/profit
   - guaranteed payments
   - W-2 salary
   - dividends
   - reimbursements/accountable plan.
2. Gate recommendations on entity/tax status.
3. Add CPA review prompts.
4. Link the module from roadmap items.
5. Add tests for entity-specific option visibility and caveats.

### 7. Banking Setup Module

1. Build banking checklist UI.
2. Include "what to bring to the bank" by structure.
3. Add account separation risk warning.
4. Add tax/sales-tax/payroll reserve account suggestions.
5. Link from dashboard risk flags and roadmap.
6. Add tests for profile-based checklist differences.

### 8. Branding Kit Generator

1. Use `docs/sample-static-site` as the visual/content reference for Mentrovia UI/brand examples.
2. Reference ChapterEcho's writing-assist design for text provider selection, fallback, pass orchestration, and output parsing.
3. Use the `avoid-ai-writing.md` guardrail for generated marketing prose.
4. Generate:
   - business name ideas
   - tagline options
   - brand positioning
   - tone/voice guide
   - color palette
   - logo/image prompt pack
   - social bios.
5. Store generated brand kits.
6. Add UI to review/regenerate/save selections.
7. Add tests for provider fakes, persistence, and user scoping.

### 9. Advertising Generator

1. Generate:
   - ad angles
   - Facebook/Instagram copy
   - Google ad text concepts
   - social posts
   - flyer copy
   - image prompt variants
   - landing page outline
   - first 30 days marketing plan.
2. Reuse brand kit context when available.
3. Optionally connect saved prompts to the Photo Studio pipeline.
4. Add tests for structured output and ownership.

### 10. Beta Hardening

1. Add user feedback/report issue action.
2. Add visible stale-answer handling.
3. Add admin review dashboard.
4. Add queue/runtime deployment notes:
   - queue worker timeout
   - scheduler
   - Node/Sharp runtime
   - S3-compatible storage expectations.
5. Add browser smoke tests or manual QA checklist.
6. Add empty/error/loading states across every user-facing workflow.
7. Add rate limiting or quota controls for expensive AI actions.
8. Add cost reporting/guardrails for image and future text generation.
9. Verify accessibility and responsive behavior for Projects UI and future modules.
10. Audit every Flux Pro component usage for a working flux-free fallback per the UI Kit Policy.

## Current Risks / Blockers

- `composer test` is not green until Pint formatting is fixed in existing tests.
- Frontend build is not verified in this WSL environment because Node/npm resolves to Windows tooling and native bindings mismatch.
- Real Photo Studio E2E is blocked on actual bucket credentials/provider keys and queue runtime verification.
- `photos:image-chooser` currently fails under real local config/key state despite tests proving selection logic.
- Compliance guidance remains source-seeded but not LLM-validated or admin-refreshable.
- Advisor Q&A is not built; AI conversation tables alone are only groundwork.
- Recurring task calendar is not built; current task content is educational/static.

## Definition Of Done Delta

Already satisfied:

- A user can create a business profile.
- The app can classify their stage.
- The app can generate a profile-aware roadmap.
- Texas-first cached compliance content exists with source metadata and last-verified dates.
- The app can create photo projects, upload photos, process derivatives, share projects, and run an AI image-generation pipeline in tested/faked conditions.

Still required for V1 beta:

- Generated weekly/monthly/quarterly/yearly tasks.
- Display/browse cached compliance content in-product.
- LLM validation for high-risk compliance text.
- Caveat/source freshness display on generated answers.
- Advisor Q&A.
- Brand kit text generator.
- Advertising text generator.
- Admin stale/revalidation workflow.
- Full `composer test`, frontend build, browser smoke, and real-provider Photo Studio E2E.
