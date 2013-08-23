<?php
namespace Codeception\Module;

use Codeception\Exception\ElementNotFound;
use Codeception\Util\Locator;
use Codeception\Util\RemoteInterface;
use Codeception\Util\WebInterface;
use Symfony\Component\DomCrawler\Crawler;
use Codeception\PHPUnit\Constraint\WebDriver as WebDriverConstraint;
use Codeception\PHPUnit\Constraint\Page as PageConstraint;

class WebDriver extends \Codeception\Module implements WebInterface, RemoteInterface {

    protected $requiredFields = array('browser', 'url');
    protected $config = array(
        'host' => '127.0.0.1',
        'port' => '4444',
        'restart' => false,
        'wait' => 0,
        'capabilities' => array());
    
    protected $wd_host;
    protected $capabilities;

    /**
     * @var \WebDriver
     */
    public $webDriver;

    public function _initialize()
    {
        $this->wd_host =  sprintf('http://%s:%s/wd/hub', $this->config['host'], $this->config['port']);
        $this->capabilities = $this->config['capabilities'];
        $this->capabilities[\WebDriverCapabilityType::BROWSER_NAME] = $this->config['browser'];
        $this->webDriver = new \WebDriver($this->wd_host, $this->capabilities);
        $this->webDriver->manage()->timeouts()->implicitlyWait($this->config['wait']);
    }

    public function _before(\Codeception\TestCase $test)
    {
        $this->webDriver->manage()->deleteAllCookies();
    }

    public function _after(\Codeception\TestCase $test)
    {
        try {
            $this->webDriver->switchTo()->alert()->dismiss(); // close alert if exists
        } catch (\NoAlertOpenWebDriverError $e) {}

        $this->config['restart']
            ? $this->webDriver->close()
            : $this->amOnPage('/');
    }

    public function _afterSuite()
    {
        $this->webDriver->quit();
    }

    public function amOnSubdomain($subdomain)
    {
        $url = $this->config['url'];
        $url = preg_replace('~(https?:\/\/)(.*\.)(.*\.)~', "$1$3", $url); // removing current subdomain
        $url = preg_replace('~(https?:\/\/)(.*)~', "$1$subdomain.$2", $url); // inserting new
        $this->_reconfigure(array('url' => $url));
    }

    public function _getUrl()
    {
        if (!isset($this->config['url']))
            throw new \Codeception\Exception\ModuleConfig(__CLASS__, "Module connection failure. The URL for client can't bre retrieved");
        return $this->config['url'];
    }

    public function _getCurrentUri()
    {
        $url = $this->webDriver->getCurrentURL();
        $parts = parse_url($url);
        if (!$parts) $this->fail("URL couldn't be parsed");
        $uri = "";
        if (isset($parts['path'])) $uri .= $parts['path'];
        if (isset($parts['query'])) $uri .= "?".$parts['query'];
        if (isset($parts['fragment'])) $uri .= "#".$parts['fragment'];
        return $uri;
    }

    public function _saveScreenshot($filename)
    {
        $this->webDriver->takeScreenshot($filename);
    }

    /**
     * Resize current window
     *
     * Example:
     * ``` php
     * <?php
     * $I->resizeWindow(800, 600);
     *
     * ```
     *
     * @param int    $width
     * @param int    $height
     */
    public function resizeWindow($width, $height) {
        $this->webDriver->manage()->window()->setSize(new \WebDriverDimension($width, $height));
    }


    public function _getResponseCode()
    {
        return "";
    }

    public function _sendRequest($url)
    {
        // TODO: Implement _sendRequest() method.
    }

    public function seeCookie($cookie)
    {
        $cookies = $this->webDriver->manage()->getCookies();
        $cookies = array_map(function($c) { return $c['name']; }, $cookies);
        $this->assertContains($cookie, $cookies);
    }

    public function dontSeeCookie($cookie)
    {
        $this->assertNull($this->webDriver->manage()->getCookieNamed($cookie));
    }

    public function setCookie($cookie, $value)
    {
        $this->webDriver->manage()->addCookie(array('name' => $cookie, 'value' => $value));
    }

    public function resetCookie($cookie)
    {
        $this->webDriver->manage()->deleteCookieNamed($cookie);
    }

    public function grabCookie($cookie)
    {
        $value = $this->webDriver->manage()->getCookieNamed($cookie);
        if (is_array($value)) return $value['value'];
    }

    public function amOnPage($page)
    {
        $host = rtrim($this->config['url'], '/');
        $page = ltrim($page, '/');
        $this->webDriver->get($host . '/' . $page);
    }

