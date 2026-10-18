<?php

namespace Modules\Authentication\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Enums\EnterpriseScope;

trait HasEnterpriseScope
{
    public static function bootHasEnterpriseScope(): void
    {
        static::saving(function (Model $model): void {
            $type = EnterpriseScope::tryFrom((string) $model->getAttribute('scope_type'));
            if ($type === null) {
                throw new InvalidArgumentException('The enterprise scope type is invalid.');
            }

            $scope = AuthorizationScope::fromArray($model->getAttributes());
            foreach ($scope->databaseColumns($type) as $column => $value) {
                $model->setAttribute($column, $value);
            }
        });
    }

    public function enterpriseScope(): AuthorizationScope
    {
        return AuthorizationScope::fromArray($this->getAttributes());
    }

    public function enterpriseScopeType(): EnterpriseScope
    {
        return EnterpriseScope::from((string) $this->getAttribute('scope_type'));
    }
}
