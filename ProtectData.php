<?php

namespace vasadibt\protector;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\di\Instance;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\Application as WebApplication;
use yii\web\Response;
use yii\web\View;

/**
 * Class ProtectData
 * @package vasadibt\protector
 *
 * Configuration:
 * 'bootstrap' => [
 *     // ...
 *     'protector',
 *     // ...
 * ],
 * 'components' => [
 *     // ...
 *     'protector' => [
 *         'class' => '\vasadibt\protector\ProtectData',
 *     ]
 *     // ...
 * ],
 * Usage:
 *
 *
 *
 * <h3>Write to me<span class="email">[[protect:info@company.com]]</span></h3>
 *
 */
class ProtectData extends Component implements BootstrapInterface
{
    const PD_SCRIPT_BLOCK = '<![CDATA[PROTECT-SCRIPT-BLOCK]]>';

    /**
     * @var bool
     */
    public $enable = true;
    /**
     * @var bool
     */
    public $debug = false;
    /**
     * @var string
     */
    public $protectPattern = '/\[\[protect:(.*)\]\]/U';
    /**
     * @var string
     */
    public $protectTemplate = '[[protect:%s]]';
    /**
     * @var string
     */
    public $safeTemplate = '[[%s]]';
    /**
     * @var string
     */
    public $safePrefix = 'safe_';
    /**
     * @var bool
     */
    public $enableAutoDetect = false;
    /**
     * @var array
     */
    public $autoDetectPatterns = [
        "/[-0-9a-zA-Z.+_]+@[-0-9a-zA-Z.+_]+.[a-zA-Z]{2,4}/U", // https://thisinterestsme.com/php-email-regex/
    ];
    /**
     * @var callable
     */
    public $autoDetectMatchCallback;
    /**
     * @var WebApplication
     */
    public $app;
    /**
     * @var Response|string
     */
    public $response = 'response';
    /**
     * @var View|string
     */
    public $view = 'view';

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        parent::init();
        $this->app = $this->app ?? Yii::$app;
        $this->response = Instance::ensure($this->response, Response::class, $this->app);
        $this->view = Instance::ensure($this->view, View::class, $this->app);
    }

    /**
     * @inheritDoc
     */
    public function bootstrap($app)
    {
        if ($this->enable) {
            $this->registerEvents();
        }
    }

    /**
     * Register events
     */
    public function registerEvents()
    {
        // register javascript location in content
        $this->view->on(View::EVENT_END_BODY, [$this, 'scriptBlock']);

        // register dynamic values to javascript and add content position
        $this->response->on(Response::EVENT_BEFORE_SEND, [$this, 'processResponseContent']);
    }

    /**
     * Register script block position
     */
    public function scriptBlock()
    {
        if (!$this->enable || $this->response->format !== Response::FORMAT_HTML) {
            return;
        }
        echo static::PD_SCRIPT_BLOCK;
    }

    /**
     * Build protect data
     */
    public function processResponseContent()
    {
        if (!$this->enable || $this->response->format !== Response::FORMAT_HTML) {
            return;
        }

        $content = $this->getContent();
        $registeredItems = [];

        $this->registerItems($content, $registeredItems);

        if ($this->enableAutoDetect) {
            $this->registerAutoDetectItems($content, $registeredItems);
        }

        if (empty($registeredItems)) {
            $this->setScriptBlock($content);
            $this->setContent($content);
            return;
        }

        $characterList = $this->generateCharacterList($registeredItems);

        $js = $this->buildClientScript($characterList, $registeredItems);
        $this->setScriptBlock($content, $js);

        $this->setContent($content);
    }

    /**
     * @param string $content
     * @param array $registeredItems
     */
    public function registerItems(string &$content, array &$registeredItems)
    {
        if (preg_match_all($this->protectPattern, $content, $matches)) {
            foreach ($matches[0] as $k => $match) {
                if (array_search($matches[1][$k], $registeredItems) !== false) {
                    continue;
                }

                $id = $this->generateSafeId($matches[1][$k]);
                $registeredItems[$id] = $matches[1][$k];
                $content = str_replace($match, sprintf($this->safeTemplate, $id), $content);
            }
        }
    }

    /**
     * @param string $content
     * @param array $registeredItems
     */
    public function registerAutoDetectItems(string &$content, array &$registeredItems)
    {
        foreach ($this->autoDetectPatterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $matchIndex => $match) {
                    if (array_search($match, $registeredItems) !== false) {
                        continue;
                    }

                    if (is_callable($this->autoDetectMatchCallback) && !call_user_func($this->autoDetectMatchCallback, $match, $pattern, $matchIndex, $content)) {
                        continue;
                    }

                    $id = $this->generateSafeId($match);
                    $registeredItems[$id] = $match;
                    $content = str_replace($match, sprintf($this->safeTemplate, $id), $content);
                }
            }
        }
    }

    /**
     * @param array $characterList
     * @param array $registeredItems
     * @return string
     */
    public function buildClientScript(array $characterList, array $registeredItems)
    {
        $js = <<<JS
if(!String.prototype.replaceAll){
    String.prototype.replaceAll = function(search, replacement) {
        var target = this;
        return target.split(search).join(replacement);
    };    
}

JS;
        $js .= sprintf("var characterList = %s;\n", Json::htmlEncode($characterList));

        $characterMap = array_flip($characterList);
        foreach ($registeredItems as $id => $value) {
            $keys = [];
            foreach (str_split($value) as $character) {
                $keys [] = $characterMap[$character];
            }

            $safeKey = sprintf('[[%s]]', $id);
            $valueGenerator = sprintf("%s.map(index => characterList[index]).join('')", Json::htmlEncode($keys));
            $js .= sprintf("document.body.innerHTML = document.body.innerHTML.replaceAll('%s', %s);\n", $safeKey, $valueGenerator);
        }

        if($this->debug){
            $json = Json::encode($registeredItems, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_PRETTY_PRINT);
            $js .= sprintf("var protectorDebug = %s;\n", $json);
        }

        return $js;
    }

    /**
     * @param string $content
     * @param string $script
     */
    public function setScriptBlock(string &$content, $script = '')
    {
        if (!empty($script)) {
            $script = "<script>\n" . $script . "</script>\n";
        }

        $content = str_replace(static::PD_SCRIPT_BLOCK, $script, $content);
    }

    /**
     * @return string|mixed
     */
    public function getContent()
    {
        return $this->response->data;
    }

    /**
     * @param string $content
     */
    public function setContent($content)
    {
        $this->response->data = $content;
    }

    /**
     * @param string $protectString
     * @param string|null $prefix
     * @return string
     */
    public function generateSafeId($protectString, $prefix = null)
    {
        $prefix = $prefix ?? $this->safePrefix;
        return $prefix . md5(md5($protectString) . static::class);
    }

    /**
     * @param array $registeredItems
     * @return array
     */
    private function generateCharacterList(array $registeredItems)
    {
        $fullString = join($registeredItems);
        $characterList = array_unique(str_split($fullString));
        sort($characterList);
        return $characterList;
    }

    /** ########################################## HELPERS ########################################## **/

    /**
     * @param $string
     * @return string
     */
    public function protect($string)
    {
        return sprintf($this->protectTemplate, $string);
    }

    /**
     * @param string $text
     * @param string $email
     * @param array $options
     * @return string
     */
    public function mailto($text, $email = null, $options = [])
    {
        return $this->a(
            $this->protect($text),
            'mailto:' . $this->protect($email ?? $text),
            $options
        );
    }

    /**
     * @param string $text
     * @param string $tel
     * @param array $options
     * @return string
     */
    public function tel($text, $tel = null, $options = [])
    {
        return $this->a(
            $this->protect($text),
            'tel:' . $this->protect($tel ?? $text),
            $options
        );
    }

    /**
     * @param $text
     * @param null $url
     * @param array $options
     * @return string
     */
    public function a($text, $url = null, $options = [])
    {
        if ($url !== null) {
            $options['href'] = $url;
        }

        return Html::tag(
            'a',
            $text,
            $options
        );
    }
}