    public function see($text, $selector = null)
    {
        if (!$selector) return $this->assertPageContains($text);
        $nodes = $this->match($this->webDriver, $selector);
        $this->assertNodesContain($text, $nodes);
    }

    public function dontSee($text, $selector = null)
    {
        if (!$selector) return $this->assertPageNotContains($text);
        $nodes = $this->match($this->webDriver, $selector);
        $this->assertNodesNotContain($text, $nodes);
    }

    protected function match($page, $selector)
    {
        $nodes = array();
        if (Locator::isCSS($selector)) $nodes = $page->findElements(\WebDriverBy::cssSelector($selector));
        if (!empty($nodes)) return $nodes;
        if (Locator::isXPath($selector)) $nodes = $page->findElements(\WebDriverBy::xpath($selector));
        return $nodes;
    }

    public function click($link, $context = null)
    {
        $page = $this->webDriver;
        if ($context) {
            $nodes = $this->match($this->webDriver, $context);
            if (empty($nodes)) throw new ElementNotFound($context,'CSS or XPath');
            $page = reset($nodes);
        }
        $el = $this->findClickable($page, $link);
        if (!$el) {
            $els = $this->match($page, $link);
            $el = reset($els);
        }
        if (!$el) throw new ElementNotFound($link, 'Link or Button or CSS or XPath');
        $el->click();
    }

    /**
     * @param $page
     * @param $link
     * @return \WebDriverElement
     */
    protected function findClickable($page, $link)
    {
        $locator = Crawler::xpathLiteral(trim($link));

        // narrow
        $xpath = Locator::combine(
            ".//a[normalize-space(.)=$locator]",
            ".//button[normalize-space(.)=$locator]",
            ".//a/img[normalize-space(@alt)=$locator]/ancestor::a",
            ".//input[./@type = 'submit' or ./@type = 'image' or ./@type = 'button'][normalize-space(@value)=$locator]"
        );

        $els = $page->findElements(\WebDriverBy::xpath($xpath));
        if (count($els)) return reset($els);

        // wide
        $xpath = Locator::combine(
            ".//a[./@href][((contains(normalize-space(string(.)), $locator)) or .//img[contains(./@alt, $locator)])]",
            ".//input[./@type = 'submit' or ./@type = 'image' or ./@type = 'button'][contains(./@value, $locator)]",
            ".//input[./@type = 'image'][contains(./@alt, $locator)]",
            ".//button[contains(normalize-space(string(.)), $locator)]"
        );

        $els = $page->findElements(\WebDriverBy::xpath($xpath));
        if (count($els)) return reset($els);
        return null;
    }

    /**
     * @param $selector
     * @return \WebDriverElement
     * @throws \Codeception\Exception\ElementNotFound
     */
    protected function findField($selector)
    {
        $locator = Crawler::xpathLiteral(trim($selector));

        $xpath = Locator::combine(
            ".//*[self::input | self::textarea | self::select][not(./@type = 'submit' or ./@type = 'image' or ./@type = 'hidden')][(((./@name = $locator) or ./@id = //label[contains(normalize-space(string(.)), $locator)]/@for) or ./@placeholder = $locator)]",
            ".//label[contains(normalize-space(string(.)), $locator)]//.//*[self::input | self::textarea | self::select][not(./@type = 'submit' or ./@type = 'image' or ./@type = 'hidden')]"
        );

        $els = $this->webDriver->findElements(\WebDriverBy::xpath($xpath));
        if (count($els)) return reset($els);

        $els = $this->match($this->webDriver, $selector);
        if (count($els)) return reset($els);

        throw new ElementNotFound($selector, "Field by name, label, CSS or XPath");
    }


    public function seeLink($text, $url = null)
    {
        $nodes = $this->webDriver->findElements(\WebDriverBy::partialLinkText($text));
        if (!$url) return $this->assertNodesContain($text, $nodes);
        $nodes = array_filter($nodes, function(\WebDriverElement $e) use ($url) {
                $parts = parse_url($url);
                if (!$parts) $this->fail("Link URL of '$url' couldn't be parsed");
                $uri = "";
                if (isset($parts['path'])) $uri .= $parts['path'];
                if (isset($parts['query'])) $uri .= "?".$parts['query'];
                if (isset($parts['fragment'])) $uri .= "#".$parts['fragment'];
                return $uri == trim($url);
        });
        $this->assertNodesContain($text, $nodes);
    }

