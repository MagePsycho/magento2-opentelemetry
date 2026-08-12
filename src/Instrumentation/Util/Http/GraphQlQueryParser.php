<?php
/**
 * This file is part of the Mumzworld_OpenTelemetry package.
 *
 * @author    Raj KB <rajendra.bhatta@mumzworld.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com)
 */
declare(strict_types=1);

namespace Mumzworld\OpenTelemetry\Instrumentation\Util\Http;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use Magento\Framework\App\RequestInterface;
use Throwable;

class GraphQlQueryParser
{
    /**
     * Shape returned by every public method of this class.
     */
    private const DEFAULT_RESULT = [
        'type' => 'Query',
        'operation' => null
    ];

    /**
     * Extract GraphQL operation type and name from a request object.
     * Works with both POST requests (JSON body) and GET requests (URL parameters).
     *
     * @param RequestInterface $request
     * @return array{type: string, operation: string|null}
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function parseRequest(RequestInterface $request): array
    {
        try {
            // First try to get data from request body (POST)
            $content = $request->getContent();
            if (!empty($content)) {
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data) && isset($data['query'])) {
                    return self::parseJsonData($data);
                }
            }

            // If we got here, try to get from URL (GET)
            return self::parseUrl((string)$request->getUri());
        } catch (Throwable $e) {

            return self::DEFAULT_RESULT;
        }
    }

    /**
     * Parse decoded JSON data from a GraphQL request
     *
     * @param array $data
     * @return array{type: string, operation: string|null}
     */
    public static function parseJsonData(array $data): array
    {
        if (!isset($data['query']) || !is_string($data['query'])) {
            return self::DEFAULT_RESULT;
        }

        return self::resolveOperation(
            $data['query'],
            empty($data['operationName']) ? null : (string)$data['operationName']
        );
    }

    /**
     * Parse a GraphQL URL to extract query type and operation name.
     *
     * @param string $url The URL containing GraphQL parameters
     * @return array{type: string, operation: string|null} Associative array with 'type' and 'operation' keys
     */
    public static function parseUrl(string $url): array
    {
        $queryParams = [];
        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parsedUrl = parse_url($url);

        if (!isset($parsedUrl['query'])) {
            return self::DEFAULT_RESULT; // No query parameters found
        }

        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        parse_str($parsedUrl['query'], $queryParams);

        if (empty($queryParams['query']) || !is_string($queryParams['query'])) {
            return self::DEFAULT_RESULT;
        }

        // NOTE: parse_str() already percent-decodes; decoding a second time would turn a
        // literal "+" in the document into a space and mangle stray "%" sequences.
        return self::resolveOperation(
            $queryParams['query'],
            empty($queryParams['operationName']) ? null : (string)$queryParams['operationName']
        );
    }

    /**
     * Parse a raw GraphQL query string to extract query type and operation name.
     *
     * @param string $graphqlString
     * @return array{type: string, operation: string|null}
     */
    public static function parseQueryString(string $graphqlString): array
    {
        return self::resolveOperation($graphqlString, null);
    }

    /**
     * Resolve the operation type and name for a GraphQL document.
     *
     * Prefers a real AST walk so that leading fragment definitions, comments and multi
     * operation documents are handled correctly; falls back to a lightweight scanner when
     * the document cannot be parsed (syntax error, or webonyx unavailable).
     *
     * @param string $queryString
     * @param string|null $operationName Explicit operationName sent alongside the query, if any
     * @return array{type: string, operation: string|null}
     */
    private static function resolveOperation(string $queryString, ?string $operationName): array
    {
        $queryString = trim($queryString);
        if ($queryString === '') {
            return ['type' => 'Query', 'operation' => $operationName];
        }

        $document = self::parseDocument($queryString);
        if ($document !== null) {
            $node = self::selectOperationNode($document, $operationName);
            if ($node !== null) {
                return [
                    'type' => ucfirst(strtolower($node->operation)),
                    'operation' => $node->name?->value
                        ?? self::firstRootFieldName($node)
                            ?? $operationName,
                ];
            }
        }

        return self::scanOperation($queryString, $operationName);
    }

    /**
     * Parse the document with the standalone webonyx parser.
     *
     * Deliberately avoids Magento's QueryParser (and therefore the ObjectManager) so this
     * class stays free of Magento service dependencies. webonyx ships with magento/framework,
     * but the class_exists() guard means its absence degrades to the scanner instead of
     * fataling. Locations are skipped - only definition names and kinds are needed here.
     *
     * @param string $queryString
     * @return DocumentNode|null Null when the document cannot be parsed
     */
    private static function parseDocument(string $queryString): ?DocumentNode
    {
        if (!class_exists(Parser::class)) {
            return null;
        }

        try {
            return Parser::parse($queryString, ['noLocation' => true]);
        } catch (Throwable $e) {

            return null;
        }
    }

