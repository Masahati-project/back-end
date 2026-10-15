<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceImage extends Model
{
    /**
     * Without this every attribute is guarded, and Eloquent's default is to guard
     * everything. So WorkspaceImage::create() threw a MassAssignmentException on
     * the first column it was handed — which is every column. OwnerSpaceController
     * creates one of these for the base64 `image` on a space, so uploading a
     * picture to a listing was a 500.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'image_url',
    ];

    /**
     * The table has no timestamp columns at all — not even created_at — so
     * Eloquent's defaults wrote updated_at and the insert was rejected outright.
     *
     * @var bool
     */
    public $timestamps = false;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
