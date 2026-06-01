<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectFolder extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'path',
        'folder_number',
        'order',
        'is_auto_created',
        'parent_id',
    ];
}
