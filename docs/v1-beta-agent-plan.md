# Small Business Advisor — V1 Beta Agent Plan

## 1. Product Concept

Build a Texas-first small business advisory app that helps owners understand what they should do next based on their business stage, entity status, tax obligations, staffing, banking, accounting, branding, and recurring compliance calendar.

The product should not present itself as a lawyer, CPA, payroll provider, or government filing service. It should act as a guided advisor, checklist generator, education layer, and compliance organizer. Any legal, tax, payroll, or entity decision should include a disclaimer and recommend review by a qualified professional before filing or relying on the advice.

## 2. V1 Beta Scope

V1 beta should focus on Texas only.

The beta goal is to help a user answer:

> “Where is my business right now, what am I missing, what do I owe, what should I set up next, and what recurring tasks should I stay on top of?”

### V1 Beta User Stages

1. **Starting from scratch**
   - No entity yet.
   - No employees.
   - May or may not have a business name.
   - Needs basic formation, DBA/entity, sales tax, bank, bookkeeping, branding, and launch guidance.

2. **Existing business operating as DBA**
   - Already selling or operating.
   - May be using a personal bank account.
   - May not have clean books.
   - May not understand state, city, county, sales tax, payroll, or entity risk.

3. **Existing business with one employee**
   - May be sole proprietor, DBA, LLC, or unclear structure.
   - Needs employer registration, payroll setup, wage/tax compliance, workers’ comp/insurance guidance, and recurring compliance tasks.

4. **Existing LLC or corporation**
   - Has formal entity.
   - Needs franchise tax awareness, owner pay guidance, accounting structure, banking separation, compliance calendar, and growth checklist.

## 3. Core App Modules

### 3.1 Business Intake / Company Profile

The app should begin with a guided intake that builds a structured business profile.

#### Required Intake Fields

- Business name
- Desired business name, if not yet formed
- DBA / assumed name status
- Business stage
- Business category / industry
- City and county in Texas
- Physical address or operating area
- Online-only, physical location, mobile service, or hybrid
- Current legal structure
  - Not started
  - Sole proprietor
  - DBA only
  - LLC
  - Partnership
  - S corporation election
  - C corporation
  - Unsure
- Number of owners
- Number of employees
- Contractors used? yes/no
- Sells taxable goods? yes/no/unsure
- Sells taxable services? yes/no/unsure
- Has Texas sales tax permit? yes/no/unsure
- Has EIN? yes/no/unsure
- Has business bank account? yes/no
- Uses bookkeeping software? yes/no
- Has payroll provider? yes/no
- Estimated annual revenue range
- Current monthly revenue range
- Business start date
- First sale date
- First employee hire date, if any
- Current filing confidence
  - I have no idea
  - I know some things
  - I have a CPA/bookkeeper
  - I am mostly set up

#### Output

The intake should produce:

- Business profile summary
- Stage classification
- Risk flags
- Recommended next steps
- Personalized setup checklist
- Recurring task calendar
- Suggested professional review points

---

### 3.2 Formation and Legal Setup Module

This module explains possible setup paths for Texas businesses.

#### Topics

- Sole proprietorship basics
- DBA / assumed name basics
- LLC formation basics
- Partnership basics
- Corporation basics
- S corporation election as a federal tax election, not a Texas entity type
- EIN guidance
- Operating agreement guidance
- Business licenses and permits discovery checklist
- Local city/county considerations
- Professional license prompts by industry
- Registered agent explanation
- Liability separation basics

#### Texas-Specific Guardrails

- Texas assumed-name and entity filings should be verified against the Texas Secretary of State and county/city requirements.
- Texas taxable entity and franchise tax guidance should be verified against the Texas Comptroller.
- The app should not auto-generate legal conclusions. It should ask follow-up questions and recommend attorney/CPA review where needed.

#### V1 Output

- “Recommended setup path”
- “Questions to ask your CPA/attorney”
- “Documents you may need”
- “Government websites to review”
- “Formation checklist”

---

### 3.3 Texas Sales Tax Module

This module helps the user understand whether sales tax may apply and what to track.

#### Topics

- Texas sales tax permit awareness
- Taxable goods vs. taxable services
- Local sales tax considerations
- Filing frequency awareness
- What records to keep
- Invoice setup guidance
- Sales tax collected vs. business income distinction
- Marketplace sales / online sales prompts
- Late filing risk warnings

#### V1 Behavior

The app should not definitively determine taxability unless a rules database is later added. Instead, it should classify as:

