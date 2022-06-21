<?php

namespace Psalm\Type;

use InvalidArgumentException;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\Type\TypeCombiner;
use Psalm\Internal\TypeVisitor\ContainsClassLikeVisitor;
use Psalm\Internal\TypeVisitor\ContainsLiteralVisitor;
use Psalm\Internal\TypeVisitor\FromDocblockSetter;
use Psalm\Internal\TypeVisitor\TemplateTypeCollector;
use Psalm\Internal\TypeVisitor\TypeChecker;
use Psalm\Internal\TypeVisitor\TypeScanner;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\Type;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TClassStringMap;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TConditional;
use Psalm\Type\Atomic\TEmptyMixed;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TList;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TLowercaseString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNonEmptyLowercaseString;
use Psalm\Type\Atomic\TNonspecificLiteralInt;
use Psalm\Type\Atomic\TNonspecificLiteralString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TTemplateParamClass;
use Psalm\Type\Atomic\TTrue;

use function array_filter;
use function array_merge;
use function array_unique;
use function count;
use function get_class;
use function implode;
use function ksort;
use function reset;
use function sort;
use function strpos;

final class MutableUnion implements TypeNode
{
    use UnionTrait;

    /**
     * @var non-empty-array<string, Atomic>
     */
    private $types;

    /**
     * Whether the type originated in a docblock
     *
     * @var bool
     */
    public $from_docblock = false;

    /**
     * Whether the type originated from integer calculation
     *
     * @var bool
     */
    public $from_calculation = false;

    /**
     * Whether the type originated from a property
     *
     * This helps turn isset($foo->bar) into a different sort of issue
     *
     * @var bool
     */
    public $from_property = false;

    /**
     * Whether the type originated from *static* property
     *
     * Unlike non-static properties, static properties have no prescribed place
     * like __construct() to be initialized in
     *
     * @var bool
     */
    public $from_static_property = false;

    /**
     * Whether the property that this type has been derived from has been initialized in a constructor
     *
     * @var bool
     */
    public $initialized = true;

    /**
     * Which class the type was initialised in
     *
     * @var ?string
     */
    public $initialized_class;

    /**
     * Whether or not the type has been checked yet
     *
     * @var bool
     */
    public $checked = false;

    /**
     * @var bool
     */
    public $failed_reconciliation = false;

    /**
     * Whether or not to ignore issues with possibly-null values
     *
     * @var bool
     */
    public $ignore_nullable_issues = false;

    /**
     * Whether or not to ignore issues with possibly-false values
     *
     * @var bool
     */
    public $ignore_falsable_issues = false;

    /**
     * Whether or not to ignore issues with isset on this type
     *
     * @var bool
     */
    public $ignore_isset = false;

    /**
     * Whether or not this variable is possibly undefined
     *
     * @var bool
     */
    public $possibly_undefined = false;

    /**
     * Whether or not this variable is possibly undefined
     *
     * @var bool
     */
    public $possibly_undefined_from_try = false;

    /**
     * Whether or not this union had a template, since replaced
     *
     * @var bool
     */
    public $had_template = false;

    /**
     * Whether or not this union comes from a template "as" default
     *
     * @var bool
     */
    public $from_template_default = false;

    /**
     * @var array<string, TLiteralString>
     */
    private $literal_string_types = [];

    /**
     * @var array<string, TClassString>
     */
    private $typed_class_strings = [];

    /**
     * @var array<string, TLiteralInt>
     */
    private $literal_int_types = [];

    /**
     * @var array<string, TLiteralFloat>
     */
    private $literal_float_types = [];

    /**
     * True if the type was passed or returned by reference, or if the type refers to an object's
     * property or an item in an array. Note that this is not true for locally created references
     * that don't refer to properties or array items (see Context::$references_in_scope).
     *
     * @var bool
     */
    public $by_ref = false;

    /**
     * @var bool
     */
    public $reference_free = false;

    /**
     * @var bool
     */
    public $allow_mutations = true;

    /**
     * @var bool
     */
    public $has_mutations = true;

    /**
     * This is a cache of getId on non-exact mode
     * @var null|string
     */
    private $id;

    /**
     * This is a cache of getId on exact mode
     * @var null|string
     */
    private $exact_id;


    /**
     * @var array<string, DataFlowNode>
     */
    public $parent_nodes = [];

    /**
     * @var bool
     */
    public $different = false;

    /**
     * @param non-empty-array<string, Atomic>  $types
     */
    public function replaceTypes(array $types): void
    {
        $this->types = $types;
    }

    public function addType(Atomic $type): void
    {
        $this->types[$type->getKey()] = $type;

        if ($type instanceof TLiteralString) {
            $this->literal_string_types[$type->getKey()] = $type;
        } elseif ($type instanceof TLiteralInt) {
            $this->literal_int_types[$type->getKey()] = $type;
        } elseif ($type instanceof TLiteralFloat) {
            $this->literal_float_types[$type->getKey()] = $type;
        } elseif ($type instanceof TString && $this->literal_string_types) {
            foreach ($this->literal_string_types as $key => $_) {
                unset($this->literal_string_types[$key], $this->types[$key]);
            }
            if (!$type instanceof TClassString
                || (!$type->as_type && !$type instanceof TTemplateParamClass)
            ) {
                foreach ($this->typed_class_strings as $key => $_) {
                    unset($this->typed_class_strings[$key], $this->types[$key]);
                }
            }
        } elseif ($type instanceof TInt && $this->literal_int_types) {
            //we remove any literal that is already included in a wider type
            $int_type_in_range = TIntRange::convertToIntRange($type);
            foreach ($this->literal_int_types as $key => $literal_int_type) {
                if ($int_type_in_range->contains($literal_int_type->value)) {
                    unset($this->literal_int_types[$key], $this->types[$key]);
                }
            }
        } elseif ($type instanceof TFloat && $this->literal_float_types) {
            foreach ($this->literal_float_types as $key => $_) {
                unset($this->literal_float_types[$key], $this->types[$key]);
            }
        }

        $this->bustCache();
    }

