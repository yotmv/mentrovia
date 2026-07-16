# Mentrovia

**Mentrovia** is an open-source AI mentor for small business operators.

The v1 beta starts with Texas-focused guidance for formation, compliance, taxes, banking, owner pay, branding, and recurring operating tasks. The long-term goal is to build a state-by-state small business operating mentor that helps owners understand where they are, what they may be missing, and what to do next.

> Mentrovia is not a lawyer, CPA, payroll provider, government filing service, or substitute for professional advice. It is a guided education, checklist, source-verification, and workflow tool.

## What Mentrovia Does

Mentrovia helps a small business owner create a structured company profile and maintain a personalized, executable roadmap based on business stage, legal structure, staffing, sales tax exposure, banking setup, accounting maturity, owner pay questions, branding needs, and recurring compliance tasks. The durable plan tracks dependencies, execution status, assignees, internal planning targets, notes, evidence references, and completion separately from profile-derived guidance.

Onboarding separates a new company's five-step setup from an established company's three-section operating baseline. Before the first company profile is finalized, a workspace can save, resume, or restart one encrypted draft; established companies can optionally review and selectively apply one row from Mentrovia's CSV template before submission.

After creation, the company profile becomes a four-section operating record. Workspace members can safely merge section edits, managers can preview and selectively apply one-company CSV updates, and every material change creates encrypted immutable history while synchronizing profile-driven tasks and roadmap guidance. Brand, Advertising, and Advisor outputs record their profile input and show when it is current, stale, or unknown for legacy output.

The product is designed for owners who ask questions like:

- “I am starting from scratch. What do I need to set up first?”
- “I am operating as a DBA. What am I missing?”
- “I hired my first employee. What changes now?”
- “Do I need to think about Texas sales tax or franchise tax?”
- “Should I pay myself through draws, payroll, guaranteed payments, distributions, or dividends?”
- “What should I do weekly, monthly, quarterly, and yearly?”

## V1 Beta Scope

V1 beta is intentionally narrow:

- Texas only
- Small business owners and owner-operators
- Formation and entity setup education
- DBA / assumed name awareness
- Sales tax readiness workflow
- Franchise tax awareness workflow
- Banking separation guidance
- Bookkeeping and accounting setup guidance
- Owner compensation education
- First-employee readiness checklist
- Weekly, monthly, quarterly, and yearly task planner
- Resumable new-company and established-company onboarding with reviewed one-company CSV intake
- Section-scoped existing-company profile editing, encrypted immutable history, manager CSV updates, and downstream AI freshness
- Shared executable company roadmap with dependency-safe next actions, assignments, planning targets, notes, evidence, and completion provenance
- Branding kit starter flow
- LLM-backed answer validation against cached advisory content
- Account-scoped paid-AI/BYOK controls with a manager-only audit, usage, routing, export, and preflight Trust Center
- Account-scoped Laravel Cashier/Stripe billing with a 14-day Standard trial, owner Checkout/portal controls, signed subscription synchronization, and grandfathered beta access

## Technical Stack

The planned stack follows the existing Laravel workflow:

- Laravel
- MySQL
- Livewire
- Flux UI (free edition required; Pro optional, see below)
- Tailwind CSS
- Alpine.js
- Queues for LLM validation and scheduled freshness checks
- OpenRouter-compatible LLM provider abstraction
- Laravel Cashier with Stripe for account subscriptions and hosted invoices

## Flux UI Editions (Free vs. Pro)

Mentrovia's UI is built on [Flux UI](https://fluxui.dev). The repository keeps the paid `livewire/flux-pro` package in `composer.json` while the core team develops, but Mentrovia is open source and every screen must work on the free edition.

**If you do not have a Flux Pro license**, remove the Pro package before installing dependencies:

```bash
composer remove livewire/flux-pro
```

(or delete the `livewire/flux-pro` entry from `require` and the `flux-pro` repository from `repositories` in `composer.json`, then run `composer install`).

The app adapts automatically through the `flux_ui_kit` setting in `config/flux-ui.php`:

- It defaults to `flux-free` and switches to `flux-pro` only when a licensed `livewire/flux-pro` install is detected.
- Set `FLUX_UI_KIT=flux-free` or `FLUX_UI_KIT=flux-pro` in `.env` to pin the kit explicitly.

Contributor rule: any view that uses a Flux Pro component must ship an equivalent flux-free fallback in the same change, gated by `App\Enums\FluxUiKit`. Pro-only UI paths are never acceptable.

## Core Idea

Mentrovia should not answer every business question from scratch every time. Instead, it should maintain curated and cached knowledge articles, then validate freshness and risk before serving them.

A typical advisory response should pass through:

1. Company profile context
2. Cached knowledge retrieval
3. Source/date freshness check
4. Multi-model validation
5. Final decision model synthesis
6. Guardrail/disclaimer pass
7. User-facing checklist or recommendation

## Repository Structure

```txt
README.md
CHANGELOG.md
docs/v1-beta-agent-plan.md
docs/v1-working-plan.md
docs/v1-working-tickets.md
docs/deployment-runtime.md
docs/feature-audit-roadmap.md
docs/product-roadmap.md
docs/llm-validation-pipeline.md
docs/texas-compliance-scope.md
docs/contributing.md
LICENSE
```

## Project Documents

- [V1 Beta Agent Plan](docs/v1-beta-agent-plan.md)
- [V1 Working Plan](docs/v1-working-plan.md)
- [V1 Working Tickets](docs/v1-working-tickets.md)
- [Deployment and Runtime Guide](docs/deployment-runtime.md)
- [Feature Audit and Delivery Roadmap](docs/feature-audit-roadmap.md)
- [Product Roadmap](docs/product-roadmap.md)
- [LLM Validation Pipeline](docs/llm-validation-pipeline.md)
- [Texas Compliance Scope](docs/texas-compliance-scope.md)
- [Contributing](docs/contributing.md)

## Open Source Goals

Mentrovia should be transparent, auditable, and community-extensible. The project should make it easy to review how advice is generated, when source material was last checked, what model decisions were made, and where professional review is recommended.

## License

This project is released under the MIT License.
