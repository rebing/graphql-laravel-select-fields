<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

use Illuminate\Database\Eloquent\Model;

final class ModelKey
{
    /**
     * Row-identity key: class + connection + primary key. Unsaved/keyless
     * models fall back to object identity (safe: callers retain references
     * for the loader's lifetime, preventing spl_object_id reuse).
     */
    public static function for(Model $model): string
    {
        $key = $model->getKey();

        if (null === $key) {
            return $model::class . '|obj:' . spl_object_id($model);
        }

        return $model::class . '|' . $model->getConnectionName() . '|' . $key;
    }
}