- Likely taxable
- Possibly taxable
- Likely not taxable
- Needs professional/government confirmation

#### Output

- Sales tax readiness score
- Permit checklist
- Invoice and bookkeeping setup reminders
- Monthly/quarterly recurring task suggestions

---

### 3.4 Texas Franchise Tax Module

This module explains Texas franchise tax obligations for entities that may be subject to it.

#### Topics

- What Texas franchise tax is
- Which entities may need to file
- No-tax-due threshold awareness
- Annual filing calendar
- Public Information Report / Ownership Information Report awareness
- Revenue tracking needed for franchise tax
- CPA review trigger points

#### V1 Behavior

- Provide educational guidance only.
- Use a cached Texas franchise tax knowledge article.
- Require LLM freshness validation before displaying tax threshold numbers or deadlines.
- Clearly show the source date and “last verified” timestamp.

---

### 3.5 Accounting and Bookkeeping Module

This module helps users set up clean financial records.

#### Topics

- Separate business and personal spending
- Chart of accounts starter templates
- Bookkeeping software suggestions category, not affiliate recommendation
- Receipt retention
- Mileage tracking
- Owner contributions
- Owner draws/distributions
- Payroll expense tracking
- Contractor payments
- 1099 tracking
- Sales tax liability tracking
- Cash vs. accrual explanation
- Monthly close checklist
- Financial reports
  - Profit and loss
  - Balance sheet
  - Cash flow
  - Accounts receivable
  - Accounts payable

#### Output

- Bookkeeping maturity score
- Recommended chart of accounts template
- Monthly bookkeeping checklist
- “Ask your CPA” report

---

### 3.6 Banking Module

This module explains why the user should separate personal and business banking.

#### Core Recommendation

The app should strongly recommend that every business owner open a dedicated business bank account and avoid running business activity through a personal account.

#### Topics

- Business checking account
- Business savings account for taxes
- Separate card for business expenses
- Merchant services / payment processor account
- Documentation usually needed to open an account
  - EIN or SSN depending on structure
  - Entity formation documents, if applicable
  - Assumed name/DBA certificate, if applicable
  - Operating agreement or ownership info, if applicable
  - Personal identification
- Owner contributions
- Owner draws
- Reimbursements
- Tax reserve account
- Sales tax reserve account
- Payroll reserve account
- Banking hygiene checklist

#### Output

- Banking setup checklist
- “What to bring to the bank” checklist
- Account separation risk warning
- Suggested account structure

---

### 3.7 Owner Pay Module

This module compares owner compensation approaches based on business structure.

#### Compensation Types to Explain

- Owner draw
- Distribution
- Retained earnings / leaving profit in the business
- Guaranteed payments
- W-2 salary as employee-owner
- Dividends
- Reimbursements under accountable plan
- Profit allocation between partners/members

#### Decision Matrix

Each method should include:

- Who can typically use it
- Pros
- Cons
- Tax considerations
- Payroll considerations
- Cash-flow considerations
- Compliance risk
- When to ask a CPA

#### Important Logic

The app must first identify or ask for entity/tax status before recommending owner pay options.

Examples:

- Sole proprietor: usually owner draws, not W-2 salary to self.
- Partnership: guaranteed payments may be relevant.
- LLC taxed as disregarded entity: owner draws are common, but self-employment tax planning matters.
- S corporation: reasonable W-2 compensation is generally required before non-wage distributions.
- C corporation: W-2 salary and dividends may both be relevant, with double-taxation considerations.

#### Output

- Owner pay comparison table
- Recommended questions for CPA
- Pay method risk flags
- Suggested cash-flow policy

---

### 3.8 Employee / Payroll Module

This module activates when the business has or plans to hire employees.

#### Topics

- EIN requirement awareness
- Texas Workforce Commission employer registration awareness
- Payroll provider setup
- Federal payroll taxes
- State unemployment tax
- W-4, I-9, direct deposit, handbook basics
- New hire reporting awareness
- Workers’ compensation discussion
- Employee vs. contractor distinction
- Wage and overtime basics
- Payroll calendar
- Payroll tax due-date reminders

#### Output

- First employee checklist
- Payroll setup checklist
- Employee compliance checklist
- Monthly/quarterly payroll task reminders

---

### 3.9 Contractor / 1099 Module

This module helps businesses track vendors and contractors.

#### Topics

- Contractor onboarding checklist
- W-9 collection
- Vendor records
- 1099-NEC tracking
- Employee vs. contractor risk prompts
- Payment method tracking
- Year-end reporting reminders

