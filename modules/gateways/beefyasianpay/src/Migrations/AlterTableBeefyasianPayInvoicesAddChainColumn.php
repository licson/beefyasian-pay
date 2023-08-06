<?php

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

class AlterTableBeefyasianPayInvoicesAddChainColumn
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

        if ($schema->hasTable('mod_beefyasian_pay_invoices') && !$schema->hasColumn('mod_beefyasian_pay_invoices', 'chain')) {
            $schema->table('mod_beefyasian_pay_invoices', function (Blueprint $table) {
                $table->enum('chain', ['TRC20', 'POLYGON'])->after('invoice_id')->default('TRC20');
            });
        }
    }
}
