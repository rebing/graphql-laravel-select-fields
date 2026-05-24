<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields;

use Rebing\GraphQL\Support\Contracts\WrapType;
use Rebing\GraphQL\Support\PaginationType as BasePaginationType;

/**
 * SelectFields-aware pagination type.
 *
 * Extends the core PaginationType with:
 * - WrapType marker interface (so SelectFields can traverse into the wrapper)
 * - 'selectable' => false on metadata fields (so SelectFields skips them)
 */
class PaginationType extends BasePaginationType implements WrapType
{
    protected function getPaginationFields(\GraphQL\Type\Definition\Type $underlyingType): array
    {
        $fields = parent::getPaginationFields($underlyingType);

        // Mark all non-data fields as non-selectable for SelectFields
        foreach ($fields as $name => &$field) {
            if ('data' !== $name) {
                $field['selectable'] = false;
            }
        }

        return $fields;
    }
}
