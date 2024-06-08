<?php

namespace packages\peeker\Scanners;

use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\ActionConflictException;
use packages\peeker\Actions;
use packages\peeker\FileScannerTrait;
use packages\peeker\IAction;
use packages\peeker\Scanner;

class JSScanner extends Scanner
{
    use FileScannerTrait;

    public function scan(): void
    {
        $log = Log::getInstance();
        $files = $this->getFilesWithNoAction($this->home, ['js', 'json']);
        foreach ($files as $file) {
            $path = $file->getRelativePath($this->home);
            $log->debug('check', $path);
            $this->scanFile($file);
        }
    }

    protected function scanFile(File $file): void
    {
        $log = Log::getInstance();
        $action = $this->checkFileName($file);
        if (!$action) {
            $action = $this->checkFileSource($file);
        }
        if (!$action) {
            return;
        }
        if (!$action instanceof Actions\CleanFile) {
            $log->reply('Infacted, Reason:', $action->getReason());
        }
        try {
            $this->actions->add($action);
        } catch (ActionConflictException $conflict) {
            $old = $conflict->getOldAction();
            if (
                !$old instanceof Actions\CleanFile
                and !$old instanceof Actions\Repair
                and !$old instanceof Actions\ReplaceFile
                and !$old instanceof Actions\HandCheckFile
            ) {
                $this->actions->delete($old);
                $this->actions->add((new Actions\HandCheckFile($file))->setReason('resolving-conflict'));
            }
        }
    }

    protected function checkFileName(File $file): ?IAction
    {
        $badNames = [];
        $badNamesPatterns = [];
        if (in_array($file->basename, $badNames)) {
            return (new Actions\RemoveFile($file))
                ->setReason('bad-name-js');
        }
        foreach ($badNamesPatterns as $badName) {
            if (preg_match($badName, $file->basename)) {
                return (new Actions\RemoveFile($file))
                    ->setReason('bad-name-js');
            }
        }

        return null;
    }

    public function checkFileSource(File $file): ?IAction
    {
        $log = Log::getInstance();
        $rules = [
            [
                'type' => 'pattern',
                'needle' => "/^Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);/",
                'action' => new Actions\Repairs\NastyJSVirusRepair($file, 'in-js'),
            ],
            [
                'type' => 'pattern',
                'needle' => '/108.+111.+119.+101.+114.+98.+101.+102.+111.+114.+119.+97.+114.+100.+101.+110/',
                'action' => new Actions\HandCheckFile($file),
            ],
            [
                'type' => 'pattern',
                'needle' => '/lowerbeforwarden/i',
                'action' => new Actions\HandCheckFile($file),
            ],
            [
                'type' => 'pattern',
                'needle' => '/var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);/',
                'action' => new Actions\Repairs\InjectedJSRepair($file),
            ],
        ];
        $content = $file->read();

        $highlights = [];
        foreach ($rules as $rule) {
            switch ($rule['type']) {
                case 'exact':
                    $valid = false !== stripos($content, $rule['needle']);
                    break;
                case 'pattern':
                    $valid = preg_match($rule['needle'], $content, $matches) > 0;
                    break;
                default:
                    $valid = false;
            }
            if ($valid) {
                return $rule['action']->setReason($rule['reason'] ?? 'Found "'.('exact' == $rule['type'] ? $rule['needle'] : $matches[0]).'" in js file');
            }
        }

        return null;
    }
}
