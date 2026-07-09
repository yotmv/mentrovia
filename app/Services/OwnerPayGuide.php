<?php

namespace App\Services;

use App\Enums\LegalStructure;
use App\Enums\OwnerPayFit;
use App\Enums\OwnerPayMethod;
use App\Models\Business;

/**
 * Builds the owner-pay decision guide: a comparison of pay methods tailored
 * to the business's legal/tax structure, sourced from the seeded owner-pay
 * knowledge articles. Static v1 rules; no AI generation involved.
 */
class OwnerPayGuide
{
    public function advise(Business $business): OwnerPayAdvice
    {
        $structure = $business->legal_structure;

        return match (true) {
            $structure->isUndecided() => $this->undecidedStructure(),
            $structure === LegalStructure::SCorporation => $this->sCorporation(),
            $structure === LegalStructure::CCorporation => $this->cCorporation(),
            $structure === LegalStructure::Partnership => $this->partnership(),
            $structure === LegalStructure::Llc => $business->owner_count > 1
                ? $this->partnership(multiMemberLlc: true)
                : $this->soleProprietor(singleMemberLlc: true),
            default => $this->soleProprietor(),
        };
    }

    private function soleProprietor(bool $singleMemberLlc = false): OwnerPayAdvice
    {
        $cpaQuestions = [
            __('How much should I set aside for income and self-employment taxes per dollar I draw?'),
            __('Would an S election reduce my overall tax at my profit level, considering the payroll costs it would add?'),
            __('How should I schedule and record my draws so the books stay clean?'),
        ];

        if ($singleMemberLlc) {
            $cpaQuestions[] = __('Does my LLC\'s current tax classification still fit, or should I consider an S or C election?');
        }

        return new OwnerPayAdvice(
            needsStructureDecision: false,
            structureSummary: $singleMemberLlc
                ? __('A single-member LLC with default tax treatment is taxed like a sole proprietorship: you are taxed on the business profit whether or not you take money out. An S or C election would change these options, so confirm your current classification.')
                : __('As a sole proprietor, you and the business are the same taxpayer. You are taxed on the business profit whether or not you take money out, so owner pay is about cash-flow discipline rather than tax categories.'),
            options: [
                new OwnerPayOption(
                    method: OwnerPayMethod::OwnerDraw,
                    fit: OwnerPayFit::Typical,
                    summary: __('Move money from the business account to yourself for personal use. This is the normal way owners in your setup get paid.'),
                    caveats: [
                        __('A draw is not a deductible expense and not a paycheck. You are taxed on profit regardless of what you draw, generally including self-employment tax.'),
                        __('Plan for quarterly estimated taxes and keep a tax reserve so the bill never surprises you.'),
                        __('Make draws deliberate and recorded: a consistent amount on a schedule, not ad-hoc transfers when the personal account runs low.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::RetainedEarnings,
                    fit: OwnerPayFit::Available,
                    summary: __('Leave profit in the business to fund growth or smooth out lean months.'),
                    caveats: [
                        __('You are taxed on all profit whether or not you take it out, so leaving money in the business does not defer your personal tax bill.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::W2Salary,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Sole proprietors and single-member LLC owners with default tax treatment generally cannot put themselves on W-2 payroll for their own business.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Distribution,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Distributions are profit payouts from S corporations and partnerships. In your setup the equivalent is simply an owner draw.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::GuaranteedPayment,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Guaranteed payments only exist in partnerships and multi-member LLCs taxed as partnerships.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Dividend,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Dividends are paid by C corporations to shareholders. They do not apply to your setup.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::AccountablePlanReimbursement,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('You deduct business expenses directly on your own return. Accountable plans matter for corporation owner-employees, not sole proprietors.'),
                ),
            ],
            cpaQuestions: $cpaQuestions,
            articleSlugs: [
                'owner-draw-vs-salary-vs-distribution',
            ],
        );
    }

    private function partnership(bool $multiMemberLlc = false): OwnerPayAdvice
    {
        return new OwnerPayAdvice(
            needsStructureDecision: false,
            structureSummary: $multiMemberLlc
                ? __('A multi-member LLC with default tax treatment is taxed as a partnership: members generally are not employees of the business. Money reaches you through profit allocations, draws against them, and guaranteed payments for services, and the tax bill follows the allocation rather than the cash.')
                : __('Partners generally are not employees of their own partnership. Money reaches you through profit allocations, draws against them, and guaranteed payments for services, and the tax bill follows the allocation rather than the cash.'),
            options: [
                new OwnerPayOption(
                    method: OwnerPayMethod::OwnerDraw,
                    fit: OwnerPayFit::Typical,
                    summary: __('Take cash out against your share of partnership profit. Draws are the cash side of profit allocations that are already taxed to you.'),
                    caveats: [
                        __('You are taxed on your allocated share of profit whether or not cash is distributed.'),
                        __('Working partners generally owe self-employment tax and quarterly estimated taxes.'),
                        __('Draws affect each partner\'s basis and fairness between partners, so record them per partner.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::GuaranteedPayment,
                    fit: OwnerPayFit::Typical,
                    summary: __('Fixed amounts paid to a partner for services regardless of profit. The closest thing partnership tax law has to a salary.'),
                    caveats: [
                        __('Put amounts and conditions in the partnership agreement before the money flows.'),
                        __('Guaranteed payments are generally taxable to the receiving partner and subject to self-employment tax.'),
                        __('Record them distinctly in the books; they appear separately on the partnership return and each partner\'s K-1.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::RetainedEarnings,
                    fit: OwnerPayFit::Available,
                    summary: __('Leave profit in the partnership to fund growth or smooth out lean months.'),
                    caveats: [
                        __('Each partner is still taxed on their allocated share of profit even when no cash is distributed.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::AccountablePlanReimbursement,
                    fit: OwnerPayFit::Available,
                    summary: __('The partnership can repay documented business expenses you cover personally.'),
                    caveats: [
                        __('Set the reimbursement policy in writing and keep receipts; ask a CPA how it interacts with unreimbursed partner expense rules.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::W2Salary,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Partners generally cannot be W-2 employees of their own partnership. Guaranteed payments fill the salary role instead.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Distribution,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('In partnership taxation, distributions and draws against your profit share are the same thing; see owner draw.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Dividend,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Dividends are paid by C corporations to shareholders. They do not apply to your setup.'),
                ),
            ],
            cpaQuestions: [
                __('Should the working partner be compensated by guaranteed payment, a bigger profit share, or both?'),
                __('How do our draws interact with each partner\'s basis?'),
                __('Are our quarterly estimated taxes right given profit allocations plus guaranteed payments?'),
            ],
            articleSlugs: [
                'owner-draw-vs-salary-vs-distribution',
                'partnership-guaranteed-payments-basics',
            ],
        );
    }

    private function sCorporation(): OwnerPayAdvice
    {
        return new OwnerPayAdvice(
            needsStructureDecision: false,
            structureSummary: __('An S corporation is a federal tax election, not a Texas entity type. If you work in the business, the IRS expects a reasonable W-2 salary before profit distributions, and the entity still owes Texas franchise tax reporting regardless of the election.'),
            options: [
                new OwnerPayOption(
                    method: OwnerPayMethod::W2Salary,
                    fit: OwnerPayFit::Typical,
                    summary: __('Pay yourself a reasonable W-2 salary through payroll for the work you do. The IRS expects this before any profit distributions.'),
                    caveats: [
                        __('Taking large distributions on a tiny or zero salary is the classic audit trigger; reclassification plus penalties is the standard outcome.'),
                        __('"Reasonable" means what you would pay someone else to do your job. Document a defensible number with your CPA.'),
                        __('The salary must be funded even in lean quarters; distributions can flex, wages should not.'),
                        __('Health insurance premiums for a more-than-two-percent shareholder-employee have special W-2 reporting treatment.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Distribution,
                    fit: OwnerPayFit::Typical,
                    summary: __('Take remaining profit as periodic distributions, proportional to ownership, after your reasonable salary is covered.'),
                    caveats: [
                        __('Distributions generally avoid payroll taxes, which is exactly why the IRS insists on reasonable wages first.'),
                        __('Basis rules can make distributions taxable in some situations; track shareholder basis with your CPA.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::AccountablePlanReimbursement,
                    fit: OwnerPayFit::Available,
                    summary: __('Have the corporation repay your documented business expenses tax-free under a written accountable plan.'),
                    caveats: [
                        __('The plan must be set up properly: business purpose, substantiation, and timely return of excess advances.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::RetainedEarnings,
                    fit: OwnerPayFit::Available,
                    summary: __('Leave profit in the corporation to fund growth.'),
                    caveats: [
                        __('S corporation profit passes through to your return either way, so retaining cash does not defer your personal tax bill.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::OwnerDraw,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Informal draws are not a category for S corporation owners; money should leave as payroll or recorded distributions.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::GuaranteedPayment,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Guaranteed payments only exist in partnership taxation.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Dividend,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('S corporation payouts are distributions, not C corporation dividends.'),
                ),
            ],
            cpaQuestions: [
                __('What salary is defensible as "reasonable" for my role and hours?'),
                __('At my profit level, does the S election actually save money after payroll costs?'),
                __('How should my health insurance premiums be reported?'),
                __('Am I tracking shareholder basis correctly?'),
            ],
            articleSlugs: [
                'owner-draw-vs-salary-vs-distribution',
                's-corporation-owner-pay-basics',
            ],
        );
    }

    private function cCorporation(): OwnerPayAdvice
    {
        return new OwnerPayAdvice(
            needsStructureDecision: false,
            structureSummary: __('A C corporation is its own taxpayer: it pays corporate income tax on profits, and shareholders pay tax again on dividends. That double taxation shapes every owner-pay decision.'),
            options: [
                new OwnerPayOption(
                    method: OwnerPayMethod::W2Salary,
                    fit: OwnerPayFit::Typical,
                    summary: __('Pay yourself through payroll as an owner-employee. Salary is deductible to the corporation, so it is only taxed once, to you.'),
                    caveats: [
                        __('Because salary avoids the corporate-level tax, the IRS watches for unreasonably high owner salaries in C corporations, the mirror image of the S corporation issue.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Dividend,
                    fit: OwnerPayFit::Available,
                    summary: __('Distribute after-tax corporate profit to shareholders.'),
                    caveats: [
                        __('Dividends are not deductible to the corporation: the profit is taxed at the corporate level and again on your return.'),
                        __('They fit returning profit to non-working shareholders or deliberately distributing retained profits.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::RetainedEarnings,
                    fit: OwnerPayFit::Available,
                    summary: __('Keep profit in the corporation to fund growth, taxed only at the corporate level for now.'),
                    caveats: [
                        __('Accumulating earnings well beyond the business\'s needs can attract an IRS accumulated-earnings problem; document growth plans.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::AccountablePlanReimbursement,
                    fit: OwnerPayFit::Available,
                    summary: __('Have the corporation repay your documented business expenses tax-free under a written accountable plan.'),
                    caveats: [
                        __('The plan must be set up properly: business purpose, substantiation, and timely return of excess advances.'),
                        __('Loans to or from shareholders must be real loans with documented terms and interest, or they risk recharacterization as dividends or wages.'),
                    ],
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::OwnerDraw,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Informal withdrawals from a C corporation risk being recharacterized as dividends or wages; money should leave as payroll, dividends, or documented reimbursements.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::Distribution,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('C corporation profit payouts to shareholders are dividends; see that option instead.'),
                ),
                new OwnerPayOption(
                    method: OwnerPayMethod::GuaranteedPayment,
                    fit: OwnerPayFit::NotAvailable,
                    summary: __('Guaranteed payments only exist in partnership taxation.'),
                ),
            ],
            cpaQuestions: [
                __('What salary level is defensible for my role, and is any part at risk of being called excessive?'),
                __('Should this year\'s profit be salary, dividend, or retained for growth?'),
                __('Would an accountable plan and formal fringe-benefit setup save us money?'),
                __('Given my situation, is C status even the right election going forward?'),
            ],
            articleSlugs: [
                'owner-draw-vs-salary-vs-distribution',
                'c-corporation-salary-dividends-basics',
            ],
        );
    }

    private function undecidedStructure(): OwnerPayAdvice
    {
        $dependsOnStructure = fn (OwnerPayMethod $method, string $summary): OwnerPayOption => new OwnerPayOption(
            method: $method,
            fit: OwnerPayFit::DependsOnStructure,
            summary: $summary,
        );

        return new OwnerPayAdvice(
            needsStructureDecision: true,
            structureSummary: __('Your company profile does not settle on a legal structure yet, and owner-pay options depend almost entirely on it. Update your profile once you decide, or review the decision with a professional first. Here is what each method means so you can compare.'),
            options: [
                $dependsOnStructure(OwnerPayMethod::OwnerDraw, __('Taking money out of the business for personal use. The normal method for sole proprietors, single-member LLCs, and partners drawing against profit.')),
                $dependsOnStructure(OwnerPayMethod::W2Salary, __('Being on the business\'s payroll like any employee. Generally required for working S corporation owners and standard for C corporation owner-officers.')),
                $dependsOnStructure(OwnerPayMethod::Distribution, __('A payout of profits to owners of an S corporation, or a profit allocation in a partnership or multi-member LLC.')),
                $dependsOnStructure(OwnerPayMethod::GuaranteedPayment, __('Fixed payments to a partner for services regardless of profit. Partnership taxation\'s cousin of a salary.')),
                $dependsOnStructure(OwnerPayMethod::Dividend, __('A C corporation\'s distribution of after-tax profit to shareholders, taxed a second time on your return.')),
                $dependsOnStructure(OwnerPayMethod::RetainedEarnings, __('Leaving profit in the business to fund growth. Whether that defers your personal tax depends on the structure.')),
                $dependsOnStructure(OwnerPayMethod::AccountablePlanReimbursement, __('The business repaying your documented expenses tax-free under a written plan. Mostly relevant to corporation owner-employees.')),
            ],
            cpaQuestions: [
                __('Which legal structure fits my liability exposure and income level?'),
                __('What would each structure mean for how I pay myself and my total tax bill?'),
                __('When does an S election start to make sense at my profit level?'),
            ],
            articleSlugs: [
                'owner-draw-vs-salary-vs-distribution',
            ],
        );
    }
}
