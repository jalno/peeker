<?php

namespace packages\peeker\actions\wordpress;

use packages\base\Log;
use packages\peeker\actions\Repair;
use packages\peeker\IAction;
use packages\peeker\IActionDatabase;
use packages\peeker\WordpressScript;

class ScriptInPostsContentRepair extends Repair implements IActionDatabase
{
    protected $script;
    protected $post;

    public function __construct(WordpressScript $script, int $post)
    {
        $this->script = $script;
        $this->post = $post;
    }

    public function getScript(): WordpressScript
    {
        return $this->script;
    }

    public function getPostID(): int
    {
        return $this->post;
    }

    public function hasConflict(IAction $other): bool
    {
        return false;
    }

    public function isValid(): bool
    {
        return true;
    }

    public function do(): void
    {
        $log = Log::getInstance();
        $log->info("Repair injected script tag in post content #{$this->post}");
        $sql = $this->script->requireDB();
        $sql->where('ID', $this->post)
            ->update('posts', [
                'post_content' => $sql->func('REGEXP_REPLACE(`post_content`, "<script.+</script>", "")'),
            ]);
    }

    public function serialize()
    {
        return serialize([
            $this->script,
            $this->post,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->script = $data[0];
        $this->post = $data[1];
    }
}
