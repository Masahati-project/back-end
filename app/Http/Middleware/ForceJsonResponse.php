<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /api/* route answers JSON, whatever the client asked for.
 *
 * Laravel used to ship ForceJsonResponse and put it in the `api` group. It was
 * dropped from the framework, and with it went the only thing that made
 * `$request->expectsJson()` true for a request that did not send an Accept
 * header. That default matters more than it looks, because the handler branches
 * on it for the two most common answers this API gives:
 *
 *   - ValidationException: `$request->expectsJson()` decides between a 422 with
 *     an `errors` map and a 302 redirect back with the errors flashed to the
 *     session. Without this middleware, POST /api/owner/spaces with a missing
 *     field answered 302 to http://localhost with an HTML body — verified, not
 *     theorised. That is the worst possible answer for the one endpoint where
 *     a client mistake is most likely: `docs` is a required upload, and a form
 *     sent without it got a redirect and no explanation at all.
 *   - AuthenticationException: the same branch decides between a 401 JSON body
 *     and a redirect to route('login'), which does not exist here.
 *
 * Only /api/* is affected. routes/web.php keeps serving whatever it serves, and
 * the /up health check is registered outside the api group.
 *
 * Setting the Accept header is all this does; it is not a response decorator,
 * so nothing else about how a response is built changes.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}