<?php

namespace packages\peeker\actions\repairs;

use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\actions\Repair;
use packages\peeker\IAction;
use packages\peeker\IActionFile;
use packages\peeker\IO\IPreloadedMd5;

class ForbiddenBase64Repair extends Repair implements IActionFile
{
    protected $file;
    protected $md5;

    public function __construct(File $file)
    {
        $this->file = $file;
        $this->md5 = $file->md5();
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function hasConflict(IAction $other): bool
    {
        return !$other instanceof static and $other instanceof IActionFile and $other->getFile()->getPath() == $this->file->getPath();
    }

    public function isValid(): bool
    {
        if ($this->file instanceof IPreloadedMd5) {
            $this->file->resetMd5();
        }

        return $this->file->exists() and $this->file->md5() == $this->md5;
    }

    public function do(): void
    {
        $log = Log::getInstance();
        $log->info("Repair Forbidden Base64 {$this->file->getPath()}");
        $pattern = '/'.
            '^\<\?php\R?.*'.
            'PCFET0NUWVBFIEhUTUwgUFVCTElDICItLy9JRVRGLy9EVEQgSFRNTCAyLjAvL0VOIj4KPGh0bWw\+PG\R?'.
            'hlYWQ\+Cjx0aXRsZT40MDMgRm9yYmlkZGVuPC90aXRsZT4KPC9oZWFkPjxib2R5Pgo8aDE\+Rm9yYmlkZGVuPC9oMT4KPHA\+WW91IGRvbid0IGhhdmUgcGVybWlzc2lvbiB0byBhY2Nlc3MgdGhpcyByZXNvdXJjZS48L3A\+Cjxocj4KPC\R?'.
            '9ib2R5PjwvaHRtbD4\=.*die.*\R?'.
            '\?\>\R?'.
        '/';

        $content = $this->file->read();
        $content = preg_replace($pattern, '', $content);
        $this->file->write($content);
    }

    public function serialize()
    {
        return serialize([
            $this->file,
            $this->md5,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->file = $data[0];
        $this->md5 = $data[1];
    }
}
