# Feature Audit and Delivery Roadmap

Decision date: 2026-07-14
Last updated: 2026-07-15

This is the current implementation and delivery source of truth. It follows the secure lifecycle audit and the paid-AI/BYOK controls phase. Decisions are timeboxed to 20 wall-clock minutes; running agents do not extend the deadline.

## Executive decision

Mentrovia is usable today as a guided Texas beta. Registration, verification, company intake, source-backed guidance, banking and owner-pay guidance, Advisor history, versioned branding/advertising outputs, and the tested Photo Studio workflow are real.

It is not yet ready to market as a paid business-operations platform. The recurring-work, customer-facing AI resilience, company-scoped authorization, queued-AI isolation, workspace administration, creator-safe user erasure, secure workspace erasure, customer AI trust center, executable-roadmap, first-company onboarding, existing-business profile/versioning, and billing-provider lifecycle blockers are resolved. The primary remaining gaps are vendor/document integrations, output editing and publishing workflows, and real-provider Photo Studio staging sign-off.

## Client journey verdicts

### Brand-new company

Working path: register → verify email → choose “starting a company” → five-step Texas intake with continue/save-and-exit → resume or revision-safe start-over when needed → atomic profile classification/task/roadmap finalization → plan-ready summary → dashboard, roadmap, tasks, guides, Advisor, and AI settings.

Main friction:

- verification success is easy to miss when onboarding immediately takes over;
- each workspace still supports one company profile rather than multiple Businesses; and
- AI controls and the Trust Center are deliberately manager-only, so members must ask an owner/admin to investigate policy or spend.

### Established company

Working path for a workspace without a profile: choose “already running a company” → complete three grouped operating-baseline sections or preview and selectively apply one row from Mentrovia's CSV template → atomic profile classification/task/roadmap finalization → established-company plan-ready handoff. After creation: open the four-section company profile hub → edit one section with safe teammate merge/rebase or, as a manager, preview one-company CSV changes → save one encrypted immutable revision → atomically refresh stage, recurring tasks, and system-managed roadmap guidance.

Main friction:

- no vendor mapping, XLSX/PDF/OCR or multi-row import, document evidence, government verification, or filing history;
- profile history is read-only and does not yet support restore/rollback;
- workspace deletion is available only to the owner through a deliberately high-friction secure confirmation flow; and
- marketing and Advisor outputs have weak edit, approval, export, publishing, and task-conversion handoffs.

## Feature reality

| Area | Current state | Decision |
|---|---|---|
| Auth, onboarding, and profile | Encrypted resumable first-company intake plus a four-section existing-business hub, immutable encrypted profile revisions, optimistic field merge/rebase, and manager-only one-company CSV preview/apply | Keep Phases 6B/6C; defer vendor/document/multi-row integrations and profile restore |
| Dashboard and roadmap | One durable executable plan per business, with 26 synchronized profile-aware items, dependencies, team execution/provenance, assignees, planning targets, notes, HTTPS evidence references, current CTAs, completion controls, and dependency-safe next actions | Keep Phase 6A/6C synchronization; profile changes update system guidance without overwriting manual execution history |
| Recurring tasks | Scheduled rollover plus profile-triggered reconciliation; completed periods are preserved, inapplicable tasks retire, later applicability reactivates history, and stale caller state is rejected through a fresh locked Business | Keep the one-current-task model until evidence supports separate occurrence rows |
| Guides, banking, owner pay, knowledge | Useful sourced guidance and checklist mechanics | Keep; add evidence and workflow completion where valuable |
| Advisor | Grounded answers, limits, sources, validation, history, pinned full-profile context, and current/stale/legacy provenance | Add threads, search/export, and convert-to-task |
| Branding and advertising | Versioned structured generators with profile/brand input provenance, section-aware Brand freshness, and current/stale/legacy guidance | Add editing, approvals, immutable revisions, export/publishing, and performance feedback |
| Projects and Photo Studio | Account-owned projects, private delivery, project sharing, and durable account snapshots for queued paid work | Require real S3/provider/worker staging sign-off; add company/campaign lifecycle and export |
| Paid AI and BYOK | Account-scoped controls/models/budgets/concurrency, isolated key transport, fail-closed entitlement, permanent audit ledger, queued origin isolation, manager Trust Center, usage/routing visibility, export, control-change auditing, key/model preflight, and Stripe-synchronized commercial entitlement | Complete real-provider staging proof |
| Collaboration | Secure project/workspace invitations, administration, switching, removal fallback, ownership transfer, creator-safe user erasure, and durable owner-only workspace deletion are implemented | Stage-test worker recovery, storage verification, and destructive UX before production use |
| Commercial lifecycle | Account-level Laravel Cashier/Stripe billing, 14-day Standard trial, grandfathered beta, owner-only Checkout/portal, signed subscription projection, and portal-hosted invoices | Configure production Stripe prices, portal, webhook, and deployment credentials; stage-test the lifecycle |

