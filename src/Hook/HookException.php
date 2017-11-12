<?php
declare(strict_types=1);

namespace CertBot\Hook;

class HookException extends \Exception
{
    public const ERROR_TO_EXECUTE = 1;
    public const EMPTY_ARGUMENTS = 2;
    public const PARSE_ARGUMENTS = 3;
    public const CONTAINER_NOT_FOUND = 4;
}
