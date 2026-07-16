# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Owner-only billing at `/settings/billing` with Laravel Cashier for Stripe, server-mapped monthly/yearly Checkout, local subscription status, persistent checkout-recovery states, and Stripe portal access for payment methods, subscriptions, and invoices.
- Account-level Stripe customer/subscription storage and signed webhook projection into the provider-neutral entitlement gate. New workspaces receive one 14-day Standard trial; existing active beta workspaces remain grandfathered.
- Existing-business company profile hub with four section-scoped editors, field-level conflict/rebase handling, encrypted immutable revision history, manager-only one-company CSV preview/apply, and friendly profile-value presentation.
- Profile-input provenance and freshness for Brand, Advertising, and Advisor outputs. Provider calls pin one immutable profile input; paid results remain saved and show stale if the profile changes during generation.
- Resumable company onboarding with one encrypted 180-day draft per account, new-company five-step and established-company three-section paths, resume/start-over/save-and-exit controls, a strict one-company CSV preview/apply flow for established-company intake, and atomic profile/task/roadmap finalization.
- Executable company roadmap: one durable plan per business synchronizes all 26 profile-aware template items with same-plan dependencies, separate computed-profile and team execution statuses, provenance, assignees, internal planning targets, notes, HTTPS evidence references, active module actions, and explicit complete/reopen controls.
- Owner-only workspace deletion in `/settings/account`, with exact-name confirmation, current/recent password confirmation, attempt throttling, immediate member/project-guest evacuation and current-account repair, nine-phase resumable erasure, verified private-storage and Stripe-customer cleanup, and permanent AI audit retention.
- Creator-safe user erasure for workspace members and project-only guests: removes private Advisor conversations, contributed photos, initiated/derived generation work and storage, received invitations, and memberships while nullable creator attribution preserves company business, project, brand, advertising, and validation records. Permanent AI audit rows remain unchanged.
- Workspace administration at `/settings/account`: secure owner/admin invitations, membership role/removal controls, full-session workspace switching, and password-confirmed ownership transfer. Invitation links support existing users and preserve the signed continuation through registration and email verification for new users.
- Membership-validated current-account request boundary for controllers and Livewire, with account-owned business/tasks/projects/Advisor/brand/advertising access and owner/admin/member policies.
- Durable project-derived account snapshots for photos, generation batches, and lifecycle leases so queued paid-AI routing, budget, credentials, entitlement, and auditing cannot follow a user's later account selection.
- Company account/workspace persistence foundation with owner/admin/member memberships, provider-neutral entitlements and capability checks, deterministic legacy-user backfill, and account scope columns for company, project, AI-control, and Advisor data.
- Account-level paid-AI controls for master access, hosted providers, monthly/per-operation USD limits, concurrency, encrypted OpenRouter BYOK credentials, and per-purpose Auto/custom model routing.
- Manager-only AI Trust Center with a permanent account-scoped audit timeline, filters, deleted-actor attribution, monthly usage/reservation/concurrency totals, effective routing, bounded sanitized CSV export, and OpenRouter key/model preflight.
- Daily, bounded recurring-task rollover with immutable period history and a database-enforced one-completion-per-task-period invariant.
- Permanent append-only AI operation ledger with credential lifecycle events, provider/model and usage metadata, content hashes, immutable database triggers, and account-erasure retention.
- Secure beta lifecycle foundation: dedicated same-database `security-erasure` and `photo-lifecycle` queues, scheduler heartbeat/health checks, bounded never-enqueued reconciliation, resumable account erasure with a storage-completion barrier, fenced paid-generation slots, durable storage cleanup outboxes, and migration preflights for ambiguous legacy ownership/input data.
- Authenticated private photo delivery with owner/collaborator authorization, project/photo mismatch rejection, and private no-store responses.
- Signed, throttled project invitations with encrypted queued notifications, stable acceptance attribution, recipient cooldowns, revocation, and scheduled retention pruning.
- Security headers and telemetry controls including HSTS behind explicitly trusted proxies, enforced and report-only CSP policies, bounded sanitized CSP reports, and exception-response coverage.
- Authenticated feedback reporting for product issues, source feedback, and feature ideas, available from both desktop and mobile user menus.
- Deployment and runtime guide covering Node/Sharp, private S3-compatible storage, provider keys, queue worker timing, scheduler setup, real Photo Studio E2E, and responsive beta QA.
- Stale-source warning in Advisor answers and an upfront paid-AI cost notice before Photo Studio generation.
- Roadmap cross-links into modules: business name and brand asset items link to Branding, the 30-day marketing plan item links to Advertising, the compliance rhythm item links to Tasks, the licenses/permits item links to the Advisor, and legal structure, sales tax, franchise tax, bookkeeping, payroll, contractor, and professional support items link to category-filtered Knowledge views.
- Dashboard next actions now include each roadmap item's module link, and every dashboard risk flag links to the module that resolves it (banking checklist, bookkeeping guidance, company profile update, or legal structure comparison) with an "Ask the Advisor" hint under the flag list.
- Navigation smoke tests covering sidebar labels/order, per-route link resolution, page loads for every sidebar destination, admin-only visibility, and roadmap/dashboard cross-links.
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

