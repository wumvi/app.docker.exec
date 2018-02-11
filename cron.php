<?php
declare(strict_types=1);

use CertBot\Hook\ExecCmdInContainer;

include __DIR__ . '/vendor/autoload.php';

try {
    $code = new ExecCmdInContainer(__DIR__);
    $code->run();
} catch (\Exception $ex) {
    echo $ex->getMessage(), PHP_EOL;
    exit($ex->getCode());
}