    public function dontSeeLink($text, $url = null)
    {
        $nodes = $this->webDriver->findElements(\WebDriverBy::partialLinkText($text));
        if (!$url) return $this->assertNodesNotContain($text, $nodes);
        $nodes = array_filter($nodes, function(\WebDriverElement $e) use ($url) {
            return trim($e->getAttribute('href')) == trim($url);
        });
        $this->assertNodesNotContain($text, $nodes);
    }

    public function seeInCurrentUrl($uri)
    {
        $this->assertContains($uri, $this->_getCurrentUri());
    }

    public function seeCurrentUrlEquals($uri)
    {
        $this->assertEquals($uri, $this->_getCurrentUri());
    }

    public function seeCurrentUrlMatches($uri)
    {
        \PHPUnit_Framework_Assert::assertRegExp($uri, $this->_getCurrentUri());
    }

    public function dontSeeInCurrentUrl($uri)
    {
        $this->assertNotContains($uri, $this->_getCurrentUri());
    }

    public function dontSeeCurrentUrlEquals($uri)
    {
        $this->assertNotEquals($uri, $this->_getCurrentUri());
    }

    public function dontSeeCurrentUrlMatches($uri)
    {
        \PHPUnit_Framework_Assert::assertNotRegExp($uri, $this->_getCurrentUri());
    }

    public function grabFromCurrentUrl($uri = null)
    {
        if (!$uri) return $this->_getCurrentUri();
        $matches = array();
        $res = preg_match($uri, $this->_getCurrentUri(), $matches);
        if (!$res) $this->fail("Couldn't match $uri in ".$this->_getCurrentUri());
        if (!isset($matches[1])) $this->fail("Nothing to grab. A regex parameter required. Ex: '/user/(\\d+)'");
        return $matches[1];
    }

    public function seeCheckboxIsChecked($checkbox)
    {
        $this->assertTrue($this->findField($checkbox)->isSelected());
    }

    public function dontSeeCheckboxIsChecked($checkbox)
    {
        $this->assertFalse($this->findField($checkbox)->isSelected());
    }

    public function seeInField($field, $value)
    {
        $el = $this->findField($field);
        if (!$el) throw new ElementNotFound($field, "Field by name, label, CSS or XPath");
        $el_value = $el->getTagName() == 'textarea'
            ? $el->getText()
            : $el->getAttribute('value');
        $this->assertEquals($value, $el_value);
    }

    public function dontSeeInField($field, $value)
    {
        $el = $this->findField($field);
        $el_value = $el->getTagName() == 'textarea'
            ? $el->getText()
            : $el->getAttribute('value');
        $this->assertNotEquals($value, $el_value);
    }

    public function selectOption($select, $option)
    {
        $el = $this->findField($select);
        $select = new \WebDriverSelect($el);
        if ($select->isMultiple()) $select->deselectAll();
        if (!is_array($option)) $option = array($option);
        foreach ($option as $opt) {
            $select->selectByVisibleText($opt);
        }
    }

    public function checkOption($option)
    {
        $field = $this->findField($option);
        if ($field->isSelected()) return;
        $field->click();
    }

    public function uncheckOption($option)
    {
        $field = $this->findField($option);
        if (!$field->isSelected()) return;
        $field->click();

    }

    public function fillField($field, $value)
    {
        $el = $this->findField($field);
        $el->clear();
        $el->sendKeys($value);
    }

    public function attachFile($field, $filename)
    {
        $el = $this->findField($field);
        $el->sendKeys(\Codeception\Configuration::dataDir().$filename);
    }

    public function grabTextFrom($cssOrXPathOrRegex)
    {
        $els = $this->match($this->webDriver, $cssOrXPathOrRegex);
        if (count($els)) return $els[0]->getText();
        if (@preg_match($cssOrXPathOrRegex, $this->webDriver->getPageSource(), $matches)) return $matches[1];
        throw new ElementNotFound($cssOrXPathOrRegex, 'CSS or XPath or Regex');
    }

    public function grabValueFrom($field)
    {
        $el = $this->findField($field);
        if ($el->getTagName() == 'textarea') return $el->getText();
        if ($el->getTagName() == 'input') return $el->getAttribute('value');
        if ($el->getTagName() != 'select') return null;
        $select = new \WebDriverSelect($el);
        return $select->getFirstSelectedOption()->getAttribute('value');
    }

    public function seeElement($selector)
    {
        $this->assertNotEmpty($this->match($this->webDriver, $selector));
    }

    public function dontSeeElement($selector)
    {
        $this->assertEmpty($this->match($this->webDriver, $selector));
    }

