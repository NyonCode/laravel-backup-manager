<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp;

/**
 * Validates tool arguments against the subset of JSON Schema the tools use.
 *
 * MCP clients are not obliged to validate anything before calling, so the server
 * does it: a typo'd argument becomes a precise message the model can act on
 * instead of a PHP type error deep inside a tool.
 */
final class ArgumentValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     * @return list<string> Human readable problems; empty when the call is valid.
     */
    public function validate(array $schema, array $arguments): array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = (array) ($schema['properties'] ?? []);
        /** @var list<string> $required */
        $required = array_values((array) ($schema['required'] ?? []));
        $errors = [];

        foreach ($required as $name) {
            if (! array_key_exists($name, $arguments)) {
                $errors[] = "Missing required argument [{$name}].";
            }
        }

        foreach ($arguments as $name => $value) {
            if (! isset($properties[$name])) {
                $known = implode(', ', array_keys($properties));
                $errors[] = "Unknown argument [{$name}]."
                    .($known === '' ? '' : " Known arguments: {$known}.");

                continue;
            }

            $error = $this->check($name, $properties[$name], $value);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Fill in the defaults declared by the schema for arguments left out.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function withDefaults(array $schema, array $arguments): array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = (array) ($schema['properties'] ?? []);

        foreach ($properties as $name => $property) {
            if (! array_key_exists($name, $arguments) && array_key_exists('default', $property)) {
                $arguments[$name] = $property['default'];
            }
        }

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private function check(string $name, array $property, mixed $value): ?string
    {
        $type = $property['type'] ?? null;

        if (is_string($type) && ! $this->matchesType($type, $value)) {
            return sprintf('Argument [%s] must be of type %s, %s given.', $name, $type, get_debug_type($value));
        }

        if (isset($property['enum']) && is_array($property['enum']) && ! in_array($value, $property['enum'], true)) {
            return sprintf(
                'Argument [%s] must be one of: %s.',
                $name,
                implode(', ', array_map(static fn (mixed $option): string => (string) json_encode($option), $property['enum'])),
            );
        }

        if ($type === 'array' && is_array($value) && isset($property['items']['type']) && is_string($property['items']['type'])) {
            foreach ($value as $index => $item) {
                if (! $this->matchesType($property['items']['type'], $item)) {
                    return sprintf(
                        'Argument [%s][%s] must be of type %s, %s given.',
                        $name,
                        (string) $index,
                        $property['items']['type'],
                        get_debug_type($item),
                    );
                }
            }
        }

        return null;
    }

    private function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            // A JSON integer may arrive as a float with no fractional part.
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && (! array_is_list($value) || $value === []),
            'null' => $value === null,
            default => true,
        };
    }
}
