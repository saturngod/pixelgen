<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReferenceImageStoreRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReferenceImageController extends Controller
{
    /**
     * Days the uploaded image can be downloaded by Pixelforge. S3 signed links last 7 days at most.
     */
    private const LinkDays = 7;

    /**
     * Store an uploaded reference image in the private bucket and return a temporary link to it.
     */
    public function store(ReferenceImageStoreRequest $request): JsonResponse
    {
        $image = $request->file('image');
        $path = "references/{$request->user()->id}/".Str::ulid().'.'.$image->guessExtension();

        Storage::disk('s3')->put($path, $image->get(), ['ContentType' => $image->getMimeType()]);

        return response()->json([
            'url' => Storage::disk('s3')->temporaryUrl($path, now()->addDays(self::LinkDays)),
        ], 201);
    }
}
