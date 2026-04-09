<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields;

use Rebing\GraphQL\Support\Contracts\WrapType;
use Rebing\GraphQL\Support\CursorPaginationType as BaseCursorPaginationType;

/**
 * SelectFields-aware cursor pagination type.
 */
class CursorPaginationType extends BaseCursorPaginationType implements WrapType
{
    protected function getPaginationFields(\GraphQL\Type\Definition\Type $underlyingType): array
    {
        $fields = parent::getPaginationFields($underlyingType);

        foreach ($fields as $name => &$field) {
            if ('data' !== $name) {
                $field['selectable'] = false;
            }
        }

        return $fields;
    }
}
