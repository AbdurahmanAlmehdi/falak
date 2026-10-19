<?php

namespace Falak\Kernel\Http;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;

/**
 * post_max_size is a form limit: raw bodies that the endpoint streams to disk under its own cap (builder artifact
 * uploads, capped by builds.artifacts.max_upload_bytes) skip it, or every release over 64 MB fails with 413.
 */
final class StreamedUploadsPostSize extends ValidatePostSize
{
    /** @var list<string> */
    private const STREAMED = ['api/internal/artifacts/*'];

    public function handle($request, Closure $next)
    {
        if ($request instanceof Request && $request->isMethod('PUT') && $request->is(...self::STREAMED)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
