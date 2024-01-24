<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    const EXPRESSION_DENIED = '⸜(*ˊᗜˋ*)⸝';

    /**
     * @var mysqli
     */
    private mysqli $mysqli;

    /**
     * @var array
     */
    private array $allowed_placeholders = [
        '?' => 'replaceCommon',
        '?d' => 'replaceInt',
        '?f' => 'replaceFloat',
        '?a' => 'replaceArray',
        '?#' => 'replaceIdentifier'
    ];

    /**
     * @var int
     */
    private int $replacementIndex;

    /**
     * @param mysqli $mysqli
     */
    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * MySQL query awesome builder.
     *
     * @param string $query
     * @param array $args
     *
     * @return string
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        if ( substr_count($query, '?') !== count($args) ) {
            throw new Exception('Query builder arguments count is wrong.');
        }

        $this->replacementIndex = 0;
        $prepared_query = preg_replace_callback(
            '@\?+(?:[\sdfa#])|\{[\s\w=\?]*\}@',
            function($query_placeholders) use ($args) {
                return $this->replaceQueryPlaceholders($query_placeholders, $args[$this->replacementIndex]);
            },
            $query
        );

        return $prepared_query;
    }

    /**
     * Special value to prevent adding expression to the query
     *
     * @return string
     */
    public function skip(): string
    {
        return static::EXPRESSION_DENIED;
    }

    /**
     * Do replacing
     *
     * @param array $query_placeholders
     * @param mixed $arg
     *
     * @return string
     * @throws Exception
     */
    private function replaceQueryPlaceholders(array $query_placeholders, mixed $arg): string
    {
        $placeholder = trim($query_placeholders[0]);

        if ( strpos($placeholder, '{') !== false ) {
            return $this->processConditionalPlaceholder($placeholder, $arg);
        }

        if ( ! array_key_exists($placeholder, $this->allowed_placeholders) ) {
            throw new Exception('Provided placeholder type (' . $placeholder . ') is not supported.');
        }

        $replace_handler = $this->allowed_placeholders[$placeholder];

        if ( ! method_exists($this, $replace_handler) ) {
            throw new Exception('Provided placeholder\'s handler (' . $replace_handler . ') is not implemented.');
        }

        $this->replacementIndex++;
        return $this->$replace_handler($arg);
    }

    /**
     * Conditional constructions handler
     *
     * @param string $placeholder
     * @param mixed $arg
     * @return string
     * @throws Exception
     */
    private function processConditionalPlaceholder(string $placeholder, mixed $arg): string
    {
        $placeholder_inner = trim($placeholder, '{}');

        if ( strpos($placeholder_inner, '{') !== false || strpos($placeholder_inner, '}') !== false ) {
            throw new Exception('Nested conditional constructions is not allowed.');
        }

        if ( $arg === self::EXPRESSION_DENIED ) {
            return '';
        }

        return $this->buildQuery($placeholder_inner, [$arg]);
    }

    /**
     * Check the $value against allowed types: string, int, float, bool, null
     *
     * @param mixed $value
     * @return bool
     */
    private function isAllowedType(mixed $value): bool
    {
        return is_scalar($value) || is_null($value);
    }

    /**
     * @param mixed $input
     *
     * @return string
     * @throws Exception
     */
    private function replaceCommon($input): string
    {
        $output = $this->normalizeCommonParameter($input);

        // @DoTo hard bug fix, need to solve this. just normalize the regular expression matched the placeholders
        $output .= ' ';

        return $output;
    }

    /**
     * @param mixed $input
     *
     * @return string
     * @throws Exception
     */
    private function replaceIdentifier($input): string
    {
        if ( is_array($input) ) {
            if ( count($input) === 0 ) {
                throw new Exception('Placeholder\'s parameter is empty. Expected an array.');
            }
            $output = $this->normalizeArrayParameter($input, true);
        } else {
            $output = $this->normalizeIdentifierParameter($input);
        }
        return $output;
    }

    /**
     * @param mixed $input
     *
     * @return int
     */
    private function replaceInt($input): int
    {
        return (int) $input;
    }

    /**
     * @param mixed $input
     *
     * @return float
     */
    private function replaceFloat($input): float
    {
        return (float) $input;
    }

    /**
     * @param mixed $input
     *
     * @return string
     * @throws Exception
     */
    private function replaceArray(mixed $input): string
    {
        if ( ! is_array($input) ) {
            throw new Exception('Placeholder\'s parameter is wrong. Expected an array.');
        }

        if ( count($input) === 0 ) {
            throw new Exception('Placeholder\'s parameter is empty. Expected an array.');
        }
        return $this->normalizeArrayParameter($input);
    }

    /**
     * @param scalar|null $input
     *
     * @return int|float|string
     */
    private function normalizeCommonParameter(float|bool|int|string|null $input): int|float|string
    {
        if ( ! $this->isAllowedType($input) ) {
            throw new Exception('Provided replacement (' . var_export($input, true) . ') type is not implemented.');
        }

        switch ( gettype($input) ) {
            case 'boolean':
                return (int) $input;
            case 'NULL':
                return 'NULL';
            case 'string':
                return "'{$this->mysqli->real_escape_string($input)}'";
            default:
                return $input;
        }
    }

    /**
     * @param string $input
     *
     * @return string
     */
    private function normalizeIdentifierParameter(string $input): string
    {
        return "`{$this->mysqli->real_escape_string($input)}`";
    }

    /**
     * @param array $input
     * @param bool $for_identifier
     *
     * @return string
     */
    private function normalizeArrayParameter(array $input, bool $for_identifier = false): string
    {
        $output = '';
        foreach ( $input as $key => $value ) {
            if ( array_is_list($input) ) {
                $output .= $for_identifier ? "{$this->normalizeIdentifierParameter($value)}, " : $this->normalizeCommonParameter($value) . ", ";
            } else {
                $output .= "{$this->normalizeIdentifierParameter($key)} = {$this->normalizeCommonParameter($value)}, ";
            }
        }

        return trim($output, ', ');
    }
}
