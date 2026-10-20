<?php

namespace Falak\Templates\Application\Console;

use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\CatalogRules;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Application\Catalog\TemplateValidator;
use Falak\Templates\Application\Compose\FalakPlaceholders;
use Falak\Templates\Application\Inputs\InputResolver;
use Falak\Templates\Domain\InputType;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\TemplateInput;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Template authoring aid (falak-sh/templates' ./test runs it): validate a catalog template, or a template directory, and print its compose file as Falak would render it (test
 * domains under --domain), optionally with a dotenv of sample input values — pipe into
 * `docker compose -f - --env-file <file> config` to check that Compose accepts it.
 */
final class RenderTemplateCommand extends Command
{
    protected $signature = 'templates:render {template : catalog slug, or a directory with template.yaml + compose.yaml} {--domain=falak.test : test domain base} {--env-file= : write sample input values to this dotenv file}';

    protected $description = 'Validate a catalog template (import + catalog rules) and print its rendered compose file';

    public function handle(Catalog $catalog, TemplateParser $parser, TemplateValidator $validator, CatalogRules $rules, InputResolver $inputs): int
    {
        $argument = (string) $this->argument('template');
        $iconFile = null;

        if (is_dir($argument)) {
            $directory = rtrim($argument, '/');

            if (! is_file("{$directory}/template.yaml")) {
                $this->error("{$directory} has no template.yaml.");

                return self::FAILURE;
            }

            try {
                $template = $parser->parse((string) file_get_contents("{$directory}/template.yaml"), is_file("{$directory}/compose.yaml") ? (string) file_get_contents("{$directory}/compose.yaml") : '');
            } catch (InvalidTemplate $e) {
                foreach ($e->errors as $error) {
                    $this->output->getErrorStyle()->writeln("<error>{$error}</error>");
                }

                return self::FAILURE;
            }

            $iconFile = is_file("{$directory}/icon.svg") ? "{$directory}/icon.svg" : null;
        } else {
            $template = $catalog->find($argument);

            if ($template === null) {
                $this->error('Unknown catalog template (or it does not parse — check the logs).');

                return self::FAILURE;
            }

            $iconFile = $template->iconPath;
        }

        $problems = [...$validator->problems($template), ...$rules->problems($template, $iconFile)];

        foreach ($problems as $problem) {
            $this->output->getErrorStyle()->writeln("<error>{$problem}</error>");
        }

        $base = trim((string) $this->option('domain'), '.');
        $domains = [];

        foreach ($template->publicServices() as $index => $service) {
            $domains[$service] = $index === 0 ? "{$template->slug}.{$base}" : "{$service}-{$template->slug}.{$base}";
        }

        $this->output->write(FalakPlaceholders::render($template->composeYaml, $domains, $template->slug), false, OutputInterface::OUTPUT_RAW);

        if ($file = $this->option('env-file')) {
            $sample = array_map(fn (TemplateInput $input) => match (true) {
                $input->default !== null || $input->generate !== null || ! $input->required => null,
                $input->type === InputType::Email => 'admin@example.com',
                $input->type === InputType::Number => '1',
                $input->type === InputType::Domain => "mail.{$base}",
                default => 'sample',
            }, array_combine($template->inputKeys(), $template->inputs) ?: []);
            $values = $inputs->resolve($template, array_filter($sample, fn ($value) => $value !== null));
            $lines = array_map(fn (string $key, string $value) => $key.'='.escapeshellarg(FalakPlaceholders::render($value, $domains, $template->slug)), array_keys($values), $values);
            file_put_contents((string) $file, implode("\n", $lines)."\n");
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
