<?php

namespace Modules\Authentication\Services;

use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Data\EffectiveAuthPolicy;

final class AuthenticationPolicyService
{
    public const METHOD_LOGIN_CODE = 'login_code';

    public const METHOD_USERNAME_PASSWORD = 'username_password';

    public function __construct(private readonly AuthPolicyResolver $policies) {}

    /** @return list<string> */
    public function allowedMethods(
        string $channel,
        AuthorizationScope $scope,
        ?string $fallbackPosLoginMode = null,
        bool $forUpdate = false,
    ): array
    {
        $channel = $this->normaliseChannel($channel);

        // This is a non-overridable product boundary. Dynamic policies may
        // strengthen back-office authentication, but never replace the
        // username/password factor with a short POS code.
        if ($channel === 'back_office') {
            return [self::METHOD_USERNAME_PASSWORD];
        }

        if ($channel !== 'pos') {
            return [];
        }

        $mode = (string) $this->effectivePolicy(
            $scope,
            $fallbackPosLoginMode,
            $forUpdate
        )->get(
            'pos.login_mode',
            self::METHOD_USERNAME_PASSWORD
        );

        return match ($mode) {
            'code', 'login_code' => [self::METHOD_LOGIN_CODE],
            'both', 'code_or_password', 'code_or_username_password' => [
                self::METHOD_LOGIN_CODE,
                self::METHOD_USERNAME_PASSWORD,
            ],
            default => [self::METHOD_USERNAME_PASSWORD],
        };
    }

    public function allows(
        string $channel,
        string $method,
        AuthorizationScope $scope,
        ?string $fallbackPosLoginMode = null,
        bool $forUpdate = false,
    ): bool {
        return in_array(
            $method,
            $this->allowedMethods($channel, $scope, $fallbackPosLoginMode, $forUpdate),
            true
        );
    }

    public function effectivePolicy(
        AuthorizationScope $scope,
        ?string $fallbackPosLoginMode = null,
        bool $forUpdate = false,
    ): EffectiveAuthPolicy {
        $fallback = $this->normalisePosMode($fallbackPosLoginMode);

        return $this->policies->resolve(
            'authentication',
            $scope,
            forUpdate: $forUpdate,
            defaultOverrides: $fallback === null
                ? []
                : ['pos' => ['login_mode' => $fallback]],
        );
    }

    private function normaliseChannel(string $channel): string
    {
        return match (strtolower(str_replace([' ', '-'], '_', trim($channel)))) {
            'backoffice', 'back_office', 'cloud' => 'back_office',
            'frontoffice', 'front_office', 'pos', 'terminal', 'edge' => 'pos',
            default => '',
        };
    }

    private function normalisePosMode(?string $mode): ?string
    {
        return match (strtolower(str_replace([' ', '-'], '_', trim((string) $mode)))) {
            'code', 'login_code', 'pin' => 'login_code',
            'both', 'code_or_password', 'code_or_username_password' => 'both',
            'password', 'username_password' => 'username_password',
            default => null,
        };
    }
}