- Existing companies now use four section-scoped profile editors instead of the legacy full-profile form. Material changes atomically version the profile, reclassify its stage, reconcile recurring tasks, and synchronize system-managed roadmap guidance without overwriting team execution history.
- Recurring task reconciliation reloads the locked company profile before evaluating applicability, retires and reactivates work without deleting history, keeps tasks due today in the current period, and refreshes obsolete due dates when incomplete work becomes applicable again.
- Dashboard, company overview, onboarding plan-ready, and Roadmap now read the persisted execution plan; next-action surfaces omit completed, not-applicable, and dependency-blocked work, while profile resubmission deterministically refreshes system-managed statuses without overwriting manual execution history.
- Sole-owner user erasure now hands each snapshotted workspace to the durable workspace eraser and waits for every completed tombstone before deleting the user; surviving users, other workspaces, and permanent AI audits remain intact.
- Removing a user's selected workspace membership now atomically selects another membership or provisions a new personal owner workspace with a 14-day Standard trial; users are never left without a usable current workspace.
- AI settings, OpenRouter credentials, model preferences, budget, concurrency, and operation attribution are now partitioned by account; legacy user IDs remain nullable creator/actor attribution only.
- Workspace members share operating data, projects, Advisor history, generated brand/ad outputs, and enabled AI policy; only owners/admins manage AI controls, credentials, project sharing, and destructive project actions.
- Photo Studio provider-start state is now recorded only inside the authorized provider callback; predictable pre-provider denials terminate with a permanent origin-account `Prevented` audit rather than ambiguous-spend review.
- Registration now atomically provisions a personal workspace, sole owner membership, 14-day Standard trial, and current account; user erasure requires transfer for shared-owner workspaces and durably hands sole-owner workspaces to the workspace eraser.
- Text generation now follows each account's ordered OpenRouter models; Photo Studio fans out one BYOK result per configured image model up to the three-result limit and preserves provider-reported routing/cost metadata through staged recovery.
- Advisor, Branding, Advertising, and Photo Studio initiation now present sanitized, recoverable AI failure states with settings actions for policy/model/budget failures; unlinked Today recommendations provide accessible Tasks and Advisor fallback actions.
- Dashboard and timeframe task views now include incomplete overdue work first; completed recurring tasks reopen only after their next due period advances.
- Photo Studio paid generation now uses one fenced batch/provider/model slot, deterministic staging, stale-token rejection, and fail-closed manual review for provider-started ambiguity instead of time-based paid-call redispatch.
- Security and Photo Studio lifecycle work now requires named database queues on the default database with a 900-second retry window; Redis/SQS remain available only for unrelated work.
- User-account erasure now blocks authentication immediately and proceeds through bounded, resumable queue phases; final user deletion waits for verified private-object cleanup and every snapshotted sole-owner workspace to complete the durable Phase 4C3 handoff. Phase 4C2 does not directly delete workspaces.
- Private photo URLs now route through application authorization instead of direct or fallback public storage URLs.
- Sidebar navigation regrouped from a single "Platform" list into Overview (Dashboard, Company Profile), Guidance (Roadmap, Tasks, Advisor, Knowledge), and Marketing (Projects, Branding, Advertising) groups in the final V1 order; Settings stays in the user menu and the Admin group is unchanged.
- Documented the Flux UI editions policy: `livewire/flux-pro` stays in `composer.json` during development, FOSS installs without a license remove it (`composer remove livewire/flux-pro`), and every Flux Pro component usage must ship a flux-free fallback gated by `flux_ui_kit` (README, contributing guide, V1 working plan and tickets).
- Documented the real Photo Studio E2E review, runtime requirements, and credential blockers for ticket 8.

### Fixed

