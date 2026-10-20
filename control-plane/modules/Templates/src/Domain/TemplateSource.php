<?php

namespace Falak\Templates\Domain;

enum TemplateSource: string
{
    /** Curated: github.com/falak-sh/templates, fetched hourly (and baked into the image). */
    case Catalog = 'catalog';
    /** An organization's own template (stored in the database). */
    case Custom = 'custom';
}