    public function seeOptionIsSelected($selector, $optionText)
    {
        $el = $this->findField($selector);
        $select = new \WebDriverSelect($el);
        $this->assertNodesContain($optionText, $select->getAllSelectedOptions());
    }

    public function dontSeeOptionIsSelected($selector, $optionText)
    {
        $el = $this->findField($selector);
        $select = new \WebDriverSelect($el);
        $this->assertNodesNotContain($optionText, $select->getAllSelectedOptions());
    }

    public function seeInTitle($title)
    {
        $this->assertContains($title, $this->webDriver->getTitle());
    }

    public function dontSeeInTitle($title)
    {
        $this->assertNotContains($title, $this->webDriver->getTitle());
    }

    public function acceptPopup()
    {
        $this->webDriver->switchTo()->alert()->accept();
    }

    public function cancelPopup()
    {
        $this->webDriver->switchTo()->alert()->dismiss();
    }

    public function seeInPopup($text)
    {
        $this->assertContains($text, $this->webDriver->switchTo()->alert()->getText());
    }

    public function typeInPopup($keys)
    {
        $this->webDriver->switchTo()->alert()->sendKeys($keys);
    }

    /**
     * Reloads current page
     */
    public function reloadPage() {
        $this->webDriver->navigate()->refresh();
    }

    /**
     * Moves back in history
     */
    public function moveBack() {
        $this->webDriver->navigate()->back();
        $this->debug($this->_getCurrentUri());
    }

    /**
     * Moves forward in history
     */
    public function moveForward() {
        $this->webDriver->navigate()->forward();
        $this->debug($this->_getCurrentUri());
    }


    /**
     * Low-level API method.
     * If Codeception commands are not enough, use Selenium WebDriver methods directly
     *
     * ``` php
     * $I->executeInSelenium(function(\WebDriver $webdriver) {
     *   $webdriver->get('http://google.com');
     * });
     * ```
     *
     * Use [WebDriver Session API](https://github.com/facebook/php-webdriver)
     * Not recommended this command too be used on regular basis.
     * If Codeception lacks important Selenium methods implement then and submit patches.
     *
     * @param callable $function
     */
    public function executeInSelenium(\Closure $function)
    {
        $function($this->webDriver);
    }

    /**
     * Switch to another window identified by its name.
     *
     * The window can only be identified by its name. If the $name parameter is blank it will switch to the parent window.
     *
     * Example:
     * ``` html
     * <input type="button" value="Open window" onclick="window.open('http://example.com', 'another_window')">
     * ```
     *
     * ``` php
     * <?php
     * $I->click("Open window");
     * # switch to another window
     * $I->switchToWindow("another_window");
     * # switch to parent window
     * $I->switchToWindow();
     * ?>
     * ```
     *
     * If the window has no name, the only way to access it is via the `executeInSelenium()` method like so:
     *
     * ```
     * <?php
     * $I->executeInSelenium(function (\Webdriver $webdriver) {
     * $handles=$webDriver->getWindowHandles();
     * $last_window = end($handles);
     * $webDriver->switchTo()->window($name);
     * });
     * ?>
     * ```
     *
     * @param string|null $name
     */
    public function switchToWindow($name = null) {
        $this->webDriver->switchTo()->window($name);

    }

    /**
     * Switch to another frame
     *
     * Example:
     * ``` html
     * <iframe name="another_frame" src="http://example.com">
     *
     * ```
     *
     * ``` php
     * <?php
     * # switch to iframe
     * $I->switchToIFrame("another_frame");
     * # switch to parent page
     * $I->switchToIFrame();
     *
     * ```
     *
     * @param string|null $name
     */
    public function switchToIFrame($name = null) {
        $this->webDriver->switchTo()->frame($name);
    }

    protected function assertNodesContain($text, $nodes)
    {
        $this->assertThat($nodes, new WebDriverConstraint($text, $this->_getCurrentUri()), $text);
    }

    protected function assertNodesNotContain($text, $nodes)
    {
        $this->assertThatItsNot($nodes, new WebDriverConstraint($text, $this->_getCurrentUri()), $text);
    }

    protected function assertPageContains($needle, $message = '')
    {
        $this->assertThat($this->webDriver->getPageSource(), new PageConstraint($needle, $this->_getCurrentUri()),$message);
    }

    protected function assertPageNotContains($needle, $message = '')
    {
        $this->assertThatItsNot($this->webDriver->getPageSource(), new PageConstraint($needle, $this->_getCurrentUri()),$message);
    }

}