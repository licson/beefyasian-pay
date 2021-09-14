<?php

namespace BeefyAsianPay;

use BeefyAsianPay\Exceptions\NoAddressAvailable;
use BeefyAsianPay\Models\BeefyAsianPayInvoice;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Throwable;

class App
{
    /**
     * USDT Addresses.
     *
     * @var string[]
     */
    protected $addresses = [];

    /**
     * The payment gateway fields.
     *
     * @var string[]
     */
    protected $config = [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => '肉償付',
        ],
        'addresses' => [
            'FriendlyName' => 'USDT Addresses',
            'Type' => 'textarea',
            'Rows' => '20',
            'Cols' => '30',
        ],
    ];

    /**
     * Create a new instance.
     *
     * @param   string  $addresses
     *
     * @return  void
     */
    public function __construct(string $addresses = '')
    {
        $this->addresses = preg_split("/\r\n|\n|\r/", $addresses);
    }

    /**
     * Install BeefyAsianPay.
     *
     * @return  string[]
     */
    public function install()
    {
        $this->runMigrations();

        return $this->config;
    }

    /**
     * Run beefy asian pay migrations.
     *
     * @return  void
     */
    protected function runMigrations()
    {
        $migrationPath = __DIR__ . DIRECTORY_SEPARATOR . 'Migrations';
        $migrations = array_diff(scandir($migrationPath), ['.', '..']);

        foreach ($migrations as $migration) {
            require_once $migrationPath . DIRECTORY_SEPARATOR . $migration;

            $migrationName = str_replace('.php', '', $migration);

            (new $migrationName)->execute();
        }
    }

    /**
     * Get trc20 transfer QRCode.
     *
     * @param   string  $address
     * @param   float   $amount
     *
     * @return  string
     */
    protected function getQRCode(string $address, float $amount): string
    {
        $http = new Client([
            'base_uri' => 'https://api.cryptapi.io',
            'timeout' => 15,
        ]);

        $response = $http->get('/trc20/usdt/qrcode', [
            'query' => [
                'address' => $address,
                'amount' => $amount,
            ]
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        return $response['qr_code'];
    }

    /**
     * Render payment html.
     *
     * @param   array  $params
     *
     * @return  string
     */
    public function render(array $params): string
    {
        try {
            $beefyInvoice = new BeefyAsianPayInvoice();

            $address = '';
            if ($validAddress = $beefyInvoice->validInvoice($params['invoiceid'])) {
                $validAddress->renew();
                $address = $validAddress->address;
            } else {
                $address = $this->getAvailableAddress($params['invoiceid'], $params['amount']);
            }

            $qrcode = $this->getQRCode($address, $params['amount']);
            return <<<HTML
                <p>TRC20: <small>$address</small></p>
                <img src="data:image/png;base64,{$qrcode}" alt="" height="150">
            HTML;
        } catch (Throwable $e) {
            return <<<HTML
                <p>No address. please try again later. {$e->getMessage()}</p>
            HTML;
        }
    }

    /**
     * Remove expired invoices.
     *
     * @return  void
     */
    public function cron()
    {
        require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'init.php';
        require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/gatewayfunctions.php';
        require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/invoicefunctions.php';

        $this->checkPaidInvoice();

        (new BeefyAsianPayInvoice())->markExpiredInvoiceAsReleased();
    }

    /**
     * Check paid invoices.
     *
     * @return  void
     */
    protected function checkPaidInvoice()
    {
        $invoices = (new BeefyAsianPayInvoice())->getValidInvoices();

        $invoices->each(function ($invoice) {
            $transactions = $this->getTransactions($invoice['address'])->filter(function ($transaction) {
                return ! $transaction->confirmed;
            });

            $transactions->each(function ($transaction) use ($invoice) {
                addInvoicePayment($invoice['invoice_id'], $transaction['transaction_id'], '', 0, 'BeefyAsianPay');
                logTransaction('BeefyAsianPay', $transaction['transaction_id'], 'Successfully Paid');

                $actualAmount = $transaction['quant'] / 1000000 + $invoice['paid_amount'];
                if ($actualAmount >= $invoice['amount']) {
                    $invoice->markAsPaid($transaction['from_address'], $actualAmount, $transaction['transaction_id']);
                } else {
                    $invoice->updatePaidAmount($actualAmount);
                    $invoice->renew();
                }
            });
        });
    }

    /**
     * Get TRC 20 address transactions.
     *
     * @param   string  $address
     *
     * @return  Collection
     */
    protected function getTransactions(string $address): Collection
    {
        $http = new Client([
            'base_uri' => 'https://apiasia.tronscan.io:5566',
            'timeout' => 10,
        ]);

        $response = $http->get('/api/token_trc20/transfers', [
            'query' => [
                'direction' => 'in',
                'count' => true,
                'tokens' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'start_timestamp' => Carbon::now()->subMinutes(30)->getTimestamp(),
                'relatedAddress' => $address,
            ],
        ]);

        $response = json_decode($response->getBody(), true);

        return new Collection($response['token_transfers']);
    }

    /**
     * Get an available usdt address.
     *
     * @param   int     $invoiceId
     * @param   float   $amount
     *
     * @return  string
     * @throws  NoAddressAvailable
     */
    protected function getAvailableAddress(int $invoiceId, float $amount): string
    {
        $beefyInvoice = new BeefyAsianPayInvoice();

        $inUseAddresses = $beefyInvoice->inUse()->get(['address']);

        $availableAddresses = array_diff($this->addresses, $inUseAddresses->pluck('address')->toArray());

        if (count($availableAddresses) <= 0) {
            throw new NoAddressAvailable('not enough addresses.');
        }

        $address = $availableAddresses[mt_rand(0, count($availableAddresses) - 1)];
        $beefyInvoice->associate($address, $invoiceId, $amount);

        return $address;
    }
}