#### Output

- Contractor checklist
- 1099 readiness score
- Missing W-9 warning list

---

### 3.10 Recurring Task Calendar Module

This module is central to the app experience.

The user should receive a recurring compliance and operations checklist divided into weekly, monthly, quarterly, and yearly tasks.

#### Weekly Tasks

- Categorize new transactions
- Upload receipts
- Review cash balance
- Review accounts receivable
- Review unpaid bills
- Review sales tax collected
- Log mileage
- Review payroll hours, if employees exist
- Review upcoming deadlines
- Check open customer/vendor issues

#### Monthly Tasks

- Reconcile bank accounts
- Reconcile credit cards
- Review profit and loss
- Review balance sheet
- Move money into tax reserve account
- Review sales tax liability
- File/pay sales tax if monthly filer
- Review payroll reports
- Review contractor payments
- Update owner pay plan
- Review subscriptions and recurring expenses
- Review business insurance needs
- Review marketing results

#### Quarterly Tasks

- Review estimated federal tax payments
- Review franchise tax revenue projection
- Review payroll tax filings, if applicable
- Review sales tax filings if quarterly filer
- Review bookkeeping cleanup with CPA/bookkeeper
- Review profit distributions / owner draws
- Review cash-flow forecast
- Review contractor totals for 1099 planning
- Review pricing and margins

#### Yearly Tasks

- Prepare books for tax filing
- Review Texas franchise tax requirements
- Review annual entity compliance
- Review 1099 filing requirements
- Review W-2 filing requirements
- Review insurance policies
- Renew permits/licenses, if applicable
- Review registered agent and business address
- Review owner compensation strategy
- Review entity/tax election fit
- Review banking and credit needs
- Build annual budget
- Refresh brand/website/advertising plan

#### Output

- Personalized task calendar
- Task frequency
- Source/rationale
- Owner role
- Due date if known
- Confidence level
- “Ask CPA/bookkeeper” flag
- Completion tracking

---

### 3.11 Branding Kit Module

This module helps early-stage businesses create a basic brand identity.

#### Topics

- Business name generation
- Tagline generation
- Brand positioning
- Tone of voice
- Color palette suggestions
- Font style suggestions
- Logo concept generation
- Social bio generation
- Elevator pitch
- Brand usage notes
- Basic customer persona

#### LLM Features

- Generate 10 business name ideas
- Check obvious name conflicts conceptually, but do not replace legal trademark/entity search
- Generate logo prompt options
- Generate tagline options
- Generate brand voice guide
- Generate simple style guide
- Generate website headline and homepage copy

#### Output

- Brand kit PDF/HTML page later
- V1 Markdown/HTML brand profile
- Logo/image prompt pack
- Social media bio pack

---

### 3.12 Image and Advertising Module

This module helps owners generate basic ad concepts and marketing assets.

#### Topics

- Social media post ideas
- Facebook/Instagram ad copy
- Google ad text concepts
- Local service ad copy
- Flyer copy
- Before/after post captions
- Seasonal promotion ideas
- Basic image generation prompts
- Product/service photography shot list
- Landing page copy

#### V1 LLM Features

- Generate 5 ad angles
- Generate 10 social posts
- Generate 3 image prompts
- Generate 1 landing page outline
- Generate “first 30 days marketing plan”

#### Output

- Ad copy variants
- Image prompt variants
- Marketing checklist
- Brand consistency warnings

---

## 4. LLM Architecture

The app should use cached knowledge articles and LLM validation rather than generating all compliance advice from scratch every time.

### 4.1 Core Principle

Do not let a single LLM generate final legal/tax/compliance guidance unsupported.

Use a pipeline:

1. Retrieve cached answer / knowledge article.
2. Retrieve relevant user profile facts.
3. Retrieve source metadata and last verified date.
4. Run low-cost LLM validation checks.
5. Run final decision model.
6. Return a structured answer with confidence, caveats, and source freshness.

---

### 4.2 Cached Response System

#### Cache Types

- Static knowledge article
- Texas compliance explainer
- Checklist template
- Decision tree
- Owner pay comparison
- Banking checklist
- Formation guide
- Payroll guide
- Branding prompt template
- Ad prompt template

#### Cache Metadata

Each cached article should store:

- Title
- Slug
- Jurisdiction
- Topic category
- Business stage applicability
- Entity applicability
- Employee applicability
- Source URLs
- Source type
- Last verified date
- Next required review date
- Confidence rating
- Risk level
- Version number
- Author/editor
- LLM validation history

