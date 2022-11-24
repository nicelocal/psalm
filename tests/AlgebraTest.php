<?php

namespace Psalm\Tests;

use PhpParser;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\FormulaGenerator;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\ClauseConjunction;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\Internal\Provider\StatementsProvider;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\IsIdentical;
use Psalm\Storage\Assertion\IsIsset;
use Psalm\Storage\Assertion\IsType;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TString;

use function spl_object_id;

class AlgebraTest extends TestCase
{
    public function testNegateFormula(): void
    {
        $formula = new ClauseConjunction([
            new Clause(['$a' => ['truthy' => new Truthy()]], 1, 1),
        ]);

        $negated_formula = $formula->getNegation();

        $this->assertCount(1, $negated_formula->clauses);
        $this->assertSame('!$a', (string)$negated_formula->clauses[0]);
        $this->assertSame($formula, $negated_formula->getNegation());

        $formula = new ClauseConjunction([
            new Clause(['$a' => ['truthy' => new Truthy()], '$b' => ['truthy' => new Truthy()]], 1, 1),
        ]);

        $negated_formula = $formula->getNegation();

        $this->assertCount(2, $negated_formula->clauses);
        $this->assertSame('!$a', (string)$negated_formula->clauses[0]);
        $this->assertSame('!$b', (string)$negated_formula->clauses[1]);
        $this->assertSame($formula, $negated_formula->getNegation());

        $formula = new ClauseConjunction([
            new Clause(['$a' => ['truthy' => new Truthy()]], 1, 1),
            new Clause(['$b' => ['truthy' => new Truthy()]], 1, 2),
        ]);

        $negated_formula = $formula->getNegation();

        $this->assertCount(1, $negated_formula->clauses);
        $this->assertSame('(!$a) || (!$b)', (string)$negated_formula->clauses[0]);
        $this->assertSame($formula, $negated_formula->getNegation());

        $a1 = new IsType(new TInt());
        $a2 = new IsType(new TString());

        $formula = new ClauseConjunction([
            new Clause(
                [
                    '$a' => [(string)$a1 => $a1, (string)$a2 => $a2],
                    '$b' => ['truthy' => new Truthy()]
                ],
                1,
                1
            ),
        ]);

        $negated_formula = $formula->getNegation();

        $this->assertCount(3, $negated_formula->clauses);
        $this->assertSame('$a is not int', (string)$negated_formula->clauses[0]);
        $this->assertSame('$a is not string', (string)$negated_formula->clauses[1]);
        $this->assertSame('!$b', (string)$negated_formula->clauses[2]);
        $this->assertSame($formula, $negated_formula->getNegation());
    }

    public function testNegateFormulaWithUnreconcilableTerm(): void
    {
        $a1 = new IsType(new TInt());
        $formula = new ClauseConjunction([
            new Clause(['$a' => [(string)$a1 => $a1]], 1, 1),
            new Clause(['$b' => [(string)$a1 => $a1]], 1, 2, false, false),
        ]);

        $negated_formula = $formula->getNegation();

        $this->assertCount(1, $negated_formula->clauses);
        $this->assertSame('$a is not int', (string)$negated_formula->clauses[0]);
    }

    public function testCombinatorialExpansion(): void
    {
        $dnf = '<?php ($b0 === true && $b4 === true && $b8 === true)
                  || ($b0 === true && $b1 === true && $b2 === true)
                  || ($b0 === true && $b3 === true && $b6 === true)
                  || ($b1 === true && $b4 === true && $b7 === true)
                  || ($b2 === true && $b5 === true && $b8 === true)
                  || ($b2 === true && $b4 === true && $b6 === true)
                  || ($b3 === true && $b4 === true && $b5 === true)
                  || ($b6 === true && $b7 === true && $b8 === true);';

        $has_errors = false;

        $dnf_stmt = StatementsProvider::parseStatements($dnf, 7_04_00, $has_errors)[0];

        $this->assertInstanceOf(PhpParser\Node\Stmt\Expression::class, $dnf_stmt);

        $file_analyzer = new FileAnalyzer($this->project_analyzer, 'somefile.php', 'somefile.php');
        $file_analyzer->context = new Context();
        $statements_analyzer = new StatementsAnalyzer($file_analyzer, new NodeDataProvider());

        $dnf_clauses = FormulaGenerator::getFormula(
            spl_object_id($dnf_stmt->expr),
            spl_object_id($dnf_stmt->expr),
            $dnf_stmt->expr,
            null,
            $statements_analyzer
        );

        $this->assertCount(6_561, $dnf_clauses->clauses);

        $simplified_dnf_clauses = (new ClauseConjunction($dnf_clauses->clauses))->simplify();

        $this->assertCount(23, $simplified_dnf_clauses->clauses);
    }

