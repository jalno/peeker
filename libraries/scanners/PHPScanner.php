<?php
namespace packages\peeker\scanners;

use packages\base\{IO\File, Log};
use packages\peeker\{IAction, Scanner, FileScannerTrait, ActionConflictException, actions};

class PHPScanner extends Scanner {
	use FileScannerTrait;

	public function scan(): void {	
		$log = Log::getInstance();
		$files = $this->getFiles($this->home, array('php'));
		foreach ($files as $file) {
			$path = $file->getRelativePath($this->home);
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
		if (!$action instanceof actions\CleanFile) {
			$path = $file->getRelativePath($this->home);
			$log->info($path, "Infacted");
			$log->reply()->debug("Reason:", $action->getReason());
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
		$badNames = [
			"adminer.php",
			"pma.php", "phpmyadmin.php",
			"wp.php",
			"wpconfig.bak.php",
			"wp-build-report.php",
			"wp-stream.php",
			"1index.php",
			"aindex.php"
		];
		$badNamesPatterns = ["/_index\.php$/"];
		if (in_array($file->basename, $badNames)) {
			return (new actions\RemoveFile($file))
				->setReason('bad-name-php');
		}
		foreach ($badNamesPatterns as $badName) {
			if (preg_match($badName, $file->basename)) {
				return (new actions\RemoveFile($file))
					->setReason('bad-name-php');
			}
		}
		return null;
	}
	public function checkFileSource(File $file): ?IAction {
		$log = Log::getInstance();
		$rules = array(
			array(
				'type' => 'exact',
				'needle' => 'is_joomla',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'testtrue',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'add_backlink_to_post',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'str_split(rawurldecode(str_rot13',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'include \'check_is_bot.php\'',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'eval(gzuncompress(',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'fopen("part$i"',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'if (count($ret)>2000) continue;',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'Class_UC_key',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'LoginWall',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'CMSmap',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'file_put_contents(\'lte_\',\'<?php \'.$lt)',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'go go go',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/<script.*>\s*Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);<\/script>/',
				'action' => new actions\repairs\NastyJSVirusRepair($file, 'in-php'),
			),
			array(
				'type' => 'pattern',
				'needle' => "/^Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);/",
				'action' => new actions\repairs\NastyJSVirusRepair($file, 'in-js'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/function .*=substr\(.*\(int\)\(hex2bin\(.*eval\(eval\(eval\(eval/',
				'action' => new actions\RemoveFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => "/108.+111.+119.+101.+114.+98.+101.+102.+111.+114.+119.+97.+114.+100.+101.+110/",
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<script.+<\?php/i',
				'action' => new actions\repairs\InjectedLowerbeforwardenRepair($file, 'php'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<script .* src=.*temp\.js.*/i',
				'action' => new actions\repairs\InjectedLowerbeforwardenRepair($file, 'html'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/<script.*src=\".*hostingcloud.*\".*><\/script>/i',
				'action' => new actions\repairs\InjectedHostingCloudRacing($file, 'default'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/<script>.*Client.Anonymous.*<\/script>/i',
				'action' => new actions\repairs\InjectedHostingCloudRacing($file, 'default'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<\?php\s{200,}.+eval.+\?><\?php/i',
				'action' => new actions\repairs\InjectedFirstlinePHPRepair($file, 'default'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<\?php.+md5.+\?><\?php/i',
				'action' => new actions\repairs\InjectedFirstlinePHPRepair($file, 'md5'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/\<\?php\s+.+\$_REQUEST\[md5\([\s\S]+function_exists:\s+true.+\s+.+\?><\?php/',
				'action' => new actions\repairs\InjectedFirstlinePHPRepair($file, 'second-line'),
			),
			array(
				'type' => 'pattern',
				'needle' => '/<script.+ src=[\"\'].+lowerbeforwarden.+[\"\'].*><\/script>/i',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'function_exists: true',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => '"_F"."IL"."ES"',
				'action' => new actions\RemoveFile($file),
			),

			array(
				'type' => 'exact',
				'needle' => 'mapilo.net',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'theme_temp_setup',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'div_code_name',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'start_wp_theme_tmp',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => "function updatefile(\$blacks='')",
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => "daksldlkdsadas",
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => "/^\<\?php .{200,}/",
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => "/^<script .{30,}<\/script>/",
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => "/var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);/",
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/\$\w+\s*=.+array\(\);.+exit\(\);.+}$/i',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => 'move_uploaded_file',
				'action' => new actions\HandCheckFile($file)
			),
			array(
				'type' => 'pattern',
				'needle' => '/\Weval\s*[\[\]\~\!\\\'\"\@\#\$%\^\&\*\(\)\-_+=:\{\}\<\>\/\\\\]/',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'exact',
				'needle' => '$htaccess_rule',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/\$function\s*=.+b.*a.*s.*e.*6.*4.*_.*d.*e.*c.*o.*d.*e.*\s+\$string\s*=\s*\$function\(\$s\);/i',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/ignore_user_abort[\s\S]+\..*h.*t.*a.*c.*c.*e.*s.*s/i',
				'action' => new actions\HandCheckFile($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/\/\*([\w\d]+)\*\/\s+@?include.+\s+\/\*\1\*\//',
				'action' => new actions\repairs\InjectedIcoIncludeRepair($file),
			),
			array(
				'type' => 'pattern',
				'needle' => '/(curl_init|curl_setopt|copy|file_get_content|fopen|md5)\s*\(.*\$_(GET|POST|REQUEST|COOKIE).*\)/i',
				'action' => new actions\HandCheckFile($file),
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
				return $rule['action']->setReason($rule['reason'] ?? "Found \"" . ($rule['type'] == 'exact' ? $rule['needle'] : $matches[0]) . "\" in php file");
			}
		}
		return null;
	}
}
