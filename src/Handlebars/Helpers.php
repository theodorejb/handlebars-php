<?php
/**
 * Helpers
 *
 * a collection of helper function. normally a function like
 * function ($sender, $name, $arguments) $arguments is unscaped arguments and
 * is a string, not array
 *
 * @category  Xamin
 * @package   Handlebars
 * @author    fzerorubigd <fzerorubigd@gmail.com>
 * @author    Behrooz Shabani <everplays@gmail.com>
 * @author    Mardix <https://github.com/mardix>
 * @copyright 2012 (c) ParsPooyesh Co
 * @copyright 2013 (c) Behrooz Shabani
 * @copyright 2014 (c) Mardix
 * @license   MIT
 * @link      http://voodoophp.org/docs/handlebars
 */

namespace Handlebars;

use DateTime;
use InvalidArgumentException;
use Traversable;
use LogicException;

class Helpers
{
    /**
     * @var array array of helpers
     */
    protected array $helpers = [];
    private array $tpl = [];
    protected array $builtinHelpers = [
        "if",
        "each",
        "with",
        "unless",
        "bindAttr",
        "upper",                // Put all chars in uppercase
        "lower",                // Put all chars in lowercase
        "capitalize",           // Capitalize just the first word
        "capitalize_words",     // Capitalize each words
        "reverse",              // Reverse a string
        "format_date",          // Format a date
        "inflect",              // Inflect the wording based on count ie. 1 album, 10 albums
        "default",              // If a variable is null, it will use the default instead
        "truncate",             // Truncate section
        "raw",                  // Return the source as is without converting
        "repeat",               // Repeat a section
        "define",               // Define a block to be used with "invoke"
        "invoke",               // Invoke a block that was defined with "define"
    ];

    /**
     * Create new helper container class
     */
    public function __construct(?array $helpers = null)
    {
        foreach($this->builtinHelpers as $helper) {
            $helperName = $this->underscoreToCamelCase($helper);
            $this->add($helper, [$this, "helper{$helperName}"]);
        }

        if ($helpers !== null) {
            foreach ($helpers as $name => $helper) {
                $this->add($name, $helper);
            }
        }
    }

    /**
     * Add a new helper to helpers
     */
    public function add(string $name, callable $helper): void
    {
        $this->helpers[$name] = $helper;
    }

