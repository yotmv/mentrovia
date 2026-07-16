<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ConfirmSensitiveAccountAction
{
    /** @throws ValidationException */
    public function handle(User $user, #[\SensitiveParameter] ?string $currentPassword): void
    {
        $confirmedAt = request()->hasSession()
            ? (int) request()->session()->get('auth.password_confirmed_at', 0)
            : 0;
        $timeout = (int) config('auth.password_timeout', 10800);

        if ($confirmedAt > now()->subSeconds($timeout)->timestamp) {
            return;
        }

        if (! is_string($currentPassword) || ! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => __('The provided password is incorrect.'),
            ]);
        }

        if (request()->hasSession()) {
            request()->session()->put('auth.password_confirmed_at', now()->timestamp);
        }
    }
}
