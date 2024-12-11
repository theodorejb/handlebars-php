<?php
/**
 * Handlebars
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

use InvalidArgumentException;

class Handlebars
{
    const OPTION_ENABLE_DATA_VARIABLES = 'enableDataVariables';

    private Tokenizer $tokenizer;

    private Parser $parser;

    private Helpers $helpers;

    /**
     * @var bool Enable @data variables
     */
    private bool $enableDataVariables = false;

    /**
     * Handlebars engine constructor
     * $options array can contain :
     * helpers        => Helpers object
     * enableDataVariables => boolean. Enables @data variables (default: false)
     *
     * @param array $options array of options to set
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $options = [])
    {
        if (isset($options['helpers'])) {
            $this->setHelpers($options['helpers']);
        }

        if (isset($options[self::OPTION_ENABLE_DATA_VARIABLES])) {
            if (!is_bool($options[self::OPTION_ENABLE_DATA_VARIABLES])) {
                throw new InvalidArgumentException(
                    'Handlebars Constructor "' . self::OPTION_ENABLE_DATA_VARIABLES . '" option must be a boolean'
                );
            }
            $this->enableDataVariables = $options[self::OPTION_ENABLE_DATA_VARIABLES];
        }
    }

    /**
     * Shortcut 'render' invocation.
     *
     * Equivalent to calling `$handlebars->loadTemplate($template)->render($data);`
     *
     * @param mixed  $data     data to use as context
     */
    public function render(string $template, $data): string
    {
        return $this->loadTemplate($template)->render($data);
    }

    /**
     * Set helpers for current engine
     */
    public function setHelpers(Helpers $helpers): void
    {
        $this->helpers = $helpers;
    }

    /**
     * Get helpers, or create new one if there is no helper
     */
    public function getHelpers(): Helpers
    {
        if (!isset($this->helpers)) {
            $this->helpers = new Helpers();
        }
        return $this->helpers;
    }

    /**
     * Add a new helper.
     */
    public function addHelper(string $name, callable $helper): void
    {
        $this->getHelpers()->add($name, $helper);
    }

    /**
     * Get a helper by name.
     */
    public function getHelper(string $name): callable
    {
        return $this->getHelpers()->__get($name);
    }

    /**
     * Check whether this instance has a helper.
     */
    public function hasHelper(string $name): bool
    {
        return $this->getHelpers()->has($name);
    }

    /**
     * Remove a helper by name.
     */
    public function removeHelper(string $name): void
    {
        $this->getHelpers()->remove($name);
    }

    /**
     * Get the current Handlebars Tokenizer instance.
     *
     * If no Tokenizer instance has been explicitly specified, this method will
     * instantiate and return a new one.
     */
    public function getTokenizer(): Tokenizer
    {
        if (!isset($this->tokenizer)) {
            $this->tokenizer = new Tokenizer();
        }

        return $this->tokenizer;
    }

    /**
     * Get the current Handlebars Parser instance.
     *
     * If no Parser instance has been explicitly specified, this method will
     * instantiate and return a new one.
     */
    public function getParser(): Parser
    {
        if (!isset($this->parser)) {
            $this->parser = new Parser();
        }
        return $this->parser;
    }

    /**
     * Determines if the @data variables are enabled.
     */
    public function isDataVariablesEnabled(): bool
    {
        return $this->enableDataVariables;
    }

    /**
     * Load a template by name with current template loader
     */
    public function loadTemplate(string $source): Template
    {
        $tree = $this->tokenize($source);
        return new Template($this, $tree, $source);
    }

    /**
     * Try to tokenize source, or get them from cache if available
     */
    private function tokenize(string $source): array
    {
        $tokens = $this->getTokenizer()->scan($source);
        return $this->getParser()->parse($tokens);
    }
}
