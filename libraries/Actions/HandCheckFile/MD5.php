<?php

namespace packages\peeker\Actions\HandCheckFile;

use packages\base\Date;
use packages\base\DB;
use packages\base\Exception;

/**
 * @property string|null $md5
 * @property reason|null $reason
 * @property int|null    $update_at
 * @property string|null $action
 */
class MD5 extends DB\DBObject
{
    public const OK = 1;
    public const DELETE = 2;
    public const REPLACE = 3;

    protected $dbTable = 'peeker_handchecks_md5';
    protected $primaryKey = 'md5';
    protected $dbFields = [
        'md5' => ['type' => 'text', 'required' => true],
        'reason' => ['type' => 'text'],
        'update_at' => ['type' => 'int', 'required' => true],
        'action' => ['type' => 'text', 'required' => true],
    ];
    protected $relations = [];

    public function preLoad(array $data): array
    {
        if (!isset($data['update_at'])) {
            $data['update_at'] = Date::time();
        }

        return $data;
    }

    public function toAnswer(): string
    {
        switch ($this->action) {
            case self::OK:
                return 'OK';
            case self::DELETE:
                return 'D';
            case self::REPLACE:
                return 'R';
            default:
                throw new Exception("unexcepted action: {$this->action}");
        }
    }

    public function fromAnswer(string $answer): void
    {
        switch ($answer) {
            case 'OK':
                $this->action = self::OK;
                break;
            case 'D':
                $this->action = self::DELETE;
                break;
            case 'R':
                $this->action = self::REPLACE;
                break;
            default:
                throw new Exception();
        }
    }
}
