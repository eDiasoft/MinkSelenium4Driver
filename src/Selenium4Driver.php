<?php

/*
 * This file is part of the Behat\Mink.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\Mink\Driver;

use Behat\Mink\Exception\DriverException;
use Behat\Mink\KeyModifier;
use Behat\Mink\Selector\Xpath\Escaper;
use WebDriver\Element;
use WebDriver\Exception\NoSuchElement;
use WebDriver\Exception\StaleElementReference;
use WebDriver\Exception\UnknownCommand;
use WebDriver\Exception\UnknownError;
use WebDriver\Key;
use WebDriver\Session;
use WebDriver\WebDriver;
use WebDriver\Window;

/**
 * Selenium2 driver.
 *
 * @author Pete Otaqui <pete@otaqui.com>
 */
class Selenium4Driver extends CoreDriver
{
    /**
     * Whether the browser has been started
     * @var bool
     */
    private bool $started = false;

    /**
     * The WebDriver instance
     * @var WebDriver
     */
    private WebDriver $webDriver;

    /**
     * @var string
     */
    private string $browserName;

    /**
     * @var array
     */
    private array $firstMatch = array();

    /**
     * @var array
     */
    private array $alwaysMatch = array();

    /**
     * The WebDriverSession instance
     * @var Session|null
     */
    private Session $wdSession;

    /**
     * The timeout configuration
     * @var array{script?: int, implicit?: int, page?: int}
     */
    private array $timeouts = array();

    /**
     * @var Escaper
     */
    private Escaper $xpathEscaper;

    /**
     * Instantiates the driver.
     *
     * @param string     $browserName         Browser name
     * @param array|null $capabilities The desired capabilities
     * @param string     $wdHost              The WebDriver host
     */
    public function __construct(string $browserName = 'firefox', ?array $capabilities = null, string $wdHost = 'http://localhost:4444/wd/hub')
    {
        $this->setBrowserName($browserName);
        $this->setCapabilities($capabilities);
        $this->setWebDriver(new WebDriver($wdHost));
        $this->xpathEscaper = new Escaper();
    }

    /**
     * Sets the browser name
     *
     * @param string $browserName the name of the browser to start, default is 'firefox'
     *
     * @return void
     */
    protected function setBrowserName(string $browserName = 'firefox')
    {
        $this->browserName = $browserName;
    }

    /**
     * Sets the desired capabilities - called on construction.  If null is provided, will set the
     * defaults as desired.
     *
     * See http://code.google.com/p/selenium/wiki/DesiredCapabilities
     *
     * @param array|null $desiredCapabilities an array of capabilities to pass on to the WebDriver server
     *
     * @return void
     *
     * @throws DriverException
     */
    public function setCapabilities(?array $capabilities = null)
    {
        if ($this->started) {
            throw new DriverException("Unable to set capabilities, the session has already started");
        }

        if ($capabilities === null) {
            return;
        }

        $this->firstMatch = (isset($capabilities['capabilities']['firstMatch']))? $capabilities['capabilities']['firstMatch'] : [];
        $this->alwaysMatch = (isset($capabilities['capabilities']['alwaysMatch']))? $capabilities['capabilities']['alwaysMatch'] : [];
    }

    /**
     * Gets the firstMatch
     *
     * @return array
     */
    public function getfirstMatch()
    {
        return $this->firstMatch;
    }

    /**
     * Gets the alwaysMatch
     *
     * @return array
     */
    public function getalwaysMatch()
    {
        return $this->alwaysMatch;
    }

    /**
     * Sets the WebDriver instance
     *
     * @param WebDriver $webDriver An instance of the WebDriver class
     *
     * @return void
     */
    public function setWebDriver(WebDriver $webDriver)
    {
        $this->webDriver = $webDriver;
    }

    /**
     * Gets the WebDriverSession instance
     *
     * @return Session
     *
     * @throws DriverException if the session is not started
     */
    public function getWebDriverSession()
    {
        if ($this->wdSession === null) {
            throw new DriverException('The driver is not started.');
        }

        return $this->wdSession;
    }

