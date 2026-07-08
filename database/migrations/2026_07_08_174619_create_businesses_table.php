<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('desired_name')->nullable();
            $table->string('dba_status')->default('no');
            $table->string('stage')->nullable();
            $table->string('legal_structure')->default('unsure');
            $table->string('tax_classification')->nullable();
            $table->string('industry');
            $table->string('city');
            $table->string('county');
            $table->string('state', 2)->default('TX');
            $table->string('location_type');
            $table->string('address')->nullable();
            $table->unsignedTinyInteger('owner_count')->default(1);
            $table->unsignedSmallInteger('employee_count')->default(0);
            $table->boolean('uses_contractors')->default(false);
            $table->string('sells_taxable_goods')->default('unsure');
            $table->string('sells_taxable_services')->default('unsure');
            $table->string('has_sales_tax_permit')->default('unsure');
            $table->string('has_ein')->default('unsure');
            $table->boolean('has_business_bank')->default(false);
            $table->boolean('has_bookkeeping')->default(false);
            $table->boolean('has_payroll')->default(false);
            $table->string('annual_revenue_range')->nullable();
            $table->string('monthly_revenue_range')->nullable();
            $table->date('started_on')->nullable();
            $table->date('first_sale_on')->nullable();
            $table->date('first_employee_on')->nullable();
            $table->string('filing_confidence')->nullable();
            $table->timestamps();

            $table->index('stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
