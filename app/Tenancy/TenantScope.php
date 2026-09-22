<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Der einzige strukturelle Schutz der Mandantentrennung (Entscheidung A3).
 *
 * MySQL kennt keine Row Level Security, deshalb liegt die Durchsetzung in der
 * Anwendung. Die Grenze gehoert dazu: Der Scope greift auf Eloquent. Ein
 * DB::table(...) umgeht ihn vollstaendig.
 */
final class TenantScope implements Scope
{
    public const NAME = 'tenant';

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->scopeIsSuspended()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('organization_id'),
            $context->requireId($model::class)
        );
    }
}
