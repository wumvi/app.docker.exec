<?php
declare(strict_types=1);

use CertBot\Manage\ManageCertificate;

include __DIR__ . '/vendor/autoload.php';

try {
    $code = new ManageCertificate(__DIR__);
    $code->run();
} catch (\Exception $ex) {
    echo $ex->getMessage(), PHP_EOL;
    exit($ex->getCode());
}
