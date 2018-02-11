<?php
declare(strict_types=1);

namespace CertBot\Hook;

use CertBot\Hook\Exception\HookException;
use DockerApi\Arguments\Exec\Prepare;
use DockerApi\Containers;
use DockerApi\Exec;

class ExecCmdInContainer
{
    /**
     * @var string
     */
    private $currentDir;

    /**
     * ManageCertificate constructor.
     *
     * @param bool $currentDir
     */
    public function __construct($currentDir)
    {
        $this->currentDir = $currentDir . DIRECTORY_SEPARATOR;
    }

    /**
     * @param int $domainId
     * @param string $domainName
     *
     * @return bool
     *
     * @throws HookException
     */
    public function renew(int $domainId, string $domainName): bool
    {
        //
        $file = vsprintf('/tmp/%s.cert', [$domainId,]);
        $cmd = vsprintf(
            'certbot renew --renew-hook "echo 1 > %s" --cert-name %s >> /dev/stdout',
            [$file, escapeshellarg($domainName),]
        );
        exec($cmd, $output, $status);
        $output = implode("\n", $output);
        if ($status !== 0) {
            throw new HookException('Error: ' . $output, HookException::ERROR_TO_EXEC_CMD);
        }

        if (!file_exists($file)) {
            return false;
        }

        unlink($file);

        return true;
    }

    /**
     * @throws HookException
     */
    public function run(): void
    {
        $error = '';
        $dbName = $this->currentDir . 'data/domains.sqlite';
        $sqlCon = new \SQLite3($dbName);
        if (!$sqlCon) {
            $msg = vsprintf('Error to open db "%s". Msg "%s"', [$dbName, $error,]);
            throw new HookException($msg, HookException::ERROR_TO_OPEN_DB);
        }

        $data = $sqlCon->query('SELECT name FROM sqlite_master WHERE type="table" AND name="domains"');
        if (!$data->fetchArray()) {
            return;
        }

        $containersForExec = [];
        $fetchDomain = $sqlCon->query('SELECT id, name FROM domains');
        while ($domain = $fetchDomain->fetchArray(SQLITE3_ASSOC)) {
            $domainId = $domain['id'];
            $renew = $this->renew($domainId, $domain['name']);
            if (!$renew) {
                continue;
            }

            $stmt = $sqlCon->prepare('select * from containers WHERE domain_id = :domain_id');
            $stmt->bindParam(':domain_id', $domainId, SQLITE3_INTEGER);
            $fetchContainer = $stmt->execute();
            while ($container = $fetchContainer->fetchArray(SQLITE3_ASSOC)) {
                $containersForExec[] = $container['name'];
            }
        }

        $containersForExec = array_unique($containersForExec);

        foreach ($containersForExec as $container) {
            $this->exec($container);
        }
    }

    /**
     * @param string $container
     *
     * @throws HookException
     */
    private function exec(string $container): void
    {
        $dockerContainers = new Containers();

        try {
            $dockerContainers->inspect($container);
        } catch (\Exception $ex) {
            $msg = @json_decode($ex->getMessage());
            $msg = $msg ? $msg->message : $ex->getMessage();
            $msg = $msg ? $msg : vsprintf('Container "%s" not found', [$container,]);
            throw new HookException($msg, HookException::CONTAINER_NOT_FOUND);
        }

        $dockerExec = new Exec();
        $cmd = '/ssl.update.sh';
        $prepareExec = new Prepare($container, $cmd);

        try {
            $startId = $dockerExec->prepare($prepareExec);
            $dockerExec->start($startId);
            $exitCode = $dockerExec->inspect($startId)->getExitCode();
        } catch (\Exception $ex) {
            $msg = @json_decode($ex->getMessage());
            $msg = $msg ? $msg->message : $ex->getMessage();
            $msg = $msg ? $msg : vsprintf('Error to exec "%s" in "%s"', [$cmd, $container,]);
            throw new HookException($msg, HookException::ERROR_TO_EXECUTE);
        }

        if ($exitCode !== 0) {
            throw new HookException('Error to execute update command', HookException::ERROR_TO_EXECUTE);
        }
    }
}
