<?php

namespace Modules\Authentication\Services;

use Illuminate\Validation\ValidationException;

final class AuthPolicySettingsValidator
{
    public const SUPPORTED_POLICY_KEYS = ['authentication', 'credential', 'session'];

    /**
     * Validate the durable policy document without coercing values. Policy
     * publication is a security boundary: accepting "5", 5.5 or arbitrary
     * nested keys here would make runtime behaviour depend on PHP casts.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function validate(string $policyKey, array $settings): array
    {
        $errors = [];
        $policyKey = trim($policyKey);

        if (! in_array($policyKey, self::SUPPORTED_POLICY_KEYS, true)) {
            $errors['policy_key'][] = 'The selected authentication policy key is invalid.';
        } elseif ($settings === []) {
            $errors['settings'][] = 'At least one policy setting is required.';
        } elseif (array_is_list($settings)) {
            $errors['settings'][] = 'Policy settings must be a JSON object.';
        } elseif ($policyKey === 'authentication') {
            $this->validateAuthentication($settings, $errors);
        } elseif ($policyKey === 'credential') {
            $this->validateCredential($settings, $errors);
        } else {
            $this->validateSession($settings, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings @param array<string, list<string>> $errors */
    private function validateAuthentication(array $settings, array &$errors): void
    {
        $this->rejectUnknown($settings, ['pos', 'back_office', 'lockout'], 'settings', $errors);

        if (array_key_exists('pos', $settings)) {
            $pos = $this->object($settings['pos'], 'settings.pos', $errors);
            if ($pos !== null) {
                $this->rejectUnknown($pos, ['login_mode'], 'settings.pos', $errors);
                if (array_key_exists('login_mode', $pos)) {
                    $this->enum(
                        $pos['login_mode'],
                        ['username_password', 'login_code', 'both'],
                        'settings.pos.login_mode',
                        $errors
                    );
                }
            }
        }

        if (array_key_exists('back_office', $settings)) {
            $backOffice = $this->object($settings['back_office'], 'settings.back_office', $errors);
            if ($backOffice !== null) {
                $this->rejectUnknown($backOffice, ['login_mode'], 'settings.back_office', $errors);
                if (array_key_exists('login_mode', $backOffice)
                    && $backOffice['login_mode'] !== 'username_password') {
                    $errors['settings.back_office.login_mode'][] =
                        'Back Office login mode is fixed to username_password.';
                }
            }
        }

        if (array_key_exists('lockout', $settings)) {
            $lockout = $this->object($settings['lockout'], 'settings.lockout', $errors);
            if ($lockout !== null) {
                $this->rejectUnknown(
                    $lockout,
                    ['maximum_attempts', 'decay_seconds'],
                    'settings.lockout',
                    $errors
                );
                if (array_key_exists('maximum_attempts', $lockout)) {
                    $this->integerRange(
                        $lockout['maximum_attempts'],
                        1,
                        100,
                        'settings.lockout.maximum_attempts',
                        $errors
                    );
                }
                if (array_key_exists('decay_seconds', $lockout)) {
                    $this->integerRange(
                        $lockout['decay_seconds'],
                        30,
                        86400,
                        'settings.lockout.decay_seconds',
                        $errors
                    );
                }
            }
        }
    }

