# Mentrovia Product Roadmap

## Product Vision

Mentrovia is an open-source AI mentor for small business operators. It begins with Texas-focused guidance and should eventually support multi-state business formation, compliance calendars, tax-readiness workflows, owner operations, banking separation, branding, and growth planning.

The product should feel like a practical mentor: clear, grounded, source-aware, and cautious when legal, tax, or payroll decisions require professional review.

## Guiding Principles

1. **Texas-first, not Texas-only forever**
   - V1 launches with Texas because a narrower jurisdiction keeps the system safer and easier to validate.
   - The architecture should support future state packs.

2. **Guidance, not professional advice**
   - The app should not claim to be a CPA, attorney, payroll provider, or filing agent.
   - It should provide education, checklists, reminders, source links, and professional-review prompts.

3. **Cached knowledge with validation**
   - Common advisory answers should be curated and cached.
   - Cached answers should be revalidated by LLMs and source checks before display when risk is high or stale.

4. **Personalized roadmap, not generic FAQ**
   - Every user should receive guidance based on their actual business profile, stage, city/county, entity status, employees, tax exposure, and financial setup.

5. **Human-readable explanations**
   - Small business owners should understand what they need to do and why it matters.

---

## V1 Beta: Texas Small Business Mentor

### Target Users

- Solo founder starting from scratch
- Sole proprietor or DBA operator
- Existing small business with one employee
- LLC/corporation owner who needs compliance and operational structure
- Owner-operator who does not yet have a CPA, bookkeeper, or attorney

### Core Beta Outcomes

By the end of the beta onboarding flow, the user should have:

- Business profile summary
- Business stage classification
- Risk flags
- Personalized setup checklist
- Recurring task calendar
- Tax and filing awareness report
- Banking/accounting setup checklist
- Owner pay education summary
- Branding starter kit prompts
- Professional review checklist

### V1 Modules

#### 1. Business Intake

Collect structured information about the company, location, ownership, employees, entity status, tax status, banking, accounting, and business confidence level.

#### 2. Stage Classifier

Classify each user into one or more stages:

- Starting from scratch
- Existing DBA / sole proprietor
- Existing business with one employee
- Existing formal entity
- Sales-tax-exposed business
- Payroll-exposed business
- Banking/accounting cleanup needed

#### 3. Personalized Roadmap

Generate a prioritized roadmap:

- Do first
- Do next
- Watch for
- Ask a professional
- Add to recurring calendar

#### 4. Texas Formation & DBA Guidance

Explain Texas business setup paths, assumed name awareness, entity basics, EIN prompts, operating agreement prompts, and professional review points.

#### 5. Sales Tax Readiness

Help the user understand whether they may need a Texas sales tax permit, what to track, and when to verify taxability.

#### 6. Franchise Tax Awareness

Explain franchise tax concepts, filing awareness, annual calendar concepts, source verification, and CPA review triggers.

#### 7. Banking & Bookkeeping

Guide users away from commingled personal/business accounts and toward dedicated business banking, clean bookkeeping, recordkeeping, and software setup.

#### 8. Owner Pay

Explain common owner compensation paths, including:

- Owner draws
- Retained earnings / retained profits
- Guaranteed payments
- W-2 salary
- Distributions
- Dividends

The module should show pros, cons, entity fit, tax treatment concepts, and professional review triggers.

#### 9. First Employee Readiness

Cover employer setup awareness, payroll provider selection, employment tax registration prompts, new-hire documentation, workers’ compensation awareness, timekeeping, and recurring payroll tasks.

#### 10. Recurring Task Calendar

Create weekly, monthly, quarterly, and yearly task lists personalized to the company profile.

#### 11. Branding Kit

Use LLMs and image-generation-capable providers to help with:

- Name brainstorming
- Logo concept prompts
- Brand colors
- Voice/tone
- Social media starter copy
- Ad copy variations
- Launch checklist

---

## V1 Data Model Candidates

### Core Tables

- users
- companies
- company_profiles
- company_locations
- company_owners
- company_stage_assessments
- company_risk_flags
- advisory_articles
- advisory_article_versions
- advisory_responses
- validation_runs
- validation_votes
- compliance_tasks
- company_tasks
- task_occurrences
- source_references
- professional_review_flags

### Optional Later Tables

- state_packs
- jurisdiction_rules
- city_requirements
- industry_rule_prompts
- payroll_provider_profiles
- bookkeeping_integrations
- brand_kits
- generated_assets

---

## V1 Release Milestones

### Milestone 1 — Repo Foundation

- README
- Docs
- License
- Laravel app scaffold
- Local development setup
- Basic CI

### Milestone 2 — Company Intake

- Company profile form
- Texas-only guardrail
- Stage classifier
- Risk flag generator
- Profile summary page

### Milestone 3 — Advisory Content System

- Advisory article model
- Versioned cached answers
- Source references
- Last-verified timestamps
- Staleness status

### Milestone 4 — LLM Validation Pipeline

- OpenRouter provider abstraction
- Low-cost validator models
- Final decision model
- Validation result storage
- Confidence/risk scoring
- Human-readable validation summary

### Milestone 5 — Roadmap Generator

- Personalized checklist
- Priority levels
- Recurring task suggestions
- Professional review points

### Milestone 6 — Texas Compliance Modules

- Formation/DBA module
- Sales tax readiness module
- Franchise tax awareness module
- Employer/payroll readiness module

### Milestone 7 — Owner Operations Modules

- Banking separation
- Bookkeeping setup
- Owner pay education
- Branding kit starter

### Milestone 8 — Beta Polish

- UI cleanup
- Empty states
- Example company profiles
- Seeded advisory content
- Beta disclaimer language
- Exportable roadmap

---

## Post-Beta Ideas

- Multi-state state pack system
- CPA/bookkeeper collaboration mode
- Attorney review export
- Bookkeeping software integrations
- Calendar sync
- Email reminders
- Payroll provider checklist integrations
- Business license discovery by industry/city
- Source monitoring jobs
- Public advisory article library
- Community-contributed state packs

