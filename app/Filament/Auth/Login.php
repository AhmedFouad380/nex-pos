<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * One login for both panels: a cashier who signs in on /admin (or a manager without the cash register on /pos)
 * lands in the panel they may use, instead of "these credentials do not match our records".
 * Someone with no panel at all is still refused with that message. Signs in by e-mail or username.
 */
class Login extends \Filament\Auth\Pages\Login
{
    /** Users imported from the old system have a username and no real e-mail: either one signs in. */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Email or username'))
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /** @return array<string, mixed> */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) $data['email']);

        return [str_contains($login, '@') ? 'email' : 'username' => $login, 'password' => $data['password']];
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $this->panelFor($user) !== null;
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        $user = Filament::auth()->user();
        $current = Filament::getCurrentOrDefaultPanel();
        if ($response !== null && $user instanceof User && ! $user->canAccessPanel($current)) {
            $panel = $this->panelFor($user);
            if ($panel !== null) {
                $this->redirect($panel->getUrl() ?? '/', navigate: false);

                return null;
            }
        }

        return $response;
    }

    /** The current panel when allowed, otherwise the first other panel the user may open. */
    private function panelFor(Authenticatable $user): ?Panel
    {
        if (! $user instanceof User) {
            return Filament::getCurrentOrDefaultPanel();
        }
        $current = Filament::getCurrentOrDefaultPanel();
        if ($user->canAccessPanel($current)) {
            return $current;
        }

        return collect(Filament::getPanels())->first(fn (Panel $panel) => $user->canAccessPanel($panel));
    }
}