    /**
     * Returns the default capabilities
     *
     * @return array
     */
    public static function getDefaultCapabilities()
    {
        return array(
            'browser'           => 'firefox',
            'name'              => 'Behat Test',
        );
    }

    /**
     * Makes sure that the Syn event library has been injected into the current page,
     * and return $this for a fluid interface,
     *
     *     $this->withSyn()->executeJsOnXpath($xpath, $script);
     *
     * @return Selenium4Driver
     *
     * @throws DriverException
     */
    protected function withSyn()
    {
        $hasSyn = $this->getWebDriverSession()->execute(array(
            'script' => 'return window.syn !== undefined && window.syn.trigger !== undefined',
            'args'   => array()
        ));

        if (!$hasSyn) {
            $synJs = file_get_contents(__DIR__.'/Resources/syn.js');
            \assert($synJs !== false);
            $this->getWebDriverSession()->execute(array(
                'script' => $synJs,
                'args'   => array()
            ));
        }

        return $this;
    }

    /**
     * Creates some options for key events
     *
     * @param string|int          $char     the character or code
     * @param KeyModifier::*|null $modifier
     *
     * @return string a json encoded options array for Syn
     *
     * @throws DriverException
     */
    protected static function charToOptions($char, ?string $modifier = null)
    {
        if (is_int($char)) {
            $charCode = $char;
            $char = chr($charCode);
        } else {
            $charCode = ord($char);
        }

        $options = array(
            'key'  => $char,
            'which'  => $charCode,
            'charCode'  => $charCode,
            'keyCode'  => $charCode,
        );

        if ($modifier) {
            $options[$modifier . 'Key'] = true;
        }

        $json = json_encode($options);

        if ($json === false) {
            throw new DriverException('Failed to encode options: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Executes JS on a given element - pass in a js script string and {{ELEMENT}} will
     * be replaced with a reference to the result of the $xpath query
     *
     * @example $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.childNodes.length');
     *
     * @param string $xpath  the xpath to search with
     * @param string $script the script to execute
     * @param bool   $sync   whether to run the script synchronously (default is TRUE)
     *
     * @return mixed
     *
     * @throws DriverException
     */
    protected function executeJsOnXpath(string $xpath, string $script, bool $sync = true)
    {
        return $this->executeJsOnElement($this->findElement($xpath), $script, $sync);
    }

    /**
     * Executes JS on a given element - pass in a js script string and {{ELEMENT}} will
     * be replaced with a reference to the element
     *
     * @example $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.childNodes.length');
     *
     * @param Element $element the webdriver element
     * @param string  $script  the script to execute
     * @param bool    $sync    whether to run the script synchronously (default is TRUE)
     *
     * @return mixed
     */
    private function executeJsOnElement(Element $element, string $script, bool $sync = true)
    {
        $script  = str_replace('{{ELEMENT}}', 'arguments[0]', $script);

        $options = array(
            'script' => $script,
            'args'   => array($element),
        );

        if ($sync) {
            return $this->getWebDriverSession()->execute($options);
        }

        return $this->getWebDriverSession()->execute_async($options);
    }

    public function start()
    {
        try {
            $this->wdSession = $this->webDriver->session($this->browserName, $this->firstMatch, $this->alwaysMatch);
        } catch (\Exception $e) {
            throw new DriverException('Could not open connection: '.$e->getMessage(), 0, $e);
        }

        $this->started = true;

        $this->applyTimeouts();
    }

    /**
     * Sets the timeouts to apply to the webdriver session
     *
     * @param array{script?: int, implicit?: int, page?: int} $timeouts times are in milliseconds
     *
     * @return void
     *
     * @throws DriverException
     */
    public function setTimeouts(array $timeouts)
    {
        $this->timeouts = $timeouts;

        if ($this->isStarted()) {
            $this->applyTimeouts();
        }
    }

    /**
     * Applies timeouts to the current session
     */
    private function applyTimeouts(): void
    {
        try {
            foreach ($this->timeouts as $type => $param) {
                $this->getWebDriverSession()->timeouts($type, $param);
            }
        } catch (UnknownError $e) {
            throw new DriverException('Error setting timeout: ' . $e->getMessage(), 0, $e);
        }
    }

    public function isStarted()
    {
        return $this->started;
    }

    public function stop()
    {
        if (!$this->wdSession) {
            throw new DriverException('Could not connect to a Selenium 2 / WebDriver server');
        }

        $this->started = false;
        try {
            $this->wdSession->close();
        } catch (\Exception $e) {
            throw new DriverException('Could not close connection', 0, $e);
        }
    }

    public function reset()
    {
        $this->getWebDriverSession()->deleteAllCookies();
    }

    public function visit(string $url)
    {
        $this->getWebDriverSession()->open($url);
    }

    public function getCurrentUrl()
    {
        return $this->getWebDriverSession()->url();
    }

    public function reload()
    {
        $this->getWebDriverSession()->refresh();
    }

    public function forward()
    {
        $this->getWebDriverSession()->forward();
    }

    public function back()
    {
        $this->getWebDriverSession()->back();
    }

    public function switchToWindow(?string $name = null)
    {
        $this->getWebDriverSession()->focusWindow($name ?: '');
    }

    public function switchToIFrame(?string $name = null)
    {
        $this->getWebDriverSession()->frame(array('id' => $name));
    }

    public function setCookie(string $name, ?string $value = null)
    {
        if (null === $value) {
            $this->getWebDriverSession()->deleteCookie($name);

            return;
        }

        // PHP 7.4 changed the way it encodes cookies to better respect the spec.
        // This assumes that the server and the Mink client run on the same version (or
        // at least the same side of the behavior change), so that the server and Mink
        // consider the same value.
        if (\PHP_VERSION_ID >= 70400) {
            $encodedValue = rawurlencode($value);
        } else {
            $encodedValue = urlencode($value);
        }

        $cookieArray = array(
            'name'   => $name,
            'value'  => $encodedValue,
            'secure' => false, // thanks, chibimagic!
        );

        $this->getWebDriverSession()->setCookie($cookieArray);
    }

    public function getCookie(string $name)
    {
        $cookies = $this->getWebDriverSession()->getAllCookies();
        foreach ($cookies as $cookie) {
            if ($cookie['name'] === $name) {
                // PHP 7.4 changed the way it encodes cookies to better respect the spec.
                // This assumes that the server and the Mink client run on the same version (or
                // at least the same side of the behavior change), so that the server and Mink
                // consider the same value.
                if (\PHP_VERSION_ID >= 70400) {
                    return rawurldecode($cookie['value']);
                }

                return urldecode($cookie['value']);
            }
        }

        return null;
    }

    public function getContent()
    {
        return $this->getWebDriverSession()->source();
    }

    public function getScreenshot()
    {
        return base64_decode($this->getWebDriverSession()->screenshot());
    }

    public function getWindowNames()
    {
        return $this->getWebDriverSession()->window_handles();
    }

    public function getWindowName()
    {
        return $this->getWebDriverSession()->window_handle();
    }

    /**
     * @protected
     */
    public function findElementXpaths(string $xpath)
    {
        $nodes = $this->getWebDriverSession()->elements('xpath', $xpath);

        $elements = array();
        foreach ($nodes as $i => $node) {
            $elements[] = sprintf('(%s)[%d]', $xpath, $i+1);
        }

        return $elements;
    }

    public function getTagName(string $xpath)
    {
        return $this->findElement($xpath)->name();
    }

    public function getText(string $xpath)
    {
        $node = $this->findElement($xpath);
        $text = $node->text();
        $text = (string) str_replace(array("\r", "\r\n", "\n"), ' ', $text);

        return $text;
    }

    public function getHtml(string $xpath)
    {
        return $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.innerHTML;');
    }

    public function getOuterHtml(string $xpath)
    {
        return $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.outerHTML;');
    }

    public function getAttribute(string $xpath, string $name)
    {
        $script = 'return {{ELEMENT}}.getAttribute(' . json_encode((string) $name) . ')';

        return $this->executeJsOnXpath($xpath, $script);
    }

    public function getValue(string $xpath)
    {
        $element = $this->findElement($xpath);
        $elementName = strtolower($element->name());
        $elementType = strtolower($element->attribute('type') ?: '');

        // Getting the value of a checkbox returns its value if selected.
        if ('input' === $elementName && 'checkbox' === $elementType) {
            return $element->selected() ? $element->attribute('value') : null;
        }

        if ('input' === $elementName && 'radio' === $elementType) {
            $script = <<<JS
var node = {{ELEMENT}},
    value = null;

var name = node.getAttribute('name');
if (name) {
    var fields = window.document.getElementsByName(name),
        i, l = fields.length;
    for (i = 0; i < l; i++) {
        var field = fields.item(i);
        if (field.form === node.form && field.checked) {
            value = field.value;
            break;
        }
    }
}

return value;
JS;

            return $this->executeJsOnElement($element, $script);
        }

        // Using $element->attribute('value') on a select only returns the first selected option
        // even when it is a multiple select, so a custom retrieval is needed.
        if ('select' === $elementName && $element->attribute('multiple')) {
            $script = <<<JS
var node = {{ELEMENT}},
    value = [];

for (var i = 0; i < node.options.length; i++) {
    if (node.options[i].selected) {
        value.push(node.options[i].value);
    }
}

return value;
JS;

            return $this->executeJsOnElement($element, $script);
        }

        return $element->attribute('value');
    }

    public function setValue(string $xpath, $value)
    {
        $element = $this->findElement($xpath);
        $elementName = strtolower($element->name());

        if ('select' === $elementName) {
            if (is_array($value)) {
                $this->deselectAllOptions($element);

                foreach ($value as $option) {
                    $this->selectOptionOnElement($element, $option, true);
                }

                return;
            }

            if (\is_bool($value)) {
                throw new DriverException('Boolean values cannot be used for a select element.');
            }

            $this->selectOptionOnElement($element, $value);

            return;
        }

        if ('input' === $elementName) {
            $elementType = strtolower($element->attribute('type') ?: '');

            if (in_array($elementType, array('submit', 'image', 'button', 'reset'))) {
                throw new DriverException(sprintf('Impossible to set value an element with XPath "%s" as it is not a select, textarea or textbox', $xpath));
            }

            if ('checkbox' === $elementType) {
                if ($element->selected() xor (bool) $value) {
                    $this->clickOnElement($element);
                }

                return;
            }

            if ('radio' === $elementType) {
                if (!\is_string($value)) {
                    throw new DriverException('Only string values can be used for a radio input.');
                }

                $this->selectRadioValue($element, $value);

                return;
            }

            if ('file' === $elementType) {
                if (!\is_string($value)) {
                    throw new DriverException('Only string values can be used for a file input.');
                }

                $element->postValue(array('value' => array(strval($value))));

                return;
            }
        }

        if (!\is_string($value)) {
            throw new DriverException(sprintf('Only string values can be used for a %s element.', $elementName));
        }

        $value = strval($value);

        if (in_array($elementName, array('input', 'textarea'))) {
            $existingValueLength = strlen($element->attribute('value'));
            $value = str_repeat(Key::BACKSPACE . Key::DELETE, $existingValueLength) . $value;
        }

        $element->postValue(array('value' => array($value)));
        // Remove the focus from the element if the field still has focus in
        // order to trigger the change event. By doing this instead of simply
        // triggering the change event for the given xpath we ensure that the
        // change event will not be triggered twice for the same element if it
        // has lost focus in the meanwhile. If the element has lost focus
        // already then there is nothing to do as this will already have caused
        // the triggering of the change event for that element.
        $script = <<<JS
var node = {{ELEMENT}};
if (document.activeElement === node) {
  document.activeElement.blur();
}
JS;

        // Cover case, when an element was removed from DOM after its value was
        // changed (e.g. by a JavaScript of a SPA) and therefore can't be focused.
        try {
            $this->executeJsOnElement($element, $script);
        } catch (StaleElementReference $e) {
            // Do nothing because an element was already removed and therefore
            // blurring is not needed.
        }
    }

    public function check(string $xpath)
    {
        $element = $this->findElement($xpath);
        $this->ensureInputType($element, $xpath, 'checkbox', 'check');

        if ($element->selected()) {
            return;
        }

        $this->clickOnElement($element);
    }

    public function uncheck(string $xpath)
    {
        $element = $this->findElement($xpath);
        $this->ensureInputType($element, $xpath, 'checkbox', 'uncheck');

        if (!$element->selected()) {
            return;
        }

        $this->clickOnElement($element);
    }

    public function isChecked(string $xpath)
    {
        return $this->findElement($xpath)->selected();
    }

    public function selectOption(string $xpath, string $value, bool $multiple = false)
    {
        $element = $this->findElement($xpath);
        $tagName = strtolower($element->name());

        if ('input' === $tagName && 'radio' === strtolower($element->attribute('type') ?: '')) {
            $this->selectRadioValue($element, $value);

            return;
        }

        if ('select' === $tagName) {
            $this->selectOptionOnElement($element, $value, $multiple);

            return;
        }

        throw new DriverException(sprintf('Impossible to select an option on the element with XPath "%s" as it is not a select or radio input', $xpath));
    }

    public function isSelected(string $xpath)
    {
        return $this->findElement($xpath)->selected();
    }

    public function click(string $xpath)
    {
        $this->clickOnElement($this->findElement($xpath));
    }

    private function clickOnElement(Element $element): void
    {
        try {
            // Move the mouse to the element as Selenium does not allow clicking on an element which is outside the viewport
            $this->getWebDriverSession()->moveto(array('element' => $element->getID()));
        } catch (UnknownCommand $e) {
            // If the Webdriver implementation does not support moveto (which is not part of the W3C WebDriver spec), proceed to the click
        } catch (UnknownError $e) {
            // Chromium driver sends back UnknownError (WebDriver\Exception with code 13)
        }

        $element->click();
    }

    public function doubleClick(string $xpath)
    {
        $this->mouseOver($xpath);
        $this->getWebDriverSession()->doubleclick();
    }

    public function rightClick(string $xpath)
    {
        $this->mouseOver($xpath);
        $this->getWebDriverSession()->click(array('button' => 2));
    }

    public function attachFile(string $xpath, string $path)
    {
        $element = $this->findElement($xpath);
        $this->ensureInputType($element, $xpath, 'file', 'attach a file on');

        // Upload the file to Selenium and use the remote path. This will
        // ensure that Selenium always has access to the file, even if it runs
        // as a remote instance.
        try {
          $remotePath = $this->uploadFile($path);
        } catch (\Exception $e) {
          // File could not be uploaded to remote instance. Use the local path.
          $remotePath = $path;
        }

        $element->postValue(array('value' => array($remotePath)));
    }

    public function isVisible(string $xpath)
    {
        return $this->findElement($xpath)->displayed();
    }

    public function mouseOver(string $xpath)
    {
        $this->getWebDriverSession()->moveto(array(
            'element' => $this->findElement($xpath)->getID()
        ));
    }

    public function focus(string $xpath)
    {
        $this->trigger($xpath, 'focus');
    }

    public function blur(string $xpath)
    {
        $this->trigger($xpath, 'blur');
    }

    public function keyPress(string $xpath, $char, ?string $modifier = null)
    {
        $options = self::charToOptions($char, $modifier);
        $this->trigger($xpath, 'keypress', $options);
    }

    public function keyDown(string $xpath, $char, ?string $modifier = null)
    {
        $options = self::charToOptions($char, $modifier);
        $this->trigger($xpath, 'keydown', $options);
    }

    public function keyUp(string $xpath, $char, ?string $modifier = null)
    {
        $options = self::charToOptions($char, $modifier);
        $this->trigger($xpath, 'keyup', $options);
    }

    public function dragTo(string $sourceXpath, string $destinationXpath)
    {
        $source      = $this->findElement($sourceXpath);
        $destination = $this->findElement($destinationXpath);

        $this->getWebDriverSession()->moveto(array(
            'element' => $source->getID()
        ));

        $script = <<<JS
(function (element) {
    var event = document.createEvent("HTMLEvents");

    event.initEvent("dragstart", true, true);
    event.dataTransfer = {};

    element.dispatchEvent(event);
}({{ELEMENT}}));
JS;
        $this->withSyn()->executeJsOnElement($source, $script);

        $this->getWebDriverSession()->buttondown();
        $this->getWebDriverSession()->moveto(array(
            'element' => $destination->getID()
        ));
        $this->getWebDriverSession()->buttonup();

        $script = <<<JS
(function (element) {
    var event = document.createEvent("HTMLEvents");

    event.initEvent("drop", true, true);
    event.dataTransfer = {};

    element.dispatchEvent(event);
}({{ELEMENT}}));
JS;
        $this->withSyn()->executeJsOnElement($destination, $script);
    }

    public function executeScript(string $script)
    {
        if (preg_match('/^function[\s\(]/', $script)) {
            $script = preg_replace('/;$/', '', $script);
            $script = '(' . $script . ')';
        }

        $this->getWebDriverSession()->execute(array('script' => $script, 'args' => array()));
    }

    public function evaluateScript(string $script)
    {
        if (0 !== strpos(trim($script), 'return ')) {
            $script = 'return ' . $script;
        }

        return $this->getWebDriverSession()->execute(array('script' => $script, 'args' => array()));
    }

    public function wait(int $timeout, string $condition)
    {
        $script = 'return (' . rtrim($condition, " \t\n\r;") . ');';
        $start = microtime(true);
        $end = $start + $timeout / 1000.0;

        do {
            $result = $this->getWebDriverSession()->execute(array('script' => $script, 'args' => array()));
            if ($result) {
              break;
            }
            usleep(10000);
        } while (microtime(true) < $end);

        return (bool) $result;
    }

    public function resizeWindow(int $width, int $height, ?string $name = null)
    {
        $window = $this->getWebDriverSession()->window($name ?: 'current');
        \assert($window instanceof Window);
        $window->postSize(
            array('width' => $width, 'height' => $height)
        );
    }

    public function submitForm(string $xpath)
    {
        $this->findElement($xpath)->submit();
    }

    public function maximizeWindow(?string $name = null)
    {
        $window = $this->getWebDriverSession()->window($name ?: 'current');
        \assert($window instanceof Window);
        $window->maximize();
    }

    /**
     * Returns Session ID of WebDriver or `null`, when session not started yet.
     *
     * @return string|null
     */
    public function getWebDriverSessionId()
    {
        return $this->wdSession !== null ? basename($this->wdSession->getUrl()) : null;
    }

    /**
     * @param string $xpath
     *
     * @return Element
     *
     * @throws DriverException
     */
    private function findElement(string $xpath): Element
    {
        return $this->getWebDriverSession()->element('xpath', $xpath);
    }

    /**
     * Selects a value in a radio button group
     *
     * @param Element $element An element referencing one of the radio buttons of the group
     * @param string  $value   The value to select
     *
     * @throws DriverException when the value cannot be found
     */
    private function selectRadioValue(Element $element, string $value): void
    {
        // short-circuit when we already have the right button of the group to avoid XPath queries
        if ($element->attribute('value') === $value) {
            $element->click();

            return;
        }

        $name = $element->attribute('name');

        if (!$name) {
            throw new DriverException(sprintf('The radio button does not have the value "%s"', $value));
        }

        $formId = $element->attribute('form');

        try {
            if (null !== $formId) {
                $xpath = <<<'XPATH'
//form[@id=%1$s]//input[@type="radio" and not(@form) and @name=%2$s and @value = %3$s]
|
//input[@type="radio" and @form=%1$s and @name=%2$s and @value = %3$s]
XPATH;

                $xpath = sprintf(
                    $xpath,
                    $this->xpathEscaper->escapeLiteral($formId),
                    $this->xpathEscaper->escapeLiteral($name),
                    $this->xpathEscaper->escapeLiteral($value)
                );
                $input = $this->getWebDriverSession()->element('xpath', $xpath);
            } else {
                $xpath = sprintf(
                    './ancestor::form//input[@type="radio" and not(@form) and @name=%s and @value = %s]',
                    $this->xpathEscaper->escapeLiteral($name),
                    $this->xpathEscaper->escapeLiteral($value)
                );
                $input = $element->element('xpath', $xpath);
            }
        } catch (NoSuchElement $e) {
            $message = sprintf('The radio group "%s" does not have an option "%s"', $name, $value);

            throw new DriverException($message, 0, $e);
        }

        $input->click();
    }

    /**
     * @throws DriverException
     */
    private function selectOptionOnElement(Element $element, string $value, bool $multiple = false): void
    {
        $escapedValue = $this->xpathEscaper->escapeLiteral($value);
        // The value of an option is the normalized version of its text when it has no value attribute
        $optionQuery = sprintf('.//option[@value = %s or (not(@value) and normalize-space(.) = %s)]', $escapedValue, $escapedValue);
        $option = $element->element('xpath', $optionQuery);

        if ($multiple || !$element->attribute('multiple')) {
            if (!$option->selected()) {
                $option->click();
            }

            return;
        }

        // Deselect all options before selecting the new one
        $this->deselectAllOptions($element);
        $option->click();
    }

    /**
     * Deselects all options of a multiple select
     *
     * Note: this implementation does not trigger a change event after deselecting the elements.
     *
     * @param Element $element
     *
     * @throws DriverException
     */
    private function deselectAllOptions(Element $element): void
    {
        $script = <<<JS
var node = {{ELEMENT}};
var i, l = node.options.length;
for (i = 0; i < l; i++) {
    node.options[i].selected = false;
}
JS;

        $this->executeJsOnElement($element, $script);
    }

    /**
     * Ensures the element is of the specified type
     *
     * @throws DriverException
     */
    private function ensureInputType(Element $element, string $xpath, string $type, string $action): void
    {
        if ('input' !== strtolower($element->name()) || $type !== strtolower($element->attribute('type') ?: '')) {
            $message = 'Impossible to %s the element with XPath "%s" as it is not a %s input';

            throw new DriverException(sprintf($message, $action, $xpath, $type));
        }
    }

    /**
     * @throws DriverException
     */
    private function trigger(string $xpath, string $event, string $options = '{}'): void
    {
        $script = 'syn.trigger({{ELEMENT}}, "' . $event . '", ' . $options . ')';
        $this->withSyn()->executeJsOnXpath($xpath, $script);
    }

    /**
     * Uploads a file to the Selenium instance.
     *
     * Note that uploading files is not part of the official WebDriver
     * specification, but it is supported by Selenium.
     *
     * @param string $path     The path to the file to upload.
     *
     * @return string          The remote path.
     *
     * @throws DriverException When PHP is compiled without zip support, or the file doesn't exist.
     * @throws UnknownError    When an unknown error occurred during file upload.
     * @throws \Exception      When a known error occurred during file upload.
     *
     * @see https://github.com/SeleniumHQ/selenium/blob/master/py/selenium/webdriver/remote/webelement.py#L533
     */
    private function uploadFile(string $path): string
    {
        if (!is_file($path)) {
          throw new DriverException('File does not exist locally and cannot be uploaded to the remote instance.');
        }

        if (!class_exists('ZipArchive')) {
          throw new DriverException('Could not compress file, PHP is compiled without zip support.');
        }

        // Selenium only accepts uploads that are compressed as a Zip archive.
        $tempFilename = tempnam('', 'WebDriverZip');

        if ($tempFilename === false) {
            throw new DriverException('Could not create a temporary file.');
        }

        $archive = new \ZipArchive();
        $result = $archive->open($tempFilename, \ZipArchive::OVERWRITE);
        if ($result !== true) {
          throw new DriverException('Zip archive could not be created. Error ' . $result);
        }
        $result = $archive->addFile($path, basename($path));
        if (!$result) {
          throw new DriverException('File could not be added to zip archive.');
        }
        $result = $archive->close();
        if (!$result) {
          throw new DriverException('Zip archive could not be closed.');
        }

        $fileContents = file_get_contents($tempFilename);
        \assert($fileContents !== false);

        try {
          $remotePath = $this->getWebDriverSession()->file(array('file' => base64_encode($fileContents)));

          // If no path is returned the file upload failed silently. In this
          // case it is possible Selenium was not used but another web driver
          // such as PhantomJS.
          // @todo Support other drivers when (if) they get remote file transfer
          // capability.
          if (empty($remotePath)) {
            throw new UnknownError();
          }
        } finally {
            unlink($tempFilename);
        }

        return $remotePath;
    }
}
