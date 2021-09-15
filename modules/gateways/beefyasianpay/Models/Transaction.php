<?php

namespace BeefyAsianPay\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tblaccounts';

    /**
     * Find transaction by transaction id.
     *
     * @param   string  $transId
     *
     * @return  \Illuminate\Database\Eloquent\Model|null
     */
    public function firstByTransId(string $transId)
    {
        return $this->newQuery()
            ->where('transid', $transId)
            ->first();
    }
}
