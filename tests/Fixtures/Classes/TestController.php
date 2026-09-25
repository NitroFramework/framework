<?php

namespace Nitro\Tests\Fixtures\Classes;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TestController extends Controller
{
    public function __construct(public Counter $counter)
    {
    }

    public function index()
    {
        return 'home';
    }

    public function show(string $id)
    {
        return "id={$id}";
    }

    public function inject(Request $request, Counter $counter, $id)
    {
        return ['path' => $request->path(), 'counter' => $counter instanceof Counter, 'id' => $id];
    }

    public function optional($a = 'default')
    {
        return "a={$a}";
    }

    public function post(Post $post)
    {
        return ['id' => $post->id, 'slug' => $post->slug];
    }

    public function status(Status $status)
    {
        return $status->value;
    }

    public function httpResponse()
    {
        throw new HttpResponseException(response('from exception', 202));
    }

    public function form(StorePostRequest $request)
    {
        return ['validated' => $request->validated()];
    }
}