    /**
     * Check if $name helper is available
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->helpers);
    }

    /**
     * Get a helper. __magic__ method :)
     * @throws \InvalidArgumentException if $name is not available
     */
    public function __get(string $name): callable
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException('Unknown helper :' . $name);
        }
        return $this->helpers[$name];
    }

    /**
     * Check if $name helper is available __magic__ method :)
     * @see Handlebras_Helpers::has
     */
    public function __isset(string $name)
    {
        return $this->has($name);
    }

    /**
     * Add a new helper to helpers __magic__ method :)
     */
    public function __set(string $name, callable $helper): void
    {
        $this->add($name, $helper);
    }

    /**
     * Unset a helper
     */
    public function __unset(string $name): void
    {
        unset($this->helpers[$name]);
    }

    /**
     * Check whether a given helper is present in the collection.
     * @throws \InvalidArgumentException if the requested helper is not present.
     */
    public function remove(string $name): void
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException('Unknown helper: ' . $name);
        }
        unset($this->helpers[$name]);
    }

    /**
     * Clear the helper collection.
     *
     * Removes all helpers from this collection.
     */
    public function clear(): void
    {
        $this->helpers = [];
    }

    /**
     * Check whether the helper collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->helpers);
    }

    /**
     * Create handler for the 'if' helper.
     *
     * {{#if condition}}
     *      Something here
     * {{else if condition}}
     *      something else if here
     * {{else if condition}}
     *      something else if here
     * {{else}}
     *      something else here
     * {{/if}}
     */
    public function helperIf(Template $template, Context $context, string $args, string $source): string
    {
        $tpl = $template->getEngine()->loadTemplate('{{#if ' . $args . '}}' . $source . '{{/if}}');
        $tree = $tpl->getTree();
        $tmp = $context->get($args);
        if ($tmp) {
            $token = 'else';
            foreach ($tree[0]['nodes'] as $node) {
                $name = trim($node['name'] ?? '');
                if ($name && substr($name, 0, 7) == 'else if') {
                    $token = $node['name'];
                    break;
                }
            }
            $template->setStopToken($token);
            $buffer = $template->render($context);
            $template->setStopToken(false);
            $template->discard();
            return $buffer;
        } else {
            foreach ($tree[0]['nodes'] as $key => $node) {
                $name = trim($node['name'] ?? '');
                if ($name && substr($name, 0, 7) == 'else if') {
                    $template->setStopToken($node['name']);
                    $template->discard();
                    $template->setStopToken(false);
                    $args = $this->parseArgs($context, substr($name, 7));
                    $token = 'else';
                    $remains = array_slice($tree[0]['nodes'], $key + 1);
                    foreach ($remains as $remain) {
                        $name = trim($remain['name'] ?? '');
                        if ($name && substr($name, 0, 7) == 'else if') {
                            $token = $remain['name'];
                            break;
                        }
                    }
                    if (isset($args[0]) && $args[0]) {
                        $template->setStopToken($token);
                        $buffer = $template->render($context);
                        $template->setStopToken(false);
                        $template->discard();
                        return $buffer;
                    } else if ($token != 'else') {
                        continue;
                    } else {
                        return $this->renderElse($template, $context);
                    }
                }
            }
            return $this->renderElse($template, $context);
        }
    }

    /**
     * Create handler for the 'each' helper.
     * example {{#each people}} {{name}} {{/each}}
     * example with slice: {{#each people[0:10]}} {{name}} {{/each}}
     * example with else
     *  {{#each Array}}
     *        {{.}}
     *  {{else}}
     *      Nothing found
     *  {{/each}}
     */
    public function helperEach(Template $template, Context $context, string $args, string $source): string
    {
        [$keyname, $slice_start, $slice_end] = $this->extractSlice($args);
        $tmp = $context->get($keyname);

        if (is_array($tmp) || $tmp instanceof Traversable) {
            $tmp = array_slice($tmp, $slice_start ?? 0, $slice_end, true);
            $buffer = '';
            $islist = array_values($tmp) === $tmp;

            if (is_array($tmp) && ! count($tmp)) {
                return $this->renderElse($template, $context);
            } else {

                $itemCount = -1;
                if ($islist) {
                    $itemCount = count($tmp);
                }

                foreach ($tmp as $key => $var) {
                    $tpl = clone $template;
                    if ($islist) {
                        $context->pushIndex($key);

                        // If data variables are enabled, push the data related to this #each context
                        if ($template->getEngine()->isDataVariablesEnabled()) {
                            $context->pushData([
                                Context::DATA_KEY => $key,
                                Context::DATA_INDEX => $key,
                                Context::DATA_LAST => $key == ($itemCount - 1),
                                Context::DATA_FIRST => $key == 0,
                            ]);
                        }
                    } else {
                        $context->pushKey($key);

                        // If data variables are enabled, push the data related to this #each context
                        if ($template->getEngine()->isDataVariablesEnabled()) {
                            $context->pushData([
                                Context::DATA_KEY => $key,
                            ]);
                        }
                    }
                    $context->push($var);
                    $tpl->setStopToken('else');
                    $buffer .= $tpl->render($context);
                    $context->pop();
                    if ($islist) {
                        $context->popIndex();
                    } else {
                        $context->popKey();
                    }

                    if ($template->getEngine()->isDataVariablesEnabled()) {
                        $context->popData();
                    }
                }
                return $buffer;
            }
        } else {
            return $this->renderElse($template, $context);
        }
    }

    /**
     * Applying the DRY principle here.
     * This method help us render {{else}} portion of a block
     */
    private function renderElse(Template $template, Context $context): string
    {
        $template->setStopToken('else');
        $template->discard();
        $template->setStopToken(false);
        return $template->render($context);
    }

    /**
     * Create handler for the 'unless' helper.
     * {{#unless condition}}
     *      Something here
     * {{else}}
     *      something else here
     * {{/unless}}
     */
    public function helperUnless(Template $template, Context $context, string $args, string $source): string
    {
        $tmp = $context->get($args);
        if (!$tmp) {
            $template->setStopToken('else');
            $buffer = $template->render($context);
            $template->setStopToken(false);
            $template->discard();
            return $buffer;
        } else {
            return $this->renderElse($template, $context);
        }
    }

    /**
     * Create handler for the 'with' helper.
     */
    public function helperWith(Template $template, Context $context, string $args, string $source): string
    {
        $tmp = $context->get($args);
        $context->push($tmp);
        $buffer = $template->render($context);
        $context->pop();

        return $buffer;
    }

    /**
     * Create handler for the 'bindAttr' helper.
     * Needed for compatibility with PHP 5.2 since it doesn't support anonymous
     * functions.
     */
    public function helperBindAttr(Template $template, Context $context, string $args, string $source): string
    {
        return $args;
    }

    /**
     * To uppercase string
     *
     * {{#upper data}}
     */
    public function helperUpper(Template $template, Context $context, string $args, string $source): string
    {
        return strtoupper($context->get($args));
    }

    /**
     * To lowercase string
     *
     * {{#lower data}}
     */
    public function helperLower(Template $template, Context $context, string $args, string $source): string
    {
        return strtolower($context->get($args));
    }

    /**
     * to capitalize first letter
     *
     * {{#capitalize}}
     */
    public function helperCapitalize(Template $template, Context $context, string $args, string $source): string
    {
        return ucfirst($context->get($args));
    }

    /**
     * To capitalize first letter in each word
     *
     * {{#capitalize_words data}}
     */
    public function helperCapitalizeWords(Template $template, Context $context, string $args, string $source): string
    {
        return ucwords($context->get($args));
    }

    /**
     * To reverse a string
     *
     * {{#reverse data}}
     */
    public function helperReverse(Template $template, Context $context, string $args, string $source): string
    {
        return strrev($context->get($args));
    }

    /**
     * Format a date
     *
     * {{#format_date date 'Y-m-d @h:i:s'}}
     */
    public function helperFormatDate(Template $template, Context $context, string $args, string $source): string
    {
        preg_match("/(.*?)\s+(?:(?:\"|\')(.*?)(?:\"|\'))/", $args, $m);
        $keyname = $m[1];
        $format = $m[2];

        $date = $context->get($keyname);
        if ($format) {
            $dt = new DateTime;
            if (is_numeric($date)) {
                $dt = (new DateTime)->setTimestamp($date);
            } else {
                $dt = new DateTime($date);
            }
            return $dt->format($format);
        } else {
            return $date;
        }
    }

    /**
     * {{inflect count 'album' 'albums'}}
     * {{inflect count '%d album' '%d albums'}}
     */
    public function helperInflect(Template $template, Context $context, string $args, string $source): string
    {
        preg_match("/(.*?)\s+(?:(?:\"|\')(.*?)(?:\"|\'))\s+(?:(?:\"|\')(.*?)(?:\"|\'))/", $args, $m);
        $keyname = $m[1];
        $singular = $m[2];
        $plurial = $m[3];
        $value = $context->get($keyname);
        $inflect = ($value <= 1) ? $singular : $plurial;
        return sprintf($inflect, $value);
    }

    /**
      * Provide a default fallback
      *
      * {{default title "No title available"}}
      */
    public function helperDefault(Template $template, Context $context, string $args, string $source): string
    {
        preg_match("/(.*?)\s+(?:(?:\"|\')(.*?)(?:\"|\'))/", trim($args), $m);
        $keyname = $m[1];
        $default = $m[2];
        $value = $context->get($keyname);
        return ($value) ?: $default;
    }

    /**
      * Truncate a string to a length, and append and ellipsis if provided
      * {{#truncate content 5 "..."}}
      */
    public function helperTruncate(Template $template, Context $context, string $args, string $source): string
    {
        preg_match("/(.*?)\s+(.*?)\s+(?:(?:\"|\')(.*?)(?:\"|\'))/", trim($args), $m);
        $keyname = $m[1];
        $limit = $m[2];
        $ellipsis = $m[3];
        $value = substr($context->get($keyname), 0, $limit);
        if ($ellipsis && strlen($context->get($keyname)) > $limit) {
            $value .= $ellipsis;
        }
        return $value;
    }

    /**
     * Return the data source as is
     *
     * {{#raw}} {{/raw}}
     */
    public function helperRaw(Template $template, Context $context, string $args, string $source): string
    {
        return $source;
    }

    /**
     * Repeat section $x times.
     *
     * {{#repeat 10}}
     *      This section will be repeated 10 times
     * {{/repeat}}
     */
    public function helperRepeat(Template $template, Context $context, string $args, string $source): string
    {
        $buffer = $template->render($context);
        return str_repeat($buffer, intval($args));
    }

    /**
     * Define a section to be used later by using 'invoke'
     *
     * --> Define a section: hello
     * {{#define hello}}
     *      Hello World!
     *
     *      How is everything?
     * {{/define}}
     *
     * --> This is how it is called
     * {{#invoke hello}}
     */
    public function helperDefine(Template $template, Context $context, string $args, string $source): string
    {
        $this->tpl["DEFINE"][$args] = clone($template);
        return '';
    }

    /**
     * Invoke a section that was created using 'define'
     *
     * --> Define a section: hello
     * {{#define hello}}
     *      Hello World!
     *
     *      How is everything?
     * {{/define}}
     *
     * --> This is how it is called
     * {{#invoke hello}}
     */
    public function helperInvoke(Template $template, Context $context, string $args, string $source): string
    {
        if (! isset($this->tpl["DEFINE"][$args])) {
            throw new LogicException("Can't INVOKE '{$args}'. '{$args}' was not DEFINE ");
        }
        return $this->tpl["DEFINE"][$args]->render($context);
    }

    /**
     * Change underscore helper name to CamelCase
     */
    private function underscoreToCamelCase($string): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
    }

    /**
     * slice
     * Allow to split the data that will be returned
     * #loop[start:end] => starts at start through end -1
     * #loop[start:] = Starts at start though the rest of the array
     * #loop[:end] = Starts at the beginning through end -1
     * #loop[:] = A copy of the whole array
     *
     * #loop[-1]
     * #loop[-2:] = Last two items
     * #loop[:-2] = Everything except last two items

     * @return array{string, int|null, int|null}
     */
    private function extractSlice(string $string): array
    {
        preg_match("/^([\w\._\-]+)(?:\[([\-0-9]*?:[\-0-9]*?)\])?/i", $string, $m);
        $slice_start = $slice_end = null;
        if (isset($m[2])) {
            [$slice_start, $slice_end] = explode(":", $m[2]);
            $slice_start = (int) $slice_start;
            $slice_end = $slice_end ? (int) $slice_end : null;
        }
        return [$m[1], $slice_start, $slice_end];
    }

    /**
     * Parse a variable from current args
     */
    private function parseArgs(Context $context, string $args): array
    {
        $args = preg_replace('/\s+/', ' ', trim($args));
        $eles = explode(' ', $args);
        foreach ($eles as $key => $ele) {
            if (in_array(substr($ele, 0, 1), ['\'', '"'])) {
                $val = trim($ele, '\'"');
            } else if (is_numeric($ele)) {
                $val = $ele;
            } else {
                $val = $context->get($ele);
            }
            $eles[$key] = $val;
        }

        return $eles;
    }
}
