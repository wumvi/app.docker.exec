<?php
declare(strict_types=1);

namespace CertBot\Hook;

use \DockerApi\Exec;
use \DockerApi\Containers;
use \DockerApi\Arguments\Exec\Prepare;
use GetOpt\GetOpt;
use GetOpt\Option;

class Run
{
    /**
     * @var string
     */
    private $service;

    public function __construct()
    {
        $this->initArguments();
    }

    private function initArguments()
    {
        $getOpt = new GetOpt();

        $optionHelp = new Option(null, 'help', GetOpt::NO_ARGUMENT);
        $optionHelp->setDescription('This help');
        $getOpt->addOption($optionHelp);

        $option = new Option('s', 'service', GetOpt::REQUIRED_ARGUMENT);
        $option->setDescription('Name or ID of docker container');
        $getOpt->addOption($option);

        try {
            $getOpt->process();
        } catch (\Exception $ex) {
            throw new HookException($ex->getMessage(), HookException::PARSE_ARGUMENTS);
        }

        $options = $getOpt->getOption('help');
        if ($options) {
            echo $getOpt->getHelpText();
            exit;
        }

        $this->service = (string)$getOpt->getOption('service');
        if (empty($this->service)) {
            throw new HookException('Service is empty', HookException::PARSE_ARGUMENTS);
        }
    }

    public function run(): void
    {
        $dockerContainers = new Containers();

        try {
            $dockerContainers->inspect($this->service);
        } catch (\Exception $ex) {
            $msg = @json_decode($ex->getMessage());
            $msg = $msg ? $msg->message : $ex->getMessage();
            throw new HookException($msg, HookException::CONTAINER_NOT_FOUND);
        }

        $dockerExec = new Exec();
        $prepareExec = new Prepare($this->service, '/ssl.update.sh');

        try {
            $startId = $dockerExec->prepare($prepareExec);
            $dockerExec->start($startId);
            $exitCode = $dockerExec->inspect($startId)->getExitCode();
        } catch (\Exception $ex) {
            $msg = @json_decode($ex->getMessage());
            $msg = $msg ? $msg->message : $ex->getMessage();
            throw new HookException($msg, HookException::ERROR_TO_EXECUTE);
        }

        if ($exitCode !== 0) {
            throw new HookException('Error to execute update command', HookException::ERROR_TO_EXECUTE);
        }
    }
}
