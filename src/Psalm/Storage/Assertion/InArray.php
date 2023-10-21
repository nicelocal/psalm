<?php

declare(strict_types=1);

namespace Psalm\Storage\Assertion;

use Psalm\Storage\Assertion;
use Psalm\Type\Union;

/**
 * @psalm-immutable
 */
final class InArray extends Assertion
{
    public function __construct(public readonly Union $type)
    {
    }

    public function getNegation(): Assertion
    {
        return new NotInArray($this->type);
    }

    public function __toString(): string
    {
        return 'in-array-' . $this->type->getId();
    }

    public function isNegationOf(Assertion $assertion): bool
    {
        return $assertion instanceof NotInArray && $this->type->getId() === $assertion->type->getId();
    }
}
