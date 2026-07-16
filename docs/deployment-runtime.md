# Deployment and Runtime Guide

This guide covers the production requirements for Mentrovia's beta, especially Photo Studio. It complements the normal Laravel deploy steps: install dependencies, build assets, migrate, cache configuration, and restart application processes.

## Required environment

Use PHP 8.4 and a Linux Node.js runtime that is compatible with the installed `sharp` package. The repository currently installs `sharp` 0.35.3, which requires Node.js 20.9 or newer. Install Node dependencies on the same OS and architecture that will run queue workers; do not reuse `node_modules` from Windows on Linux or the reverse.

Set `PHOTOSTUDIO_NODE_BINARY` to that runtime's absolute Node binary when it is not already on the queue worker's `PATH`.

Set the following storage values for the private Photo Studio disk:

- `PHOTOSTUDIO_IMAGE_DISK=s3`
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, and `AWS_BUCKET`
- `AWS_ENDPOINT` for S3-compatible services
- `AWS_USE_PATH_STYLE_ENDPOINT=true` when the provider requires path-style bucket URLs
- `AWS_URL` only when the provider's base URL is the intended fallback for `Storage::url()`

Photo objects must remain private. Browser reads and downloads go through the authenticated `projects.photos.show` route, which authorizes project access and streams the object with `private, no-store` caching. Do not expose the bucket or configure a public `AWS_URL` as an alternate delivery path.

Provide credentials only for the selected services:

- `OPENROUTER_API_KEY` for OpenRouter text roles, Photo Studio analysis, and OpenRouter image models.
- `REPLICATE_API_TOKEN` for Replicate image models.
- `STABILITY_API_KEY` for Stability image models.
- `OPENAI_API_KEY` when a selected model profile requires an upstream OpenAI key.

Keep provider keys private. Do not add them to checked-in `.env` files, browser variables, issue reports, or feedback submissions.

## Cashier and Stripe billing

Mentrovia uses Laravel Cashier v16 with Stripe. `Account` is the Cashier customer; `AccountEntitlement` remains the only source used by feature gates. Authorization never performs a live Stripe request. New workspaces receive one 14-day Standard trial in the provider-neutral entitlement. Legacy active beta workspaces remain grandfathered and do not require a subscription.

Install PHP BCMath in every web, CLI, scheduler, and queue runtime before `composer install`; Cashier's money formatting dependency requires it. Configure:

```dotenv
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_STANDARD_MONTHLY_PRICE=price_...
STRIPE_STANDARD_YEARLY_PRICE=price_...
CASHIER_CURRENCY=usd
CASHIER_PATH=stripe
```

Use live values only in production secrets. Monthly/yearly Checkout accepts only the configured interval and resolves the Stripe price server-side. The owner-facing Billing page intentionally does not render raw price IDs. Configure Stripe's customer portal for payment-method updates, subscription management, and invoice history; Mentrovia does not maintain a second local invoice ledger.

Expose the signed webhook at `https://your-host/{CASHIER_PATH}/webhook`. Create it with the deployed application URL and Stripe secret:

```bash
php artisan cashier:webhook --url="https://your-host/stripe/webhook" --no-interaction
```

Store the returned signing secret as `STRIPE_WEBHOOK_SECRET`, then restart web workers. If `CASHIER_PATH` changes, update the Stripe endpoint in the same deployment. Cashier's payment-confirmation route is under the same prefix. Keep the webhook public, HTTPS-only, signature-verified, and CSRF-exempt only at that exact configured path.

`CACHE_STORE` must be a shared, atomic-lock-capable store across all web nodes. The default database cache satisfies this requirement. Webhook processing locks each Account across admission, Cashier's local subscription mutation, and entitlement projection. Its 600-second lease must remain greater than the declared 300-second processing budget; unsafe configuration fails fast.

Checkout is owner-only, password-confirmed, and fenced by durable Account state. The fence binds subscription type, interval, and configured price, reuses the same Stripe idempotency token after an uncertain response, and never recycles a completed session until a canonical signed subscription webhook clears it. If the Billing page reports terminal confirmation pending, repair by replaying the missing Stripe event after checking the Stripe customer/subscription; do not clear the Account checkout columns by hand.

Subscription webhooks project only recognized Standard prices. Active and future trialing subscriptions grant the matching provider-neutral state. Past-due, unpaid, incomplete, unknown-price, missing, or multiple-current-subscription states suspend access; explicit subscription/customer deletion cancels Standard access. Grandfathered beta remains unchanged. Use the Billing page and local webhook projection records for diagnosis before replaying an event.

