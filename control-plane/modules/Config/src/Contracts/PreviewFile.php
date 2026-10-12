<?php

namespace Falak\Config\Contracts;

/**
 * The falak.yaml a pull request's preview is built from ({@see PreviewConfig::prepare()}): resolved for the previews'
 * base environment, without what a preview never takes from a file (database and redis units, which Previews creates
 * per its data strategies; domains, which are the preview's hosts; servers, which are the preview server; volumes).
 */
final readonly class PreviewFile
{
    /**
     * @param  array<string, mixed>  $resolved  the resolved file, pruned for a preview
     * @param  list<string>  $omitted  units the file's `previews.units` leaves out of previews
     * @param  string  $ref  the commit (or branch) the file was read at
     */
    public function __construct(
        public array $resolved,
        public array $omitted,
        public string $ref,
        public string $raw,
        public string $format,
    ) {}

    /** The units a preview runs from the file. */
    public function units(): array
    {
        return array_keys((array) ($this->resolved['units'] ?? []));
    }
}
