<?php

namespace BeefyAsianPay\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tblinvoices';


    /**
     * Invoice transactions.
     *
     * @return  \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'invoiceid');
    }
}
