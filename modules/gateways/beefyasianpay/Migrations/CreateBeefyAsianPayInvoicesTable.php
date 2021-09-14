<?php

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

class CreateBeefyAsianPayInvoicesTable
{
    /**
     * Execute the migration.
     *
     * @return  void
     */
    public function execute()
    {
        /** @var  Illuminate\Support\Facades\Schema $schema */
        $schema = Capsule::schema();

        if (!$schema->hasTable('mod_beefy_asian_pay_invoices')) {
            $schema->create('mod_beefy_asian_pay_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('address');
                $table->integer('invoice_id');
                $table->decimal('amount');
                $table->boolean('is_paid')->default(false);
                $table->timestamp('paid_at')->nullable();
                $table->decimal('paid_amount')->nullable()->default(0.00);
                $table->string('transaction_id')->nullable();
                $table->string('paid_address')->nullable();
                $table->timestamp('expires_on')->nullable();
                $table->boolean('is_released')->default(false);
                $table->timestamps();
            });
        }
    }
}
