<?php

namespace BeefyAsianPay;

use BeefyAsianPay\Exceptions\NoAddressAvailable;
use BeefyAsianPay\Models\BeefyAsianPayInvoice;
use BeefyAsianPay\Models\Invoice;
use BeefyAsianPay\Models\Transaction;
use Carbon\Carbon;
use Exception;
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

        $response = $http->get('/trc20/usdt/qrcode/', [
            'query' => [
                'address' => $address,
                'value' => $amount,
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
                $address = $this->getAvailableAddress($params['invoiceid']);
            }

            $qrcode = $this->getQRCode($address, $params['amount']);

            return <<<HTML
                <p>TRC20: <small>$address</small></p>
                <img src="data:image/png;base64,{$qrcode}" alt="" height="150">
                HTML;
            } catch (Throwable|Exception $e) {
            return <<<HTML
                <p>No available address. please try again later.</p>
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
            // Only confirmed transactions can be processed.
            $transactions = $this->getTransactions($invoice['to_address'])->filter(function ($transaction) {
                return ! $transaction->confirmed;
            });

            $transactions->each(function ($transaction) use ($invoice) {
                $whmcsTransaction = (new Transaction())->firstByTransId($transaction['transaction_id']);
                $whmcsInvoice = Invoice::find($invoice['invoice_id']);
                // If current invoice has been paid ignore it.
                if ($whmcsTransaction || mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    return;
                }

                $actualAmount = $transaction['quant'] / 1000000;
                AddInvoicePayment(
                    $invoice['invoice_id'], // Invoice id
                    $transaction['transaction_id'], // Transaction id
                    $actualAmount, // Paid amount
                    0, // Transaction fee
                    'BeefyAsianPay', // Gateway
                );

                logTransaction('BeefyAsianPay', $transaction, 'Successfully Paid');

                $whmcsInvoice = $whmcsInvoice->fresh();
                // If the invoice has been paid in full, release the address, otherwise renew it.
                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    $invoice->markAsPaid($transaction['from_address'], $transaction['transaction_id']);
                } else {
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
                'count' => 8,
                'tokens' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'start_timestamp' => Carbon::now()->subMinutes(30)->getTimestamp(),
                'relatedAddress' => $address,
            ],
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        return new Collection($response['token_transfers']);
    }

    /**
     * Get an available usdt address.
     *
     * @param   int     $invoiceId
     *
     * @return  string
     * @throws  NoAddressAvailable
     */
    protected function getAvailableAddress(int $invoiceId): string
    {
        $beefyInvoice = new BeefyAsianPayInvoice();

        $inUseAddresses = $beefyInvoice->inUse()->get(['to_address']);

        $availableAddresses = array_values(array_diff($this->addresses, $inUseAddresses->pluck('to_address')->toArray()));

        if (count($availableAddresses) <= 0) {
            throw new NoAddressAvailable('not enough addresses.');
        }

        $address = $availableAddresses[array_rand($availableAddresses)];
        $beefyInvoice->associate($address, $invoiceId);

        return $address;
    }
}
