<?php

namespace BeefyAsianPay;

use BeefyAsianPay\Exceptions\NoAddressAvailable;
use BeefyAsianPay\Models\BeefyAsianPayInvoice;
use BeefyAsianPay\Models\Invoice;
use BeefyAsianPay\Models\Transaction;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use RuntimeException;
use Smarty;
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
        'timeout' => [
            'FriendlyName' => 'Timeout',
            'Type' => 'text',
            'Value' => 30,
            'Description' => 'Minutes'
        ]
    ];

    /**
     * Smarty template engine.
     *
     * @var Smarty
     */
    protected $smarty;

    /**
     * Create a new instance.
     *
     * @param   string  $addresses
     * @param   bool    $configMode
     *
     * @return  void
     */
    public function __construct(array $params = [])
    {
        if (!function_exists('getGatewayVariables')) {
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'init.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/gatewayfunctions.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/invoicefunctions.php';
        } else {
            if (empty($params) && !$configMode) {
                try {
                    $params = getGatewayVariables('beefyasianpay');
                } catch (Throwable $e) {}
            }
        }

        $this->timeout = $params['timeout'] ?? 30;
        $this->addresses = array_filter(preg_split("/\r\n|\n|\r/", $params['addresses'] ?? ''));

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir(BEEFYASIAN_PAY_ROOT . DIRECTORY_SEPARATOR . 'templates');
        $this->smarty->setCompileDir(WHMCS_ROOT . DIRECTORY_SEPARATOR . 'templates_c');
    }

    /**
     * Fetch smarty renderred template.
     *
     * @param   string  $viewName
     * @param   array   $arguments
     *
     * @return  string
     */
    protected function view(string $viewName, array $arguments = [])
    {
        foreach ($arguments as $name => $variable) {
            $this->smarty->assign($name, $variable);
        }

        return $this->smarty->fetch($viewName);
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
        switch ($_GET['act']) {
            case 'invoice_status':
                $this->renderInvoiceStatusJson($params);
            case 'create':
                $this->createBeefyAsianPayInvoice($params);
            default:
                return $this->renderPaymentHTML($params);
        }
    }

    /**
     * Create beefy asian pay invoice.
     *
     * @param   array  $params
     *
     * @return  void
     */
    protected function createBeefyAsianPayInvoice(array $params)
    {
        try {
            $invoice = (new Invoice())->find($params['invoiceid']);

            if (mb_strtolower($invoice['status']) === 'paid') {
                $this->json([
                    'status' => false,
                    'error' => 'The invoice has been paid in full.'
                ]);
            }

            $address = $this->getAvailableAddress($params['invoiceid']);

            $this->json([
                'status' => true,
                'address' => $address,
            ]);
        } catch (Throwable $e) {
            $this->json([
                'status' => false,
                'error' => $e->getMessage(),
            ]);
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
        $beefyInvoice = (new BeefyAsianPayInvoice())->firstValidByInvoiceId($params['invoiceid']);
        if ($beefyInvoice) {
            $invoice = (new Invoice())->with('transactions')->find($params['invoiceid']);
            $this->checkTransaction($beefyInvoice);
            $beefyInvoice = $beefyInvoice->refresh();

            if (mb_strtolower($invoice['status']) === 'unpaid') {
                if ($beefyInvoice['expires_on']->subMinutes(3)->lt(Carbon::now())) {
                    $beefyInvoice->renew($this->timeout);
                }

                $beefyInvoice = $beefyInvoice->refresh();
            }

            $json = [
                'status' => $invoice['status'],
                'amountin' => $invoice['transactions']->sum('amountin'),
                'valid_till' => $beefyInvoice['expires_on']->toDateTimeString(),
            ];

            $this->json($json);
        }

        $this->json([
            'status' => false,
            'error' => 'invoice does not exists',
        ]);
    }

    /**
     * Responed with JSON.
     *
     * @param   array  $json
     *
     * @return  void
     */
    protected function json(array $json)
    {
        $json = json_encode($json);
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
        $beefyInvoice = new BeefyAsianPayInvoice();

        if ($validAddress = $beefyInvoice->validInvoice($params['invoiceid'])) {
            $validAddress->renew($this->timeout);
            $validTill = Carbon::now()->addMinutes($this->timeout)->toDateTimeString();

            return $this->view('payment.tpl', [
                'address' => $validAddress['to_address'],
                'amount' => $params['amount'],
                'validTill' => $validTill,
            ]);
        } else {
            return $this->view('pay_with_usdt.tpl');
        }
    }

    /**
     * Remove expired invoices.
     *
     * @return  void
     */
    public function cron()
    {
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
            $this->checkTransaction($invoice);
        });
    }

    /**
     * Check USDT Transaction.
     *
     * @param   BeefyAsianPayInvoice  $invoice
     *
     * @return  void
     */
    protected function checkTransaction(BeefyAsianPayInvoice $invoice)
    {
        $this->getTransactions($invoice['to_address'], $invoice['created_at'])
            ->each(function ($transaction) use ($invoice) {
                $whmcsTransaction = (new Transaction())->firstByTransId($transaction['transaction_id']);
                $whmcsInvoice = Invoice::find($invoice['invoice_id']);
                // If current invoice has been paid ignore it.
                if ($whmcsTransaction) {
                    return;
                }

                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    return;
                }

                $actualAmount = $transaction['value'] / 1000000;
                AddInvoicePayment(
                    $invoice['invoice_id'], // Invoice id
                    $transaction['transaction_id'], // Transaction id
                    $actualAmount, // Paid amount
                    0, // Transaction fee
                    'beefyasianpay' // Gateway
                );

                logTransaction('BeefyAsianPay', $transaction, 'Successfully Paid');

                $whmcsInvoice = $whmcsInvoice->refresh();
                // If the invoice has been paid in full, release the address, otherwise renew it.
                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    $invoice->markAsPaid($transaction['from'], $transaction['transaction_id']);
                } else {
                    $invoice->renew($this->timeout);
                }
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
            'base_uri' => 'https://api.trongrid.io',
            'timeout' => 30,
        ]);

        $response = $http->get("/v1/accounts/{$address}/transactions/trc20", [
            'query' => [
                'limit' => 5,
                'only_to' => true,
                'only_confirmed' => true,
                'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'min_timestamp' => $startDatetime->getTimestamp() * 1000,
            ],
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        return new Collection($response['data']);
    }

    /**
     * Get an available usdt address.
     *
     * @param   int     $invoiceId
     *
     * @return  string
     *
     * @throws  NoAddressAvailable
     * @throws  RuntimeException
     */
    protected function getAvailableAddress(int $invoiceId): string
    {
        $beefyInvoice = new BeefyAsianPayInvoice();

        if ($beefyInvoice->firstValidByInvoiceId($invoiceId)) {
            throw new RuntimeException("The invoice has been associated with a USDT address please refresh the invoice page.");
        }

        $inUseAddresses = $beefyInvoice->inUse()->get(['to_address']);

        $availableAddresses = array_values(array_diff($this->addresses, $inUseAddresses->pluck('to_address')->toArray()));

        if (count($availableAddresses) <= 0) {
            throw new NoAddressAvailable('no available address please try again later.');
        }

        $address = $availableAddresses[array_rand($availableAddresses)];
        $beefyInvoice->associate($address, $invoiceId, $this->timeout);

        return $address;
    }
}
