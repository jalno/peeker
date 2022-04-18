<?php
namespace packages\peeker\interfaces;

use packages\base\IO\File;
use packages\peeker\IInterface;

class CLI implements IInterface {
	public function askQuestion(string $question, ?array $answers = null, \Closure $callback): void {
		do {
			$helpToAsnwer = "";
			if ($answers) {
				foreach ($answers as $shortcut => $answer) {
					if ($helpToAsnwer) {
						$helpToAsnwer .= ", ";
					}
					$shutcut = strtoupper($shortcut);
					$helpToAsnwer .= ($answer != $shutcut ? $shortcut . " = " : "") . $answer;
				}
			}
			echo($question . ($helpToAsnwer ? " [{$helpToAsnwer}]" : "") . ": ");
			$response = trim(fgets(STDIN));
			if ($answers) {
				$response = strtoupper($response);
				$shutcuts = array_map('strtoupper', array_keys($answers));
				if (in_array($response, $shutcuts)) {
					$callback($response);
					return;
				}
			} elseif ($response) {
				$callback($response);
				return;
			}
		}while(true);
	}

	public function showFile(File $file): void {
		$nanoFile = $file;
		if (!$file instanceof File\Local) {
			$nanoFile = new File\Tmp();
			$file->copyTo($nanoFile); 
		}
		$initMd5 = $nanoFile->md5();
		system('nano --help | grep linenumbers > /dev/null', $exitCode);
		$supportLineNumber = $exitCode == 0;

		system('nano ' . ($supportLineNumber ? '--linenumbers ' : '') . '--softwrap ' . $nanoFile->getPath() . ' > `tty`');
		if ($nanoFile !== $file and $nanoFile->md5() != $initMd5) {
			$nanoFile->copyTo($file);
		}
	}
}
