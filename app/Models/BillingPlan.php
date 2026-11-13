<?php

namespace App\Models;

use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    use BelongsToApp;

    public const ENTITLEMENT_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    protected $fillable = ['app_id', 'name', 'description', 'entitlement', 'amount', 'currency', 'interval', 'stripe_product_id', 'stripe_price_id', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function priceLabel(): string
    {
        $amount = $this->currency === 'jpy' ? number_format($this->amount).'円' : number_format($this->amount / 100, 2).' '.strtoupper($this->currency);

        return $amount.' / '.($this->interval === 'year' ? '年' : '月');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'entitlement' => $this->entitlement,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'price_label' => $this->priceLabel(),
        ];
    }
}
