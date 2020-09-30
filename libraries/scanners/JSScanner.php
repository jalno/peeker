<?php
namespace packages\peeker\scanners;

use packages\base\{IO\File, Log};
use packages\peeker\{IAction, Scanner, FileScannerTrait, ActionConflictException, actions};

class JSScanner extends Scanner {
	use FileScannerTrait;

	public function scan(): void {	
		$log = Log::getInstance();
		$files = $this->getFiles($this->home, array('js', 'json'));
		foreach ($files as $file) {
			$path = $this->home->getRelativePath($file);
			$log->debug("check", $path);
			$this->scanFile($file);
		}
	}

	protected function scanFile(File $file): void {
		$log = Log::getInstance();
		$action = $this->checkFileName($file);
		if (!$action) {
			$action = $this->checkFileSource($file);
		}
		if (!$action) {
			return;
		}
		if (!$action instanceof CleanFile) {
			$log->reply("Infacted, Reason:", $action->getReason());
		}
		try {
			$this->actions->add($action);
		} catch (ActionConflictException $conflict) {
			$old = $conflict->getOldAction();
			if (
				!$old instanceof actions\CleanFile and
				!$old instanceof actions\Repair and
				!$old instanceof actions\ReplaceFile and
				!$old instanceof actions\HandCheckFile
			) {
				$this->actions->delete($old);
				$this->actions->add((new actions\HandCheckFile($file))->setReason("resolving-conflict"));
			}
		}
		
	}
	protected function checkFileName(File $file): ?IAction {
		$badNames = [];
		$badNamesPatterns = [];
		if (in_array($file->basename, $badNames)) {
			$this->actions->add();
			return (new actions\RemoveFile($file))
				->setReason('bad-name-js');
		}
		foreach ($badNamesPatterns as $badName) {
			if (preg_match($badName, $file->basename)) {
				return (new actions\RemoveFile($file))
					->setReason('bad-name-js');
			}
		}
		return null;
	}
	public function checkFileSource(File $file): ?IAction {
		$log = Log::getInstance();		
		$rules = array(
			array(
				'type' => 'pattern',
				'needle' => "/^Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);/",
				'action' => new actions\repairs\NastyJSVirusRepair($file, 'in-js'),
			),
			array(
				'type' => 'pattern',
				'needle' => "/108.+111.+119.+101.+114.+98.+101.+102.+111.+114.+119.+97.+114.+100.+101.+110/",
				'action' => new actions\HandcheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/lowerbeforwarden/i',
				'action' => new actions\HandcheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => "/var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);/",
				'action' => new actions\repairs\injectedJSRepair($file),
			),
		);
		$content = $file->read();

		$highlights = [];
		foreach($rules as $rule) {
			switch ($rule['type']) {
				case "exact":
					$valid = stripos($content, $rule['needle']) !== false;
					break;
				case "pattern":
					$valid = preg_match($rule['needle'], $content, $matches) > 0;
					break;
				default:
					$valid = false;
			}
			if ($valid) {
				return $rule['action']->setReason($rule['reason'] ?? "Found \"" . ($rule['type'] == 'exact' ? $rule['needle'] : $matches[0]) . "\" in js file");
			}
		}
		return null;
	}
}
