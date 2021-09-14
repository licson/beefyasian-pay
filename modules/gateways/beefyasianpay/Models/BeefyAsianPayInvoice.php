<?php

namespace BeefyAsianPay\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class BeefyAsianPayInvoice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mod_beefy_asian_pay_invoices';

    /**
     * Release time (minutes).
     *
     * @var int
     */
    public const RELEASE_TIMEOUT = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'address',
        'invoice_id',
        'amount',
        'is_paid',
        'paid_at',
        'paid_address',
        'paid_amount',
        'transaction_id',
        'expires_on',
        'is_released',
    ];

    /**
     * The attributes that should be cast to native type.
     *
     * @var string[]
     */
    protected $casts = [
        'paid_at' => 'date',
        'expires_on' => 'date',
    ];

    /**
     * In use condition.
     *
     * @param   Builder  $builder
     *
     * @return  Builder
     */
    public function scopeInUse(Builder $builder): Builder
    {
        return $builder->where('is_released', false);
    }

    /**
     * Assoicated with an invoice.
     *
     * @param   string  $address
     * @param   int     $invoiceId
     * @param   float   $amount
     *
     * @return  void
     */
    public function associate(string $address, int $invoiceId, float $amount)
    {
        $this->newQuery()->create([
            'address' => $address,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'expires_on' => Carbon::now()->addMinutes(self::RELEASE_TIMEOUT),
        ]);
    }

    /**
     * Determine if there is an invoice within the validity period.
     *
     * @param   int  $invoiceId
     *
     * @return  \Illuminate\Support\Collection
     */
    public function validInvoice(int $invoiceId)
    {
        return $this->newQuery()
            ->where('invoice_id', $invoiceId)
            ->where('is_paid', 0)
            ->where('expires_on', '>', Carbon::now())
            ->first();
    }

    /**
     * Update the expires date.
     *
     * @return  bool
     */
    public function renew(): bool
    {
        return $this->forceFill(['expires_on' => Carbon::now()->addMinutes(self::RELEASE_TIMEOUT)])->save();
    }

    /**
     * Mark all expired invoices as released.
     *
     * @return  void
     */
    public function markExpiredInvoiceAsReleased()
    {
        $this->newQuery()
            ->where('expires_on', '<=', Carbon::now())
            ->update([
                'is_released' => true,
            ]);
    }

    /**
     * Get all valid invoices.
     *
     * @return  \Illuminate\Support\Collection
     */
    public function getValidInvoices()
    {
        return $this->newQuery()
            ->where('is_paid', 0)
            ->where('expires_on', '>', Carbon::now())
            ->where('is_released', 0)
            ->get();
    }

    /**
     * Mark inovice as paid.
     *
     * @param  string  $paidAddress
     * @param  float   $paidAmount
     * @param  string  $transactionId
     *
     * @return  void
     */
    public function markAsPaid(string $paidAddress, float $paidAmount, string $transactionId)
    {
        $this->forceFill([
            'is_paid' => true,
            'paid_at' => Carbon::now(),
            'paid_address' => $paidAddress,
            'paid_amount' => $paidAmount,
            'transaction_id' => $transactionId,
            'is_released' => true,
        ])
        ->save();
    }

    /**
     * Mark inovice as paid.
     *
     * @param  float  $paidAmount
     *
     * @return  void
     */
    public function updatePaidAmount(string $paidAmount)
    {
        $this->forceFill([
            'paid_amount' => $paidAmount,
        ])
        ->save();
    }
}