---

### 4.3 Multi-Model Validation Pipeline

#### Suggested Flow

1. **Model A — Low-cost factual reviewer**
   - Checks for obvious outdated information.
   - Flags missing caveats.
   - Returns structured concerns.

2. **Model B — Low-cost contradiction reviewer**
   - Compares cached answer against source snippets.
   - Looks for contradictions or overconfident claims.

3. **Model C — Low-cost user-fit reviewer**
   - Checks whether the answer matches the user’s business profile.
   - Flags irrelevant sections.

4. **Final Judge Model**
   - Sonnet-tier, GPT-5-tier, or equivalent stronger model.
   - Decides whether to approve, revise, escalate, or refuse definitive answer.

#### Final Judge Output

- Approved answer
- Revised answer
- Confidence level
- Source freshness status
- Must-show caveats
- Recommended professional review
- Whether cache should be invalidated
- Whether admin review is required

---

### 4.4 Validation Statuses

Each answer should have one of these statuses:

- `approved_current`
- `approved_with_caveats`
- `needs_source_refresh`
- `needs_professional_review`
- `conflicting_sources`
- `not_enough_information`
- `admin_review_required`

---

### 4.5 Hallucination Controls

- Never let the model invent filing deadlines.
- Never let the model invent tax rates.
- Never let the model invent city/county obligations.
- Require source-backed cached content for compliance topics.
- Separate creative/branding generation from compliance generation.
- Show confidence levels.
- Show “last verified” dates.
- Escalate unclear tax/legal issues to CPA/attorney review.
- Keep all source URLs and source snapshots in the admin system.

---

## 5. Technical Stack

Use the normal stack:

- Laravel
- MySQL
- Livewire
- Tailwind CSS
- Alpine.js
- Queues for LLM validation jobs
- Scheduled commands for cache revalidation
- OpenRouter for model routing

### 5.1 Database Choice

Use MySQL for V1 beta.

Rationale:

- Fits the existing Laravel stack well.
- Simple deployment and maintenance.
- Strong enough for transactional app data, checklists, cached responses, validation logs, and user profiles.
- Avoids adding complexity before the product proves itself.

PostgreSQL could be revisited later if advanced JSON querying, full-text search, analytics, or more complex document structures become central.

---

## 6. Suggested Data Model

### 6.1 Core Tables

#### users
Standard Laravel users table.

#### businesses
Stores each company profile.

Fields:

- id
- user_id
- name
- stage
- legal_structure
- tax_classification
- city
- county
- state
- industry
- employee_count
- contractor_count
- has_ein
- has_sales_tax_permit
- has_business_bank
- has_bookkeeping
- has_payroll
- first_sale_date
- first_employee_date
- annual_revenue_range
- created_at
- updated_at

#### business_profiles
Stores deeper intake answers.

Fields:

- id
- business_id
- question_key
- answer_value
- confidence
- created_at
- updated_at

#### knowledge_articles
Stores cached answer content.

Fields:

- id
- title
- slug
- jurisdiction
- category
- body_markdown
- source_summary
- risk_level
- last_verified_at
- next_review_at
- status
- version
- created_at
- updated_at

#### knowledge_sources
Stores source references for cached knowledge.

Fields:

- id
- knowledge_article_id
- source_name
- source_url
- source_type
- retrieved_at
- effective_date
- notes
- created_at
- updated_at

#### llm_validations
Stores multi-model validation logs.

Fields:

- id
- knowledge_article_id
- business_id nullable
- model_name
- model_role
- prompt_hash
- response_json
- verdict
- confidence
- concerns_json
- created_at

#### advisor_sessions
Stores user guidance sessions.

Fields:

- id
- business_id
- session_type
- status
- summary
- created_at
- updated_at

#### advisor_recommendations
Stores generated recommendations.

Fields:

- id
- advisor_session_id
- business_id
- category
- title
- body
- priority
- confidence
- validation_status
- professional_review_required
- due_date nullable
- created_at
- updated_at

#### recurring_tasks
Stores task templates and generated user tasks.

Fields:

- id
- business_id nullable
- knowledge_article_id nullable
- title
- description
- category
- frequency
- applies_to_stage
- applies_to_entity
- applies_when_employee_count_min
- due_rule
- next_due_at
- status
- confidence
- created_at
- updated_at

#### task_completions
Tracks completed recurring tasks.

Fields:

- id
- recurring_task_id
- business_id
- completed_by
- completed_at
- notes
- created_at
- updated_at

#### brand_kits
Stores generated brand assets.

Fields:

- id
- business_id
- business_name_options_json
- tagline_options_json
- brand_voice
- color_palette_json
- font_style_notes
- logo_prompt_json
- ad_copy_json
- social_bio_json
- created_at
- updated_at

---

## 7. UI / UX Roadmap

### 7.1 Main Navigation

- Dashboard
- Company Profile
- Roadmap
- Tasks
- Taxes
- Formation
- Banking
- Accounting
- Payroll
- Owner Pay
- Branding
- Advertising
- Settings

### 7.2 Dashboard

Dashboard should show:

- Business setup score
- Compliance risk score
- Next 5 recommended actions
- Upcoming tasks
- Missing setup items
- Last validated compliance content date
- “Ask Advisor” prompt box

### 7.3 Roadmap View

The roadmap should be grouped by phase:

1. Foundation
2. Legal setup
3. Taxes
4. Banking
5. Accounting
6. Payroll
7. Owner pay
8. Branding
9. Advertising
10. Growth readiness

Each item should show:

- Required / recommended / optional
- Status
- Priority
- Why it matters
- Who should review it
- Source freshness

### 7.4 Task Calendar View

Views:

- This week
- This month
- This quarter
- This year
- All tasks

Task cards should show:

- Task name
- Frequency
- Due date
- Why it matters
- Related module
- Completion checkbox
- Notes

### 7.5 Advisor Chat / Guided Q&A

The advisor should answer based on:

- Business profile
- Cached knowledge articles
- Validation status
- Current module context

Answers should include:

- Direct recommendation
- Why it matters
- What to do next
- Confidence level
- Caveats
- Related tasks
- Professional review flag

---

## 8. Admin System

V1 should include a lightweight admin area for maintaining knowledge.

### Admin Features

- Create/edit knowledge articles
- Attach source URLs
- Set jurisdiction
- Set category
- Set risk level
- Set review frequency
- View validation history
- Run revalidation manually
- Mark article stale
- Approve revised answer
- See conflicting model outputs

### Admin Review Triggers

- Tax threshold changed
- Deadline changed
- Source unavailable
- Model disagreement
- Low confidence
- High-risk legal/tax topic
- User reports incorrect answer

---

## 9. V1 Beta Build Phases

### Phase 1 — Foundation

- Laravel app setup
- Auth
- Business profile intake
- MySQL schema
- Basic dashboard
- Static roadmap generation
- Manual knowledge article seeding

### Phase 2 — Compliance Knowledge Cache

- Knowledge article CRUD
- Source metadata
- Texas-only content categories
- Cached responses
- Last verified timestamps
- Basic source/caveat display

### Phase 3 — Personalized Roadmap

- Stage classifier
- Entity classifier
- Employee/payroll trigger logic
- Sales tax readiness questions
- Banking readiness questions
- Accounting readiness questions
- Owner pay module routing

### Phase 4 — Recurring Task System

- Weekly/monthly/quarterly/yearly task templates
- Generate tasks from business profile
- Dashboard reminders
- Completion tracking
- Due date rules

### Phase 5 — LLM Validation Pipeline

- OpenRouter integration
- Model configuration table
- Low-cost reviewer prompts
- Final judge prompt
- Validation logs
- Cache approval statuses
- Admin review queue

### Phase 6 — Advisor Q&A

- Ask Advisor interface
- Retrieve relevant business profile
- Retrieve cached article
- Run validation if stale or high risk
- Generate structured answer
- Save session history

### Phase 7 — Branding and Advertising

- Brand kit generator
- Business name generator
- Tagline generator
- Logo prompt generator
- Ad copy generator
- Social post generator
- Brand kit storage

### Phase 8 — Beta Hardening

- Add disclaimers
- Add logging
- Add error states
- Add user feedback button
- Add stale answer handling
- Add admin review dashboard
- Add seed content for common Texas cases

---

## 10. V1 Beta Seed Knowledge Articles

Seed the system with articles for:

1. Starting a Texas business from scratch
2. Sole proprietor vs. LLC in Texas
3. DBA / assumed name basics in Texas
4. Texas sales tax permit basics
5. Texas franchise tax basics
6. First employee checklist in Texas
7. Business banking separation basics
8. Basic bookkeeping setup
9. Owner draw vs. salary vs. distribution
10. S corporation owner pay basics
11. Partnership guaranteed payments basics
12. C corporation salary and dividends basics
13. Contractor and 1099 tracking basics
14. Weekly business admin checklist
15. Monthly bookkeeping checklist
16. Quarterly tax review checklist
17. Yearly business compliance checklist
18. Brand kit starter guide
19. First 30 days local advertising plan
20. When to hire a CPA, bookkeeper, attorney, or payroll provider