- Existing-business profile backfill now advances across repeated bounded runs, excludes erasing workspaces and completed profiles, and cannot strand later businesses behind an already-processed prefix.
- Profile provenance fails closed when live company facts do not match the latest immutable revision. Derived stage changes appear in history, forged section fields are rejected, legacy banking answers normalize safely, and downstream synchronization failures roll back the profile and version together.
- Existing-business CSV review now distinguishes exact matches from employee-consistency rejections, disables no-op apply, humanizes enum/date/boolean values, and never retains the raw upload.
- Roadmap collaboration now rejects stale detail-form overwrites and cross-workspace IDs. Removing a member clears active, retired, and later-reactivated assignments while preserving status/evidence attribution; user erasure nulls creator attribution, and workspace erasure removes the business roadmap with the workspace.
- Closed workspace-deletion races across synchronous writers, paid-AI provider boundaries, queued photo state machines, invitation flows, cached account resolution, and upload storage. Human writers revalidate the Account, actor, membership/role/capability, and resource under one global lock order; multi-user administration locks all affected Users once in ascending order. Queued AI/photo/provider/storage commits recheck durable account fences. Upload metadata is provisional before object storage so erasure can adopt an object after a process crash, and raw account loss cannot produce a false completed tombstone without verified storage proof.
- BYOK enablement now locks and revalidates an active OpenRouter credential in the settings transaction; a component mounted before a competing revoke cannot restore BYOK or partially change hosted/budget/concurrency settings.
- Closed cross-account request, AI-control, budget/concurrency, model/key, Advisor/output, and queued-work isolation gaps; account switches cannot redirect in-flight AI and membership/lease loss is revalidated before provider execution.
- Removed legacy project-creator IDs from production authorization; live workspace membership or explicit project sharing now governs project/lifecycle access, while project-only guests cannot consume workspace paid AI.
- Paid AI now checks the provider-neutral entitlement before any settings, credential, model, or provider lookup; unknown/inactive entitlements and lease denials fail closed without a provider call or partial durable state.
- Account ownership now fails closed at runtime: the database rejects duplicate owners and raw owner deletion, entitlement checks reject unknown plans/capabilities, and signup provisioning failures leave no partial user or workspace records.
- Paid-AI budget checks now reserve conservative estimates, reconcile actual usage, fail closed for unpriced limited models, and defer pre-provider image work when only the concurrency cap blocks it.
- Paid-AI policy failures are no longer swallowed by provider fallback; unexpected UI-boundary failures log only allowlisted metadata, failed Advisor attempts restore quota and leave no partial conversation, and successful Photo Studio retries clear stale failure state.
- Recurring tasks no longer remain permanently complete after their next weekly, monthly, quarterly, or yearly period begins; repeated scheduler runs cannot duplicate completion history.
- Closed provider-call, collaborator-revocation, account-erasure, derivative replacement, gallery deletion, original pruning, and upload commit-acknowledgement races that could otherwise duplicate spend or leave metadata and private storage inconsistent. Erasure now fences late Advisor writes plus account/project invitation creation, acceptance, and queued delivery; finalization rewinds when an in-flight private reference commits. Human mutations share Account → Users in ascending ID order → membership/role/capability → resource ordering, while project-only guests remain limited to their explicit project grant.
- Removed raw provider/processing exception chains from reportable failures and legacy queued photo payloads; provider response downloads now enforce HTTPS, host allowlists, redirect limits, byte/MIME/magic-byte checks, and maximum dimensions/pixels.
- Enforced published-only knowledge/advisor retrieval, unique business ownership preflights, email verification on product/Livewire routes, uniform time-boxed password-reset responses, sensitive-route throttling, and non-fillable administrator status.
- Pint `single_blank_line_at_eof` failures in 9 test files (auth, settings, unit example).

### Security

- Checkout creation uses a durable Account-scoped intent bound to subscription type, interval, and configured price, plus Stripe idempotency keys and terminal-completion fencing. Concurrent/retried requests cannot silently create or switch subscriptions.
- Signed Stripe subscription webhooks serialize the full Cashier mutation and entitlement projection per Account, reject stale/equal-time lower-watermark events, and fail closed for unknown prices or inactive states. Workspace deletion deletes the Stripe customer before the Account and retains only a keyed proof, never the raw customer ID.
- Immutable company profile snapshots and import metadata are encrypted at rest and fingerprinted with a keyed HMAC. Validation providers receive the pinned full profile in memory, while persisted validation runs keep only the revision/fingerprint and a safe allowlisted summary.
- OpenRouter preflight now accepts only the byte-exact official `https://openrouter.ai/api/v1` base, performs fixed read-only key/model GET requests, validates the endpoint before decrypting a BYOK credential, bounds provider responses, and permanently audits every Started and terminal outcome without storing provider responses.
- Workspace erasure uses one Account-first fence for admission and final commits, drains admitted paid-AI/photo work, records a durable object manifest, repeats prefix listing and per-object existence proof, retains permanent AI audit rows, and fails closed when the Account is missing without verified storage proof.
- Workspace-erasure reverse migrations refuse before the first reverse DDL while a workspace marker, incomplete/unverified workspace proof, pre-handoff creator-safe user-erasure marker/progress, or account erasure target remains. Completed user-erasure artifacts cascade away before rollback can be considered safe.
- Workspace invitations use normalized recipient emails, stable public IDs, hashed bearer tokens, expiry/revocation/acceptance state, signed and throttled acceptance routes, and encrypted after-commit queued notifications. Acceptance requires a verified matching email and is replay/race safe.
- Permanent AI auditing records pre-provider membership, entitlement, budget, concurrency, and lifecycle denials against the captured origin account/actor without logging prompts, outputs, keys, or fabricated provider starts.
- BYOK OpenRouter traffic uses isolated AI and HTTP dispatchers, keeping customer keys, prompts, and responses out of global application event listeners; credential forms are password-confirmed, write-only, and excluded from flashed validation input.
- Production seeding no longer creates a verified account with a committed known password; the root seeder installs reference data only.
