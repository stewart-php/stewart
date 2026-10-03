<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Php\PhpType;
use Stewart\Codegen\Snapshot\RawMap;

final readonly class ServiceSelector
{
    private const string ANY_ARRAY = 'array<array-key, mixed>';

    private const string DURATION = 'array{days?: int|float, hours?: int|float, minutes?: int|float, seconds?: int|float, milliseconds?: int|float}|string|int|float';

    /** @var array<string, array{0: string, 1?: string}> */
    private const array KIND_TYPES = [
        'action' => ['array', self::ANY_ARRAY],
        'addon' => ['string'],
        'area' => ['string'],
        'assist_pipeline' => ['string'],
        'attribute' => ['string'],
        'backup_location' => ['string'],
        'boolean' => ['bool'],
        'color_rgb' => ['array', 'array{int, int, int}'],
        'color_temp' => ['int|float'],
        'condition' => ['array', self::ANY_ARRAY],
        'config_entry' => ['string'],
        'conversation_agent' => ['string'],
        'country' => ['string'],
        'date' => ['string'],
        'datetime' => ['string'],
        'device' => ['string'],
        'duration' => ['array|string|int|float', self::DURATION],
        'entity' => ['string'],
        'file' => ['string'],
        'floor' => ['string'],
        'icon' => ['string'],
        'label' => ['string'],
        'language' => ['string'],
        'location' => ['array', self::ANY_ARRAY],
        'media' => ['array', self::ANY_ARRAY],
        'navigation' => ['string'],
        'number' => ['int|float'],
        'object' => ['array', self::ANY_ARRAY],
        'qr_code' => ['string'],
        'selector' => ['array', self::ANY_ARRAY],
        'state' => ['string'],
        'statistic' => ['string'],
        'target' => ['array', self::ANY_ARRAY],
        'template' => ['string'],
        'text' => ['string|int|float'],
        'theme' => ['string'],
        'time' => ['string'],
        'trigger' => ['array', self::ANY_ARRAY],
        'tts' => ['string'],
        'ui_action' => ['array', self::ANY_ARRAY],
        'ui_color' => ['string'],
    ];

    private const string SELECT = 'select';

    private const string CONSTANT = 'constant';

    private const string CONSTANT_VALUE = 'value';

    public ?string $kind;

    public RawMap $configuration;

    public function __construct(RawMap $selector)
    {
        $this->kind = $selector->listKeys()[0] ?? null;
        $this->configuration = $selector->readMap($this->kind ?? '');
    }

    public function resolvePhpType(): PhpType
    {
        if ($this->kind === null) {
            return PhpType::mixed();
        }

        $type = $this->phpTypeForKind($this->kind);

        return $this->configuration->readBool('multiple') ? $type->listOf() : $type;
    }

    private function phpTypeForKind(string $kind): PhpType
    {
        if ($kind === self::SELECT) {
            return $this->phpTypeForSelect();
        }

        if ($kind === self::CONSTANT) {
            return $this->phpTypeForConstant();
        }

        $type = self::KIND_TYPES[$kind] ?? null;

        return $type === null ? PhpType::mixed() : PhpType::fromNative(...$type);
    }

    private function phpTypeForConstant(): PhpType
    {
        $value = $this->configuration->readScalar(self::CONSTANT_VALUE);

        return $value === null ? PhpType::mixed() : PhpType::fromNative(get_debug_type($value), var_export($value, true));
    }

    private function phpTypeForSelect(): PhpType
    {
        $options = $this->selectOptionValues();

        if ($options === [] || $this->configuration->readBool('custom_value')) {
            return PhpType::fromNative('string');
        }

        return PhpType::fromNative('string', implode('|', array_map(static fn(string $option): string => var_export($option, true), $options)));
    }

    /** @return list<string> */
    private function selectOptionValues(): array
    {
        $options = $this->configuration->listStrings('options');

        if ($options !== []) {
            return $options;
        }

        return array_values(array_filter(
            $this->configuration->listMaps('options')->mapToList(static fn(RawMap $option): ?string => $option->readString('value')),
            static fn(?string $value): bool => $value !== null,
        ));
    }
}
