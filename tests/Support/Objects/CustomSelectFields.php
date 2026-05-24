<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Support\Objects;

use Rebing\GraphQL\Support\SelectFields;

/**
 * Test fixture: a SelectFields subclass used to verify that the parameter
 * injector honours subclass type-hints in resolve() signatures.
 */
class CustomSelectFields extends SelectFields
{
}