    public function testContainsClause(): void
    {
        $this->assertTrue(
            (new Clause(
                [
                    '$a' => ['truthy' => new Truthy()],
                    '$b' => ['truthy' => new Truthy()],
                ],
                1,
                1
            ))->contains(
                new Clause(
                    [
                        '$a' => ['truthy' => new Truthy()],
                    ],
                    1,
                    1
                )
            )
        );

        $this->assertFalse(
            (new Clause(
                [
                    '$a' => ['truthy' => new Truthy()],
                ],
                1,
                1
            ))->contains(
                new Clause(
                    [
                        '$a' => ['truthy' => new Truthy()],
                        '$b' => ['truthy' => new Truthy()],
                    ],
                    1,
                    1
                )
            )
        );
    }

    public function testSimplifySimpleCNF(): void
    {
        $formula = new ClauseConjunction([
            new Clause(['$a' => ['truthy' => new Truthy()]], 1, 1),
            new Clause(['$a' => ['falsy' => new Falsy()], '$b' => ['falsy' => new Falsy()]], 1, 2),
        ]);

        $simplified_formula = $formula->simplify();

        $this->assertCount(2, $simplified_formula->clauses);
        $this->assertSame('$a', (string)$simplified_formula->clauses[0]);
        $this->assertSame('!$b', (string)$simplified_formula->clauses[1]);
        $this->assertSame($simplified_formula, $simplified_formula->simplify());
    }

    public function testSimplifyCNFWithOneUselessTerm(): void
    {
        /** @psalm-suppress ArgumentTypeCoercion due to Psalm bug */
        $formula = [
            new Clause(['$a' => ['truthy' => new Truthy()], '$b' => ['truthy' => new Truthy()]], 1, 1),
            new Clause(['$a' => ['falsy' => new Falsy()], '$b' => ['truthy' => new Truthy()]], 1, 2),
        ];

        $simplified_formula = (new ClauseConjunction($formula))->simplify();

        $this->assertCount(1, $simplified_formula->clauses);
        $this->assertSame('$b', (string)$simplified_formula->clauses[0]);
    }

    public function testSimplifyCNFWithNonUselessTerm(): void
    {
        $formula = [
            new Clause(['$a' => ['truthy' => new Truthy()], '$b' => ['truthy' => new Truthy()]], 1, 1),
            new Clause(['$a' => ['falsy' => new Falsy()], '$b' => ['falsy' => new Falsy()]], 1, 2),
        ];

        $simplified_formula = (new ClauseConjunction($formula))->simplify();

        $this->assertCount(2, $simplified_formula->clauses);
        $this->assertSame('($a) || ($b)', (string)$simplified_formula->clauses[0]);
        $this->assertSame('(!$a) || (!$b)', (string)$simplified_formula->clauses[1]);
    }

    public function testSimplifyCNFWithUselessTermAndOneInMiddle(): void
    {
        /** @psalm-suppress ArgumentTypeCoercion due to Psalm bug */
        $formula = [
            new Clause(['$a' => ['truthy' => new Truthy()], '$b' => ['truthy' => new Truthy()]], 1, 1),
            new Clause(['$b' => ['truthy' => new Truthy()]], 1, 2),
            new Clause(['$a' => ['falsy' => new Falsy()], '$b' => ['truthy' => new Truthy()]], 1, 3),
        ];

        $simplified_formula = (new ClauseConjunction($formula))->simplify();

        $this->assertCount(1, $simplified_formula->clauses);
        $this->assertSame('$b', (string)$simplified_formula->clauses[0]);
    }

    public function testGroupImpossibilities(): void
    {
        $a1 = new IsIdentical(new TArray([Type::getArrayKey(), Type::getMixed()]));

        $clause1 = (new Clause(
            [
                '$a' => [(string)$a1 => $a1]
            ],
            1,
            2,
            false,
            true,
            true,
            []
        ));

        $a2 = new IsIsset();

        $clause2 = (new Clause(
            [
                '$b' => [(string)$a2 => $a2]
            ],
            1,
            2,
            false,
            true,
            true,
            []
        ));

        $result_clauses = (new ClauseConjunction([$clause1, $clause2]))->getNegation()->clauses;

        $this->assertCount(1, $result_clauses);
        $this->assertCount(0, $result_clauses[0]->possibilities);
    }
}
