<?php

namespace Handlebars;

use InvalidArgumentException;
use LogicException;

/**
 * Context for a template
 */
class Context
{
    const DATA_KEY = 'key';
    const DATA_INDEX = 'index';
    const DATA_FIRST = 'first';
    const DATA_LAST = 'last';

    /**
     * @var array stack for context only top stack is available
     */
    protected array $stack = [];

    /**
     * @var array index stack for sections
     */
    protected array $index = [];

    /**
     * @var array dataStack stack for data within sections
     */
    protected array $dataStack = [];

    /**
     * @var array key stack for objects
     */
    protected array $key = [];

    /**
     * @var bool enableDataVariables true if @data variables should be used.
     */
    protected bool $enableDataVariables = false;

    /**
     * Mustache rendering Context constructor.
     *
     * @param mixed $context Default rendering context (default: null)
     * @param array $options Options for the context. It may contain the following: (default: empty array)
     *                       enableDataVariables => bool, Enables @data variables (default: false)
     *
     * @throws InvalidArgumentException when calling this method when enableDataVariables is not a boolean.
     */
    public function __construct($context = null, array $options = [])
    {
        if ($context !== null) {
            $this->stack = [$context];
        }

        if (isset($options[Handlebars::OPTION_ENABLE_DATA_VARIABLES])) {
            if (!is_bool($options[Handlebars::OPTION_ENABLE_DATA_VARIABLES])) {
                throw new InvalidArgumentException(
                    'Context Constructor "' . Handlebars::OPTION_ENABLE_DATA_VARIABLES . '" option must be a boolean'
                );
            }
            $this->enableDataVariables = $options[Handlebars::OPTION_ENABLE_DATA_VARIABLES];
        }
    }

    /**
     * Push a new Context frame onto the stack.
     *
     * @param mixed $value Object or array to use for context
     */
    public function push($value): void
    {
        $this->stack[] = $value;
    }

    /**
     * Push an Index onto the index stack
     */
    public function pushIndex(int $index): void
    {
        $this->index[] = $index;
    }

    /**
     * Pushes data variables onto the stack. This is used to support @data variables.
     * @param array $data Associative array where key is the name of the @data variable and value is the value.
     * @throws LogicException when calling this method without having enableDataVariables.
     */
    public function pushData(array $data): void
    {
        if (!$this->enableDataVariables) {
            throw new LogicException('Data variables are not supported due to the enableDataVariables configuration. Remove the call to data variables or change the setting.');
        }
        $this->dataStack[] = $data;
    }

    /**
     * Push a Key onto the key stack
     */
    public function pushKey(string $key): void
    {
        $this->key[] = $key;
    }

    /**
     * Pop the last Context frame from the stack.
     *
     * @return mixed Last Context frame (object or array)
     */
    public function pop()
    {
        return array_pop($this->stack);
    }

    /**
     * Pop the last index from the stack.
     */
    public function popIndex(): int
    {
        return array_pop($this->index);
    }

    /**
     * Pop the last section data from the stack.
     * @throws LogicException when calling this method without having enableDataVariables.
     */
    public function popData(): array
    {
        if (!$this->enableDataVariables) {
            throw new LogicException('Data variables are not supported due to the enableDataVariables configuration. Remove the call to data variables or change the setting.');
        }
        return array_pop($this->dataStack);
    }

    /**
     * Pop the last key from the stack.
     */
    public function popKey(): string
    {
        return array_pop($this->key);
    }

    /**
     * Get the last Context frame.
     *
     * @return mixed Last Context frame (object or array)
     */
    public function last()
    {
        return end($this->stack);
    }

    /**
     * Get the index of current section item.
     */
    public function lastIndex(): int
    {
        return end($this->index);
    }

    /**
     * Get the key of current object property.
     */
    public function lastKey(): string
    {
        return end($this->key);
    }

    /**
     * Change the current context to one of current context members
     *
     * @param string $variableName name of variable or a callable on current context
     *
     * @return mixed actual value
     */
    public function with(string $variableName)
    {
        $value = $this->get($variableName);
        $this->push($value);

        return $value;
    }

