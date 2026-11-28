<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Rector;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Keeps a bind written as `In\Number`, `Out\Number` or `InOut\Number`: the
 * namespace is the bind's direction, so importing the class as `Number`
 * would hide it. Name importing only touches fully qualified names, and this
 * rule turns these back into the qualified name they were written as.
 */
final class KeepBindDirectionRector extends AbstractRector
{
    private const string BIND = '/^Rajska\\\\Oracle\\\\Binds\\\\(In|Out|InOut)\\\\\w+$/';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Keep In\X, Out\X and InOut\X binds as written', [
            new CodeSample('new In\Number(5);', 'new In\Number(5);'),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FullyQualified::class];
    }

    /**
     * @param FullyQualified $node
     */
    public function refactor(Node $node): Node | null
    {
        if (preg_match(self::BIND, $node->toString()) !== 1) {
            return null;
        }
        $written = $node->getAttribute(AttributeKey::ORIGINAL_NAME);
        if (! $written instanceof Name || $written instanceof FullyQualified || \count($written->getParts()) !== 2) {
            return null;
        }

        return new Name($written->toString());
    }
}