## Delivery order

### Phase 3 — Operating loop repair and service resilience (completed)

Goal: make Mentrovia useful every week without requiring profile resubmission.

- [x] Reopen completed recurring tasks for a newly due period while retaining period-specific `TaskCompletion` history.
- [x] Schedule idempotent rollover and recovery.
- [x] Show incomplete overdue work first on Today and the default Tasks view.
- [x] Require an actionable CTA for every dashboard next action; otherwise provide Tasks and Ask Advisor paths.
- [x] Catch paid-AI policy, budget, provider, audit, and unexpected failures in customer-facing AI actions.

Acceptance:

- [x] completing a weekly/monthly task never completes the next period;
- [x] rollover is safe under repeated scheduler runs;
- [x] overdue work is visible without selecting All tasks;
- [x] every Today action can be advanced or resolved; and
- [x] an unavailable AI provider never becomes an unhandled page error.

### Phase 4 — Company account and commercial boundary (completed)

Goal: establish the ownership model before adding paid plans or more integrations.

#### Phase 4A — Account persistence foundation (completed)

- [x] Add accounts, owner/admin/member memberships, provider-neutral entitlements, and an explicit capability gate.
- [x] Provision registration atomically with one personal account, one owner, one 14-day Standard trial, and a current account selection; preserve active beta for legacy workspaces.
- [x] Preserve current single-user data through deterministic `account.id = legacy user.id` backfill.
- [x] Backfill account ownership for businesses, projects, AI controls, credentials, model preferences, and Advisor conversations without changing permanent AI audit rows.
- [x] Enforce one owner per account, fail closed on raw owner deletion, require ownership transfer for shared-account erasure, and snapshot sole-owner workspaces for a durable erasure handoff.
- [x] Fail closed for unknown plans and unsupported capabilities.

#### Phase 4B — Account-scoped authorization and AI isolation (completed)

- [x] Resolve every authenticated request through a membership-validated current account, including Livewire updates, without tenant global scopes.
- [x] Scope business profile, tasks, projects, Advisor conversations, brand/ad outputs, AI settings, credentials, models, budgets, concurrency, and operation audits to the selected account.
- [x] Allow owner/admin to manage AI policy and credentials while members use enabled AI without reading raw credentials.
- [x] Enforce the provider-neutral `HostedAi` capability before any hosted or BYOK settings/model/provider resolution; unknown and inactive entitlements fail closed.
- [x] Snapshot the originating project account on photos, generation batches, and operation leases; queued Photo Studio work never re-resolves a user's later `current_account_id`.
- [x] Revalidate origin membership and lifecycle leases before provider execution; pre-provider denials are terminal, permanently audited, and never classified as ambiguous spend.
- [x] Preserve existing project guests as project-only collaborators; write guests keep project operations but cannot consume workspace paid AI.

#### Phase 4C1 — Workspace administration (completed)

- [x] Add hashed-token, signed, throttled workspace invitations with encrypted queued delivery, normalized email matching, expiry/revocation state, and replay/race-safe acceptance.
- [x] Preserve the signed invitation continuation through registration and email verification for invited new users; existing users retain their personal workspace and switch to the accepted company.
- [x] Enforce the role matrix: owners manage admins/members and transfer ownership; admins invite/remove members only; members use or leave the workspace only.
- [x] Require a current or recently confirmed password for ownership transfer and admin-role changes.
- [x] Switch only to a live membership, regenerate the session, and clear request-scoped current-account state before redirect.
- [x] When current membership is removed, atomically select another membership or provision a personal owner workspace with a 14-day Standard trial.
- [x] Transfer ownership under account/user/membership locks by demoting the old owner to admin and promoting the target in one retried transaction while preserving the one-owner invariant.

