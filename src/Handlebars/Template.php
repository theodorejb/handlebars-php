<?php

namespace Handlebars;

use InvalidArgumentException;
use RuntimeException;

/**
 * Handlebars base template. Contains utility methods to get context and helpers.
 */
class Template
{
    protected Handlebars $handlebars;
    protected array $tree = [];
    protected string $source = '';
    private array $stack = [];

    public function __construct(Handlebars $engine, array $tree, string $source)
    {
        $this->handlebars = $engine;
        $this->tree = $tree;
        $this->source = $source;
        $this->stack[] = [0, $tree, false];
    }

    /**
     * Get current tree
     */
    public function getTree(): array
    {
        return $this->tree;
    }

    /**
     * Get current source
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Get current engine associated with this object
     */
    public function getEngine(): Handlebars
    {
        return $this->handlebars;
    }

    /**
     * Set stop token for render and discard method
     *
     * @param string|false $token token to set as stop token or false to remove
     */
    public function setStopToken($token): void
    {
        $topStack = array_pop($this->stack);
        $topStack[2] = $token;
        $this->stack[] = $topStack;
    }

    /**
     * get current stop token
     *
     * @return string|false
     */
    public function getStopToken()
    {
        return end($this->stack)[2];
    }

    /**
     * Render top tree
     *
     * @param mixed $context current context
     *
     * @throws \RuntimeException
     */
    public function render($context): string
    {
        if (!$context instanceof Context) {
            $context = new Context($context, [
                'enableDataVariables' => $this->handlebars->isDataVariablesEnabled(),
            ]);
        }
        $topTree = end($this->stack); // never pop a value from stack
        [$index, $tree, $stop] = $topTree;

        $buffer = '';
        while (array_key_exists($index, $tree)) {
            $current = $tree[$index];
            $index++;
            //if the section is exactly like waitFor
            if (is_string($stop)
                && $current[Tokenizer::TYPE] == Tokenizer::T_ESCAPED
                && $current[Tokenizer::NAME] === $stop
            ) {
                break;
            }
            switch ($current[Tokenizer::TYPE]) {
            case Tokenizer::T_SECTION :
                $newStack = $current[Tokenizer::NODES] ?? [];
                $this->stack[] = [0, $newStack, false];
                $buffer .= $this->section($context, $current);
                array_pop($this->stack);
                break;
            case Tokenizer::T_INVERTED :
                $newStack = $current[Tokenizer::NODES] ?? [];
                $this->stack[] = [0, $newStack, false];
                $buffer .= $this->inverted($context, $current);
                array_pop($this->stack);
                break;
            case Tokenizer::T_COMMENT :
                $buffer .= '';
                break;
            case Tokenizer::T_PARTIAL:
            case Tokenizer::T_PARTIAL_2:
                $buffer .= $this->partial($context, $current);
                break;
            case Tokenizer::T_UNESCAPED:
            case Tokenizer::T_UNESCAPED_2:
                $buffer .= $this->variables($context, $current, false);
                break;
            case Tokenizer::T_ESCAPED:
                $buffer .= $this->variables($context, $current, true);
                break;
            case Tokenizer::T_TEXT:
                $buffer .= $current[Tokenizer::VALUE];
                break;
            default:
                throw new RuntimeException(
                    'Invalid node type : ' . json_encode($current)
                );
            }
        }
        if ($stop) {
            //Ok break here, the helper should be aware of this.
            $newStack = array_pop($this->stack);
            $newStack[0] = $index;
            $newStack[2] = false; //No stop token from now on
            $this->stack[] = $newStack;
        }

        return $buffer;
    }

    /**
     * Discard top tree
     */
    public function discard(): string
    {
        $topTree = end($this->stack); //This method never pop a value from stack
        [$index, $tree, $stop] = $topTree;
        while (array_key_exists($index, $tree)) {
            $current = $tree[$index];
            $index++;
            //if the section is exactly like waitFor
            if (is_string($stop)
                && $current[Tokenizer::TYPE] == Tokenizer::T_ESCAPED
                && $current[Tokenizer::NAME] === $stop
            ) {
                break;
            }
        }
        if ($stop) {
            //Ok break here, the helper should be aware of this.
            $newStack = array_pop($this->stack);
            $newStack[0] = $index;
            $newStack[2] = false;
            $this->stack[] = $newStack;
        }

        return '';
    }

    /**
     * Process section nodes
     * @throws \RuntimeException
     */
    private function section(Context $context, array $current): string
    {
        $helpers = $this->handlebars->getHelpers();
        $sectionName = $current[Tokenizer::NAME];
        if ($helpers->has($sectionName)) {
            if (isset($current[Tokenizer::END])) {
                $source = substr(
                    $this->getSource(),
                    $current[Tokenizer::INDEX],
                    $current[Tokenizer::END] - $current[Tokenizer::INDEX]
                );
            } else {
                $source = '';
            }
            $params = [
                $this, //First argument is this template
                $context, //Second is current context
                $current[Tokenizer::ARGS], //Arguments
                $source
            ];

            return call_user_func_array($helpers->$sectionName, $params);
        } elseif (trim($current[Tokenizer::ARGS]) == '') {
            // fallback to mustache style each/with/for just if there is
            // no argument at all.
            try {
                $sectionVar = $context->get($sectionName, true);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException(
                    $sectionName . ' is not registered as a helper'
                );
            }
            $buffer = '';
            if (is_array($sectionVar) || $sectionVar instanceof \Traversable) {
                foreach ($sectionVar as $index => $d) {
                    $context->pushIndex($index);
                    $context->push($d);
                    $buffer .= $this->render($context);
                    $context->pop();
                    $context->popIndex();
                }
            } elseif (is_object($sectionVar)) {
                //Act like with
                $context->push($sectionVar);
                $buffer = $this->render($context);
                $context->pop();
            } elseif ($sectionVar) {
                $buffer = $this->render($context);
            }

            return $buffer;
        } else {
            throw new RuntimeException(
                $sectionName . ' is not registered as a helper'
            );
        }
    }

    /**
     * Process inverted section
     */
    private function inverted(Context $context, array $current): string
    {
        $sectionName = $current[Tokenizer::NAME];
        $data = $context->get($sectionName);
        if (!$data) {
            return $this->render($context);
        } else {
            //No need to discard here, since it has no else
            return '';
        }
    }

    /**
     * Process partial section
     */
    private function partial(Context $context, array $current): string
    {
        $partial = $this->handlebars->loadTemplate($current[Tokenizer::NAME]);

        if ($current[Tokenizer::ARGS]) {
            $context = $context->get($current[Tokenizer::ARGS]);
        }

        return $partial->render($context);
    }

    /**
     * Process partial section
     */
    private function variables(Context $context, array $current, bool $escaped): string
    {
        $name = $current[Tokenizer::NAME];
        $value = (string) $context->get($name);

        // If @data variables are enabled, use the more complex algorithm for handling the the variables otherwise
        // use the previous version.
        if ($this->handlebars->isDataVariablesEnabled()) {
            if (substr(trim($name), 0, 1) == '@') {
                $variable = $context->getDataVariable($name);
                if (is_bool($variable)) {
                    return $variable ? 'true' : 'false';
                }
                return $variable;
            }
        } else {
            // If @data variables are not enabled, then revert back to legacy behavior
            if ($name == '@index') {
                return $context->lastIndex();
            }
            if ($name == '@key') {
                return $context->lastKey();
            }
        }

        if ($escaped) {
            $value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
        }

        return $value;
    }
}
