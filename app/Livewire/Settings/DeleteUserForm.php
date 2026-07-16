<?php

namespace App\Livewire\Settings;

use App\Actions\Users\EraseUserAccount;
use App\Concerns\PasswordValidationRules;
use App\Exceptions\AccountErasureFailed;
use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(EraseUserAccount $eraseUserAccount, Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        try {
            $eraseUserAccount->handle($user);
        } catch (AccountErasureFailed $exception) {
            report($exception);

            $this->reset('password');

            $this->addError(
                'accountDeletion',
                __('We could not start account deletion. Your account remains active. Please try again or contact support.'),
            );

            return;
        }

        $logout();

        $this->redirect('/', navigate: true);
    }
}
