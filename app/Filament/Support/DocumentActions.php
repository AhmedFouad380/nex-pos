<?php

namespace App\Filament\Support;

use App\Support\DomainException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/** Header actions shared by document view pages (void, workflow steps). The page must expose refreshRecord(). */
final class DocumentActions
{
    public static function void(Closure $run, string $permission, object $page): Action
    {
        return Action::make('void')->label(__('Void'))->icon('heroicon-o-no-symbol')->color('danger')
            ->visible(fn (Model $record) => $record->getAttribute('voided_at') === null && auth()->user()?->can($permission))
            ->requiresConfirmation()
            ->modalDescription(__('All stock movements of this document will be reversed.'))
            ->schema([TextInput::make('reason')->label(__('Void reason'))->required()->maxLength(255)])
            ->action(fn (Model $record, array $data) => self::run(fn () => $run($record, $data['reason']), $page, __('Voided')));
    }

    public static function step(string $name, string $label, string $icon, string $color, Closure $visible, Closure $run, object $page): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible($visible)->requiresConfirmation()
            ->action(fn (Model $record) => self::run(fn () => $run($record), $page, __('Saved')));
    }

    private static function run(Closure $callback, object $page, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
        $page->refreshRecord();
    }
}
