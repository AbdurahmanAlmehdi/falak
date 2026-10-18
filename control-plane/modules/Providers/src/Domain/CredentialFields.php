<?php

namespace Falak\Providers\Domain;

use Falak\Providers\Contracts\ProviderType;

/**
 * Provider-specific credential fields (used for validation and to render the "add credential" form).
 */
final class CredentialFields
{
    /**
     * @return list<array{name: string, label: string, secret: bool, help: string, multiline?: bool, options?: list<string>}>
     */
    public static function for(ProviderType $type): array
    {
        return match ($type) {
            ProviderType::Hetzner => [
                ['name' => 'token', 'label' => 'API token', 'secret' => true, 'help' => 'Cloud Console → Project → Security → API tokens (Read & Write).'],
            ],
            ProviderType::DigitalOcean => [
                ['name' => 'token', 'label' => 'Personal access token', 'secret' => true, 'help' => 'API → Tokens → Generate new token with write scope.'],
            ],
            ProviderType::Vultr => [
                ['name' => 'api_key', 'label' => 'API key', 'secret' => true, 'help' => 'Account → API → Enable API; allow the control plane IP in the access control list.'],
            ],
            ProviderType::Linode => [
                ['name' => 'token', 'label' => 'Personal access token', 'secret' => true, 'help' => 'Cloud Manager → API Tokens; Linodes and Account read/write.'],
            ],
            ProviderType::Aws => [
                ['name' => 'access_key_id', 'label' => 'Access key ID', 'secret' => false, 'help' => 'IAM user with the Lightsail full-access policy.'],
                ['name' => 'secret_access_key', 'label' => 'Secret access key', 'secret' => true, 'help' => ''],
                ['name' => 'region', 'label' => 'Default region', 'secret' => false, 'help' => 'Used for account-level calls and SSH key pairs, e.g. us-east-1.'],
            ],
            ProviderType::Contabo => [
                ['name' => 'api_details', 'label' => 'API details', 'secret' => true, 'multiline' => true, 'help' => 'On my.contabo.com → Account → API: set an API password, then copy the "cntb config set-credentials …" line (or the Client ID, Client Secret, API User and API Password) and paste it here. Falak picks out the four values. Contabo bills monthly: deleting a server cancels it, and it keeps running until the paid month ends.'],
            ],
            ProviderType::Custom => [],
        };
    }

    /**
     * Laravel validation rules for the `credentials` payload of the given provider.
     *
     * @return array<string, list<string>>
     */
    public static function rules(ProviderType $type, bool $required = true): array
    {
        $rules = [];

        foreach (self::for($type) as $field) {
            $rule = [$required ? 'required' : 'sometimes', 'string', ($field['multiline'] ?? false) ? 'max:4096' : 'max:512'];

            if ($field['name'] === 'region') {
                $rule[] = 'regex:/^[a-z]{2}(-[a-z]+)+-\d$/';
            }

            $rules["credentials.{$field['name']}"] = $rule;
        }

        return $rules;
    }

    /**
     * @return list<ProviderType>
     */
    public static function apiProviders(): array
    {
        return array_values(array_filter(ProviderType::cases(), fn (ProviderType $type) => $type->hasApi()));
    }
}
