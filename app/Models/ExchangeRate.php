<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['channel_slug', 'channel_name', 'buy_rate', 'sell_rate'];
}
