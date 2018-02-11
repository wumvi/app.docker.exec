<?php
declare(strict_types=1);

namespace CertBot\Manage\Exception;

class ManageException extends \Exception
{
    public const ERROR_TO_EXECUTE = 1;
    public const EMPTY_ARGUMENTS = 2;
    public const PARSE_ARGUMENTS = 3;
    public const ERROR_TO_OPEN_DB = 4;
    public const DOMAIN_NOT_FOUND = 5;
    public const ERROR_TO_EXEC_CMD = 6;
}