#### Phase 4C2 — Creator-safe user erasure (completed)

- [x] Treat business, project, brand/ad kit, and project/workspace invitation creator IDs as nullable attribution; never use them as ownership or cascade-delete boundaries.
- [x] Erase a departing workspace member's or project-only guest's private conversations, contributed photos, initiated batches and derived outputs/storage, received invitations, and project/workspace memberships while preserving surviving company records.
- [x] Keep photo/batch/lease participant references restrictive until bounded cleanup and the private-storage absence barrier complete; cascade conversation messages only with their private conversation.
- [x] Snapshot sole-owned account IDs once when erasure starts, block shared owners pending transfer, and fail closed in durable `workspace_erasure` state until every target has a completed workspace tombstone. Phase 4C2 never directly deletes a workspace.
- [x] Fence Advisor writes for marked users and fence account/project invitation creation, acceptance, and queued delivery when the destination account is actively targeted; rewind finalization to the earliest cleanup phase if an already in-flight private reference commits late.
- [x] Serialize erasure startup and invitation mutations with Account → User → membership/project/invitation lock order; bounded discovery restarts capture ownership changes before any marker, progress, or target write.
- [x] Keep permanent AI operation audits unchanged and undeleted through both user and workspace erasure.

#### Phase 4C3 — Workspace erasure and destructive UI (completed)

- [x] Expose owner-only deletion with exact-name confirmation, current/recent password confirmation, attempt throttling, and clear retention copy.
- [x] Fence the Account first, lock affected Users once in ascending ID order, then revoke memberships, project guests, and invitations; repair current-account selection without deleting users or their other workspaces.
- [x] Revalidate every human synchronous writer inside its transaction with Account → Users → membership/role/capability → resource ordering. Keep project-only guests limited to their locked project grant.
- [x] Fence every queued AI/photo/provider/storage commit against its durable origin Account and lifecycle token. Persist provisional upload metadata before storage so erasure can adopt an object after a crash between storage and finalization.
- [x] Run nine durable phases: `drain_work`, `scan_photos`, `scan_staging`, `cleanup_storage`, `verify_storage`, `purge_nonstorage`, `teardown_billing`, `delete_account`, and `completed`.
- [x] Manifest represented photos, deterministic derivative prefixes, staged outputs, and prefix orphans; repeat prefix listing and per-object `exists` checks before setting storage proof. Missing raw Accounts fail closed without that proof.
- [x] Reconcile missing/stale dispatches and expired claims through the lifecycle scheduler and `security-erasure` queue; publish scheduler/backlog health and retain the completed tombstone.
- [x] Preserve permanent AI/BYOK audits, surviving users, and other workspaces. Route sole-owner user-erasure targets through the same eraser and await every tombstone before deleting the user.
- [x] Refuse rollback before the first reverse DDL while workspace erasure or pre-handoff creator-safe user erasure is active, or while storage/manifest proof is incomplete.
- [x] Revalidate and lock the active OpenRouter credential when enabling BYOK so a competing revoke wins without partial settings changes.

#### Phase 4D — Commercial provider integration (completed)

- [x] Use Laravel Cashier v16 with Stripe and make `Account` the billable customer; keep `AccountEntitlement` as the only feature-authorization source.
- [x] Give new workspaces a 14-day Standard trial while preserving existing active beta workspaces.
- [x] Add owner-only, password-confirmed monthly/yearly Checkout from server-mapped prices and one Stripe portal action for payment methods, subscription changes, and invoices.
- [x] Fence Checkout with durable Account intent state, exact interval/price fingerprints, Stripe idempotency keys, session reuse, and non-recyclable terminal completion until canonical webhook repair.
- [x] Validate signed webhooks, serialize Cashier mutation plus entitlement projection per Account, reject stale watermarks, and fail closed for unknown prices, multiple subscriptions, or inactive provider states.
- [x] Delete or prove the Stripe customer missing before local workspace deletion; clear raw customer IDs while retaining permanent AI audits and a keyed teardown proof.
- [x] Add an owner-only Billing page that distinguishes provider-neutral access, local Stripe state, live/terminal Checkout, retryable Checkout, inconsistent billing evidence, portal management, and safe resubscription without exposing raw price IDs.

