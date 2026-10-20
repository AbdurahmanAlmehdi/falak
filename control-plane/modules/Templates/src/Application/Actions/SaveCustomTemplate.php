<?php

namespace Falak\Templates\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Application\Catalog\TemplateValidator;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Models\CustomTemplate;
use Falak\Templates\Domain\Models\CustomTemplateRevision;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import or edit an organization template. Same schema and validation as the catalog; every save appends a
 * revision. Errors: ValidationException on `template` (one message per problem).
 */
final class SaveCustomTemplate
{
    public function __construct(
        private readonly TemplateParser $parser,
        private readonly TemplateValidator $validator,
        private readonly Catalog $catalog,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  ?string  $composeYaml  null: a bundle with the compose file under `compose:`
     *
     * @throws ValidationException
     */
    public function __invoke(string $organizationId, ?string $userId, string $templateYaml, ?string $composeYaml, ?CustomTemplate $existing = null): CustomTemplate
    {
        $template = $this->validate($organizationId, $templateYaml, $composeYaml, $existing);

        $model = DB::transaction(function () use ($organizationId, $userId, $template, $existing) {
            $model = $existing ?? new CustomTemplate(['organization_id' => $organizationId, 'created_by' => $userId, 'revision' => 0]);
            $model->fill([
                'slug' => $template->slug,
                'name' => $template->name,
                'version' => $template->version,
                'category' => $template->category->value,
                'description' => $template->description,
                'template_yaml' => $template->templateYaml,
                'compose_yaml' => $template->composeYaml,
                'updated_by' => $userId,
            ]);
            $model->revision = $model->revision + 1;
            $model->save();

            CustomTemplateRevision::query()->create([
                'template_id' => $model->id,
                'revision' => $model->revision,
                'version' => $template->version,
                'template_yaml' => $template->templateYaml,
                'compose_yaml' => $template->composeYaml,
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            return $model;
        });

        $this->audit->record($existing ? 'templates.updated' : 'templates.imported', 'template', $model->id, [
            'slug' => $model->slug,
            'version' => $model->version,
            'revision' => $model->revision,
        ], $organizationId, $userId);

        return $model;
    }

    /**
     * Parse + validate without saving (the import preview).
     *
     * @throws ValidationException
     */
    public function validate(string $organizationId, string $templateYaml, ?string $composeYaml, ?CustomTemplate $existing = null): Template
    {
        $max = (int) config('templates.max_bytes', 262144);

        if (strlen($templateYaml) > $max || strlen((string) $composeYaml) > $max) {
            throw ValidationException::withMessages(['template' => 'Templates are limited to '.intdiv($max, 1024).' KB per file.']);
        }

        try {
            $template = $this->parser->parse($templateYaml, $composeYaml);
        } catch (InvalidTemplate $e) {
            throw ValidationException::withMessages(['template' => $e->errors]);
        }

        $errors = $this->validator->problems($template);

        if ($template->icon === './icon.svg') {
            $errors[] = 'template.yaml: icon: custom templates use an icon key (e.g. n8n, docker/svgl-dark), not ./icon.svg';
        }

        // A slug the catalog ships later (hourly sync) does not lock the organization's existing template out.
        if ($this->catalog->find($template->slug) !== null && $existing?->slug !== $template->slug) {
            $errors[] = "template.yaml: slug {$template->slug} is used by a catalog template; pick another one";
        }

        $taken = CustomTemplate::query()->where('organization_id', $organizationId)->where('slug', $template->slug)
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();

        if ($taken) {
            $errors[] = "template.yaml: slug {$template->slug} is already used by another template of this organization";
        }

        if ($existing === null && CustomTemplate::query()->where('organization_id', $organizationId)->count() >= (int) config('templates.max_custom_per_organization', 200)) {
            $errors[] = 'This organization has reached its custom template limit.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['template' => array_values($errors)]);
        }

        return $template->withSource(TemplateSource::Custom);
    }
}
