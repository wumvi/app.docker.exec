<?php
declare(strict_types=1);

use CertBot\Hook\Run;

include __DIR__ . '/vendor/autoload.php';

try {
    $code = new Run();
    $code->run();
} catch (\Exception $ex) {
    echo $ex->getMessage(), PHP_EOL;
    exit($ex->getCode());
}
