# LLM Validation Pipeline

## Purpose

Mentrovia should use LLMs as a validation and synthesis layer, not as an unchecked source of truth.

The app will maintain cached advisory content for common small business questions. Before presenting higher-risk guidance, the system should validate whether the cached response is still current, properly scoped, and safe to display.

This is especially important for topics like:

- Texas sales tax
- Texas franchise tax
- Employer registration
- Payroll obligations
- Entity setup
- Owner compensation
- City/county requirements
- Filing deadlines
- Thresholds and tax numbers

## Core Pattern

```txt
User question + company context
        ↓
Retrieve cached advisory article(s)
        ↓
Check article freshness and source references
        ↓
Run low-cost validator models in parallel
        ↓
Aggregate disagreements, flags, and citations
        ↓
Run final decision model
        ↓
Apply guardrails and disclaimers
        ↓
Return answer, checklist, confidence, and professional review flags
```

## Goals

- Reduce hallucinations
- Lower cost by reusing cached knowledge
- Detect stale answers
- Require extra review for high-risk guidance
- Show users when advice was last verified
- Preserve an audit trail of model decisions
- Keep legal/tax/payroll disclaimers visible

## Non-Goals

- Do not let LLMs invent legal or tax rules.
- Do not let LLMs silently update compliance thresholds without source verification.
- Do not present model confidence as legal certainty.
- Do not replace CPA, attorney, payroll, or government guidance.

---

## Suggested Pipeline Stages

### 1. Input Normalization

Normalize the user question and company profile into a structured request.

#### Inputs

- User question
- Company profile
- Business stage
- Entity type
- City/county/state
- Employee count
- Sales tax exposure
- Payroll exposure
- Existing risk flags

#### Output

A normalized advisory request:

```json
{
  "topic": "texas_sales_tax",
  "risk_level": "high",
  "jurisdiction": {
    "state": "TX",
    "city": "San Antonio",
    "county": "Bexar"
  },
  "company_stage": "existing_dba",
  "requires_source_freshness": true
}
```

### 2. Cached Knowledge Retrieval

Retrieve relevant advisory articles from the local database.

Each article should include:

- Title
- Topic
- Jurisdiction
- Version
- Body
- Source references
- Last verified date
- Risk level
- Staleness status
- Required professional disclaimer type

### 3. Freshness Gate

Before running model validation, check whether the article is stale.

Potential status values:

- fresh
- needs_review_soon
- stale
- source_changed
- missing_sources
- deprecated

If the answer is stale or missing sources, the UI should either block the answer or display it as unverified draft guidance only.

### 4. Low-Cost Model Validation

Run multiple low-cost validators through OpenRouter.

Each validator should answer structured questions:

- Does the cached answer directly answer the user’s question?
- Is it scoped correctly to Texas?
- Does it make unsupported legal/tax claims?
- Does it mention thresholds, dates, or filing requirements that require freshness checks?
- Does it need CPA/attorney/payroll review language?
- What parts are uncertain?
- Should this answer be shown, revised, escalated, or blocked?

Example output:

```json
{
  "validator_model": "low_cost_model_name",
  "vote": "revise",
  "confidence": 0.74,
  "flags": [
    "mentions_tax_deadline",
    "needs_source_date",
    "should_add_cpa_review"
  ],
  "notes": "The answer is broadly correct but should not state a filing deadline unless the source has been verified."
}
```

### 5. Aggregation

Aggregate model votes and identify disagreements.

Possible aggregate decisions:

- approve
- approve_with_disclaimer
- revise
- require_source_refresh
- escalate_to_strong_model
- block

### 6. Final Decision Model

Use a stronger model as the decision maker when:

- Validators disagree
- Topic risk is high
- Cached article is old
- User asks for entity/tax/payroll decision guidance
- Source references include dates, rates, thresholds, or filing rules

The final model should produce:

- Final answer
- Checklist
- Risk flags
- Source freshness notes
- Professional review notes
- Confidence level
- Follow-up questions only when necessary

### 7. Guardrail Pass

Before display, run a deterministic guardrail pass.

The final answer should be blocked or revised if it:

- Claims to be legal/tax advice
- Guarantees compliance
- Gives definitive entity/tax conclusions without sufficient facts
- Omits required disclaimers
- References stale thresholds or dates
- Mentions unsupported local requirements
- Advises illegal evasion or false filings

---

## Risk Levels

### Low Risk

Examples:

- Branding suggestions
- General bookkeeping organization
- Basic explanation of business bank separation
- Weekly task reminders

Behavior:

- Cached answer acceptable
- One validator may be enough
- No strong model needed unless stale

### Medium Risk

Examples:

- DBA vs LLC education
- Sales tax readiness explanation
- Owner pay concepts
- First employee checklist

Behavior:

- Multiple validators preferred
- Source references required for jurisdiction-specific claims
- Disclaimer required

### High Risk

Examples:

- Filing deadlines
- Tax thresholds
- Payroll registration requirements
- Franchise tax obligations
- Legal structure recommendation
- Worker classification
- Owner compensation optimization

Behavior:

- Multiple validators required
- Strong final model required
- Source freshness required
- Professional review flag required

---

## OpenRouter Provider Abstraction

The app should not hard-code model names throughout business logic.

Create a provider abstraction with named roles:

```php
'llm_roles' => [
    'classifier' => env('LLM_CLASSIFIER_MODEL'),
    'validator_low_cost_1' => env('LLM_VALIDATOR_MODEL_1'),
    'validator_low_cost_2' => env('LLM_VALIDATOR_MODEL_2'),
    'validator_low_cost_3' => env('LLM_VALIDATOR_MODEL_3'),
    'decision_model' => env('LLM_DECISION_MODEL'),
    'copy_model' => env('LLM_COPY_MODEL'),
    'brand_model' => env('LLM_BRAND_MODEL'),
]
```

Model choices should be configurable by environment.

---

## Suggested Database Tables

### advisory_articles

- id
- topic
- jurisdiction_state
- jurisdiction_city
- jurisdiction_county
- title
- slug
- risk_level
- body_markdown
- status
- last_verified_at
- created_at
- updated_at

### advisory_article_versions

- id
- advisory_article_id
- version_number
- body_markdown
- change_summary
- created_by
- created_at

### source_references

- id
- advisory_article_id
- title
- url
- publisher
- source_type
- retrieved_at
- last_checked_at
- status

### validation_runs

- id
- advisory_article_id
- company_id
- user_id
- normalized_request_json
- aggregate_decision
- final_confidence
- created_at

### validation_votes

- id
- validation_run_id
- model_role
- model_name
- vote
- confidence
- flags_json
- notes
- raw_response_json
- created_at

---

## UI Requirements

When displaying a validated answer, show:

- Answer
- Checklist
- Confidence label
- Last verified date
- Source links
- Professional review notes
- “This is guidance, not legal/tax advice” language

For stale answers, show:

- “This article needs review before it can be treated as current.”
- Source age
- Refresh action
- Draft answer only if allowed by risk level

---

## Implementation Notes

- Use queues for validation runs.
- Store raw model responses for audit/debugging.
- Use strict JSON schemas for validator outputs.
- Add retry logic and timeout handling.
- Cache low-risk responses aggressively.
- Revalidate high-risk articles on a schedule.
- Add admin review screens before publishing updated compliance guidance.

