<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'chapter',
        'question',
        'answer',
        'language',
        'has_image',
        'image_path'
    ];
}
