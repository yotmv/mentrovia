# Texas Compliance Scope

## Purpose

This document defines the Texas-specific compliance areas that Mentrovia may cover in the v1 beta.

Mentrovia should provide educational guidance, checklists, and source-aware reminders. It should not present itself as a lawyer, CPA, payroll provider, tax preparer, registered agent, or government filing service.

## Scope Rule

For v1 beta, the app should only provide jurisdiction-specific guidance for Texas. If a user enters another state, the app should either block state-specific guidance or display a “not yet supported” message.

## Required Disclaimer Pattern

Use clear language near any legal, tax, payroll, or filing guidance:

> This is general small business guidance, not legal, tax, payroll, or accounting advice. Verify requirements with the appropriate government agency and review decisions with a qualified professional before filing or relying on them.

---

## Covered Texas Topics

### 1. Business Formation Awareness

Mentrovia may explain common setup paths:

- Sole proprietorship
- DBA / assumed name
- LLC
- Partnership
- Corporation
- S corporation election as a federal tax election, not a Texas entity type
- EIN awareness
- Registered agent concept
- Operating agreement concept

#### Sources to Track

- Texas Secretary of State
- Texas Comptroller
- IRS business structure and EIN resources
- County clerk resources for assumed names where applicable

#### Guardrails

- Do not tell the user which entity they must form.
- Do not generate legal documents as final legal instruments.
- Do not claim that an LLC guarantees protection in all situations.
- Always recommend legal/CPA review for entity and tax-election decisions.

---

### 2. DBA / Assumed Name Awareness

Mentrovia may explain that assumed name requirements can depend on entity type and location.

The intake should ask:

- Is the business operating under a name different from the owner/entity legal name?
- Is there a Texas entity already formed?
- What county/city is the business operating in?
- Has the assumed name already been filed?

#### Guardrails

- Do not claim that one assumed name filing satisfies every requirement without source verification.
- Do not skip county/city prompts for sole proprietors or local operations.

---

### 3. Texas Sales Tax Readiness

Mentrovia may help users understand whether sales tax might apply.

The intake should ask:

- Does the business sell physical goods?
- Does the business sell digital goods?
- Does the business sell services?
- Are services performed in Texas?
- Are products shipped or delivered?
- Does the business sell through marketplaces?
- Does the business already have a Texas sales tax permit?

#### Output Categories

- Likely taxable
- Possibly taxable
- Likely not taxable
- Needs confirmation

#### Guardrails

- Do not make definitive taxability decisions without a maintained rules database.
- Do not quote sales tax rates without freshness checks.
- Do not advise collecting sales tax without explaining permit and bookkeeping implications.
- Distinguish sales tax collected from business revenue.

#### Sources to Track

- Texas Comptroller sales tax permit resources
- Texas Comptroller taxable services resources
- Texas Comptroller local sales tax resources

---

### 4. Texas Franchise Tax Awareness

Mentrovia may explain franchise tax at a high level and help entities prepare records.

The intake should ask:

- Is the business a Texas taxable entity?
- Is it an LLC, corporation, limited partnership, or other formal entity?
- What is the entity formation date?
- What is estimated total revenue?
- Has the business filed franchise tax reports before?
- Does it have a CPA/bookkeeper?

#### Guardrails

- Do not quote thresholds, rates, forms, or due dates unless source-verified and last-checked.
- Do not tell the user they owe or do not owe franchise tax without sufficient data and source verification.
- Prompt CPA review for franchise tax filing questions.

#### Sources to Track

- Texas Comptroller franchise tax overview
- Texas Comptroller no-tax-due information
- Texas Comptroller franchise tax report due date resources

---

### 5. Employer / Payroll Readiness

Mentrovia may help an owner understand what changes when they hire an employee.

The intake should ask:

- Does the business have employees?
- Date of first hire
- Payroll provider status
- EIN status
- Texas Workforce Commission account status
- Workers’ compensation status
- Timekeeping method
- Employee handbook status
- Contractor vs employee uncertainty

#### Guardrails

- Do not make final worker classification decisions.
- Do not provide payroll tax calculations as final amounts owed.
- Recommend payroll provider or CPA review before running payroll.
- Explain that payroll compliance can include federal and state requirements.

#### Sources to Track

- IRS employer tax resources
- Texas Workforce Commission employer resources
- Texas Comptroller resources where applicable

---

### 6. Banking Separation

Mentrovia should strongly recommend a dedicated business bank account.

The banking module may cover:

- Why personal and business funds should be separated
- Documents often requested by banks
- Merchant services basics
- Credit card processing basics
- Expense categorization
- Deposit tracking
- Owner draw/distribution tracking
- Cash reserve planning

#### Guardrails

- Do not recommend a specific bank as best without current research.
- Do not make legal guarantees about liability protection.
- Do not give individualized financial advice without proper context.

---

### 7. Accounting and Recordkeeping

Mentrovia may guide users toward clean books.

Topics:

- Chart of accounts starter concepts
- Receipt capture
- Sales tax collected tracking
- Payroll tracking
- Contractor payment tracking
- Owner contributions and draws
- Monthly close checklist
- CPA/bookkeeper handoff checklist

#### Guardrails

- Do not replace a bookkeeper or CPA.
- Do not classify unusual transactions definitively without user confirmation.

---

### 8. Owner Pay Education

Mentrovia may explain owner compensation methods by entity type.

Topics:

- Owner draws
- Retained earnings / retained profits
- Guaranteed payments
- W-2 salary
- Distributions
- Dividends
- S corporation reasonable compensation concept
- Payroll tax considerations

#### Guardrails

- Do not recommend one compensation structure as optimal without CPA review.
- Do not calculate tax savings as final advice.
- Do not blur differences between legal entity type and tax election.

---

### 9. Recurring Compliance and Operating Tasks

Mentrovia should create recurring task lists based on the user profile.

#### Weekly

- Record income and expenses
- Save receipts
- Review cash balance
- Review unpaid invoices
- Review sales tax collected

#### Monthly

- Reconcile bank accounts
- Review profit and loss
- Set aside tax reserves
- Review payroll records, if applicable
- Review sales tax filing readiness
- Review subscriptions and recurring costs

#### Quarterly

- Review estimated tax payment readiness
- Review payroll reports, if applicable
- Review franchise tax revenue tracking
- Review owner pay and distributions
- Review bookkeeping cleanup items

#### Yearly

- Review entity compliance
- Prepare tax documents
- Review franchise tax filing requirements
- Review 1099 contractor payments
- Review insurance and licenses
- Update business plan and branding

#### Guardrails

- Tasks should be personalized based on profile.
- Deadlines must be source-verified before showing exact dates.
- Local obligations should be marked as “needs confirmation” unless a verified local rules source exists.

---

## Source Freshness Policy

High-risk Texas compliance articles should require freshness checks before display.

### High-Risk Content

- Deadlines
- Thresholds
- Tax rates
- Filing forms
- Penalty language
- Employer registration requirements
- Professional licensing requirements

### Recommended Freshness Statuses

- fresh
- needs_review_soon
- stale
- missing_sources
- source_changed
- deprecated

---

## V1 Out-of-Scope Items

V1 should not attempt to fully automate:

- Legal entity filing
- Tax return filing
- Franchise tax filing
- Payroll processing
- Sales tax remittance
- Registered agent services
- Legal document generation as final documents
- Professional licensing determinations
- Full city/county permit lookup for every industry

