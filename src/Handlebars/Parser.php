<?php

namespace Handlebars;

use ArrayIterator;
use LogicException;

/**
 * Handlebars parser (based on mustache).
 * This class is responsible for turning raw template source into a set of Handlebars tokens.
 */
class Parser
{
    /**
     * Process array of tokens and convert them into parse tree
     */
    public function parse(array $tokens = []): array
    {
        return $this->buildTree(new ArrayIterator($tokens));
    }

    /**
     * Helper method for recursively building a parse tree.
     *
     * @throws \LogicException when nesting errors or mismatched section tags are encountered.
     */
    private function buildTree(ArrayIterator $tokens): array
    {
        $stack = [];

        do {
            $token = $tokens->current();
            $tokens->next();

            if ($token === null) {
                continue;
            }

            switch ($token[Tokenizer::TYPE]) {
                case Tokenizer::T_END_SECTION:
                    $newNodes = [];
                    do {
                        $result = array_pop($stack);
                        if ($result === null) {
                            throw new LogicException(
                                'Unexpected closing tag: /' . $token[Tokenizer::NAME]
                            );
                        }

                        if (!array_key_exists(Tokenizer::NODES, $result)
                            && isset($result[Tokenizer::NAME])
                            && $result[Tokenizer::NAME] == $token[Tokenizer::NAME]
                        ) {
                            $result[Tokenizer::NODES] = $newNodes;
                            $result[Tokenizer::END] = $token[Tokenizer::INDEX];
                            $stack[] = $result;
                            break 2;
                        } else {
                            array_unshift($newNodes, $result);
                        }
                    } while (true);
                default:
                    $stack[] = $token;
            }
        } while ($tokens->valid());

        return $stack;
    }
}
