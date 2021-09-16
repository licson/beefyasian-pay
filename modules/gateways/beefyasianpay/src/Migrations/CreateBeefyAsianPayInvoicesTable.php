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

        if (!$schema->hasTable('mod_beefyasian_pay_invoices')) {
            $schema->create('mod_beefyasian_pay_invoices', function (Blueprint $table) {
                $table->id();
                $table->integer('invoice_id');
                $table->string('to_address');
                $table->string('from_address')->nullable();
                $table->string('transaction_id')->nullable();
                $table->timestamp('expires_on')->nullable();
                $table->boolean('is_released')->default(false);
                $table->timestamps();
            });
        }
    }
}
