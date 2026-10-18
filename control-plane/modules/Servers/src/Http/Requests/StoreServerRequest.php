<?php

namespace Falak\Servers\Http\Requests;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Providers\Contracts\ProviderType;
use Falak\Servers\Contracts\ServerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreServerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->requireId();

        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('servers_servers')->where('organization_id', $organizationId)],
            'type' => ['required', Rule::enum(ServerType::class)],
            'provider' => ['required', Rule::enum(ProviderType::class)],
            'credential_id' => ['nullable', 'string', 'max:26'],
            'region' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'string', 'max:200'],
            'timezone' => ['nullable', 'timezone:all'],
            'stack' => ['nullable', 'array'],
            'stack.php' => ['nullable', 'array'],
            'stack.php.runtime' => ['required_with:stack.php', 'string'],
            'stack.php.versions' => ['required_with:stack.php', 'array', 'min:1'],
            'stack.php.versions.*' => ['string'],
            'stack.php.default' => ['required_with:stack.php', 'string'],
            'stack.node' => ['nullable', 'string'],
            'ssh_key_ids' => ['nullable', 'array'],
            'ssh_key_ids.*' => ['string'],
            'control_plane_host' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores.'];
    }
}
