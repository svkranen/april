<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateMatch;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use PHPUnit\Framework\TestCase;

final class ProcessTemplateWithMatchTest extends TestCase
{
    public function testWithMatchReplacesOnlyTheMatch(): void
    {
        $original = new ProcessTemplate(
            'my-journey',
            '1.2',
            'My Journey',
            initialStepKey: 'intake',
            steps: [new ProcessTemplateStep('intake', type: 'process', processKey: 'intake_process', required: true)],
            transitions: [new ProcessTemplateTransition('intake', 'intake')],
            requiredStepKeys: ['intake'],
            scope: 'journey',
            sourceSystem: 'community-demo',
            match: new ProcessTemplateMatch(['old_process'])
        );

        $copy = $original->withMatch(new ProcessTemplateMatch(['new_process']));

        self::assertNotSame($original, $copy);
        self::assertSame(['new_process'], $copy->match?->anyProcessKeys);
        // every other field is carried over unchanged
        self::assertSame($original->key, $copy->key);
        self::assertSame($original->version, $copy->version);
        self::assertSame($original->name, $copy->name);
        self::assertSame($original->initialStepKey, $copy->initialStepKey);
        self::assertSame($original->steps, $copy->steps);
        self::assertSame($original->transitions, $copy->transitions);
        self::assertSame($original->requiredStepKeys, $copy->requiredStepKeys);
        self::assertSame($original->scope, $copy->scope);
        self::assertSame($original->sourceSystem, $copy->sourceSystem);
        // the original stays untouched
        self::assertSame(['old_process'], $original->match?->anyProcessKeys);
    }

    public function testWithMatchNullRemovesTheExplicitMatch(): void
    {
        $original = new ProcessTemplate(
            'my-journey',
            scope: 'journey',
            match: new ProcessTemplateMatch(['old_process'])
        );

        $copy = $original->withMatch(null);

        self::assertNull($copy->match);
        self::assertSame(['old_process'], $original->match?->anyProcessKeys);
    }
}
