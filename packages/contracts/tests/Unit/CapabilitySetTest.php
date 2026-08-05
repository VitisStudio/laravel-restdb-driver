<?php

declare(strict_types=1);

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Capabilities\CapabilityGate;
use Vitis\RestDB\Capabilities\CapabilitySet;
use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

it('starts from nothing', function () {
    $set = CapabilitySet::none();

    foreach (Capability::cases() as $capability) {
        expect($set->has($capability))->toBeFalse();
    }
});

it('applies additive and subtractive config', function () {
    $set = CapabilitySet::of(Capability::Select, Capability::Delete)->applyConfig([
        'sort' => true,
        'write.delete' => false,
        'filter' => ['operators' => ['eq', 'in']],
    ]);

    expect($set->has(Capability::Select))->toBeTrue()
        ->and($set->has(Capability::Sort))->toBeTrue()
        ->and($set->has(Capability::Delete))->toBeFalse()
        ->and($set->has(Capability::Filter))->toBeTrue()
        ->and($set->hasOperator(Operator::Eq))->toBeTrue()
        ->and($set->hasOperator(Operator::In))->toBeTrue()
        ->and($set->hasOperator(Operator::Gte))->toBeFalse();
});

it('rejects unknown capability keys with the valid list', function () {
    expect(fn () => CapabilitySet::none()->applyConfig(['paging' => true]))
        ->toThrow(InvalidArgumentException::class, 'paging');
});

it('rejects unknown operators with the valid list', function () {
    expect(fn () => CapabilitySet::none()->applyConfig(['filter' => ['operators' => ['~=']]]))
        ->toThrow(InvalidArgumentException::class, '~=');
});

it('drops declared operators when filter is disabled', function () {
    $set = CapabilitySet::none()
        ->applyConfig(['filter' => ['operators' => ['eq']]])
        ->applyConfig(['filter' => false]);

    expect($set->has(Capability::Filter))->toBeFalse()
        ->and($set->hasOperator(Operator::Eq))->toBeFalse();
});

it('merges sets without losing operators', function () {
    $a = CapabilitySet::of(Capability::Select)->withOperators(Operator::Eq);
    $b = CapabilitySet::of(Capability::Sort)->withOperators(Operator::In);

    $merged = $a->merge($b);

    expect($merged->has(Capability::Select))->toBeTrue()
        ->and($merged->has(Capability::Sort))->toBeTrue()
        ->and($merged->hasOperator(Operator::Eq))->toBeTrue()
        ->and($merged->hasOperator(Operator::In))->toBeTrue();
});

it('gates operators behind the filter capability', function () {
    $withoutFilter = new CapabilityGate(CapabilitySet::none()->withOperators(Operator::Eq), 'crm');

    expect(fn () => $withoutFilter->ensureOperator(Operator::Eq, 'where'))
        ->toThrow(UnsupportedCapabilityException::class, 'filter');
});

it('builds actionable exception messages', function () {
    $gate = new CapabilityGate(CapabilitySet::none(), 'crm');

    try {
        $gate->ensure(Capability::Limit, 'limit', 'App\Models\Article');
        throw new RuntimeException('Expected exception.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->getMessage())
            ->toContain('Connection [crm] does not support [page.limit]')
            ->toContain('limit() on App\Models\Article')
            ->toContain("add 'page.limit' under")
            ->toContain('connections.crm.capabilities');
    }
});