    public function __clone()
    {
        $this->literal_string_types = [];
        $this->literal_int_types = [];
        $this->literal_float_types = [];
        $this->typed_class_strings = [];

        foreach ($this->types as $key => &$type) {
            $type = clone $type;

            if ($type instanceof TLiteralInt) {
                $this->literal_int_types[$key] = $type;
            } elseif ($type instanceof TLiteralString) {
                $this->literal_string_types[$key] = $type;
            } elseif ($type instanceof TLiteralFloat) {
                $this->literal_float_types[$key] = $type;
            } elseif ($type instanceof TClassString
                && ($type->as_type || $type instanceof TTemplateParamClass)
            ) {
                $this->typed_class_strings[$key] = $type;
            }
        }
    }

    public function removeType(string $type_string): bool
    {
        if (isset($this->types[$type_string])) {
            unset($this->types[$type_string]);

            if (strpos($type_string, '(')) {
                unset(
                    $this->literal_string_types[$type_string],
                    $this->literal_int_types[$type_string],
                    $this->literal_float_types[$type_string]
                );
            }

            $this->bustCache();

            return true;
        }

        if ($type_string === 'string') {
            if ($this->literal_string_types) {
                foreach ($this->literal_string_types as $literal_key => $_) {
                    unset($this->types[$literal_key]);
                }
                $this->literal_string_types = [];
            }

            if ($this->typed_class_strings) {
                foreach ($this->typed_class_strings as $typed_class_key => $_) {
                    unset($this->types[$typed_class_key]);
                }
                $this->typed_class_strings = [];
            }

            unset($this->types['class-string'], $this->types['trait-string']);
        } elseif ($type_string === 'int' && $this->literal_int_types) {
            foreach ($this->literal_int_types as $literal_key => $_) {
                unset($this->types[$literal_key]);
            }
            $this->literal_int_types = [];
        } elseif ($type_string === 'float' && $this->literal_float_types) {
            foreach ($this->literal_float_types as $literal_key => $_) {
                unset($this->types[$literal_key]);
            }
            $this->literal_float_types = [];
        }

        return false;
    }

    public function bustCache(): void
    {
        $this->id = null;
        $this->exact_id = null;
    }

    public function substitute(Union $old_type, ?Union $new_type = null): void
    {
        if ($this->hasMixed() && !$this->isEmptyMixed()) {
            return;
        }

        if ($new_type && $new_type->ignore_nullable_issues) {
            $this->ignore_nullable_issues = true;
        }

        if ($new_type && $new_type->ignore_falsable_issues) {
            $this->ignore_falsable_issues = true;
        }

        foreach ($old_type->types as $old_type_part) {
            if (!$this->removeType($old_type_part->getKey())) {
                if ($old_type_part instanceof TFalse
                    && isset($this->types['bool'])
                    && !isset($this->types['true'])
                ) {
                    $this->removeType('bool');
                    $this->types['true'] = new TTrue;
                } elseif ($old_type_part instanceof TTrue
                    && isset($this->types['bool'])
                    && !isset($this->types['false'])
                ) {
                    $this->removeType('bool');
                    $this->types['false'] = new TFalse;
                } elseif (isset($this->types['iterable'])) {
                    if ($old_type_part instanceof TNamedObject
                        && $old_type_part->value === 'Traversable'
                        && !isset($this->types['array'])
                    ) {
                        $this->removeType('iterable');
                        $this->types['array'] = new TArray([Type::getArrayKey(), Type::getMixed()]);
                    }

                    if ($old_type_part instanceof TArray
                        && !isset($this->types['traversable'])
                    ) {
                        $this->removeType('iterable');
                        $this->types['traversable'] = new TNamedObject('Traversable');
                    }
                } elseif (isset($this->types['array-key'])) {
                    if ($old_type_part instanceof TString
                        && !isset($this->types['int'])
                    ) {
                        $this->removeType('array-key');
                        $this->types['int'] = new TInt();
                    }

                    if ($old_type_part instanceof TInt
                        && !isset($this->types['string'])
                    ) {
                        $this->removeType('array-key');
                        $this->types['string'] = new TString();
                    }
                }
            }
        }

        if ($new_type) {
            foreach ($new_type->types as $key => $new_type_part) {
                if (!isset($this->types[$key])
                    || ($new_type_part instanceof Scalar
                        && get_class($new_type_part) === get_class($this->types[$key]))
                ) {
                    $this->types[$key] = $new_type_part;
                } else {
                    $this->types[$key] = TypeCombiner::combine([$new_type_part, $this->types[$key]])->getSingleAtomic();
                }
            }
        } elseif (count($this->types) === 0) {
            $this->types['mixed'] = new TMixed();
        }

        $this->bustCache();
    }


    public function replaceClassLike(string $old, string $new): void
    {
        foreach ($this->types as $key => $atomic_type) {
            $atomic_type->replaceClassLike($old, $new);

            $this->removeType($key);
            $this->addType($atomic_type);
        }
    }
}