    /**
     * Get a variable from current context
     * Supported types :
     * variable , ../variable , variable.variable , .
     *
     * @param string  $variableName variable name to get from current context
     * @param boolean $strict       strict search? if not found then throw exception
     *
     * @throws InvalidArgumentException in strict mode and variable not found
     * @return mixed
     */
    public function get(string $variableName, bool $strict = false)
    {
        //Need to clean up
        $variableName = trim($variableName);

        //Handle data variables (@index, @first, @last, etc)
        if ($this->enableDataVariables && substr($variableName, 0, 1) == '@') {
            return $this->getDataVariable($variableName, $strict);
        }

        $level = 0;
        while (substr($variableName, 0, 3) == '../') {
            $variableName = trim(substr($variableName, 3));
            $level++;
        }
        if (count($this->stack) < $level) {
            if ($strict) {
                throw new InvalidArgumentException('can not find variable in context');
            }

            return null;
        }
        end($this->stack);
        while ($level) {
            prev($this->stack);
            $level--;
        }
        $current = current($this->stack);
        if (!$variableName) {
            if ($strict) {
                throw new InvalidArgumentException('can not find variable in context');
            }
            return null;
        } elseif ($variableName == '.' || $variableName == 'this') {
            return $current;
        } else {
            $chunks = explode('.', $variableName);
            foreach ($chunks as $chunk) {
                if (is_null($current)) {
                    return null;
                }
                $current = $this->findVariableInContext($current, $chunk, $strict);
            }
        }
        return $current;
    }

    /**
     * Given a data variable, retrieves the value associated.
     * @return mixed
     * @throws LogicException when calling this method without having enableDataVariables.
     */
    public function getDataVariable(string $variableName, bool $strict = false)
    {
        if (!$this->enableDataVariables) {
            throw new LogicException('Data variables are not supported due to the enableDataVariables configuration. Remove the call to data variables or change the setting.');
        }

        $variableName = trim($variableName);

        // make sure we get an at-symbol prefix
        if (substr($variableName, 0, 1) != '@') {
            if ($strict) {
                throw new InvalidArgumentException(
                    'Can not find variable in context'
                );
            }
            return '';
        }

        // Remove the at-symbol prefix
        $variableName = substr($variableName, 1);

        // determine the level of relative @data variables
        $level = 0;
        while (substr($variableName, 0, 3) == '../') {
            $variableName = trim(substr($variableName, 3));
            $level++;
        }

        // make sure the stack actually has the specified number of levels
        if (count($this->dataStack) < $level) {
            if ($strict) {
                throw new InvalidArgumentException(
                    'Can not find variable in context'
                );
            }

            return '';
        }

        // going from the top of the stack to the bottom, traverse the number of levels specified
        end($this->dataStack);
        while ($level) {
            prev($this->dataStack);
            $level--;
        }

        /** @var array $current */
        $current = current($this->dataStack);

        if (!array_key_exists($variableName, $current)) {
            if ($strict) {
                throw new InvalidArgumentException(
                    'Can not find variable in context'
                );
            }

            return '';
        }

        return $current[$variableName];
    }

    /**
     * Check if $variable->$inside is available
     *
     * @param mixed   $variable variable to check
     * @param string  $inside   property/method to check
     * @param boolean $strict   strict search? if not found then throw exception
     *
     * @throws \InvalidArgumentException in strict mode and variable not found
     * @return mixed
     */
    private function findVariableInContext($variable, string $inside, bool $strict = false)
    {
        $value = null;
        if (($inside !== '0' && empty($inside)) || ($inside == 'this')) {
            return $variable;
        } elseif (is_array($variable)) {
            if (isset($variable[$inside])) {
                $value = $variable[$inside];
            }
        } elseif (is_object($variable)) {
            if (isset($variable->$inside)) {
                $value = $variable->$inside;
            } elseif (is_callable(array($variable, $inside))) {
                $value = call_user_func(array($variable, $inside));
            }
        } elseif ($inside === '.') {
            $value = $variable;
        } elseif ($strict) {
            throw new InvalidArgumentException('can not find variable in context');
        }
        return $value;
    }
}
