<?php

namespace Falak\Projects\Http\Requests;

/**
 * Validation rules shared by the web and API controllers.
 */
final class ProjectRules
{
    public const NAME = ['string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'];

    public const ENVIRONMENT_NAME = ['string', 'max:48', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'];

    public const COORDINATE = ['integer', 'between:-1000000,1000000'];

    /** A Cool Icons slug, optionally with a variant ("docker", "docker/svgl-dark"); the bundled keys fit too ("php-fpm"). */
    public const ICON = ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,63}(\\/[a-z0-9][a-z0-9-]{0,47})?$/'];

    public const ICON_MESSAGES = ['icon.regex' => 'Use an icon slug from the icon picker.'];

    /**
     * @return array<string, mixed>
     */
    public static function project(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', ...self::NAME],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'icon' => ['sometimes', ...self::ICON],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function environment(bool $creating): array
    {
        return array_filter([
            'name' => ['required', ...self::ENVIRONMENT_NAME],
            'from_environment_id' => $creating ? ['nullable', 'string', 'size:26'] : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores.',
            ...self::ICON_MESSAGES,
        ];
    }
}
