<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradePackageFolder extends Model
{
    protected $fillable = [
        'trade_package_id',
        'name',
        'key',
        'sort_order',
    ];

    public function tradePackage()
    {
        return $this->belongsTo(TradePackage::class);
    }

    public function fileUploads()
    {
        return $this->hasMany(FileUpload::class, 'trade_package_folder_key', 'key')
            ->where('trade_package_id', $this->trade_package_id);
    }
}
