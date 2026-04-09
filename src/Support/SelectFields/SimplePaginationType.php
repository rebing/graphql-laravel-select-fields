<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields;

use Rebing\GraphQL\Support\Contracts\WrapType;
use Rebing\GraphQL\Support\SimplePaginationType as BaseSimplePaginationType;

/**
 * SelectFields-aware simple pagination type.
 */
class SimplePaginationType extends BaseSimplePaginationType implements WrapType
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