---

## 11. Compliance and Safety Disclaimers

Every legal/tax/compliance answer should include a concise disclaimer:

> This is general educational guidance for Texas small businesses, not legal, tax, payroll, or accounting advice. Confirm details with the applicable government agency or a qualified CPA, attorney, payroll provider, or bookkeeper before filing or making a tax/legal decision.

High-risk answers should also include:

- Last verified date
- Source links
- Confidence rating
- “Professional review recommended” flag

---

## 12. Key Source Categories for Validation

The app should prioritize official sources for compliance validation:

- Texas Comptroller
- Texas Secretary of State
- Texas Workforce Commission
- IRS
- City/county websites for local requirements
- Department of Labor where relevant

V1 should not rely on blogs as final compliance authority unless they are only used for plain-English explanation and official sources are also attached.

---

## 13. Known V1 Limitations

- Texas only.
- No automated government filing.
- No definitive legal/tax determinations.
- No city/county permit engine yet.
- No full taxability database yet.
- No payroll calculation engine yet.
- No document generation beyond checklists and educational drafts.
- No professional marketplace in V1.
- No banking integration in V1 unless added later.
- LLM validation reduces hallucination risk but does not eliminate it.

---

## 14. Future Versions

### V2 Ideas

- Add more states
- City/county permit lookup
- CPA/bookkeeper/attorney referral workflow
- Filing deadline calendar integration
- Bank transaction import
- QuickBooks/Xero integration
- Payroll provider integration
- Document generator
- Operating agreement intake packet
- Entity comparison calculator
- Owner pay calculator
- Tax reserve calculator
- Franchise tax estimate helper
- Sales tax filing reminders
- Business license renewal tracker
- AI-generated SOPs
- Professional review marketplace

---

## 15. Suggested Agent Build Tickets

### Ticket 1 — Create Business Intake and Profile Schema

Build the initial business profile intake flow and database schema. The intake should classify the user’s business stage, legal structure, employee status, sales tax readiness, banking readiness, and bookkeeping readiness.

### Ticket 2 — Build Texas Knowledge Article Cache

Create CRUD for cached knowledge articles with source metadata, jurisdiction, topic category, risk level, last verified date, next review date, and status.

### Ticket 3 — Build Personalized Roadmap Generator

Use business profile answers to generate a roadmap grouped by formation, taxes, banking, bookkeeping, payroll, owner pay, branding, and advertising.

### Ticket 4 — Build Recurring Task System

Create weekly, monthly, quarterly, and yearly recurring task templates. Generate business-specific tasks based on entity type, employee count, sales tax status, and business stage.

### Ticket 5 — Build OpenRouter Validation Pipeline

Create a multi-model validation pipeline using three lower-cost reviewers and one stronger final judge model. Store validation logs and verdicts.

### Ticket 6 — Build Advisor Q&A Interface

Create an advisor chat/guided Q&A interface that retrieves the business profile and cached knowledge articles before answering.

### Ticket 7 — Build Owner Pay Decision Module

Create an owner compensation comparison module covering draws, distributions, retained earnings, guaranteed payments, W-2 salary, dividends, and reimbursements.

### Ticket 8 — Build Banking Setup Module

Create a business banking checklist and guidance flow that encourages separation of personal and business finances.

### Ticket 9 — Build Branding Kit Generator

Create LLM-assisted name, tagline, brand voice, logo prompt, color palette, and social bio generation.

### Ticket 10 — Build Advertising Generator

Create LLM-assisted ad copy, image prompt, social post, landing page outline, and first 30-day marketing plan generation.

---

## 16. Definition of Done for V1 Beta

V1 beta is ready when:

- A user can create a business profile.
- The app can classify their stage.
- The app can generate a personalized roadmap.
- The app can generate weekly/monthly/quarterly/yearly tasks.
- The app can display Texas-first cached compliance content.
- Compliance content has source metadata and last verified dates.
- LLM validation can run and store verdicts.
- High-risk advice includes caveats and professional review flags.
- The user can generate a basic branding kit.
- The user can generate starter advertising copy/prompts.
- Admin can mark content stale and request revalidation.

