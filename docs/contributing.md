# Contributing to Mentrovia

Thank you for your interest in contributing to Mentrovia.

Mentrovia is an open-source AI mentor for small business operators. The v1 beta starts with Texas-focused guidance for formation, compliance, taxes, banking, owner pay, branding, and recurring operating tasks.

## Project Values

Contributions should support these values:

- Clear guidance for small business owners
- Source-aware and auditable advisory content
- Strong legal/tax/payroll guardrails
- Practical workflows over vague chatbot answers
- Open-source transparency
- Maintainable Laravel code
- Accessible UI patterns

## Important Disclaimer

Mentrovia does not provide legal, tax, accounting, payroll, or financial advice. Contributors should not add content or code that presents the app as a substitute for a CPA, attorney, payroll provider, tax preparer, or government filing service.

When in doubt, add a professional-review flag.

---

## Ways to Contribute

### Product and UX

- Improve onboarding flows
- Add better empty states
- Improve checklist clarity
- Improve recurring task workflows
- Add examples for common business stages

### Compliance Content

- Add or improve advisory article drafts
- Add official source references
- Flag stale content
- Improve disclaimers
- Add professional review triggers

### Engineering

- Laravel models, migrations, and services
- Livewire components
- Tailwind UI cleanup
- LLM provider abstraction
- Queue jobs
- Validation run storage
- Test coverage

### Documentation

- Improve setup docs
- Improve architecture docs
- Add examples
- Improve contributor guidance
- Document source freshness policy

---

## Content Contribution Rules

Compliance and tax-related content must follow these rules:

1. Use official sources wherever possible.
2. Include source URLs and retrieval/check dates where applicable.
3. Avoid definitive legal or tax conclusions.
4. Mark thresholds, dates, tax rates, forms, and filing requirements as high-risk content.
5. Add professional review language for legal, tax, payroll, and entity decisions.
6. Do not add unsupported city/county requirements.
7. Do not copy large sections from government or commercial websites.

---

## Code Style

The intended stack is:

- Laravel
- MySQL
- Livewire
- Tailwind CSS
- Alpine.js

Prefer:

- Service classes for business logic
- Form request validation where appropriate
- Enums for statuses and risk levels
- Policies/permissions for admin actions
- Queued jobs for LLM validation
- Feature tests for major workflows
- Clear database names
- Explicit source and validation audit trails

Avoid:

- Hard-coded model names throughout the codebase
- LLM calls directly inside controllers/components
- Unversioned advisory content
- Untracked source references
- Compliance logic hidden in Blade templates
- UI-only validation for important business rules

---

## LLM Contribution Rules

LLM-related code should be provider-abstracted.

Do not hard-code a single provider or model in core business logic. Use named roles such as:

- classifier
- validator_low_cost_1
- validator_low_cost_2
- validator_low_cost_3
- decision_model
- brand_model
- copy_model

Validator outputs should use structured JSON schemas where possible.

---

## Pull Request Checklist

Before submitting a pull request, check:

- [ ] The change is scoped and understandable.
- [ ] Compliance content includes sources where needed.
- [ ] Legal/tax/payroll claims include disclaimers or review flags.
- [ ] High-risk content has freshness handling.
- [ ] LLM behavior is auditable.
- [ ] Tests were added or updated where appropriate.
- [ ] Documentation was updated if behavior changed.
- [ ] UI text is clear for non-technical business owners.

---

## Suggested Branch Naming

```txt
feature/company-intake
feature/llm-validation-pipeline
feature/texas-sales-tax-module
fix/stale-advisory-guardrail
docs/product-roadmap
```

## Reporting Issues

When opening an issue, include:

- What you expected
- What happened
- Steps to reproduce
- Screenshots if UI-related
- Relevant advisory article or source if content-related
- Whether the issue involves legal/tax/payroll/compliance risk

## Security and Sensitive Information

Do not post real tax IDs, EINs, bank details, personal addresses, payroll records, or private business financial data in public issues.

