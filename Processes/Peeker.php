<?php

namespace packages\peeker\Processes;

use packages\base\CLI;
use packages\base\Exception;
use packages\base\IO;
use packages\base\IO\Directory;
use packages\base\Log;
use packages\base\Process;
use packages\base\SSH;
use packages\base\View\Error;
use packages\peeker\ActionManager;
use packages\peeker\Actions;
use packages\peeker\IActionInteractive;
use packages\peeker\Interfaces;
use packages\peeker\IO\Directory\IPreloadedDirectory;
use packages\peeker\IO\Directory\SFTP as SFTPDirectory;
use packages\peeker\IO\IPreloadedMd5;
use packages\peeker\Scanners;

class Peeker extends Process
{
    protected ActionManager $actions;
    protected int $maxCycles = 5;
    protected bool $justInTime = false;

    public function scan(array $data)
    {
        Log::setLevel('debug');
        $log = Log::getInstance();

        ini_set('memory_limit', '-1');

        try {
            $root = $this->getServerRoot($data);
        } catch (\Exception $e) {
            $log->fatal($e->getMessage());
            throw $e;
        }
        $users = $this->getUsersHome($root, $data);

        $cli = new Interfaces\CLI();
        $this->actions = new ActionManager($cli);

        $this->scanUsers($users);
    }

    protected function scanUsers(array $users): void
    {
        $log = Log::getInstance();

        $this->actions->reset();
        $lastTimeHasActions = 1;
        $repeats = 0;
        while (($this->countActions(false, true, true) or $lastTimeHasActions) and $repeats < $this->maxCycles) {
            ++$repeats;
            $log->info('Cycle #'.$repeats);
            array_walk($users, function ($user) {
                $log = Log::getInstance();
                $log->info($user->basename);
                try {
                    $this->scanUser($user);
                } catch (\Exception $e) {
                    $log->reply($e->__toString());
                }
            });
            $lastTimeHasActions = $this->countActions(false, true, true);
            $log->reply("there is {$lastTimeHasActions} actions to do");
            if ($lastTimeHasActions) {
                $this->actions->doActions();
            }
        }

        $log->info('reset permissions');
        $this->resetPermissions($users);
        $log->reply('Success');

        if ($repeats >= $this->maxCycles) {
            throw new Error('there is more to do');
        }
    }

    protected function scanUser(Directory $user): void
    {
        $log = Log::getInstance();
        if ($user instanceof IPreloadedDirectory) {
            $log->info('preloading the user directory');
            $user->resetItems();
            $user->preloadItems();
            $log->reply('success');
        }
        if ($user instanceof IPreloadedMd5) {
            $log->info('preloading the files md5');
            $user->preloadMd5();
            $log->reply('success');
        }
        $scanners = [ // Order matters
            Scanners\Wordpress\PluginScanner::class,
            Scanners\Wordpress\ThemeScanner::class,
            Scanners\Wordpress\WordpressFinder::class,
            Scanners\PHPScanner::class,
            Scanners\JSScanner::class,
        ];

        $lastTimeHasActions = 1;
        $repeats = 0;
        while (($this->countActions(false, false, true) or $lastTimeHasActions) and $repeats < $this->maxCycles) {
            ++$repeats;
            $log->info('Non-Interactive Cycle #'.$repeats);
            array_walk($scanners, function ($class) use ($user) {
                $log = Log::getInstance();
                $log->info($class);
                $currentActions = $this->countActions();
                $scanner = new $class($this->actions, $user);
                $scanner->prepare();
                $scanner->scan();
                $log->reply($this->countActions() - $currentActions, 'new actions');
            });
            $lastTimeHasActions = $this->countActions(false, false, true);
            $log->reply("there is {$lastTimeHasActions} non-interative actions to do");
            if (!$this->justInTime) {
                break;
            }
            if ($lastTimeHasActions) {
                $this->actions->doNonInteractiveActions();
            }
        }
        if ($repeats >= $this->maxCycles) {
            throw new Error('there is more to do');
        }
    }

    protected function countActions(bool $countCleans = false, bool $countInteractives = true, bool $countNoninteractive = true): int
    {
        $count = 0;
        foreach ($this->actions->getActions() as $action) {
            if (!$countCleans and $action instanceof actions\CleanFile) {
                continue;
            }
            if ($action instanceof IActionInteractive) {
                if ($action->hasQuestions()) {
                    if (!$countInteractives) {
                        continue;
                    }
                } else {
                    if (!$countNoninteractive) {
                        continue;
                    }
                }
            } else {
                if (!$countNoninteractive) {
                    continue;
                }
            }
            if (!$action->isValid()) {
                continue;
            }
            ++$count;
        }

        return $count;
    }

    protected function getServerRoot($data): Directory
    {
        if (!isset($data['host'])) {
            return new Directory\Local('/');
        }
        $log = Log::getInstance();
        $log->debug('Host:', $data['host']);
        $data['username'] = $data['username'] ?? CLI::readLine("Please enter {$data['host']}'s username [root]:");
        if (!$data['username']) {
            $data['username'] = 'root';
        }
        $log->debug('Username:', $data['username']);

        $data['port'] = intval($data['port'] ?? CLI::readLine("Please enter {$data['host']}'s port [21]:"));
        if (!$data['port']) {
            $data['port'] = 21;
        }
        if ($data['port'] < 1 or $data['port'] > 65365) {
            throw new Exception('port is out of range');
        }
        $log->debug('Port:', $data['port']);

        $data['password'] = $data['password'] ?? CLI::readLine("Please enter {$data['username']}@{$data['host']}'s password:");
        if (!$data['password']) {
            throw new Exception('password is empty');
        }
        $log->debug('Password:', $data['password']);

        $log->info("Connecting to {$data['username']}@{$data['host']}:{$data['port']}");
        $ssh = new SSH($data['host'], $data['port']);
        if (!$ssh->authByPassword($data['username'], $data['password'])) {
            throw new Exception('username or password is invalid');
        }
        $root = new SFTPDirectory('');
        $root->setDriver(new IO\Drivers\SFTP($ssh));

        return $root;
    }

    protected function getUsersHome(Directory $root, $data): array
    {
        $log = Log::getInstance();
        $home = $root->directory('home');
        if (isset($data['user']) and $data['user']) {
            $log->info('looking for user', $data['user']);
            $user = $home->directory($data['user']);
            if (!$user->exists()) {
                throw new Error('Unable to find any user by username '.$data['user'].' in /home');
            }
            $users[] = $user;
            $log->reply('Found');
        } else {
            $log->info('looking in /home for users');
            $users = $home->directories(false);
            $log->reply(count($users), 'found');
        }

        return $users;
    }

    protected function resetPermissions(array $users): void
    {
        $log = Log::getInstance();
        foreach ($users as $user) {
            if (preg_match("/\/home\/([^\/]+)\//", $user->getPath(), $matches)) {
                $cmd = "chown -R {$matches[1]}:{$matches[1]} ".$user->getPath();
                $log->info($cmd);
                if ($user instanceof Directory\Local) {
                    shell_exec($cmd);
                } elseif ($user instanceof Directory\SFTP) {
                    $user->getDriver()->getSSH()->execute($cmd);
                }
            }
        }
    }
}
