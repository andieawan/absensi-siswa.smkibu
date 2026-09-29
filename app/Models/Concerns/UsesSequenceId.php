<?php

namespace App\Models\Concerns;

use App\Services\Sequence;

/** ID diambil dari tabel `sequences` (kompatibel dengan data lama, aman untuk MySQL tanpa AUTO_INCREMENT). */
trait UsesSequenceId
{
    public static function bootUsesSequenceId(): void
    {
        static::creating(function ($model) {
            if (! $model->getKey()) {
                $model->setAttribute($model->getKeyName(), Sequence::next(static::sequenceEntity()));
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    protected static function sequenceEntity(): string
    {
        return (new static)->getTable();
    }
}