Phase 4D verification: mandatory backend and UI code reviews approve; 50 focused billing tests / 236 assertions pass; full Pest is 875 total, 873 passed, 2 expected environment-dependent skips, and 4,224 assertions. PHPStan reports zero errors; Pint, Blade compilation, MariaDB migration `--pretend`, Composer platform requirements with BCMath, route, whitespace, and protected-migration hash checks pass. The frontend build is unverified because this environment has no Linux Node runtime; run `npm ci && npm run build` on supported Linux or the deployment target.

Acceptance:

- [x] one company policy governs hosted AI and BYOK spend;
- [x] authorized members share the business roadmap/tasks/projects without sharing raw credentials;
- [x] suspended/unentitled companies cannot start hosted or BYOK paid AI; and
- [x] existing users retain all owned data after migration.

### Phase 5 — AI trust center (completed)

Goal: make permanent auditing and spend controls operationally useful.

- [x] Add a manager-only, account-scoped permanent audit timeline with actor/deleted-actor attribution, purpose, provider/model, safe fingerprint, outcome, cost, operation ID, and validated filters. Cap recent actor options at 100 with a batched lookup.
- [x] Show month-to-date successful cost, active reservations, remaining monthly budget, next UTC reset, concurrency use/limit, and effective hosted/BYOK/disabled routing for Auto/custom short text, long text, image prompts, image generation, and automatic selection.
- [x] Audit control and model-routing changes atomically with only sorted changed-field names and HMAC before/after fingerprints; never persist policy values in the audit row.
- [x] Export the filtered timeline with a fixed audit-ID cutoff and bounded composite `(occurred_at, id)` descending keyset. Strip control bytes, repair invalid UTF-8, neutralize spreadsheet formulas, and permanently audit the export.
- [x] Preflight OpenRouter through only the byte-exact official base and fixed `GET /api/v1/key` plus `GET /api/v1/models?output_modalities=all`; validate the endpoint before decrypting the credential and never generate content, spend on a model, or persist provider responses.
- [x] Require manager authorization, fresh password confirmation, and throttling; bound retries, time, bytes, model count/shape, IDs, modalities, and labels; revalidate terminal account/entitlement/credential/routing state and converge races to `state_changed`.

Acceptance:

- [x] managers can investigate every in-app key use and policy change;
- [x] audit records remain append-only and survive account erasure;
- [x] usage totals reconcile successful costs and active reservations; and
- [x] invalid keys/models fail before normal product work starts, with one permanent Started event and one terminal event.

### Phase 6 — Executable roadmap and established-company evidence (completed)

Goal: turn guidance into a durable operating plan.

#### Phase 6A — Executable roadmap (completed)

- [x] Persist exactly one plan per `Business` and synchronize the 26 stable template items plus same-plan dependency edges. Retire removed template keys without deleting their manual history or evidence; reactivate them on return.
- [x] Keep computed profile status separate from manual execution status and actor/time provenance. Converge only system-managed states in deterministic dependency order; manual statuses survive profile synchronization.
- [x] Support owner-default assignments, phase-based internal planning targets, team notes, completion/reopen attribution, and labeled evidence with optional HTTPS references and notes. Planning targets are coordination dates, not legal, tax, payroll, filing, or regulatory deadlines.
- [x] Resolve active CTA text and URLs from the current template by stable key rather than trusting a persisted absolute URL. Every item has an explicit completion action even when it has no module CTA.
- [x] Require active prerequisites before completion, prevent reopening a prerequisite beneath an active completed dependent, and exclude blocked work from persisted Dashboard, company Overview, and onboarding next actions.
- [x] Allow owners, admins, and members with the workspace capability to use the plan; deny project-only guests, removed/erasing members, forged account/item/evidence IDs, and cross-account assignments.
- [x] Reject stale collaborative detail saves with an optimistic version token. Member removal clears assignments across active and retired items, including later reactivation, while retaining manual status and evidence history.
- [x] Cascade roadmap deletion with workspace/business erasure. User erasure preserves another workspace's plan and manual state while nullable assignee, completion/status actor, and evidence-author fields clear safely.