    /**
     * Pick the operation definition the request actually executes.
     *
     * Fragment definitions are skipped outright - selecting them is what produced field names
     * such as "id" for documents whose fragments precede the operation.
     *
     * @param DocumentNode $document
     * @param string|null $operationName
     * @return OperationDefinitionNode|null
     */
    private static function selectOperationNode(
        DocumentNode $document,
        ?string $operationName
    ): ?OperationDefinitionNode {
        $firstOperation = null;

        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }

            if ($firstOperation === null) {
                $firstOperation = $definition;
            }

            if ($operationName !== null && $definition->name?->value === $operationName) {
                return $definition;
            }
        }

        return $firstOperation;
    }

    /**
     * First root field of an anonymous operation, e.g. "cart" for "{ cart { id } }".
     *
     * Inline fragments and fragment spreads at the root are skipped.
     *
     * @param OperationDefinitionNode $node
     * @return string|null
     */
    private static function firstRootFieldName(OperationDefinitionNode $node): ?string
    {
        foreach ($node->selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                return $selection->name->value;
            }
        }

        return null;
    }

    /**
     * Best-effort extraction for documents the parser rejected.
     *
     * Walks the document tracking brace depth so only top level operation keywords are
     * considered, with comments and string literals blanked out first.
     *
     * @param string $queryString
     * @param string|null $operationName
     * @return array{type: string, operation: string|null}
     */
    private static function scanOperation(string $queryString, ?string $operationName): array
    {
        $result = ['type' => 'Query', 'operation' => $operationName];
        $haystack = self::blankCommentsAndStrings($queryString);
        $length = strlen($haystack);
        $depth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $haystack[$i];

            if ($char === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($char === '{') {
                // Anonymous shorthand document: "{ cart { id } }"
                if ($depth === 0
                    && $result['operation'] === null
                    && preg_match('/\{\s*([A-Za-z_][A-Za-z0-9_]*)/', substr($haystack, $i), $matches)
                ) {
                    $result['operation'] = $matches[1];

                    return $result;
                }
                $depth++;
                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            // Only consider a keyword that starts a fresh token at the top level
            if ($i > 0 && preg_match('/[A-Za-z0-9_]/', $haystack[$i - 1])) {
                continue;
            }

            // Skip fragment definitions entirely - their body must never be mistaken for
            // the operation, which is exactly what produced field names such as "id".
            if (preg_match('/\Gfragment\b/i', $haystack, $ignored, 0, $i)) {
                $i = self::skipBracedBlock($haystack, $i);
                continue;
            }

            if (!preg_match(
                '/\G(query|mutation|subscription)\b\s*([A-Za-z_][A-Za-z0-9_]*)?/i',
                $haystack,
                $matches,
                0,
                $i
            )) {
                continue;
            }

            $name = $matches[2] ?? '';
            if ($operationName !== null && $name !== '' && $name !== $operationName) {
                continue; // keep looking for the operation the client asked for
            }

            $result['type'] = ucfirst(strtolower($matches[1]));
            if ($name !== '') {
                $result['operation'] = $name;
            } elseif ($result['operation'] === null
                && preg_match('/\{\s*([A-Za-z_][A-Za-z0-9_]*)/', substr($haystack, $i), $fieldMatches)
            ) {
                $result['operation'] = $fieldMatches[1];
            }

            return $result;
        }

        return $result;
    }

    /**
     * Advance past a "<keyword> ... { ... }" block, returning the index of its closing brace.
     *
     * Returns the last index when the block is unbalanced so the caller's loop terminates.
     *
     * @param string $haystack
     * @param int $start Index of the first character of the keyword
     * @return int
     */
    private static function skipBracedBlock(string $haystack, int $start): int
    {
        $length = strlen($haystack);
        $depth = 0;
        $seenBrace = false;

        for ($i = $start; $i < $length; $i++) {
            if ($haystack[$i] === '{') {
                $depth++;
                $seenBrace = true;
                continue;
            }

            if ($haystack[$i] === '}') {
                $depth--;
                if ($seenBrace && $depth <= 0) {
                    return $i;
                }
            }
        }

        return $length - 1;
    }

    /**
     * Replace comments and string literals with equal-length blanks so offsets stay intact.
     *
     * Prevents a "#" comment or a quoted default value that mentions braces or the word
     * "mutation" from being mistaken for document structure.
     *
     * @param string $queryString
     * @return string
     */
    private static function blankCommentsAndStrings(string $queryString): string
    {
        $blank = static fn(array $matches): string => str_repeat(' ', strlen($matches[0]));

        foreach (['/"""(?:.|\n)*?"""/', '/"(?:\\\\.|[^"\\\\\n])*"/', '/#[^\n]*/'] as $pattern) {
            $replaced = preg_replace_callback($pattern, $blank, $queryString);
            if ($replaced !== null) {
                $queryString = $replaced;
            }
        }

        return $queryString;
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

