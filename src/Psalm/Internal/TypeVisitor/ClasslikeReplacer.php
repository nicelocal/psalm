<?php

namespace Psalm\Internal\TypeVisitor;

use Psalm\Internal\Codebase\Scanner;
use Psalm\Storage\FileStorage;
use Psalm\Type\Atomic\TClassConstant;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TypeVisitor;
use Psalm\Type\TypeNode;

use function strtolower;

/**
 * @internal
 */
class ClasslikeReplacer extends TypeVisitor
{
    private string $old;
    private string $new;

    /**
     * @param  array<string, mixed> $phantom_classes
     */
    public function __construct(
        string $old,
        string $new
    ) {
        $this->old = strtolower($old);
        $this->new = $new;
    }

    protected function enterNode(TypeNode &$type): ?int
    {
        if ($type instanceof TClassConstant) {
            if (strtolower($type->fq_classlike_name) === $this->old) {
                $cloned = clone $type;
                $cloned->fq_classlike_name = $this->new;
            }
        } else if ($type instanceof TClassString) {
            if ($type->as !== 'object' && strtolower($type->as) === $this->old) {
                $cloned = clone $type;
                $cloned->as = $this->new;
            }
        } else if ($type instanceof TNamedObject || $type instanceof TLiteralClassString) {
            if (strtolower($type->value) === $this->old) {
                $cloned = clone $type;
                $cloned->value = $this->new;
            }
        }
        return null;
    }
}
