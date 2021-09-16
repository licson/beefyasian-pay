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
        $this->addresses = array_filter(preg_split("/\r\n|\n|\r/", $addresses));
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
     * Render payment html.
     *
     * @param   array  $params
     *
     * @return  mixed
     */
    public function render(array $params)
    {
        if ($_GET['act'] === 'invoice_status') {
            $this->renderInvoiceStatusJson($params);
        } else {
            return $this->renderPaymentHTML($params);
        }
    }

    /**
     * Get then invoice status json.
     *
     * @param   array   $params
     *
     * @return  void
     */
    protected function renderInvoiceStatusJson(array $params)
    {
        $invoice = (new Invoice())->find($params['invoiceid']);
        $beefyInvoice = (new BeefyAsianPayInvoice())->firstValidByInvoiceId($params['invoiceid']);
        if (mb_strtolower($invoice['status']) === 'unpaid') {
            if ($beefyInvoice['expires_on']->subMinutes(3)->lt(Carbon::now())) {
                $beefyInvoice->renew();
            }

            $beefyInvoice = $beefyInvoice->fresh();
        }

        $json = json_encode([
            'status' => $invoice['status'],
            'valid_till' => $beefyInvoice['expires_on']->toDateTimeString()
        ]);

        header('Content-Type: application/json');
        echo $json;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            exit();
        }
    }

    /**
     * Render pay with usdt html.
     *
     * @param   array   $params
     *
     * @return  string
     */
    protected function renderPaymentHTML(array $params): string
    {
        try {
            $beefyInvoice = new BeefyAsianPayInvoice();

            $address = '';
            if ($validAddress = $beefyInvoice->validInvoice($params['invoiceid'])) {
                $validAddress->renew();
                $address = $validAddress->to_address;
            } else {
                $address = $this->getAvailableAddress($params['invoiceid']);
            }

            $validTill = Carbon::now()->addMinutes(BeefyAsianPayInvoice::RELEASE_TIMEOUT)->toDateTimeString();

            return <<<HTML
                <style>
                    #qrcode {
                        display: flex;
                        width: 100%;
                        justify-content: center;
                    }
                    .usdt-addr {
                        font-size: 12px;
                        height: 40px;
                        border: 1px solid #eee;
                        border-radius: 4px;
                        line-height: 40px;
                        text-align: left;
                        padding-left: 10px;
                    }
                    .copy-btn {
                        display: inline-block;
                        float: right;
                        text-align: center;
                        background: #4faf95;
                        width: 55px;
                        border: 1px solid #4faf95;
                        height: 38px;
                        line-height: 36px;
                        color: #fff;
                        border-radius: 0 4px 4px 0;
                        cursor: pointer;
                    }
                </style>
                <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/clipboard@2.0.8/dist/clipboard.min.js"></script>
                <div style="width: 350px">
                    <div id="qrcode"></div>
                    <p>Pay with USDT</p>
                    <p>Valid till: <span id="valid-till">{$validTill}</span></p>
                    <p class="usdt-addr">
                        <span id="address">$address</span>
                        <button class="copy-btn" data-clipboard-target="#address">Copy</button>
                    </p>
                </div>

                <script>
                    const clipboard = new ClipboardJS('.copy-btn')
                    clipboard.on('success', () => {
                        alert('Copied')
                    })

                    new QRCode(document.querySelector('#qrcode'), {
                        text: "{$address}",
                        width: 128,
                        height: 128,
                    })

                    setInterval(() => {
                        fetch(window.location.href + '&act=invoice_status')
                            .then(r => r.json())
                            .then(r => {
                                if (r.status.toLowerCase() === 'paid') {
                                    window.location.reload(true)
                                } else {
                                    document.querySelector('#valid-till').innerHTML = r.valid_till
                                }
                            })
                    }, 1000);
                </script>
                HTML;
        } catch (Throwable | Exception $e) {
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
            $transactions = $this->getTransactions($invoice['to_address'], $invoice['created_at'])
                ->filter(function ($transaction) {
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
                    'beefyasianpay' // Gateway
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
     * @param   Carbon  $address
     *
     * @return  Collection
     */
    protected function getTransactions(string $address, Carbon $startDatetime): Collection
    {
        $http = new Client([
            'base_uri' => 'https://apiasia.tronscan.io:5566',
            'timeout' => 30,
        ]);

        $response = $http->get('/api/token_trc20/transfers', [
            'query' => [
                'direction' => 'in',
                'count' => 8,
                'tokens' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'start_timestamp' => $startDatetime->getTimestamp() * 1000,
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
