<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use PHPUnit\Framework\TestCase;

class ProcessTemplateArrayFactoryTest extends TestCase
{
    public function testBuildsTemplateWithSteps(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'version' => '1',
            'name' => 'Invoice Process',
            'initial_step' => 'received',
            'steps' => [
                ['key' => 'received', 'name' => 'Received', 'type' => 'start'],
                ['key' => 'approved'],
            ],
        ]);

        self::assertSame('invoice', $template->key);
        self::assertSame('1', $template->version);
        self::assertSame('Invoice Process', $template->name);
        self::assertSame('received', $template->initialStepKey);
        self::assertCount(2, $template->steps);
        self::assertSame('received', $template->steps[0]->key);
        self::assertSame('Received', $template->steps[0]->name);
        self::assertSame('start', $template->steps[0]->type);
        self::assertSame('approved', $template->steps[1]->key);
        self::assertNull($template->steps[1]->name);
        self::assertSame('normal', $template->steps[1]->type);
    }

    public function testBuildsTemplateWithTransitions(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'transitions' => [
                ['from' => 'received', 'to' => 'approved'],
                ['from' => 'approved', 'to' => 'booked'],
            ],
        ]);

        self::assertCount(2, $template->transitions);
        self::assertSame('received', $template->transitions[0]->from);
        self::assertSame('approved', $template->transitions[0]->to);
        self::assertSame('approved', $template->transitions[1]->from);
        self::assertSame('booked', $template->transitions[1]->to);
    }

    public function testBuildsTemplateWithParallelGroups(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'parallel_groups' => [
                [
                    'key' => 'approval_group',
                    'after' => 'received',
                    'required_steps' => ['manager_approval', 'finance_approval'],
                    'order' => 'any',
                ],
            ],
        ]);

        self::assertCount(1, $template->parallelGroups);
        self::assertSame('approval_group', $template->parallelGroups[0]->key);
        self::assertSame('received', $template->parallelGroups[0]->after);
        self::assertSame(['manager_approval', 'finance_approval'], $template->parallelGroups[0]->requiredStepKeys);
        self::assertSame('any', $template->parallelGroups[0]->order);
    }

    public function testUsesDefaultsForMissingOptionalFields(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
        ]);

        self::assertSame('invoice', $template->key);
        self::assertSame('draft', $template->version);
        self::assertNull($template->name);
        self::assertNull($template->initialStepKey);
        self::assertSame([], $template->steps);
        self::assertSame([], $template->transitions);
        self::assertSame([], $template->parallelGroups);
        self::assertSame([], $template->contextProfileRequiredFields);
    }
}