    /** @param array<string, mixed> $settings @param array<string, list<string>> $errors */
    private function validateCredential(array $settings, array &$errors): void
    {
        $this->rejectUnknown($settings, ['password', 'login_code'], 'settings', $errors);

        if (array_key_exists('password', $settings)) {
            $password = $this->object($settings['password'], 'settings.password', $errors);
            if ($password !== null) {
                $this->rejectUnknown($password, [
                    'minimum_length',
                    'maximum_length',
                    'require_uppercase',
                    'require_lowercase',
                    'require_number',
                    'require_symbol',
                    'history_count',
                ], 'settings.password', $errors);

                if (array_key_exists('minimum_length', $password)) {
                    $this->integerRange(
                        $password['minimum_length'],
                        6,
                        128,
                        'settings.password.minimum_length',
                        $errors
                    );
                }
                if (array_key_exists('maximum_length', $password)) {
                    $this->integerRange(
                        $password['maximum_length'],
                        6,
                        1024,
                        'settings.password.maximum_length',
                        $errors
                    );
                }
                $this->requirePair(
                    $password,
                    'minimum_length',
                    'maximum_length',
                    'settings.password',
                    $errors
                );
                $this->orderedPair(
                    $password,
                    'minimum_length',
                    'maximum_length',
                    'settings.password',
                    $errors
                );
                foreach (['require_uppercase', 'require_lowercase', 'require_number', 'require_symbol'] as $key) {
                    if (array_key_exists($key, $password)) {
                        $this->boolean($password[$key], "settings.password.{$key}", $errors);
                    }
                }
                if (array_key_exists('history_count', $password)) {
                    $this->integerRange(
                        $password['history_count'],
                        0,
                        24,
                        'settings.password.history_count',
                        $errors
                    );
                }
            }
        }

        if (array_key_exists('login_code', $settings)) {
            $loginCode = $this->object($settings['login_code'], 'settings.login_code', $errors);
            if ($loginCode !== null) {
                $this->rejectUnknown($loginCode, [
                    'minimum_length',
                    'maximum_length',
                    'numeric_only',
                    'reject_repeated_digits',
                    'reject_sequences',
                ], 'settings.login_code', $errors);

                foreach (['minimum_length', 'maximum_length'] as $key) {
                    if (array_key_exists($key, $loginCode)) {
                        $this->integerRange(
                            $loginCode[$key],
                            4,
                            12,
                            "settings.login_code.{$key}",
                            $errors
                        );
                    }
                }
                $this->requirePair(
                    $loginCode,
                    'minimum_length',
                    'maximum_length',
                    'settings.login_code',
                    $errors
                );
                $this->orderedPair(
                    $loginCode,
                    'minimum_length',
                    'maximum_length',
                    'settings.login_code',
                    $errors
                );
                foreach (['numeric_only', 'reject_repeated_digits', 'reject_sequences'] as $key) {
                    if (array_key_exists($key, $loginCode)) {
                        $this->boolean($loginCode[$key], "settings.login_code.{$key}", $errors);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $settings @param array<string, list<string>> $errors */
    private function validateSession(array $settings, array &$errors): void
    {
        $this->rejectUnknown($settings, ['pos', 'back_office'], 'settings', $errors);

        foreach (['pos', 'back_office'] as $channel) {
            if (! array_key_exists($channel, $settings)) {
                continue;
            }

            $session = $this->object($settings[$channel], "settings.{$channel}", $errors);
            if ($session === null) {
                continue;
            }

            $this->rejectUnknown(
                $session,
                ['lifetime_minutes', 'idle_timeout_minutes'],
                "settings.{$channel}",
                $errors
            );
            if (array_key_exists('lifetime_minutes', $session)) {
                $this->integerRange(
                    $session['lifetime_minutes'],
                    5,
                    10080,
                    "settings.{$channel}.lifetime_minutes",
                    $errors
                );
            }
            if (array_key_exists('idle_timeout_minutes', $session)) {
                $this->integerRange(
                    $session['idle_timeout_minutes'],
                    1,
                    1440,
                    "settings.{$channel}.idle_timeout_minutes",
                    $errors
                );
            }
            $this->requirePair(
                $session,
                'idle_timeout_minutes',
                'lifetime_minutes',
                "settings.{$channel}",
                $errors
            );
            $this->orderedPair(
                $session,
                'idle_timeout_minutes',
                'lifetime_minutes',
                "settings.{$channel}",
                $errors,
                'The idle timeout may not exceed the session lifetime.'
            );
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowed
     * @param array<string, list<string>> $errors
     */
    private function rejectUnknown(array $values, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($values) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $errors[$path.'.'.(string) $key][] = 'This policy setting is not supported.';
            }
        }
    }

    /** @param array<string, list<string>> $errors @return array<string, mixed>|null */
    private function object(mixed $value, string $path, array &$errors): ?array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            $errors[$path][] = 'This policy section must be a non-empty JSON object.';

            return null;
        }

        return $value;
    }

    /** @param list<string> $allowed @param array<string, list<string>> $errors */
    private function enum(mixed $value, array $allowed, string $path, array &$errors): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $errors[$path][] = 'The selected value is invalid.';
        }
    }

    /** @param array<string, list<string>> $errors */
    private function integerRange(mixed $value, int $minimum, int $maximum, string $path, array &$errors): void
    {
        if (! is_int($value)) {
            $errors[$path][] = 'The value must be an integer.';

            return;
        }

        if ($value < $minimum || $value > $maximum) {
            $errors[$path][] = "The value must be between {$minimum} and {$maximum}.";
        }
    }

    /** @param array<string, list<string>> $errors */
    private function boolean(mixed $value, string $path, array &$errors): void
    {
        if (! is_bool($value)) {
            $errors[$path][] = 'The value must be true or false.';
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function orderedPair(
        array $values,
        string $minimumKey,
        string $maximumKey,
        string $path,
        array &$errors,
        string $message = 'The minimum value may not exceed the maximum value.',
    ): void {
        if (! array_key_exists($minimumKey, $values)
            || ! array_key_exists($maximumKey, $values)
            || ! is_int($values[$minimumKey])
            || ! is_int($values[$maximumKey])) {
            return;
        }

        if ($values[$minimumKey] > $values[$maximumKey]) {
            $errors["{$path}.{$minimumKey}"][] = $message;
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function requirePair(
        array $values,
        string $firstKey,
        string $secondKey,
        string $path,
        array &$errors,
    ): void {
        $hasFirst = array_key_exists($firstKey, $values);
        $hasSecond = array_key_exists($secondKey, $values);
        if ($hasFirst === $hasSecond) {
            return;
        }

        $missing = $hasFirst ? $secondKey : $firstKey;
        $present = $hasFirst ? $firstKey : $secondKey;
        $errors["{$path}.{$missing}"][] =
            "The {$missing} setting is required when {$present} is provided.";
    }
}
