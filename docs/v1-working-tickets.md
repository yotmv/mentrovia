# Mentrovia V1 Working Tickets

Date: 2026-07-08
Source plan: `docs/v1-working-plan.md`

These tickets are ordered to finish the current V1 plan with the least churn. Each ticket should keep changes scoped, add or update tests, and leave `php artisan test` and `vendor/bin/phpstan analyse --error-format=table` green. When PHP files are changed, run Pint before handoff.

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

### Acceptance Criteria

- No major workflow has a dead empty state.
- Errors give a next action.
- AI actions communicate processing state and cost/risk where appropriate.

### Suggested Tests

- Feature tests for key empty/error states.
- Browser smoke/manual responsive checklist.

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
  - Ticket 0 100% Completed
  - Ticket 1 100% Completed
  - Ticket 2 100% Completed
