<?php
declare(strict_types=1);

namespace CertBot\Manage;

use CertBot\Manage\Exception\ManageException;
use GetOpt\GetOpt;
use GetOpt\Option;

class ManageCertificate
{
    private const TYPE_NGINX = 'nginx';
    private const TYPE_HTTP = 'http';

    /**
     * @var string[]
     */
    private $containers;

    /**
     * @var string
     */
    private $domain;

    /**
     * @var string
     */
    private $email;

    /**
     * @var bool
     */
    private $isRemove;

    /**
     * @var string
     */
    private $currentDir;

    /**
     * @var string
     */
    private $type;

    /**
     *
     */
    const DATABASE_NAME = '/etc/letsencrypt/domains.sqlite';

    /**
     * ManageCertificate constructor.
     *
     * @param bool $currentDir
     *
     * @throws ManageException
     */
    public function __construct($currentDir)
    {
        $this->currentDir = $currentDir . DIRECTORY_SEPARATOR;

        $this->initArguments();
    }

    /**
     * @throws ManageException
     */
    private function initArguments()
    {
        $getOpt = new GetOpt();

        $optionHelp = new Option(null, 'help', GetOpt::NO_ARGUMENT);
        $optionHelp->setDescription('This help');
        $getOpt->addOption($optionHelp);

        $option = new Option('c', 'container', GetOpt::MULTIPLE_ARGUMENT);
        $option->setDescription('Name or ID of docker container');
        $getOpt->addOption($option);

        $option = new Option('d', 'domain', GetOpt::REQUIRED_ARGUMENT);
        $option->setDescription('Domain');
        $getOpt->addOption($option);

        $option = new Option('e', 'email', GetOpt::REQUIRED_ARGUMENT);
        $option->setDescription('Email');
        $getOpt->addOption($option);

        $option = new Option('r', 'remove', GetOpt::OPTIONAL_ARGUMENT);
        $option->setDescription('Remove record');
        $getOpt->addOption($option);

        $option = new Option('t', 'type', GetOpt::OPTIONAL_ARGUMENT);
        $option->setDescription('Type authorization: nginx, http');
        $getOpt->addOption($option);

        try {
            $getOpt->process();
        } catch (\Exception $ex) {
            throw new ManageException($ex->getMessage(), ManageException::PARSE_ARGUMENTS);
        }

        $options = $getOpt->getOption('help');
        if ($options) {
            echo $getOpt->getHelpText();
            exit;
        }

        $this->containers = $getOpt->getOption('container');
        if (!$this->containers) {
            throw new ManageException('Container name is empty. Use --container or -c',
                ManageException::PARSE_ARGUMENTS);
        }

        $this->domain = (string)$getOpt->getOption('domain');
        if (empty($this->domain)) {
            throw new ManageException('Domain is empty. Use --domain or -d', ManageException::PARSE_ARGUMENTS);
        }

        $this->email = (string)$getOpt->getOption('email');
        if (empty($this->email)) {
            throw new ManageException('Email is empty. Use --email or -e', ManageException::PARSE_ARGUMENTS);
        }

        $this->type = $getOpt->getOption('type');
        $allowTypes = [self::TYPE_NGINX, self::TYPE_HTTP];
        if (!in_array($this->type, [self::TYPE_NGINX, self::TYPE_HTTP])) {
            $msg = vsprintf('Wrong type value. Use --type=%s or --type=%s', $allowTypes);
            throw new ManageException($msg, ManageException::PARSE_ARGUMENTS);
        }

        $this->isRemove = (bool)$getOpt->getOption('remove');
    }

    /**
     * @throws ManageException
     */
    public function run()
    {
        $error = '';
        $sqlCon = new \SQLite3(self::DATABASE_NAME);
        if (!$sqlCon) {
            $msg = vsprintf('Error to open db "%s". Msg "%s"', [self::DATABASE_NAME, $error,]);
            throw new ManageException($msg, ManageException::ERROR_TO_OPEN_DB);
        }

        $data = $sqlCon->query('SELECT name FROM sqlite_master WHERE type="table" AND name="domains"');
        if (!$data->fetchArray()) {
            $sqlCon->query('create table domains (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name TEXT, container_id INTEGER)');
            $sqlCon->query('create table containers (domain_id INTEGER, name TEXT)');
        }

        $stmt = $sqlCon->prepare('SELECT id FROM domains WHERE name=:name');
        $stmt->bindValue(':name', $this->domain, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        $domainId = $result['id'] ?? null;

        if ($this->isRemove) {
            $cmd = vsprintf(
                'certbot delete --cert-name %s > /dev/stdout',
                [escapeshellarg($this->domain),]
            );
            exec($cmd, $output, $status);
            $output = implode("\n", $output);
            if ($status !== 0) {
                throw new ManageException('Error: ' . $output, ManageException::ERROR_TO_EXEC_CMD);
            }

            $stmt = $sqlCon->prepare('delete domains WHERE id = :domain_id');
            $stmt->bindParam(':domain_id', $domainId, SQLITE3_INTEGER);
            $stmt->execute();

            echo $output, PHP_EOL;
            echo vsprintf('Remove domain "%s" is successful', [$this->domain,]);
            return;
        }

        if ($this->type === self::TYPE_NGINX) {
            $auth = '--webroot';
        } else {
            $auth = '--standalone --preferred-challenges http';
        }

        $cmd = vsprintf(
            'certbot certonly %s -w /etc/letsencrypt/www/ --agree-tos --email %s -d %s > /dev/stdout',
            [$auth, escapeshellarg($this->email), escapeshellarg($this->domain)]
        );
        exec($cmd, $output, $status);
        $output = implode("\n", $output);
        if ($status !== 0) {
            throw new ManageException('Error: ' . $output, ManageException::ERROR_TO_EXEC_CMD);
        }

        echo $output, PHP_EOL;

        if (!$domainId) {
            $stmt = $sqlCon->prepare('insert into domains(name) values(:name)');
            $stmt->bindParam(':name', $this->domain, SQLITE3_TEXT);
            $stmt->execute();

            $domainId = $sqlCon->lastInsertRowID();
        }

        $stmt = $sqlCon->prepare('delete from containers WHERE domain_id = :domain_id');
        $stmt->bindParam(':domain_id', $domainId, SQLITE3_INTEGER);
        $stmt->execute();

        foreach ($this->containers as $container) {
            $stmt = $sqlCon->prepare('insert into containers(domain_id, name) values(:domain_id, :name)');
            $stmt->bindParam(':domain_id', $domainId, SQLITE3_INTEGER);
            $stmt->bindParam(':name', $container, SQLITE3_TEXT);
            $stmt->execute();
        }
    }
}
