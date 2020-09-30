<?php
namespace packages\peeker\processes;

use packages\base\{Log, Cli, SSH, IO, IO\Directory, Process, Packages, View\Error};
use packages\peeker\{interfaces, scanners, ActionManager, IActionInteractive, actions, IO\Directory\SFTP as SFTPDirectory, IO\Directory\IPreloadedDirectory, IO\IPreloadedMd5};

class Peeker extends process {
	protected $actions;
	protected $maxCycles = 5;
	protected $justInTime = false;

	public function scan(array $data) {
		Log::setLevel("debug");
		$log = Log::getInstance();

		ini_set('memory_limit','-1');
		
		try {
			$root = $this->getServerRoot($data);
		} catch (\Exception $e) {
			$log->fatal($e->getMessage());
			throw $e;
		}
		$users = $this->getUsersHome($root, $data);
		$doneUsers = [];

		$cli = new interfaces\CLI();
		$this->actions = new ActionManager($cli);
		
		$lastTimeHasActions = 1;
		$repeats = 0;
		while (($this->countActions(false, true, true) or $lastTimeHasActions) and $repeats < $this->maxCycles) {
			$repeats++;
			$log->info("Cycle #" . $repeats);
			array_walk($users, function($user) {
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
		if ($repeats >= $this->maxCycles) {
			throw new Error("there is more to do");
		}
	}
	protected function scanUser(Directory $user): void {
		$log = Log::getInstance();
		if ($user instanceof IPreloadedDirectory) {
			$log->info("preloading the user directory");
			$user->preloadItems();
			$log->reply("success");
		}
		if ($user instanceof IPreloadedMd5) {
			$log->info("preloading the files md5");
			$user->preloadMd5();
			$log->reply("success");
		}
		$scanners = [ // Order matters
			scanners\wordpress\PluginScanner::class,
			scanners\wordpress\ThemeScanner::class,
			scanners\wordpress\WordpressFinder::class,
			scanners\PHPScanner::class,
			scanners\JSScanner::class,
		];

		$lastTimeHasActions = 1;
		$repeats = 0;
		while (($this->countActions(false, false, true) or $lastTimeHasActions) and $repeats < $this->maxCycles) {
			$repeats++;
			$log->info("Non-Interactive Cycle #" . $repeats);
			array_walk($scanners, function($class) use ($user) {
				$log = Log::getInstance();
				$log->info($class);
				$currentActions = $this->countActions();
				$scanner = new $class($this->actions, $user->directory("domains"));
				$scanner->prepare();
				$scanner->scan();
				$log->reply($this->countActions() - $currentActions, "new actions");
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
			throw new Error("there is more to do");
		}
	}

	protected function countActions(bool $countCleans = false, bool $countInteractives = true, bool $countNoninteractive = true): int {
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
			$count++;
		}
		return $count;
	}
	
	protected function getServerRoot($data): Directory {
		if (!isset($data['host'])) {
			return new Directory\Local("/");
		}
		$log = Log::getInstance();
		$log->debug("Host:", $data['host']);
		$data['username'] = $data['username'] ?? Cli::readLine("Please enter {$data['host']}'s username [root]:");
		if (!$data['username']) {
			$data['username'] = 'root';
		}
		$log->debug("Username:", $data['username']);

		$data['port'] = intval($data['port'] ?? Cli::readLine("Please enter {$data['host']}'s port [21]:"));
		if (!$data['port']) {
			$data['port'] = 21;
		}
		if ($data['port'] < 1 or $data['port'] > 65365) {
			throw new Exception("port is out of range");
		}
		$log->debug("Port:", $data['port']);

		$data['password'] = $data['password'] ?? Cli::readLine("Please enter {$data['username']}@{$data['host']}'s password:");
		if (!$data['password']) {
			throw new Exception("password is empty");
		}
		$log->debug("Password:", $data['password']);

		$log->info("Connecting to {$data['username']}@{$data['host']}:{$data['port']}");
		$ssh = new SSH($data['host'], $data['port']);
		if (!$ssh->authByPassword($data['username'], $data['password'])) {
			throw new Exception("username or password is invalid");
		}
		$root = new SFTPDirectory("");
		$root->setDriver(new IO\Drivers\Sftp($ssh));
		return $root;
	}
	protected function getUsersHome(Directory $root, $data): array {
		$log = Log::getInstance();
		$home = $root->directory("home");
		if (isset($data["user"]) and $data["user"]) {
			$log->info("looking for user", $data["user"]);
			$user = $home->directory($data["user"]);
			if (!$user->exists()) {
				throw new Error("Unable to find any user by username " . $data["user"] . " in /home");
			}
			$users[] = $user;
			$log->reply("Found");
		} else {
			$log->info("looking in /home for users");
			$users = $home->directories(false);
			$log->reply(count($users), "found");
		}
		return $users;
	}
}
