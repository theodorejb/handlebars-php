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

use Handlebars\Loader\StringLoader;
use InvalidArgumentException;

class Handlebars
{
    private static $instance = null;
    const VERSION = '2.2';

    const OPTION_ENABLE_DATA_VARIABLES = 'enableDataVariables';

    /**
     * factory method
     *
     * @param array $options see __construct's options parameter
     */
    public static function factory(array $options = []): Handlebars
    {
        if (! self::$instance) {
            self::$instance = new self($options);
        }

        return self::$instance;
    }

    private Tokenizer $tokenizer;

    private Parser $parser;

    private Helpers $helpers;

    private Loader $loader;

    private Loader $partialsLoader;

    /**
     * @var callable escape function to use
     */
    private $escape = 'htmlspecialchars';

    /**
     * @var array parameters to pass to escape function
     */
    private array $escapeArgs = [ENT_COMPAT, 'UTF-8'];

    private array $aliases = [];

    /**
     * @var bool Enable @data variables
     */
    private bool $enableDataVariables = false;

    /**
     * Handlebars engine constructor
     * $options array can contain :
     * helpers        => Helpers object
     * escape         => a callable function to escape values
     * escapeArgs     => array to pass as extra parameter to escape function
     * loader         => Loader object
     * partials_loader => Loader object
     * enableDataVariables => boolean. Enables @data variables (default: false)
     *
     * @param array $options array of options to set
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(Array $options = [])
    {
        if (isset($options['helpers'])) {
            $this->setHelpers($options['helpers']);
        }

        if (isset($options['loader'])) {
            $this->setLoader($options['loader']);
        }

        if (isset($options['partials_loader'])) {
            $this->setPartialsLoader($options['partials_loader']);
        }

        if (isset($options['escape'])) {
            if (!is_callable($options['escape'])) {
                throw new InvalidArgumentException(
                    'Handlebars Constructor "escape" option must be callable'
                );
            }
            $this->escape = $options['escape'];
        }

        if (isset($options['escapeArgs'])) {
            if (!is_array($options['escapeArgs'])) {
                $options['escapeArgs'] = array($options['escapeArgs']);
            }
            $this->escapeArgs = $options['escapeArgs'];
        }

        if (isset($options['partials_alias'])
            && is_array($options['partials_alias'])
        ) {
            $this->aliases = $options['partials_alias'];
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
     * To invoke when this object is called as a function
     *
     * @param string $template template name
     * @param mixed  $data     data to use as context
     */
    public function __invoke(string $template, $data): string
    {
        return $this->render($template, $data);
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
     * Set current loader
     */
    public function setLoader(Loader $loader): void
    {
        $this->loader = $loader;
    }

    /**
     * Get current loader
     */
    public function getLoader(): Loader
    {
        if (! isset($this->loader)) {
            $this->loader = new StringLoader();
        }
        return $this->loader;
    }

    /**
     * Set current partials loader
     */
    public function setPartialsLoader(Loader $loader): void
    {
        $this->partialsLoader = $loader;
    }

    /**
     * Get current partials loader
     */
    public function getPartialsLoader(): Loader
    {
        if (!isset($this->partialsLoader)) {
            $this->partialsLoader = new StringLoader();
        }
        return $this->partialsLoader;
    }

    /**
     * Get current escape function
     */
    public function getEscape(): callable
    {
        return $this->escape;
    }

    /**
     * Set current escape function
     * @throws \InvalidArgumentException
     */
    public function setEscape(callable $escape): void
    {
        if (!is_callable($escape)) {
            throw new InvalidArgumentException(
                'Escape function must be a callable'
            );
        }
        $this->escape = $escape;
    }

    /**
     * Get current escape function
     */
    public function getEscapeArgs(): array
    {
        return $this->escapeArgs;
    }

    /**
     * Set current escape function
     *
     * @param array $escapeArgs arguments to pass as extra arg to function
     */
    public function setEscapeArgs(array $escapeArgs): void
    {
        $this->escapeArgs = $escapeArgs;
    }

    /**
     * Set the Handlebars Tokenizer instance.
     */
    public function setTokenizer(Tokenizer $tokenizer): void
    {
        $this->tokenizer = $tokenizer;
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
     * Set the Handlebars Parser instance.
     */
    public function setParser(Parser $parser): void
    {
        $this->parser = $parser;
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
    public function loadTemplate(string $name): Template
    {
        $source = $this->getLoader()->load($name);
        $tree = $this->tokenize($source);
        return new Template($this, $tree, $source);
    }

    /**
     * Load a partial by name with current partial loader
     */
    public function loadPartial(string $name): Template
    {
        if (isset($this->aliases[$name])) {
            $name = $this->aliases[$name];
        }
        $source = $this->getPartialsLoader()->load($name);
        $tree = $this->tokenize($source);
        return new Template($this, $tree, $source);
    }

    /**
     * Register partial alias
     */
    public function registerPartial(string $alias, string $content): void
    {
        $this->aliases[$alias] = $content;
    }

    /**
     * Un-register partial alias
     */
    public function unRegisterPartial(string $alias): void
    {
        if (isset($this->aliases[$alias])) {
            unset($this->aliases[$alias]);
        }
    }

    /**
     * Load string into a template object
     */
    public function loadString(string $source): Template
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
