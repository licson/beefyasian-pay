<?php

use BeefyAsianPay\App;

add_hook('AfterCronJob', 1, function ($vars) {
    require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'modules/gateways/beefyasianpay/autoload.php';

    (new App())->cron();
});
