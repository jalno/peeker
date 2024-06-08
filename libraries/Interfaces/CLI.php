<?php

namespace packages\peeker\Interfaces;

use packages\base\IO\File;
use packages\peeker\IInterface;

class CLI implements IInterface
{
    public function askQuestion(string $question, ?array $answers, \Closure $callback): void
    {
        while (true) {
            $helpToAsnwer = '';
            if ($answers) {
                foreach ($answers as $shortcut => $answer) {
                    if ($helpToAsnwer) {
                        $helpToAsnwer .= ', ';
                    }
                    $shutcut = strtoupper($shortcut);
                    $helpToAsnwer .= ($answer != $shutcut ? $shortcut.' = ' : '').$answer;
                }
            }
            echo $question.($helpToAsnwer ? " [{$helpToAsnwer}]" : '').': ';
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
        }
    }

    public function showFile(File $file): void
    {
        $nanoFile = $file;
        if (!$file instanceof File\Local) {
            $nanoFile = new File\TMP();
            $file->copyTo($nanoFile);
        }
        $initMd5 = $nanoFile->md5();
        system('nano --help | grep linenumbers > /dev/null', $exitCode);
        $supportLineNumber = 0 == $exitCode;

        system('nano '.($supportLineNumber ? '--linenumbers ' : '').'--softwrap '.$nanoFile->getPath().' > `tty`');
        if ($nanoFile !== $file and $nanoFile->md5() != $initMd5) {
            $nanoFile->copyTo($file);
        }
    }
}
