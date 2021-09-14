<?php

spl_autoload_register(function ($className) {
    if (stripos($className, 'BeefyAsianPay') !== false) {
        require __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', '/', mb_strcut($className, 14)) . '.php';
    }
});