Workspace deletion enters `teardown_billing` after private storage and account-owned data are drained. It deletes the Stripe customer outside the final database transaction, treats Stripe `resource_missing` as proof, clears the raw customer ID, and stores only a keyed proof before deleting the Account. Stripe deletion immediately cancels that customer's subscriptions and removes saved payment details. Permanent AI/BYOK audit rows remain unchanged.

## Paid AI account controls and BYOK

Workspace owners and admins manage paid AI at `/settings/ai`. Members may use enabled account AI but cannot change policy, budgets, credentials, or model routing. The settings page always uses the membership-validated current account. Users switch live workspace memberships at `/settings/account`; switching regenerates the session and fully redirects before the new account context is used. A new personal account defaults to paid and Mentrovia-hosted AI enabled; BYOK remains disabled until an owner/admin saves an OpenRouter key and explicitly enables it.

OpenRouter credentials are encrypted at rest and write-only in the UI. Saving, rotating, or revoking a key requires recent password confirmation. The application shows only the last four characters after storage. Enabling BYOK re-queries and locks the active credential inside the settings transaction; if a competing revoke commits first, enablement and every accompanying settings change roll back. BYOK requests use isolated Laravel AI and HTTP dispatchers, so the key, prompt, and response are not published through the application's global AI or HTTP event listeners. Do not add raw request middleware, debug taps, or telemetry to `ByokHttpFactory`.

Model routing supports:

- short text/descriptions: ordered custom fallback models or the curated Auto list;
- long text/articles: ordered custom fallback models or the curated Auto list;
- image prompt generation: ordered custom fallback models or the curated Auto list;
- image generation: up to `PHOTOSTUDIO_RESULTS_PER_BATCH` custom models, one result per model in the displayed order; and
- automatic selection: the supported list in `config/account-ai.php`.

The provider-neutral `HostedAi` entitlement, paid-AI master switch, hosted-AI switch, monthly USD limit, per-operation USD limit, and concurrency limit are enforced before settings/model/provider resolution and before every provider call. This capability currently gates both hosted and BYOK paid work. Unknown plans, suspended/canceled entitlements, and expired trials fail closed. Started operations reserve a conservative cost estimate; successful operations reconcile to provider-reported cost when available. A budget-limited custom model without a safe estimate fails closed. A queued image blocked only by account concurrency returns to the queue without contacting the provider.

Every attempted in-app paid-AI/BYOK action appends lifecycle and operation events to `ai_operation_audits`. Pre-provider membership, entitlement, policy, budget, concurrency, and lease denials append `Prevented` against the durable origin account/actor without manufacturing a provider-start event. Records contain the account/actor IDs, credential fingerprint, provider/model, status, content hashes and sizes, token counts, cost, and sanitized failure class—not the key, prompt, or output. Application model guards and database triggers reject ledger updates and deletes. Account erasure removes the encrypted credential and routing controls but retains the permanent audit rows.

Advisor, Branding, Advertising, and synchronous Photo Studio initiation map AI policy, budget, concurrency, provider, audit, and unexpected failures to sanitized recovery states. Customer pages never render provider bodies or exception details. Unexpected UI-boundary failures emit a fixed server log event with only the exception class; do not replace this with raw exception reporting. Asynchronous Photo Studio provider failures continue through the sanitized persisted batch/slot status.

The AI controls migration creates database triggers. The deployment database user therefore needs `CREATE TRIGGER` and `DROP TRIGGER` privileges for migration operations. Never manually drop the triggers, truncate the ledger, or include `ai_operation_audits` in retention cleanup. The migration's rollback refuses to erase a non-empty ledger.

### AI Trust Center and OpenRouter preflight

Workspace owners/admins use `/settings/ai/trust`; members cannot view, filter, export, or preflight account AI data. The 25-row timeline is permanently account-scoped and ordered by `occurred_at DESC, id DESC`. It supports event, outcome, actor, purpose, provider, model, operation ID, and UTC date filters. Deleted users remain visible by stable actor ID; the actor selector loads only the 100 most recent distinct actors with one batched user lookup.

Usage cards report current-UTC-month successful cost, reservations from Started operations without a Succeeded/Failed terminal event in the last 15 minutes, `max(0, monthly limit - actual - reserved)`, the next UTC month boundary, and active/allowed concurrency. Effective routing displays Auto/custom models for short text, long text, image prompts, image generation, and automatic selection, then resolves the account route to BYOK, hosted, or disabled from entitlement, master/hosted/BYOK controls, and active credential state.

