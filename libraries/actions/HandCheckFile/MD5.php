<?php
namespace packages\peeker\actions\HandCheckFile;
use packages\base\{db, Date, Exception};

/**
 * @property string|null $md5
 * @property reason|null $reason
 * @property int|null $update_at
 * @property string|null $action
 */
class MD5 extends db\dbObject {
	const OK = 1;
	const DELETE = 2;
	const REPLACE = 3;

	protected $dbTable = "peeker_handchecks_md5";
	protected $primaryKey = "md5";
	protected $dbFields = array(
		"md5" => array("type" => "text", "required" => true),
		"reason" => array("type" => "text"),
		"update_at" => array("type" => "int", "required" => true),
		"action" => array("type" => "text", "required" => true),
	);
	protected $relations = array();

	public function preLoad(array $data): array {
		if (!isset($data["update_at"])) {
			$data["update_at"] = Date::time();
		}
		return $data;
	}

	public function toAnswer(): string {
		switch ($this->action) {
			case self::OK:
				return "OK";
			case self::DELETE:
				return "D";
			case self::REPLACE:
				return "R";
			default:
				throw new Exception("unexcepted action: {$this->action}");
		}
	}
	public function fromAnswer(string $answer): void {
		switch ($answer) {
			case "OK":
				$this->action = self::OK;
				break;
			case "D":
				$this->action = self::DELETE;
				break;
			case "R":
				$this->action = self::REPLACE;
				break;
			default:
				throw new Exception();
		}
	}
}