#### Phase 6B — Resumable and established-company onboarding (completed)

- [x] Persist one encrypted, schema-versioned onboarding draft per account before a Business exists. Save on validated continue or save-and-exit, resume the stored track/step/answers, extend expiry to 180 days after activity, and require the current revision for updates or start-over.
- [x] Separate the five-step new-company path from a three-section established-company operating baseline, with established-only current-name rules and plan-ready handoff copy.
- [x] Offer the established pre-profile path a generated Mentrovia CSV template and explicit preview/apply flow for one company row. Bound file/column/cell size, validate UTF-8 and typed values, reject duplicate headers, formulas and control/format characters, warn on unknown columns, and discard the raw upload after parsing.
- [x] Bind draft save, CSV apply, and finalization to current account membership/capability under Account-first locks and optimistic revisions. Finalization creates exactly one Business, classifies it, generates tasks, synchronizes the roadmap, and deletes the draft in one transaction; failure retains the draft and rolls back partial output.
- [x] Keep established Businesses on the fingerprint-fenced legacy editor during Phase 6B; Phase 6C later replaced it with section-scoped merge/rebase editing and immutable revisions.
- [x] Schedule daily bounded pruning of eligible expired drafts while workspace deletion cascades its draft immediately.

#### Phase 6C — Profile versions and downstream staleness (completed)

- [x] Add a four-section existing-business profile hub with encrypted optimistic baselines, field-level teammate merge, explicit overlap conflicts, safe rebase, cross-field employee consistency, and friendly enum/date/boolean presentation.
- [x] Create deterministic keyed profile fingerprints and encrypted immutable revisions for onboarding, manual edits, one-company CSV, and banking workflow changes. Preserve creator attribution through user erasure and cascade history only with the workspace.
- [x] Add manager-only, preview-first existing-business CSV updates. Bind apply to the account/business/current fingerprint, discard the raw upload, store safe encrypted metadata only, reject no-op/invariant-blocked apply, and defer multi-row/document/vendor imports.
- [x] Reconcile stage, recurring-task applicability, and system-managed roadmap guidance in the same profile transaction. Retire/reactivate tasks without deleting completion history or manual roadmap execution.
- [x] Pin Brand, Advertising, Advisor, and validation provider work to one profile input. Preserve a paid result if the profile changes mid-call and derive current/stale/legacy guidance from recorded input fingerprints; Projects remain unchanged because they do not consume profile facts.
- [x] Keep full Advisor profile context in memory only. Persist validation revision/fingerprint plus a safe summary, attribute the triggering member, and reject live facts that drift from the latest immutable revision.
- [x] Defer QuickBooks/Xero/vendor mappings, XLSX/PDF/OCR/multi-row import, government verification, inferred facts, profile restore, multiple Businesses, non-Texas schemas, Project/campaign links, and automatic paid regeneration.

### Phase 7 — Output workflows and integrations

- Editable brand/ad drafts, approval states, immutable revisions, packages, and publishing handoffs.
- Advisor threads, question/answer pairing, search/export, and convert-to-task/roadmap actions.
- Project rename/archive/transfer/delete, company/campaign links, bulk export, and Growth visibility for shared work.
- Document/import integrations and verified filing-history facts.

### Phase 8 — Production proof and expansion

- Complete real S3, Linux Node/Sharp, queue/scheduler, OpenRouter, and image-provider staging E2E.
- Validate trigger privileges, worker recovery, private delivery, BYOK isolation, and one-call-per-slot behavior under concurrency.
- Add multi-state content packs only after company ownership, evidence, and recurring operations are stable.

## Completed gates

