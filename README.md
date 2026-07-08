# Mentrovia

**Mentrovia** is an open-source AI mentor for small business operators.

The v1 beta starts with Texas-focused guidance for formation, compliance, taxes, banking, owner pay, branding, and recurring operating tasks. The long-term goal is to build a state-by-state small business operating mentor that helps owners understand where they are, what they may be missing, and what to do next.

> Mentrovia is not a lawyer, CPA, payroll provider, government filing service, or substitute for professional advice. It is a guided education, checklist, source-verification, and workflow tool.

## What Mentrovia Does

Mentrovia helps a small business owner create a structured company profile and then generates a personalized roadmap based on business stage, legal structure, staffing, sales tax exposure, banking setup, accounting maturity, owner pay questions, branding needs, and recurring compliance tasks.

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
- Branding kit starter flow
- LLM-backed answer validation against cached advisory content

## Technical Stack

The planned stack follows the existing Laravel workflow:

- Laravel
- MySQL
- Livewire
- Tailwind CSS
- Alpine.js
- Queues for LLM validation and scheduled freshness checks
- OpenRouter-compatible LLM provider abstraction

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
- [Product Roadmap](docs/product-roadmap.md)
- [LLM Validation Pipeline](docs/llm-validation-pipeline.md)
- [Texas Compliance Scope](docs/texas-compliance-scope.md)
- [Contributing](docs/contributing.md)

## Open Source Goals

Mentrovia should be transparent, auditable, and community-extensible. The project should make it easy to review how advice is generated, when source material was last checked, what model decisions were made, and where professional review is recommended.

## License

This project is released under the MIT License.
