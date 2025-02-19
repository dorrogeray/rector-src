<?php

declare(strict_types=1);

namespace Rector\Core\NodeManipulator;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use Rector\Core\NodeAnalyzer\ExprAnalyzer;
use Rector\Core\PhpParser\Comparing\NodeComparator;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;
use Rector\ReadWrite\Guard\VariableToConstantGuard;

final class VariableManipulator
{
    public function __construct(
        private readonly AssignManipulator $assignManipulator,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly SimpleCallableNodeTraverser $simpleCallableNodeTraverser,
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly VariableToConstantGuard $variableToConstantGuard,
        private readonly NodeComparator $nodeComparator,
        private readonly ExprAnalyzer $exprAnalyzer
    ) {
    }

    /**
     * @return Assign[]
     */
    public function collectScalarOrArrayAssignsOfVariable(ClassMethod $classMethod, Class_ $class): array
    {
        $currentClassName = (string) $this->nodeNameResolver->getName($class);
        $assignsOfArrayToVariable = [];

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable(
            (array) $classMethod->getStmts(),
            function (Node $node) use (&$assignsOfArrayToVariable, $class, $currentClassName) {
                if (! $node instanceof Assign) {
                    return null;
                }

                if (! $node->var instanceof Variable) {
                    return null;
                }

                if ($this->exprAnalyzer->isDynamicExpr($node->expr)) {
                    return null;
                }

                if ($this->hasEncapsedStringPart($node->expr)) {
                    return null;
                }

                if ($this->isTestCaseExpectedVariable($node->var)) {
                    return null;
                }

                if ($node->expr instanceof ConstFetch) {
                    return null;
                }

                if ($node->expr instanceof ClassConstFetch && $this->isOutsideClass(
                    $node->expr,
                    $class,
                    $currentClassName
                )) {
                    return null;
                }

                $assignsOfArrayToVariable[] = $node;
            }
        );

        return $assignsOfArrayToVariable;
    }

    /**
     * @param Assign[] $assignsOfArrayToVariable
     * @return Assign[]
     */
    public function filterOutChangedVariables(array $assignsOfArrayToVariable, ClassMethod $classMethod): array
    {
        return array_filter(
            $assignsOfArrayToVariable,
            fn (Assign $assign): bool => $this->isReadOnlyVariable($classMethod, $assign)
        );
    }

    private function isOutsideClass(
        ClassConstFetch $classConstFetch,
        Class_ $currentClass,
        string $currentClassName
    ): bool {
        /**
         * Dynamic class already checked on $this->exprAnalyzer->isDynamicValue() early
         * @var Name $class
         */
        $class = $classConstFetch->class;
        if ($this->nodeNameResolver->isName($class, 'self')) {
            return $currentClass->extends instanceof FullyQualified;
        }

        return ! $this->nodeNameResolver->isName($class, $currentClassName);
    }

    private function hasEncapsedStringPart(Expr $expr): bool
    {
        return (bool) $this->betterNodeFinder->findFirst(
            $expr,
            static fn (Node $subNode): bool => $subNode instanceof Encapsed || $subNode instanceof EncapsedStringPart
        );
    }

    private function isTestCaseExpectedVariable(Variable $variable): bool
    {
        $classLike = $this->betterNodeFinder->findParentType($variable, ClassLike::class);
        if (! $classLike instanceof ClassLike) {
            return false;
        }

        $className = (string) $this->nodeNameResolver->getName($classLike);
        if (! \str_ends_with($className, 'Test')) {
            return false;
        }

        return $this->nodeNameResolver->isName($variable, 'expect*');
    }

    /**
     * Inspiration
     * @see \Rector\Core\NodeManipulator\PropertyManipulator::isPropertyUsedInReadContext()
     */
    private function isReadOnlyVariable(ClassMethod $classMethod, Assign $assign): bool
    {
        if (! $assign->var instanceof Variable) {
            return false;
        }

        $variableUsages = $this->collectVariableUsages($classMethod, $assign->var, $assign);

        foreach ($variableUsages as $variableUsage) {
            if ($variableUsage instanceof Arg) {
                return false;
            }

            if (! $this->assignManipulator->isLeftPartOfAssign($variableUsage)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return Variable[]|Arg[]
     */
    private function collectVariableUsages(ClassMethod $classMethod, Variable $variable, Assign $assign): array
    {
        /** @var Variable[]|Arg[] $variables */
        $variables = [];

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable(
            (array) $classMethod->getStmts(),
            function (Node $node) use ($variable, $assign, &$variables): ?int {
                // skip anonymous classes and inner function
                if ($node instanceof Class_ || $node instanceof Function_) {
                    return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                // skip initialization
                if ($node === $assign) {
                    return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                if ($node instanceof Arg && $node->value instanceof Variable && ! $this->variableToConstantGuard->isReadArg(
                    $node
                )) {
                    $variables = [$node];
                    return NodeTraverser::STOP_TRAVERSAL;
                }

                if (! $node instanceof Variable) {
                    return null;
                }

                if ($this->nodeComparator->areNodesEqual($node, $variable)) {
                    $variables[] = $node;
                }

                return null;
            }
        );

        return $variables;
    }
}