- Secure lifecycle/auth/storage/invitation audit and remediation.
- Account-level paid-AI controls, encrypted OpenRouter BYOK, purpose-specific models, budgets/concurrency, isolated AI/HTTP transport, and permanent audit ledger.
- Known-password seed account removed; production seeding now creates reference data only.
- Recurring operating loop: daily idempotent rollover, preserved period history, database-enforced period uniqueness, and overdue-first Dashboard/Tasks visibility.
- Customer AI resilience: sanitized recoverable states across Advisor, Branding, Advertising, and Photo Studio initiation; policy failures preserve their reason; failed Advisor attempts leave no partial history or consumed quota.
- Executable roadmap: one durable plan per business, 26 synchronized items, same-plan dependencies, profile/manual status separation, team assignment/targets/notes/evidence, explicit completion, current-template CTAs, optimistic collaboration, and dependency-safe persisted next actions.
- Resumable first-company onboarding: one encrypted account draft with 180-day inactivity retention, five-step new and three-section established tracks, resume/start-over/save-and-exit, strict preview-first one-company CSV, optimistic conflict fences, atomic Business/task/roadmap finalization, and daily pruning.
- Existing-business profile lifecycle: four section-scoped editors, encrypted immutable revisions, field-level merge/rebase, manager one-company CSV updates, atomic stage/task/roadmap synchronization, and pinned Brand/Advertising/Advisor provenance with current/stale/legacy guidance.
- Account/workspace persistence foundation: deterministic legacy backfill, atomic registration, owner/admin/member roles, one-owner enforcement, provider-neutral entitlements, and permanent audit-ID preservation.
- Account-scoped product boundary: membership-validated requests, shared company data and AI controls, owner/admin management permissions, member-use permissions, project-only guest isolation, and creator-ID authorization removal.
- Queued paid-AI isolation: immutable project-derived account snapshots, account-aware leases, membership/entitlement enforcement before provider start, origin-account audits, and switch-safe job payload compatibility.
- Workspace administration: secure existing/new-user invitations, strict owner/admin/member controls, session-safe switching, atomic removal fallback, and locked one-owner transfer.
- Phase 5 AI Trust Center: manager-only permanent timeline/filtering, deleted-actor display, capped actor options, usage/concurrency math, effective routing, atomic content-free control audits, safe bounded CSV export, and read-only OpenRouter key/model preflight with endpoint-before-decrypt enforcement and terminal state convergence.
- Phase 5 release evidence: 721 passed + 2 expected environment-dependent skips / 3,361 assertions; focused Trust Center/preflight evidence 51 tests / 284 assertions and final preflight evidence 41 tests / 229 assertions. PHPStan reports zero errors; Pint, MariaDB migration pretend, whitespace, and diff checks pass.
- Phase 6A release evidence: 747 passed + 2 expected environment-dependent skips / 3,532 assertions; focused final remediation evidence 48 passed + 1 expected skip / 315 assertions. PHPStan reports zero errors; Pint and diff checks pass. The frontend build remains unverified locally only because Windows npm rejects the WSL UNC working directory and no Linux Node runtime is installed; run `npm ci && npm run build` on supported Linux or the target operating system before release.
- Phase 6B release evidence: Pint passed; PHPStan reports zero errors; Blade compilation passed; full Pest suite 781 total, 779 passed, 2 expected environment-dependent skips, and 3,726 assertions; MariaDB migration `--pretend` passed. The Vite build remains unverified because WSL has Windows npm and no Linux Node runtime; run `npm ci && npm run build` on supported Linux or the target operating system before release.
- Phase 6C release evidence: mandatory backend and UX code reviews approve with zero remaining findings; Pint, full PHPStan, Blade compilation, and MariaDB migration `--pretend` pass; full Pest suite 825 total, 823 passed, 2 expected environment-dependent skips, and 3,986 assertions. The Vite build remains unverified because the WSL environment is using Windows Node/npm and cannot load the required native Rolldown binding; run `npm ci && npm run build` on supported Linux or the target operating system before release.
- Phase 4C3 evidence: full suite 668 passed + 2 intentional environment-dependent concurrency skips / 3,057 assertions; PHPStan zero errors; Pint, MariaDB migration pretend, whitespace, and diff checks pass.
- Concurrency evidence: deterministic Account → Users ascending → membership/resource SQL-order and ownership-discovery restart tests run on SQLite. Two forked MariaDB/PCNTL contention tests cover invitation/erasure serialization and reciprocal cross-account administration; both skip safely on SQLite or without PCNTL, and the committed cross-account test additionally requires an isolated test database.

## Audit limits

The local browser bridge could not attach because of a workspace-path integration error. Journey conclusions are based on executable Laravel/Livewire feature flows, route/state-transition traces, rendered-view assertions, and code review. Complete browser-level visual/accessibility testing remains part of the production-proof phase.