Control and routing saves append their audit row inside the same locked transaction. Rows contain sorted changed-field names and HMAC-SHA-256 before/after fingerprints only—not raw switches, limits, model lists, keys, prompts, or responses.

CSV export reuses the validated filters and captures a fixed account audit-ID cutoff before appending its own `AuditExported` event. It streams bounded pages in the timeline's composite `(occurred_at, id)` descending order, with no offset or full result materialization. Every text cell is repaired to UTF-8, stripped of C0/DEL controls, and prefixed when it could be interpreted as a spreadsheet formula. Treat exports as sensitive operational records even though content and credentials are excluded.

OpenRouter preflight requires manager authorization, fresh password confirmation, and the five-attempts-per-hour manager/account throttle. The default base is byte-for-byte `https://openrouter.ai/api/v1`; if `OPENROUTER_BASE_URL` is set, it must contain that exact value. Alternate casing, ports, paths, slashes, encoding, userinfo, queries, fragments, or control bytes fail before credential decryption or HTTP. The client then performs only the official [`GET /api/v1/key`](https://openrouter.ai/docs/api/api-reference/api-keys/get-current-key) and [`GET /api/v1/models?output_modalities=all`](https://openrouter.ai/docs/api/api-reference/models/get-models) requests with redirects disabled. These calls validate the key and required output modalities; they do not generate content or invoke a billable model.

Provider calls have explicit connection/request timeouts, configured retry delays/count, response-byte and JSON-depth limits, a maximum model count, strict model-ID/modality shapes, and a sanitized bounded label. Responses and provider-reported key usage are never persisted. Endpoint safety is checked before the encrypted cast is read and again in the isolated client. A corrupt credential fails through a sanitized terminal result without HTTP or secret leakage.

Every admitted preflight permanently appends `PreflightStarted` and exactly one Succeeded, Failed, or Prevented terminal audit. Before accepting any provider result, the terminal transaction re-locks and revalidates manager access, account/erasure state, entitlement, credential ID/fingerprint/revocation, and controls/model-routing fingerprint. A concurrent change discards the provider result and records `state_changed` without persisting the transient response.

Phase 5 verification: 721 passed + 2 expected environment-dependent skips / 3,361 assertions; focused Trust Center/preflight evidence 51 tests / 284 assertions and final preflight evidence 41 tests / 229 assertions. PHPStan reports zero errors; Pint, MariaDB migration pretend, whitespace, and diff checks pass.

## Account workspace migration

Phase 4A runs four ordered migrations: create workspace tables, add nullable account-scope columns, backfill, and enforce constraints. The backfill creates one personal account per existing user with `accounts.id = users.id`. That deterministic identity preserves the meaning of existing permanent `ai_operation_audits.account_id` values; the audit ledger is never updated and intentionally has no account foreign key.

The enforcement migration stops before destructive DDL when it finds a user without a current membership, an account without exactly one owner or entitlement, an unscoped business/project/AI row, or account-scoped uniqueness conflicts. Reconcile the reported IDs and rerun the migration—do not bypass the preflight.

MariaDB/MySQL enforce at most one owner with a stored generated owner key and unique index. SQLite uses an equivalent partial unique index. The membership foreign key restricts direct user deletion so an owner cannot leave an orphan workspace. The approved erasure workflow requires ownership transfer when other members remain. Startup discovers the candidate sole-owned scope, then locks Accounts in ascending order, the target User, and memberships in ascending account/user order. It restarts discovery on scope drift before writing the marker, progress, or targets. Sole-owned account IDs are handed off after user-level private data/storage cleanup; final user deletion remains blocked until every workspace eraser records a completed durable tombstone. Permanent AI audit rows remain untouched.

Phase 4A is the persistence foundation, Phase 4B supplies the multitenant authorization and queued-work boundary, Phase 4C1 adds verified workspace administration, Phase 4C2 makes departing-user cleanup creator-safe, and Phase 4C3 supplies durable workspace deletion.

## Executable roadmap migration

Phase 6A adds `roadmap_plans`, `roadmap_plan_items`, `roadmap_item_dependencies`, and `roadmap_item_evidence`. The database enforces one plan per `Business`, one item per plan/template key, and dependency endpoints within the same plan. The migration does not guess or backfill execution state: an existing business receives its 26-item plan on the next Dashboard, company Overview, onboarding plan-ready, or Roadmap request, or when company intake is saved.

New items default to the account owner and a phase-based planning target 7–60 days ahead. These are internal coordination dates, never legal, tax, payroll, filing, or regulatory deadlines. Profile saves synchronize template data and deterministic system-managed statuses. Manual execution status/provenance, assignee, target, notes, and evidence survive synchronization; evidence URLs, when supplied, must use HTTPS. Module CTAs resolve from the current application template by stable key, so stored hostnames are not a deployment dependency.

Owners, admins, and members with the workspace capability can update roadmap execution; project-only guests and removed/erasing members cannot. Removing a member clears assignments from active and retired items before membership deletion, and a later-reactivated item remains unassigned. User erasure nulls roadmap attribution foreign keys while preserving the surviving workspace plan and manual state. Workspace/business deletion cascades through its plan, items, dependencies, and evidence; permanent AI audit retention remains separate and unchanged.

The reverse Phase 6A migration drops all four roadmap tables and their execution/evidence history. It has no data-preserving rollback path or erasure refusal preflight. Back up or export any required plan evidence before rolling it back, and do not reverse the schema while Phase 6A application code remains live.

Phase 6A verification: 747 passed + 2 expected environment-dependent skips / 3,532 assertions; focused final remediation evidence 48 passed + 1 expected skip / 315 assertions. PHPStan reports zero errors; Pint and diff checks pass. The local frontend build could not run because Windows npm rejects the WSL UNC working directory and no Linux Node runtime is installed. `npm ci && npm run build` remains mandatory on supported Linux or the target operating system.

## Onboarding drafts and company intake

Phase 6B adds one `onboarding_drafts` row per Account before that workspace has a Business. The allowlisted, schema-versioned payload uses Laravel's encrypted array cast; the database stores ciphertext. Each successful save advances an unsigned revision, records nullable saver attribution, and moves `expires_at` to 180 days after activity. Account deletion cascades the draft, while saver deletion only nulls attribution.

The welcome chooser starts either the five-step new-company path or the three-section established-company path. Continue persists a validated step; Save & exit persists a valid partial draft; Resume restores the stored track, step, and answers; Start over requires the current revision. All draft mutations revalidate the workspace capability under the Account-first lock. Stale tabs cannot overwrite a newer revision.

The established path can download Mentrovia's CSV template and upload one header row plus one nonblank company row, up to 128 KB. Parsing requires valid UTF-8, bounds columns and cells, normalizes dates/booleans/enums, rejects duplicate headers, malformed widths, NUL/control/format characters, and formula-like cells, and shows unknown-column warnings. Preview renders Current/Imported/Result values with per-field inclusion controls and does not mutate the draft. Apply uses an encrypted account/track/revision-bound proposal envelope that carries the source fingerprint; a stale revision fails closed. The temporary raw upload is deleted after parsing and is not retained as an import record.

First-profile finalization locks the Account, actor/owner membership context, draft, and Business state, then creates exactly one Business, classifies it, generates recurring tasks, synchronizes the executable roadmap, and removes the draft in one transaction. Any failure rolls the transaction back and retains the draft. The established path receives operating-baseline handoff copy on the immediate plan-ready screen.

Phase 6B did not add vendor/QuickBooks/Xero mapping, XLSX/PDF/OCR or multi-row import, government verification, existing-business CSV, section-scoped editing/version history, multiple Businesses per Account, or non-Texas support. Phase 6C adds the existing-business editor, one-company CSV, encrypted versions, and downstream provenance; the remaining integration and expansion items stay deferred.

Phase 6B verification: Pint passed; PHPStan reports zero errors; Blade compilation passed; full Pest suite 781 total, 779 passed, 2 expected environment-dependent skips, and 3,726 assertions; MariaDB migration `--pretend` passed. The Vite build remains unverified because WSL has Windows npm and no Linux Node runtime. Run `npm ci && npm run build` on supported Linux or the target operating system.

## Existing-business profile versions

Phase 6C adds `businesses.profile_revision/profile_fingerprint`, encrypted immutable `business_profile_versions`, recurring-task active/retired state, and Brand/Advertising provenance columns. Existing companies edit four allowlisted sections. Material changes classify the Business, insert one encrypted revision, reconcile recurring tasks, and synchronize system-managed roadmap guidance in one transaction; any downstream failure rolls everything back. Manual roadmap status, assignment, notes, evidence, and planning targets survive.

Profile snapshots and source metadata use Laravel encrypted casts. Fingerprints are keyed HMACs over the normalized schema-versioned snapshot. `PROFILE_FINGERPRINT_KEY` is optional and falls back to `APP_KEY`; at least one must be nonblank. Keep the effective key stable across deployments and workers. Rotating it without a planned profile-version migration makes existing live/version comparisons fail closed.

After the Phase 6C migrations, create encrypted baselines for legacy active workspaces in bounded runs:

```bash
php artisan business-profiles:backfill-versions --limit=1000 --chunk=100 --no-interaction
```

Repeat until the command reports zero inspected/created businesses. Each run selects only active, unversioned candidates, so already-completed and erasing workspaces cannot block later IDs. Read-only profile pages never create a baseline; profile writers and AI pinning create one lazily when needed. A live profile that no longer matches its latest immutable revision fails closed instead of silently relabeling provenance.

Existing-business CSV accepts one bounded UTF-8 company row and discards the temporary upload after preview. Apply trusts only its encrypted account/business/revision/fingerprint-bound envelope and stores encrypted safe metadata, never filename or cell contents. Owner/admin may import; members may edit allowed profile sections manually.

Brand sections record their marketing-profile input; Advertising records marketing-profile plus Brand content input; Advisor answers record the full-profile revision/fingerprint. Provider work pins the input before network I/O. If a paid result returns after a profile change, save it against the old input and show it as stale; never discard or automatically retry it. Validation providers receive the same full pin in memory, while `validation_runs.context_snapshot` stores only the pin and a safe allowlisted summary.

Phase 6C deliberately does not add vendor/QuickBooks/Xero mappings, XLSX/PDF/OCR or multi-row import, government verification, inferred profile facts, profile restore, multiple Businesses per Account, non-Texas schemas, Project/campaign profile linkage, or automatic paid regeneration.

Phase 6C verification: mandatory backend and UX code reviews approve; Pint, full PHPStan, Blade compilation, and MariaDB migration `--pretend` pass; full Pest suite 825 total, 823 passed, 2 expected environment-dependent skips, and 3,986 assertions. Vite remains unverified in the current WSL/Windows Node environment because the native Rolldown binding cannot load; build on supported Linux or the target operating system.

## Workspace administration

`/settings/account` is the workspace-member control plane. Treat every Livewire account, user, role, and invitation identifier as untrusted; server actions scope the target through the membership-validated current account and re-authorize inside the transaction.

Authorization is fixed:

| Actor | Allowed | Denied |
|---|---|---|
| Owner | Invite admins/members; promote, demote, or remove non-owners; transfer ownership | Leave, remove, or generically demote the owner; create another owner outside transfer |
| Admin | Invite or remove members | Affect owners/admins; grant admin; transfer ownership |
| Member | Use enabled workspace features, switch memberships, or leave | Invite or manage any membership |

Workspace invitations normalize the recipient email, expose only a random public ID, hash the bearer token, and persist expiry, acceptance, and revocation state. Acceptance routes are signed and throttled. Notifications are encrypted, queued after commit, and recheck the pending token and destination-erasure fence before delivery. Creation and acceptance reject an account actively targeted by a user whose erasure marker remains set. Acceptance also requires an authenticated, verified user whose normalized email matches. A consumed, expired, revoked, refreshed, mismatched, forged, or actively fenced invitation fails closed.

Existing users retain their personal workspace when accepting and switch to the invited company. For a new user, the signed URL survives the login → registration → email-verification continuation, then returns to explicit acceptance. Acceptance and switching regenerate the session before redirecting into the selected account.

Membership removal runs under the global human-writer lock order: Account, every involved User once in ascending ID order, membership/role/capability rows, then the affected resource. The same order governs role changes, ownership transfer, invitations, settings, business/task/project writes, Advisor/brand/advertising/validation commits, and other synchronous account mutations. If the removed membership was current, the transaction selects another membership, preferring an owned workspace. If none remains, it creates a personal workspace with owner membership and a 14-day Standard trial before committing the removal. An owner cannot leave or be removed without a completed transfer. Project-only guests remain scoped to their explicit locked project permission and never inherit workspace capabilities.

Admin-role changes and ownership transfer require the current password or a recent password confirmation. Transfer locks the account, affected users, and memberships; it demotes the old owner to admin before promoting the target inside one retried transaction. Direct pivot guards and the database one-owner index remain fail-closed outside this transfer path.

Workspace owners can start deletion from `/settings/account` after exact-name and current/recent-password confirmation; attempts are throttled. The Account marker is the admission fence. Startup locks the Account, all affected Users in ascending order, then memberships/projects; it immediately revokes memberships, project guests, and pending invitations and repairs current-workspace selections before queueing `security-erasure`. User accounts, their other workspaces, and permanent AI/BYOK audits survive.

The workspace eraser has nine durable states: `drain_work`, `scan_photos`, `scan_staging`, `cleanup_storage`, `verify_storage`, `purge_nonstorage`, `teardown_billing`, `delete_account`, and `completed`. It drains admitted paid-AI/photo work, manifests represented objects plus derivative/staging prefix orphans, records cleanup work, repeats prefix listing, and checks every manifest path with `exists`. Only then does it set storage proof, purge bounded account data, delete or prove the Stripe customer missing, delete the Account, verify the audit count, and retain the completed tombstone. A raw-missing Account cannot be marked complete without verified storage, manifest cleanup, and billing teardown proof.

All queued AI/photo/provider/storage commit paths re-lock their durable origin Account and lifecycle state before committing. Uploads persist a provisional photo row before object storage; if the process dies after storage but before finalization, reconciliation and workspace erasure can still adopt and delete the object. Provider-started ambiguous work remains fail-closed and is never blindly repeated.

Erasure startup and invitation mutations use the same Account → affected Users ascending → membership/project/invitation ordering. Scope discovery restarts when ownership changes before the locks settle. Active destination targets fence account/project invitation creation, acceptance, and queued delivery. Marked users cannot create new invitations in surviving workspaces. Finalization rewinds to the earliest affected cleanup phase if an in-flight private or membership reference commits late.

Phase 4C3 release evidence: full suite 668 passed + 2 intentional environment-dependent concurrency skips / 3,057 assertions; PHPStan zero errors; Pint, MariaDB migration pretend, whitespace, and diff checks pass. Deterministic lock-order tests run on SQLite. Two forked MariaDB/PCNTL contention tests cover invitation/erasure and reciprocal cross-account administration; both skip safely on SQLite or without PCNTL, and the committed cross-account test also requires an isolated database.

## Account-scoped AI and queued work

Phase 4B first transitions AI controls from legacy user partitioning to account partitioning. `ai_account_settings.account_id`, `(ai_provider_credentials.account_id, provider)`, and `(ai_model_preferences.account_id, purpose)` are authoritative and unique. Their nullable `user_id` fields are attribution only, use `nullOnDelete`, and never authorize or partition data. The rollback preflight refuses to restore legacy unique shapes when null or duplicate attribution would make rollback partial.

The next three migrations add nullable account snapshots to `photos`, `photo_generation_batches`, and `photo_operation_leases`, backfill strictly from `projects.account_id` in bounded chunks, then preflight and enforce non-null account foreign keys. The preflight rejects orphaned projects/accounts, project/account mismatches, and generated-photo/batch account mismatches. `ai_operation_audits` remains FK-free and untouched.

Photo Studio jobs keep their existing serialized constructor shapes. The durable photo/batch/lease row is the account source of truth, so a member switching current accounts after dispatch cannot redirect models, credentials, budget, concurrency, entitlement, or auditing. Membership removal, project permission removal, or a lease/project account mismatch denies before provider execution. External project guests retain their explicit project read/write permissions but cannot spend workspace AI.

Lifecycle state enters `provider_started` only inside the authorized callback immediately before the provider invocation. A predictable pre-provider denial ends in `failed/pre_provider_failure` with one permanent `Prevented` audit; it is not marked ambiguous or sent to manual spend review. Only an uncertain outcome after the provider has actually started may become ambiguous.

Provider image downloads are restricted to HTTPS, configured output hosts, no redirects, bounded bytes, approved MIME/magic bytes, and configured maximum dimensions/pixels. Keep `REPLICATE_OUTPUT_HOSTS` limited to the provider hosts actually in use.

Production security settings must include:

```dotenv
APP_DEBUG=false
SESSION_ENCRYPT=true
HSTS_ENABLED=true
CSP_REPORT_ONLY_ENABLED=true
CSP_REPORT_MAX_BYTES=32768
TRUSTED_PROXIES=10.0.0.10,10.0.0.11
```

Use the exact reverse-proxy addresses for `TRUSTED_PROXIES`; do not use a blanket wildcard. The CSP report endpoint is public, CSRF-exempt, throttled, byte/depth bounded, and stores only sanitized telemetry. Keep report-only monitoring enabled while tightening the enforced policy.

## Queue workers

Photo generation allows an image-provider HTTP call to run for 300 seconds. `GeneratePhotoWithModel` has a 330-second job timeout. The dedicated lifecycle queue uses a 900-second `retry_after`; keep it above both the worker timeout and lifecycle claim window.

Security erasure and Photo Studio lifecycle work must use the `lifecycle-database` connection on the application's default database. This is not optional: domain state and the Laravel `jobs` row commit in one database transaction. Redis or SQS may remain the default for unrelated work, but they cannot replace these named lifecycle queues.

Use the settings from `.env.example`:

```dotenv
LIFECYCLE_QUEUE_CONNECTION=lifecycle-database
LIFECYCLE_QUEUE_DB_CONNECTION=mariadb
LIFECYCLE_QUEUE_TABLE=jobs
LIFECYCLE_PHOTO_QUEUE=photo-lifecycle
LIFECYCLE_SECURITY_QUEUE=security-erasure
LIFECYCLE_QUEUE_RETRY_AFTER=900
LIFECYCLE_CLAIM_SECONDS=600
LIFECYCLE_REQUIRE_SCHEDULER_HEARTBEAT=true
PHOTOSTUDIO_MAX_BATCH_INPUTS=12
PHOTOSTUDIO_MAX_OUTPUT_BYTES=26214400
PHOTOSTUDIO_MAX_OUTPUT_DIMENSION=8192
PHOTOSTUDIO_MAX_OUTPUT_PIXELS=40000000
```

Run separate supervised workers so a photo backlog cannot starve account deletion:

```bash
php artisan queue:work lifecycle-database --queue=security-erasure --sleep=3 --tries=0 --timeout=360 --no-interaction
```

```bash
php artisan queue:work lifecycle-database --queue=photo-lifecycle --sleep=3 --tries=0 --timeout=360 --no-interaction
```

Run a separate default-connection worker for mail, notifications, and other application jobs. Restart every worker after deploying code or changing queue configuration. Failed jobs use `QUEUE_FAILED_DRIVER=database-uuids`; review them with Laravel's normal queue commands, but do not blindly flush lifecycle work.

Paid image work is fenced by a durable account/batch/provider/model slot. A provider-started job whose outcome is uncertain becomes ambiguous and requires manual review; it is not automatically called again. Pre-provider denials terminate as failed with a prevented audit. Staged outputs resume without another provider request, and stale execution tokens cannot persist results.

After migrations and after the scheduler has run, verify runtime health:

```bash
php artisan lifecycle:health --no-interaction
```

The command returns a failure status for a missing/stale scheduler heartbeat, excessive queue backlog, or an old queued job. Monitor that exit status. Run migrations before this health check; the heartbeat table does not exist before the lifecycle migrations are applied.

## Scheduler

Run the Laravel scheduler once per minute. The heartbeat runs before bounded lifecycle reconciliation. Photo/provider work is enqueued only when durable state shows it was never queued; it is never time-based redispatched after a possible provider call. User/workspace erasure may receive a new fenced dispatch token when its dispatch is missing/stale or its claim expires.

```cron
* * * * * cd /var/www/mentrovia && php artisan schedule:run --no-interaction >> /dev/null 2>&1
```

Scheduled work also runs `tasks:rollover` and `onboarding-drafts:prune` daily. Task rollover processes businesses in bounded chunks, reloads each company under its Account lock, reopens a completed recurring task only when its computed next due date has advanced, and leaves active incomplete overdue work unchanged. Profile reconciliation retires inapplicable tasks and refreshes an obsolete due date when incomplete work later reactivates, without deleting notes or completion history. Draft pruning deletes only eligible expired drafts in bounded batches and skips workspaces under erasure. Repeated runs are idempotent. Keep the scheduler on a single server or a shared scheduler cache so `onOneServer()` and `withoutOverlapping()` remain effective.

The scheduler also prunes expired/terminal project invitations, expired operation leases, and completed cleanup rows. `photos:prune-originals` runs daily and records cleanup work atomically before changing metadata. Check candidates before enabling deletion:

```bash
php artisan photos:prune-originals --dry-run --no-interaction
```

## Upgrade and retention procedures

Before deploying this security baseline, stop queue producers and workers and inspect legacy photo-generation payloads that may contain prompts:

```bash
php artisan security:purge-legacy-photo-generation-payloads --dry-run --no-interaction
php artisan security:purge-legacy-photo-generation-payloads --workers-stopped --no-interaction
```

The command covers configured database pending/failed tables. If the previous queue backend was Redis, SQS, or another broker, purge its legacy photo jobs with that backend's native tooling during the same stopped-worker window. A partial unsupported-backend cleanup returns a non-zero status.

Keep request producers and Photo Studio workers stopped while the Phase 4 account and photo-snapshot migrations run. This prevents old code from inserting null account snapshots during the bounded backfill/enforcement window. Existing queued job payloads do not need conversion because their constructor shapes are unchanged; deploy the new code before restarting workers.

New migrations deliberately stop instead of guessing when they find:

- more than one business for a user;
- duplicate generated photo rows for one batch/provider/model slot; or
- malformed, duplicate, or oversized legacy batch input IDs; or
- duplicate task-completion rows for one task and due period; or
- incomplete/ambiguous account backfill, ownership, entitlement, or account-scoped uniqueness state; or
- photo, generation-batch, or lifecycle-lease account snapshots that do not match their project.

Follow the reconciliation instructions in the migration error, preserve the correct row/object, durably clean duplicate objects, and rerun the migration. Do not bypass these preflights.

User and workspace deletion are asynchronous and fail-closed. Shared workspace owners must transfer ownership before user erasure. Sole-owned targets enter durable `workspace_erasure`; the security worker starts the same Account-first workspace eraser used by the owner UI and waits for completed tombstones before deleting the user. Missing/stale dispatches and expired claims for photo, user-erasure, and workspace-erasure work are repaired by `photos:reconcile-work`; `lifecycle:health` reports scheduler and lifecycle-queue health. Operational cleanup rows may be pruned only when no user/workspace proof references them. Permanent security/BYOK audit records are a separate retention class and are never erased.

Workspace reverse migrations run a refusal preflight before the first reverse DDL and again at each workspace-erasure schema boundary. Rollback stops without schema or data changes if any Account erasure marker, incomplete/unverified workspace progress or manifest, user `account_erasure_started_at` marker, user-erasure progress row, or account erasure target remains. A completed user erasure is safe only after the User deletion has cascaded its progress, targets, and cleanup links; completed verified workspace tombstones are the only workspace proof that rollback may discard.

## Pre-deploy checklist

1. Run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` on the target OS.
2. Stop request producers and old workers, take a database backup, run the legacy payload dry-run/purge procedure, then run `php artisan migrate --force`. Verify deterministic workspace and project-derived photo account backfills, then repeat `business-profiles:backfill-versions` until it reports zero candidates. Resolve any preflight or profile-drift failure instead of bypassing it. Deploy the matching code, cache production configuration, then restart producers/workers.
3. Verify the queue worker uses the configured Linux Node binary and can load `sharp`:

   ```bash
   "$PHOTOSTUDIO_NODE_BINARY" -e "import('sharp').then(() => console.log('sharp ok'))"
   ```

4. Verify the S3-compatible endpoint can upload, read, delete, and confirm absence of a private object. Verify authenticated application delivery for an owner and collaborator, and denial for an unrelated user.
5. Confirm the selected hosted provider keys and Photo Studio model requirements are available. Confirm the migration user can create triggers. Test owner/admin settings and Trust Center access; member use-only access and Trust Center denial; guest denial; inactive-entitlement denial; hosted-only, revoked-BYOK, budget-limited BYOK, and three-model BYOK image accounts. Run OpenRouter preflight with a valid key, invalid key, incompatible model, and a noncanonical base; confirm only the two documented GET endpoints are called and every attempt has Started plus one terminal audit. `php artisan photos:image-chooser --image-input --count=3` is a real-configuration diagnostic and is expected to fail without usable provider credentials.
6. Start the dedicated security-erasure worker, photo-lifecycle worker, default worker, and scheduler. Wait for a heartbeat, then require `php artisan lifecycle:health --no-interaction` to pass.
7. In MariaDB/S3 staging, run concurrent collaborator-revocation, user/workspace-erasure, upload-crash, and generation-slot smoke tests. Confirm exactly one provider call per slot, ambiguous calls fail closed, provisional uploads remain discoverable, storage is verified absent before Account deletion, users survive workspace deletion, and AI audit counts remain unchanged.
8. Run the real Photo Studio E2E: create a project; upload captioned and uncaptioned photos; confirm derivatives and auto-captioning; generate the top three; check gallery variants and downloads; share read-only; verify read-only users cannot change or delete data.

## Beta UX QA checklist

At 320 px, 768 px, and a desktop width, verify the sidebar opens and closes, user-menu feedback link is reachable, forms preserve usable controls, and buttons do not overflow. Repeat key pages in light and dark mode:

- Dashboard; onboarding chooser/resume/start-over; both intake tracks; first-company CSV and established-company profile CSV preview/apply; profile sections/history/conflicts; and the established-company handoff
- Roadmap and Tasks
- Advisor question, stale-source warning, and answer history
- Knowledge index/detail freshness warnings
- Projects upload, generation cost notice, processing/error/retry state, and gallery
- Branding and Advertising empty, loading, failure, and completed states

Flux audit: the only Flux Pro component path is the Branding tabs layout. It is gated by `flux_ui_kit`; the flux-free kit renders the same sections as a stacked layout. Feature coverage must continue to exercise both `flux-free` and `flux-pro` configurations.
